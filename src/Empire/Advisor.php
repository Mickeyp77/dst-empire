<?php
/**
 * DST Empire — Portfolio Analysis Engine for Entity Formation
 * Copyright (c) 2026 MNMS LLC
 * Licensed under the MIT License — see LICENSE in the project root.
 */

/**
 * Advisor — DST Empire chat bridge to local Ollama (hermes3-mythos:70b).
 *
 * - Synchronous POST to http://localhost:11434/api/chat
 * - Tries hermes3-mythos:70b first, then hermes3:8b, then qwen2.5:7b (CARL global decision).
 * - 180s timeout (CARL httpTimeoutMs=180000).
 * - Persists each turn to empire_chat_log (migration 074).
 * - Optional contextEntityIds: prepends BrandPlacement::analyze() results
 *   to ground the model in DST's deterministic engine output.
 * - NEVER throws on API failure — returns "[advisor unavailable: <reason>]".
 */

namespace Mnmsos\Empire;

use PDO;
use RuntimeException;

class Advisor
{
    /** Ollama API base. Overridable via constant for tests. */
    private const OLLAMA_URL = 'http://localhost:11434/api/chat';

    /** Model fallback chain — primary first. */
    private const MODELS = [
        'hermes3-mythos:70b',
        'hermes3:8b',
        'qwen2.5:7b',
    ];

    /** CARL global decision httpTimeoutMs=180000 → 180s. */
    private const TIMEOUT_S = 180;

    private PDO $db;
    private int $tenantId;

    public function __construct(PDO $db, int $tenantId)
    {
        $this->db       = $db;
        $this->tenantId = $tenantId;
    }

    /**
     * Send a chat request grounded in the DST briefing context.
     *
     * @param string     $userPrompt        the user's message
     * @param int[]|null $contextEntityIds  optional empire_brand_intake.id list to inject as context
     * @return string                       advisor response text (never throws)
     */
    public function chat(string $userPrompt, ?array $contextEntityIds = null): string
    {
        $userPrompt = trim($userPrompt);
        if ($userPrompt === '') {
            return '[advisor unavailable: empty prompt]';
        }

        $systemPrompt = $this->buildSystemPrompt();
        $contextBlock = $this->buildContextBlock($contextEntityIds);

        $messages = [];
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        if ($contextBlock !== '') {
            $messages[] = ['role' => 'system', 'content' => $contextBlock];
        }
        $messages[] = ['role' => 'user', 'content' => $userPrompt];

        [$response, $modelUsed, $reason] = $this->callOllamaWithFallback($messages);

        // Persist regardless of success/failure — log captures the attempt.
        $this->persistTurn($userPrompt, $response, $modelUsed, $contextEntityIds, $reason);

        return $response;
    }

    /** System prompt — grounds DST in the briefing rails + hard NO list. */
    private function buildSystemPrompt(): string
    {
        return <<<PROMPT
You are DST, the entity-strategy advisor for MNMS House of Brands.

You know the following from the morning briefing (docs/architecture/dstempire_morning_briefing_2026-04-28.md):
- §1: 24-brand engine output. MNMS LLC is the parent S-Corp (already elected 10+ years — never recommend Form 2553).
- §2: Engine overrides — VoltOps → DE C-Corp (NOT TX), PrintIt → TX Series LLC parent, OfficeSolutions AI → sep LLC w/ E&O, canonservice/canonparts trademark cleanup before filing, dfwprinter migration deferred to Q3.
- §4: Trust thresholds — WY DAPT \$300k, NV/SD DAPT \$500k, SD Dynasty \$1M, Bridge Trust \$2M. NO trust wrappers needed in 2026.
- §5: Sabrina blockers — Q1 valuation continuity, Q2 QBO file vs class-tracking, Q3 banking, Q4 sale horizons, Q5 insurance.

HARD NO LIST (refuse and explain alternative):
- No unauthorized practice of law (UPL). You are not an attorney; recommend attorney consultation for OAs, IP assignments, 83(b) elections.
- No spousal-asset hiding. Sabrina's MNMS LLC majority stake is locked in.
- No fraudulent-transfer suggestions. Trust planning happens BEFORE creditor threats, not after.
- No tax evasion. Tax avoidance (legal optimization) only — clearly distinguish vs evasion.

STYLE:
- Caveman comms — drop articles/hedging/pleasantries.
- Concrete recommendations with cost numbers from the briefing.
- Reference the engine output and overrides explicitly.
- When asked about a brand, give: jurisdiction, entity_type, parent_kind, year-1 cost, annual cost, blast radius.
PROMPT;
    }

    /**
     * If contextEntityIds provided, fetch each intake row + run BrandPlacement::analyze
     * and return a combined markdown context block. Errors fail open (return what we have).
     */
    private function buildContextBlock(?array $contextEntityIds): string
    {
        if (empty($contextEntityIds)) return '';

        // Sanitize to ints, dedupe.
        $ids = array_values(array_unique(array_map('intval', $contextEntityIds)));
        $ids = array_filter($ids, fn($x) => $x > 0);
        if (empty($ids)) return '';

        $blocks = [];
        foreach ($ids as $id) {
            try {
                if (!class_exists('BrandPlacement')) {
                    // Fall back to raw intake row if engine class missing.
                    $intake = $this->fetchIntake($id);
                    if ($intake) {
                        $blocks[] = $this->formatIntakeRow($intake);
                    }
                    continue;
                }
                $rec = \BrandPlacement::analyze($this->tenantId, $id);
                $blocks[] = $this->formatRec($rec);
            } catch (\Throwable $e) {
                $blocks[] = "BRAND #{$id}: [analysis error: " . $e->getMessage() . "]";
            }
        }

        if (empty($blocks)) return '';
        return "ENGINE CONTEXT (BrandPlacement::analyze output):\n\n" . implode("\n\n---\n\n", $blocks);
    }

