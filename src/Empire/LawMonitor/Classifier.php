<?php
/**
 * src/Empire/LawMonitor/Classifier.php
 *
 * LLM-based classifier for law-change items.
 *
 * Calls local Ollama (hermes3-mythos:70b → hermes3:8b → qwen2.5:7b fallback)
 * to determine whether a detected law/ruling affects any DST Empire structure.
 *
 * Output schema (JSON returned in classification_json column):
 * {
 *   "affects_dst_empire":    bool,
 *   "affected_playbooks":    string[],   // e.g. ["qsbs_1202","ip_co_separation"]
 *   "affected_jurisdictions": string[],  // 2-char codes, empty = federal/all
 *   "affected_entity_types": string[],   // ["c_corp","llc","trust","s_corp",...]
 *   "severity":              "low|medium|high|critical",
 *   "action_required":       "amend_doc|file_new|monitor|none",
 *   "summary_md":            string,     // 1-para plain-English impact
 *   "confidence":            float,      // 0.0–1.0
 * }
 *
 * Fallback: if Ollama unreachable or JSON malformed, returns a
 * "needs_human_review" classification with affects_dst_empire=true
 * (conservative — flag for human; never silently drop).
 *
 * Matches Advisor::callOllama() pattern exactly (OLLAMA_URL, TIMEOUT_S,
 * MODELS chain, curl options).
 *
 * Namespace: Mnmsos\Empire\LawMonitor
 */

namespace Mnmsos\Empire\LawMonitor;

class Classifier
{
    private const OLLAMA_URL = 'http://localhost:11434/api/chat';

    private const MODELS = [
        'hermes3-mythos:70b',
        'hermes3:8b',
        'qwen2.5:7b',
    ];

    // 180s — matches CARL global decision httpTimeoutMs=180000
    private const TIMEOUT_S = 180;

    // Max tokens in LLM response — classification JSON is compact
    private const MAX_TOKENS = 512;

