<?php
/**
 * NarrativeParser — AI-driven extraction from free-text interview answers.
 *
 * Calls local Ollama (hermes3-mythos:70b fallback chain) with a strict
 * extraction-only system prompt. Never invents data — returns "unknown"
 * for absent fields.
 *
 * Fallback: if Ollama unreachable (curl error, HTTP non-200, or JSON parse
 * failure), returns an empty extraction dict so the wizard still advances
 * to Step 2 gracefully with no fields pre-filled.
 *
 * Namespace: Mnmsos\Empire\Intake
 */

namespace Mnmsos\Empire\Intake;

use PDO;

class NarrativeParser
{
    private const OLLAMA_URL = 'http://localhost:11434/api/chat';
    private const TIMEOUT_S  = 90;
    private const MODELS     = [
        'hermes3-mythos:70b',
        'hermes3:8b',
        'qwen2.5:7b',
    ];

    /** Fields this parser tries to extract. */
    private const EXTRACT_FIELDS = [
        'biggest_fears',
        'sale_horizon_signal',
        'liability_profile_signal',
        'customer_concentration_signal',
        'owner_age_estimate',
        'ip_concerns',
        'retirement_timeline',
        'estate_concerns',
        'tax_pain_points',
        'partner_dynamics',
        'employee_count_signal',
        'industry_vertical_signal',
        'real_estate_signal',
        'earnout_signal',
        'noncompete_signal',
    ];

    private PDO $db;
    private int $tenantId;

    public function __construct(PDO $db, int $tenantId)
    {
        $this->db       = $db;
        $this->tenantId = $tenantId;
    }