    /** Format a BrandPlacement::analyze() result for inclusion in the prompt. */
    private function formatRec(array $rec): string
    {
        $brand = $rec['brand'] ?? [];
        $name  = $brand['name'] ?? '?';
        $tier  = $brand['tier'] ?? '?';
        $r     = $rec['recommendation'] ?? [];
        $cost  = $rec['cost_breakdown'] ?? [];

        $lines = [];
        $lines[] = "BRAND: {$name} (tier {$tier})";
        $lines[] = "engine.jurisdiction: " . ($r['jurisdiction'] ?? '?');
        $lines[] = "engine.entity_type:  " . ($r['entity_type']  ?? '?');
        $lines[] = "engine.parent_kind:  " . ($r['parent_kind']  ?? '?');
        $lines[] = "engine.trust:        " . ($r['trust_wrapper'] ?? 'none');
        $lines[] = "engine.sale_horizon: " . ($r['sale_horizon'] ?? 'not_set');
        $lines[] = "year_1_cost: \$" . number_format((float)($cost['first_year'] ?? 0), 0);
        $lines[] = "annual_cost: \$" . number_format((float)($cost['annual_ongoing'] ?? 0), 0);
        if (!empty($rec['blast_radius_notes'])) {
            $lines[] = "blast_radius: " . $rec['blast_radius_notes'];
        }
        return implode("\n", $lines);
    }

    /** Format raw intake row when engine isn't available. */
    private function formatIntakeRow(array $r): string
    {
        return "BRAND: {$r['brand_name']} (tier {$r['tier']}, slug={$r['brand_slug']}) — "
            . "current_status={$r['current_status']}, liability={$r['liability_profile']}, "
            . "decided_jurisdiction=" . ($r['decided_jurisdiction'] ?? 'null') . ", "
            . "decided_entity_type=" . ($r['decided_entity_type'] ?? 'null');
    }

    /** Direct fetch of an intake row, tenant-isolated. */
    private function fetchIntake(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM empire_brand_intake WHERE id=? AND tenant_id=? LIMIT 1"
        );
        $stmt->execute([$id, $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Try each model in MODELS until one returns a valid response.
     * Returns [responseText, modelUsed, reasonOnFailure].
     */
    private function callOllamaWithFallback(array $messages): array
    {
        $lastErr = '';
        foreach (self::MODELS as $model) {
            [$ok, $text, $err] = $this->callOllama($model, $messages);
            if ($ok && $text !== '') {
                return [$text, $model, ''];
            }
            $lastErr = "{$model}: {$err}";
            error_log("[Advisor] {$lastErr}");
        }
        return ["[advisor unavailable: {$lastErr}]", '', $lastErr];
    }

    /**
     * Single Ollama call. Returns [ok, text, error].
     */
    private function callOllama(string $model, array $messages): array
    {
        $payload = [
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
            'options'  => ['num_predict' => 1024],
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
        // curl_close() is a no-op in PHP 8.0+ and deprecated in 8.5 — handle is freed at scope end.

        if ($err)        return [false, '', "curl_err={$err}"];
        if ($code !== 200) return [false, '', "http={$code}"];
        if (!$resp)        return [false, '', 'empty_response'];

        $data = json_decode($resp, true);
        $text = $data['message']['content'] ?? '';
        if (!is_string($text) || $text === '') {
            return [false, '', 'no_content'];
        }
        return [true, $text, ''];
    }

    /**
     * Persist a single turn (user + assistant) into empire_chat_log.
     * Quietly swallows DB errors — logging shouldn't break the chat path.
     */
    private function persistTurn(
        string $userPrompt,
        string $assistantResponse,
        string $modelUsed,
        ?array $contextEntityIds,
        string $reason
    ): void {
        try {
            $ctx = !empty($contextEntityIds) ? json_encode(array_values($contextEntityIds)) : null;
            $stmt = $this->db->prepare(
                "INSERT INTO empire_chat_log
                 (tenant_id, user_prompt, assistant_response, model_used, context_entity_ids, reason)
                 VALUES (?,?,?,?,?,?)"
            );
            $stmt->execute([
                $this->tenantId,
                $userPrompt,
                $assistantResponse,
                $modelUsed,
                $ctx,
                $reason ?: null,
            ]);
        } catch (\Throwable $e) {
            error_log('[Advisor] persistTurn failed: ' . $e->getMessage());
        }
    }

    /**
     * Fetch the last N chat turns for this tenant — used by advisor.php to
     * render the scrollback. Newest at the bottom.
     */
    public function recentTurns(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->prepare(
            "SELECT id, ts, user_prompt, assistant_response, model_used, context_entity_ids
             FROM empire_chat_log
             WHERE tenant_id=?
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$this->tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_reverse($rows);
    }
}