    // Path for audit log of raw LLM responses (structured JSON, one object per line)
    private const AUDIT_LOG = '/Users/mickeyp/Projects/mnmsos-saas/storage/logs/law_monitor_llm_audit.jsonl';

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Classify a single law-change item.
     *
     * @param array<string,mixed> $item  Item from SourcePoller (title, summary, url, raw_content_md, source, jurisdiction)
     * @return array<string,mixed>       Classification dict matching schema above
     */
    public function classify(array $item): array
    {
        $messages = $this->buildMessages($item);

        [$ok, $text, $modelUsed, $error] = $this->callOllamaWithFallback($messages);

        // Audit log — always write regardless of parse success
        $this->writeAuditLog($item, $ok, $text, $modelUsed, $error);

        if (!$ok || empty($text)) {
            return $this->fallbackClassification('llm_unreachable: ' . $error);
        }

        $classification = $this->parseJsonFromResponse($text);

        if ($classification === null) {
            return $this->fallbackClassification('json_parse_failed');
        }

        return $this->normaliseClassification($classification);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — Prompt construction
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<int,array{role:string,content:string}> */
    private function buildMessages(array $item): array
    {
        $systemPrompt = <<<'SYSPROMPT'
You are a tax-law and corporate-structure compliance analyst for DST Empire,
a multi-state business structure advisory platform. Your clients hold entities
spanning these structures and playbooks:

STRUCTURES:
- Wyoming LLC (charging-order protection, pass-through, no state income tax)
- Delaware C-Corp (QSBS §1202 eligibility, VC-ready capital structure)
- Nevada LLC/Corp (privacy, no state income tax)
- South Dakota trust (DAPT — Domestic Asset Protection Trust, perpetual dynasty)
- Texas LLC (homestead + business combo, franchise tax applies)
- Series LLC (parent + protected cells, DE/WY/TX variants)
- S-Corp election (QBI §199A, salary/distribution split)
- Management company (management fee transfer pricing §482)
- IP holding company (royalty streams, §1235 capital gain treatment)

PLAYBOOKS (key identifiers):
qsbs_1202, ip_co_separation, charging_order_protection, dapt_dynasty_trust,
management_fee_transfer_pricing, qbi_199a, rd_credit_41, cost_segregation,
solo_401k_max, flp_valuation_discount, captive_insurance_831b, s_corp_election

TASK:
Given a law change, ruling, regulation, or news item, output ONLY a JSON object
(no markdown fences, no commentary) with this exact structure:

{
  "affects_dst_empire": <true|false>,
  "affected_playbooks": [<string>, ...],
  "affected_jurisdictions": [<2-char state code>, ...],
  "affected_entity_types": ["c_corp"|"llc"|"trust"|"s_corp"|"lp"|"series_llc"],
  "severity": "low"|"medium"|"high"|"critical",
  "action_required": "amend_doc"|"file_new"|"monitor"|"none",
  "summary_md": "<1 paragraph plain English: what changed, who is affected, what to do>",
  "confidence": <0.0 to 1.0>
}

severity rules:
- critical: immediate legal exposure or filing deadline within 30 days
- high: requires attorney review within 90 days
- medium: monitor; may require amendment within 1 year
- low: informational; no immediate action

action_required rules:
- amend_doc: existing operating agreements / trust docs need amendment
- file_new: new government filing required (e.g. BOI update, new election form)
- monitor: flag for future review, no current action
- none: does not affect DST Empire structures

If the item is clearly unrelated to business entities, trusts, tax law, or
corporate structure, return affects_dst_empire=false, severity="low", action_required="none".
SYSPROMPT;

        $userContent = "Classify this law change:\n\n"
            . "SOURCE: " . ($item['source'] ?? 'unknown') . "\n"
            . "JURISDICTION: " . ($item['jurisdiction'] ?? 'federal') . "\n"
            . "TITLE: " . ($item['title'] ?? '') . "\n"
            . "SUMMARY: " . ($item['summary'] ?? '') . "\n"
            . "URL: " . ($item['url'] ?? '') . "\n\n"
            . "Output ONLY the JSON object, no markdown.";

        return [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userContent],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — Ollama call (mirrors Advisor::callOllama pattern exactly)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Try each model until one succeeds.
     * Returns [ok, text, model_used, error_string].
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @return array{bool, string, string, string}
     */
    private function callOllamaWithFallback(array $messages): array
    {
        $lastError = 'no_models_tried';

        foreach (self::MODELS as $model) {
            [$ok, $text, $err] = $this->callOllama($model, $messages);
            if ($ok) {
                return [true, $text, $model, ''];
            }
            $lastError = $err;
            fwrite(STDERR, '[Classifier] model=' . $model . ' failed: ' . $err . "\n");
        }

        return [false, '', '', $lastError];
    }

    /**
     * Single Ollama call. Returns [ok, text, error].
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @return array{bool, string, string}
     */
    private function callOllama(string $model, array $messages): array
    {
        $payload = [
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
            'options'  => [
                'num_predict'   => self::MAX_TOKENS,
                'temperature'   => 0.1, // Low temp — we want deterministic JSON
            ],
        ];

        $body = json_encode($payload);
        if ($body === false) {
            return [false, '', 'json_encode_failed'];
        }

        $ch = curl_init(self::OLLAMA_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_S,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $body,
        ]);

        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        if ($err)          return [false, '', "curl_err={$err}"];
        if ($code !== 200) return [false, '', "http={$code}"];
        if (!$resp)        return [false, '', 'empty_response'];

        $data = json_decode($resp, true);
        $text = $data['message']['content'] ?? '';

        if (!is_string($text) || $text === '') {
            return [false, '', 'no_content'];
        }

        return [true, $text, ''];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — JSON extraction + normalisation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Extract JSON from LLM response text.
     * Handles cases where the model wraps output in ```json ... ``` fences.
     *
     * @return ?array<string,mixed>
     */
    private function parseJsonFromResponse(string $text): ?array
    {
        $text = trim($text);

        // Strip markdown code fences if present
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```\s*$/i', '', $text) ?? $text;
        $text = trim($text);

        // Find the JSON object boundaries
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $jsonStr = substr($text, $start, $end - $start + 1);
        $decoded = json_decode($jsonStr, true);

        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Ensure all expected keys exist with correct types.
     * Coerces/defaults any missing or invalid fields.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function normaliseClassification(array $raw): array
    {
        $validSeverities = ['low', 'medium', 'high', 'critical'];
        $validActions    = ['amend_doc', 'file_new', 'monitor', 'none'];

        $severity = in_array($raw['severity'] ?? '', $validSeverities, true)
            ? $raw['severity']
            : 'medium';

        $action = in_array($raw['action_required'] ?? '', $validActions, true)
            ? $raw['action_required']
            : 'monitor';

        $confidence = is_numeric($raw['confidence'] ?? null)
            ? max(0.0, min(1.0, (float)$raw['confidence']))
            : 0.5;

        return [
            'affects_dst_empire'      => (bool)($raw['affects_dst_empire'] ?? false),
            'affected_playbooks'      => array_values(array_filter((array)($raw['affected_playbooks'] ?? []), 'is_string')),
            'affected_jurisdictions'  => array_values(array_filter((array)($raw['affected_jurisdictions'] ?? []), 'is_string')),
            'affected_entity_types'   => array_values(array_filter((array)($raw['affected_entity_types'] ?? []), 'is_string')),
            'severity'                => $severity,
            'action_required'         => $action,
            'summary_md'              => (string)($raw['summary_md'] ?? ''),
            'confidence'              => $confidence,
        ];
    }

    /**
     * Conservative fallback when LLM is unavailable or returns garbage.
     * Sets affects_dst_empire=true so a human always reviews.
     *
     * @return array<string,mixed>
     */
    private function fallbackClassification(string $reason): array
    {
        return [
            'affects_dst_empire'      => true,    // Conservative: flag for human
            'affected_playbooks'      => [],
            'affected_jurisdictions'  => [],
            'affected_entity_types'   => [],
            'severity'                => 'medium',
            'action_required'         => 'monitor',
            'summary_md'              => "Automatic classification unavailable ({$reason}). Manual review required.",
            'confidence'              => 0.0,
            '_fallback'               => true,
            '_fallback_reason'        => $reason,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — Audit log
    // ─────────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $item */
    private function writeAuditLog(array $item, bool $ok, string $rawText, string $model, string $error): void
    {
        $entry = [
            'ts'        => date('Y-m-d H:i:s'),
            'source'    => $item['source'] ?? '',
            'url'       => $item['url'] ?? '',
            'model'     => $model,
            'ok'        => $ok,
            'error'     => $error ?: null,
            'raw_text'  => mb_strimwidth($rawText, 0, 2000, '…'),
        ];

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        $fp = @fopen(self::AUDIT_LOG, 'a');
        if ($fp) {
            fwrite($fp, $line);
            fclose($fp);
        }
    }
}