    /**
     * Parse free-text narrative, return extraction dict.
     * Optionally persists the LLM exchange to empire_chat_log.
     *
     * @param string   $text      The user's free-text narrative
     * @param int|null $intakeId  If set, logged against this intake row
     * @return array{
     *   extracted_fields: array<string,mixed>,
     *   follow_up_questions: string[],
     *   confidence: float,
     *   model_used: string,
     *   parse_error: string
     * }
     */
    public function parseNarrative(string $text, ?int $intakeId = null): array
    {
        $text = trim($text);
        if ($text === '') {
            return $this->emptyExtraction('empty_input');
        }

        $systemPrompt = $this->buildSystemPrompt();
        $userMsg      = "CLIENT NARRATIVE:\n\n" . $text . "\n\nExtract the JSON now.";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userMsg],
        ];

        [$ok, $rawText, $model, $err] = $this->callWithFallback($messages);

        if (!$ok || $rawText === '') {
            $result = $this->emptyExtraction($err);
            $this->persistTurn($text, '[parse unavailable: ' . $err . ']', '', $intakeId);
            return $result;
        }

        $parsed = $this->parseJson($rawText);
        if ($parsed === null) {
            $result = $this->emptyExtraction('json_parse_failed');
            $this->persistTurn($text, $rawText, $model, $intakeId);
            return $result;
        }

        $this->persistTurn($text, $rawText, $model, $intakeId);

        return [
            'extracted_fields'    => $parsed['extracted_fields']    ?? $this->blankFields(),
            'follow_up_questions' => $parsed['follow_up_questions'] ?? [],
            'confidence'          => (float)($parsed['confidence']  ?? 0.0),
            'model_used'          => $model,
            'parse_error'         => '',
        ];
    }

    /**
     * Apply extracted fields to an intake row — only fills nulls, never
     * overwrites user-entered values. Returns count of fields populated.
     *
     * @param int   $intakeId   empire_brand_intake.id
     * @param array $extraction extracted_fields dict from parseNarrative()
     * @return int              count of columns actually written
     */
    public function applyExtractionToIntake(int $intakeId, array $extraction): int
    {
        if (empty($extraction)) return 0;

        // Field mapping: extraction key → (column, transform)
        $fieldMap = [
            'sale_horizon_signal' => [
                'column'    => 'decided_sale_horizon',
                'transform' => fn($v) => $this->mapSaleHorizon($v),
                'valid'     => ['never', '5y_plus', '3y', '1y', 'active_sale'],
            ],
            'liability_profile_signal' => [
                'column'    => 'liability_profile',
                'transform' => fn($v) => $this->mapLiabilityProfile($v),
                'valid'     => ['low', 'low_med', 'medium', 'med_high', 'high'],
            ],
            'ip_concerns' => [
                'column'    => 'ip_owned_md',
                'transform' => fn($v) => (string)$v,
                'valid'     => null,
            ],
            'tax_pain_points' => [
                'column'    => 'advisor_notes_md',
                'transform' => fn($v) => '[AI-extracted tax notes] ' . (string)$v,
                'valid'     => null,
            ],
            'industry_vertical_signal' => [
                'column'    => 'industry_vertical',
                'transform' => fn($v) => $this->mapVertical($v),
                'valid'     => ['saas', 'healthcare', 'realestate', 'crypto', 'professional_services',
                                'manufacturing', 'retail', 'agency', 'ecommerce', 'other'],
            ],
            'real_estate_signal' => [
                'column'    => 'real_estate_owned',
                'transform' => fn($v) => (str_contains(strtolower((string)$v), 'yes') || $v === true) ? 1 : null,
                'valid'     => null,
            ],
            'earnout_signal' => [
                'column'    => 'earnout_acceptable',
                'transform' => fn($v) => str_contains(strtolower((string)$v), 'yes') ? 1 : (str_contains(strtolower((string)$v), 'no') ? 0 : null),
                'valid'     => null,
            ],
            'noncompete_signal' => [
                'column'    => 'noncompete_acceptable',
                'transform' => fn($v) => str_contains(strtolower((string)$v), 'yes') ? 1 : (str_contains(strtolower((string)$v), 'no') ? 0 : null),
                'valid'     => null,
            ],
        ];

        // Fetch current row to avoid overwriting non-null values
        $stmt = $this->db->prepare(
            'SELECT ' . implode(',', array_unique(array_column($fieldMap, 'column')))
            . ' FROM empire_brand_intake WHERE id=? AND tenant_id=? LIMIT 1'
        );
        $stmt->execute([$intakeId, $this->tenantId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current) return 0;

        $sets   = [];
        $params = [];
        $count  = 0;

        foreach ($fieldMap as $extractKey => $mapping) {
            $col   = $mapping['column'];
            $val   = $extraction[$extractKey] ?? null;

            if ($val === null || $val === 'unknown' || $val === '') continue;

            // Only fill if current DB column is NULL
            if ($current[$col] !== null && $current[$col] !== '') continue;

            $transformed = ($mapping['transform'])($val);
            if ($transformed === null) continue;

            // Validate against allowed values if list provided
            if (!empty($mapping['valid']) && !in_array($transformed, $mapping['valid'], true)) continue;

            $sets[]   = "`{$col}` = ?";
            $params[] = $transformed;
            $count++;
        }

        if (empty($sets)) return 0;

        $params[] = $intakeId;
        $params[] = $this->tenantId;
        $sql = 'UPDATE empire_brand_intake SET ' . implode(', ', $sets)
             . ' WHERE id=? AND tenant_id=?';
        $this->db->prepare($sql)->execute($params);

        return $count;
    }

    // ─── Private helpers ──────────────────────────────────────────────────

    private function buildSystemPrompt(): string
    {
        $fields = implode(', ', self::EXTRACT_FIELDS);
        return <<<PROMPT
You are a structured-data extractor for DST Empire, a legal/tax structure advisor.

TASK: Extract structured data from a client's free-text narrative. Return ONLY valid JSON.
Do NOT invent data. If a field cannot be determined from the text, return "unknown".
Do NOT give advice, recommendations, or commentary.

EXTRACT THESE FIELDS: {$fields}

FIELD DEFINITIONS:
- biggest_fears: array of strings — fears about the business (lawsuit, taxes, exit, competition, etc.)
- sale_horizon_signal: one of "never","5y_plus","3y","1y","active_sale","unknown"
- liability_profile_signal: one of "low","medium","high","unknown"
- customer_concentration_signal: one of "low","moderate","high","unknown"
- owner_age_estimate: integer age estimate OR null if not determinable
- ip_concerns: string describing IP assets/concerns OR "unknown"
- retirement_timeline: string describing retirement intent OR "unknown"
- estate_concerns: string describing estate/family transfer concerns OR "unknown"
- tax_pain_points: string describing tax problems mentioned OR "unknown"
- partner_dynamics: string describing partner/spouse ownership dynamics OR "unknown"
- employee_count_signal: integer estimate OR null
- industry_vertical_signal: one of "saas","healthcare","realestate","crypto","professional_services","manufacturing","retail","agency","ecommerce","other","unknown"
- real_estate_signal: "yes","no","unknown"
- earnout_signal: "yes","no","unknown"
- noncompete_signal: "yes","no","unknown"

OUTPUT FORMAT (JSON only, no prose):
{
  "extracted_fields": { <field: value> },
  "follow_up_questions": ["...", "..."],
  "confidence": 0.0-1.0
}

RULES:
1. Return ONLY the JSON object above, no markdown fences, no commentary.
2. confidence = fraction of fields you could determine (vs "unknown").
3. follow_up_questions: 2-4 questions that would help fill remaining unknowns.
4. If text is completely off-topic, return confidence=0, all fields="unknown".
PROMPT;
    }

    private function callWithFallback(array $messages): array
    {
        $lastErr = '';
        foreach (self::MODELS as $model) {
            [$ok, $text, $err] = $this->callOllama($model, $messages);
            if ($ok && $text !== '') {
                return [true, $text, $model, ''];
            }
            $lastErr = "{$model}: {$err}";
            error_log("[NarrativeParser] {$lastErr}");
        }
        return [false, '', '', $lastErr];
    }

    private function callOllama(string $model, array $messages): array
    {
        $payload = json_encode([
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
            'options'  => ['num_predict' => 800, 'temperature' => 0.1],
        ]);
        if ($payload === false) return [false, '', 'json_encode_failed'];

        $ch = curl_init(self::OLLAMA_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_S,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        if ($err)          return [false, '', "curl_err={$err}"];
        if ($code !== 200) return [false, '', "http={$code}"];
        if (!$resp)        return [false, '', 'empty_response'];

        $data = json_decode($resp, true);
        $text = $data['message']['content'] ?? '';
        if (!is_string($text) || $text === '') return [false, '', 'no_content'];

        return [true, trim($text), ''];
    }

    /** Try to decode LLM output as JSON, stripping markdown fences if present. */
    private function parseJson(string $raw): ?array
    {
        $raw = trim($raw);
        // Strip ```json ... ``` fences
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```[a-z]*\n?/i', '', $raw);
            $raw = preg_replace('/```\s*$/', '', $raw);
            $raw = trim($raw);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) return null;
        return $data;
    }

    private function emptyExtraction(string $reason): array
    {
        return [
            'extracted_fields'    => $this->blankFields(),
            'follow_up_questions' => [],
            'confidence'          => 0.0,
            'model_used'          => '',
            'parse_error'         => $reason,
        ];
    }

    private function blankFields(): array
    {
        return array_fill_keys(self::EXTRACT_FIELDS, 'unknown');
    }

    private function persistTurn(string $userText, string $response, string $model, ?int $intakeId): void
    {
        try {
            $ctx = $intakeId ? json_encode([$intakeId]) : null;
            $stmt = $this->db->prepare(
                'INSERT INTO empire_chat_log
                 (tenant_id, user_prompt, assistant_response, model_used, context_entity_ids, reason)
                 VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([
                $this->tenantId,
                $userText,
                $response,
                $model,
                $ctx,
                'intake_narrative_parse',
            ]);
        } catch (\Throwable $e) {
            error_log('[NarrativeParser] persistTurn failed: ' . $e->getMessage());
        }
    }

    // ─── Signal → enum mappers ────────────────────────────────────────────

    private function mapSaleHorizon(string $v): ?string
    {
        $v = strtolower(trim($v));
        $map = [
            'never'       => 'never',
            'retain'      => 'never',
            '5y_plus'     => '5y_plus',
            '5y+'         => '5y_plus',
            'long'        => '5y_plus',
            '3y'          => '3y',
            'medium'      => '3y',
            '1y'          => '1y',
            'short'       => '1y',
            'active_sale' => 'active_sale',
            'now'         => 'active_sale',
        ];
        return $map[$v] ?? null;
    }

    private function mapLiabilityProfile(string $v): ?string
    {
        $v = strtolower(trim($v));
        if ($v === 'low')    return 'low';
        if ($v === 'medium') return 'medium';
        if ($v === 'high')   return 'high';
        return null;
    }

    private function mapVertical(string $v): ?string
    {
        $allowed = ['saas', 'healthcare', 'realestate', 'crypto', 'professional_services',
                    'manufacturing', 'retail', 'agency', 'ecommerce', 'other'];
        $v = strtolower(trim($v));
        return in_array($v, $allowed, true) ? $v : 'other';
    }
}
