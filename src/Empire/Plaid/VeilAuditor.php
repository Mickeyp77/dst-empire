<?php
/**
 * src/Empire/Plaid/VeilAuditor.php
 *
 * Corporate-veil audit engine.
 *
 * classifyPending()         — LLM-classifies unclassified transactions
 * computeVeilStrengthScore() — 0-100 score per entity (last 90d flags)
 * weeklyDigest()             — weekly summary array for email/TG digest
 *
 * LLM: local Ollama hermes3-mythos:70b → hermes3:8b → qwen2.5:7b (Advisor pattern)
 * System prompt: conservative — flag if uncertain
 *
 * Namespace: Mnmsos\Empire\Plaid
 */

namespace Mnmsos\Empire\Plaid;

use PDO;

class VeilAuditor
{
    private PDO $db;
    private int $tenantId;

    // Ollama config (mirrors Advisor.php)
    private const OLLAMA_URL = 'http://localhost:11434/api/chat';
    private const OLLAMA_TIMEOUT = 180;
    private const MODELS = [
        'hermes3-mythos:70b',
        'hermes3:8b',
        'qwen2.5:7b',
    ];

    // Veil scoring constants
    private const SCORE_START         = 100;
    private const DEDUCT_HARD         = 5;
    private const DEDUCT_SOFT         = 1;
    private const DEDUCT_STALE_HARD   = 10;  // unresolved hard flag > 30d
    private const STALE_DAYS          = 30;
    private const LOOKBACK_DAYS       = 90;

    // Hard-flag thresholds
    private const HARD_PERSONAL_MIN_USD = 500.00;

    // Valid classification labels
    private const VALID_CLASSIFICATIONS = [
        'business',
        'personal_flagged',
        'intercompany',
        'distribution',
        'loan',
        'reimbursable',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // CONSTRUCTOR
    // ─────────────────────────────────────────────────────────────────────

    public function __construct(PDO $db, int $tenantId)
    {
        $this->db       = $db;
        $this->tenantId = $tenantId;
    }

    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Classify a batch of pending (classification='unknown') transactions via LLM.
     *
     * For each transaction:
     *   1. Build a short prompt with merchant, amount, category, date
     *   2. LLM returns a classification label
     *   3. Rules engine sets flag_severity
     *   4. DB updated
     *
     * @param int $batchSize  Max transactions to process in one run
     * @return int            Count of transactions classified
     */
    public function classifyPending(int $batchSize = 100): int
    {
        $txns = $this->fetchPendingTransactions($batchSize);
        if (empty($txns)) {
            return 0;
        }

        $classified = 0;

        foreach ($txns as $txn) {
            $result = $this->classifyOne($txn);
            if ($result === null) {
                continue;
            }

            [$classification, $flagSeverity, $flagReason] = $result;

            $stmt = $this->db->prepare(
                "UPDATE plaid_transactions
                 SET classification = ?,
                     flag_severity  = ?,
                     flag_reason    = ?
                 WHERE id = ? AND tenant_id = ?"
            );
            $stmt->execute([
                $classification,
                $flagSeverity,
                $flagReason,
                (int)$txn['id'],
                $this->tenantId,
            ]);

            $classified++;
        }

        return $classified;
    }

    /**
     * Compute Veil Strength Score (0-100) for an entity.
     *
     * Scoring (last 90 days):
     *   Start: 100
     *   - 5 per hard flag
     *   - 1 per soft flag
     *   - 10 if any unresolved hard flag > 30d old
     *   Floor: 0
     *
     * Fresh entity with 0 history returns 100 (clean slate).
     *
     * @param int $intakeId
     * @return int  0-100
     */
    public function computeVeilStrengthScore(int $intakeId): int
    {
        $since = date('Y-m-d', strtotime('-' . self::LOOKBACK_DAYS . ' days'));

        // Count hard flags (last 90d)
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM plaid_transactions
             WHERE tenant_id = ? AND intake_id = ?
               AND flag_severity = 'hard'
               AND txn_date >= ?"
        );
        $stmt->execute([$this->tenantId, $intakeId, $since]);
        $hardCount = (int)$stmt->fetchColumn();

        // Count soft flags (last 90d)
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM plaid_transactions
             WHERE tenant_id = ? AND intake_id = ?
               AND flag_severity = 'soft'
               AND txn_date >= ?"
        );
        $stmt->execute([$this->tenantId, $intakeId, $since]);
        $softCount = (int)$stmt->fetchColumn();

        // Check for stale unresolved hard flags (> 30d old, not resolved)
        $staleThreshold = date('Y-m-d', strtotime('-' . self::STALE_DAYS . ' days'));
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM plaid_transactions
             WHERE tenant_id = ? AND intake_id = ?
               AND flag_severity = 'hard'
               AND resolved_at IS NULL
               AND txn_date <= ?"
        );
        $stmt->execute([$this->tenantId, $intakeId, $staleThreshold]);
        $staleHardCount = (int)$stmt->fetchColumn();

        // Calculate score
        $score = self::SCORE_START;
        $score -= ($hardCount * self::DEDUCT_HARD);
        $score -= ($softCount * self::DEDUCT_SOFT);
        if ($staleHardCount > 0) {
            $score -= self::DEDUCT_STALE_HARD;
        }

        return max(0, $score);
    }

    /**
     * Weekly digest for a single entity.
     *
     * @param int $intakeId
     * @return array{
     *   intake_id: int,
     *   veil_score: int,
     *   week_hard: int,
     *   week_soft: int,
     *   week_txn_count: int,
     *   week_flagged_usd: float,
     *   top_flags: array,
     *   period: string
     * }
     */
    public function weeklyDigest(int $intakeId): array
    {
        $weekAgo = date('Y-m-d', strtotime('-7 days'));

        // Counts for the week
        $stmt = $this->db->prepare(
            "SELECT
               COUNT(*) AS total,
               SUM(flag_severity = 'hard') AS hard_count,
               SUM(flag_severity = 'soft') AS soft_count,
               SUM(CASE WHEN flag_severity IN ('hard','soft') THEN ABS(amount_usd) ELSE 0 END) AS flagged_usd
             FROM plaid_transactions
             WHERE tenant_id = ? AND intake_id = ? AND txn_date >= ?"
        );
        $stmt->execute([$this->tenantId, $intakeId, $weekAgo]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC);

        // Top 5 flagged transactions this week
        $stmt = $this->db->prepare(
            "SELECT txn_date, merchant_name, amount_usd, classification, flag_reason, flag_severity
             FROM plaid_transactions
             WHERE tenant_id = ? AND intake_id = ?
               AND flag_severity IN ('hard','soft')
               AND txn_date >= ?
             ORDER BY flag_severity DESC, ABS(amount_usd) DESC
             LIMIT 5"
        );
        $stmt->execute([$this->tenantId, $intakeId, $weekAgo]);
        $topFlags = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'intake_id'       => $intakeId,
            'veil_score'      => $this->computeVeilStrengthScore($intakeId),
            'week_hard'       => (int)($counts['hard_count'] ?? 0),
            'week_soft'       => (int)($counts['soft_count'] ?? 0),
            'week_txn_count'  => (int)($counts['total'] ?? 0),
            'week_flagged_usd' => round((float)($counts['flagged_usd'] ?? 0), 2),
            'top_flags'       => $topFlags,
            'period'          => $weekAgo . ' to ' . date('Y-m-d'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE — Classification
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Classify a single transaction using LLM + rules engine.
     *
     * @param array $txn  plaid_transactions row
     * @return array|null [classification, flag_severity, flag_reason] or null on LLM failure
     */
    private function classifyOne(array $txn): ?array
    {
        $merchant = $txn['merchant_name'] ?? 'Unknown';
        $amount   = (float)($txn['amount_usd'] ?? 0);
        $category = $txn['category'] ?? '';
        $date     = $txn['txn_date'] ?? '';

        $userMsg = "Transaction: merchant=\"{$merchant}\", amount=\${$amount}, category=\"{$category}\", date={$date}. "
                 . "Classify this transaction. Respond with ONLY one of: "
                 . implode(', ', self::VALID_CLASSIFICATIONS) . ". "
                 . "If unsure whether it is a business expense, use personal_flagged.";

        $llmResult = $this->callOllamaWithFallback([
            ['role' => 'system', 'content' => $this->buildSystemPrompt()],
            ['role' => 'user',   'content' => $userMsg],
        ]);

        if (!$llmResult['ok']) {
            error_log('[VeilAuditor] LLM unavailable for txn ' . ($txn['id'] ?? '?') . ': ' . $llmResult['error']);
            return null;
        }

        $rawText       = strtolower(trim($llmResult['text']));
        $classification = $this->parseClassification($rawText);
        [$severity, $reason] = $this->applyRules($classification, $amount, $merchant, $category);

        return [$classification, $severity, $reason];
    }

    /**
     * Parse LLM response to a valid classification label.
     * Falls back to 'personal_flagged' (conservative) if LLM returns garbage.
     */
    private function parseClassification(string $rawText): string
    {
        foreach (self::VALID_CLASSIFICATIONS as $label) {
            if (str_contains($rawText, $label)) {
                return $label;
            }
        }
        // Conservative fallback — flag if uncertain
        error_log('[VeilAuditor] Unrecognized classification from LLM: ' . substr($rawText, 0, 80));
        return 'personal_flagged';
    }

    /**
     * Rules engine: map classification + amount → flag_severity + flag_reason.
     *
     * Rules:
     *   hard: personal_flagged + amount > $500
     *   hard: intercompany (no intercompany agreement on file — assume none until docs confirmed)
     *   soft: personal_flagged + amount <= $500
     *   soft: reimbursable (needs documentation)
     *   none: business, distribution (properly categorized), loan (needs docs but not immediate hard)
     *
     * @return array{0: string, 1: string}  [severity, reason]
     */
    private function applyRules(string $classification, float $amount, string $merchant, string $category): array
    {
        switch ($classification) {
            case 'personal_flagged':
                if (abs($amount) > self::HARD_PERSONAL_MIN_USD) {
                    return [
                        'hard',
                        "Personal expense >$500 paid by business account: {$merchant} (\${$amount})",
                    ];
                }
                return [
                    'soft',
                    "Potential personal expense paid by business account: {$merchant} (\${$amount})",
                ];

            case 'intercompany':
                // Any undocumented intercompany transfer = hard flag
                return [
                    'hard',
                    "Intercompany transfer without confirmed agreement: {$merchant} (\${$amount})",
                ];

            case 'reimbursable':
                return [
                    'soft',
                    "Reimbursable expense requires documentation: {$merchant} (\${$amount})",
                ];

            case 'distribution':
                // Distributions are OK but should be documented
                return [
                    'none',
                    "Owner distribution — ensure proper documentation",
                ];

            case 'loan':
                return [
                    'soft',
                    "Loan transaction — requires promissory note and arm's-length terms",
                ];

            case 'business':
            default:
                return ['none', ''];
        }
    }

    /**
     * Build the veil-audit system prompt for the LLM.
     */
    private function buildSystemPrompt(): string
    {
        return <<<PROMPT
You analyze business bank transactions for corporate-veil compliance.
Flag personal expenses paid by business accounts, undocumented intercompany transfers, and owner-direct payments.
Be CONSERVATIVE — flag if uncertain. It is better to over-flag than to miss a piercing risk.

Classifications:
- business: clearly a legitimate business expense (vendor, supplier, software, office, utilities, payroll)
- personal_flagged: personal expense charged to business account (restaurants, retail, personal subscriptions, travel w/o clear business purpose)
- intercompany: transfer between two related entities (flag — requires documented intercompany agreement)
- distribution: owner withdrawal / dividend distribution (OK if properly documented)
- loan: loan to owner or related party (requires promissory note)
- reimbursable: employee expense reimbursement (requires receipts + expense report)

Key risk signals:
- Grocery stores, gas stations (personal use), personal retail, pharmacies → personal_flagged
- Transfers to owner-named accounts → distribution or intercompany
- Transfers to other business entities owned by same person → intercompany
- Cash withdrawals > $200 without clear purpose → personal_flagged
- Restaurants without meeting description → personal_flagged if > $100

Respond with ONLY the classification label. No explanation. No punctuation.
PROMPT;
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE — DB fetch
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Fetch pending (unclassified) transactions for this tenant.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchPendingTransactions(int $limit): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, intake_id, txn_date, amount_usd, merchant_name, category
             FROM plaid_transactions
             WHERE tenant_id = ? AND classification = 'unknown'
             ORDER BY txn_date DESC
             LIMIT ?"
        );
        $stmt->execute([$this->tenantId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE — Ollama (mirrors Advisor.php pattern exactly)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Try each model in MODELS until one returns a valid response.
     *
     * @param array $messages  [['role'=>..., 'content'=>...], ...]
     * @return array{ok: bool, text: string, model: string, error: string}
     */
    private function callOllamaWithFallback(array $messages): array
    {
        foreach (self::MODELS as $model) {
            $result = $this->callOllama($model, $messages);
            if ($result['ok']) {
                return $result;
            }
        }
        return ['ok' => false, 'text' => '', 'model' => '', 'error' => 'All models failed'];
    }

    /**
     * Single Ollama call. Returns [ok, text, model, error].
     */
    private function callOllama(string $model, array $messages): array
    {
        $body = json_encode([
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
        ]);

        $ch = curl_init(self::OLLAMA_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::OLLAMA_TIMEOUT,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);

        $raw      = curl_exec($ch);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['ok' => false, 'text' => '', 'model' => $model, 'error' => $curlErr];
        }

        $decoded = json_decode($raw, true);
        $text    = $decoded['message']['content'] ?? '';

        if (!$text) {
            return ['ok' => false, 'text' => '', 'model' => $model, 'error' => 'Empty response'];
        }

        return ['ok' => true, 'text' => $text, 'model' => $model, 'error' => ''];
    }
}
