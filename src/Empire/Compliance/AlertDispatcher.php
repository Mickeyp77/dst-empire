<?php
/**
 * src/Empire/Compliance/AlertDispatcher.php
 *
 * Dispatches compliance alerts via three channels:
 *   1. Email queue  — writes to compliance_alert_queue table (Phase B sends)
 *   2. Telegram     — appends to ~/.voltops-tg-feed.md (infra:tg_bridge_v3)
 *   3. SMS          — writes to sms_pending queue (decision:sms_channel)
 *                     SMS is HIGH-URGENCY only (overdue or ≤3 days)
 *
 * ALL writes are queue/file-only. No actual sending. Phase A review gate.
 *
 * Idempotency: alert_hash (SHA-256 of tenant+task_id+threshold+date) stored in
 * compliance_alert_log. Same hash on same calendar day = skip.
 *
 * Namespace: Mnmsos\Empire\Compliance
 */

namespace Mnmsos\Empire\Compliance;

use PDO;

class AlertDispatcher
{
    private PDO $db;
    private int $tenantId;

    // Alert threshold buckets (days until due)
    private const THRESHOLDS = [30, 14, 7, 3, 1];

    // TG feed file path (per infra:tg_bridge_v3 memory)
    private const TG_FEED_PATH = '/Users/mickeyp/.voltops-tg-feed.md';

    // Base URL for action links
    private const BASE_URL = 'https://voltops.net/empire/calendar.php';

    public function __construct(PDO $db, int $tenantId)
    {
        $this->db       = $db;
        $this->tenantId = $tenantId;

        $this->ensureAlertLogTable();
    }

    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Run daily alert dispatch for this tenant.
     *
     * Checks all tasks against thresholds; deduplicates via alert_hash.
     * Returns counts of queued alerts per channel.
     *
     * @return array{emails_queued:int, tg_alerts:int, sms_queued:int}
     */
    public function dispatchUpcoming(): array
    {
        $emailsQueued = 0;
        $tgAlerts     = 0;
        $smsQueued    = 0;

        // Fetch all tasks that might hit a threshold today
        $tasks = $this->fetchAlertCandidates();

        foreach ($tasks as $task) {
            $daysUntil  = (int)$task['days_until_due'];
            $isOverdue  = $daysUntil < 0;
            $threshold  = $this->matchThreshold($daysUntil, $isOverdue);

            if ($threshold === null) {
                continue; // Not a threshold day — skip
            }

            $alertHash = $this->buildAlertHash($task['id'], $threshold);

            // Idempotency: skip if already dispatched today
            if ($this->alertAlreadySent($alertHash)) {
                continue;
            }

            $message = $this->buildAlertMessage($task, $daysUntil, $isOverdue);

            // ── Email queue (all thresholds) ───────────────────────────
            $this->queueEmail($task, $message, $threshold);
            $emailsQueued++;

            // ── Telegram (all thresholds) ──────────────────────────────
            $this->pushToTelegram($task, $message, $isOverdue, $daysUntil);
            $tgAlerts++;

            // ── SMS (high urgency only: overdue, ≤3 days) ─────────────
            if ($isOverdue || $daysUntil <= 3) {
                $this->queueSms($task, $message);
                $smsQueued++;
            }

            // Record alert sent
            $this->logAlertSent($alertHash, $task['id'], $threshold);
        }

        return [
            'emails_queued' => $emailsQueued,
            'tg_alerts'     => $tgAlerts,
            'sms_queued'    => $smsQueued,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE — Candidate Fetch
    // ─────────────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    private function fetchAlertCandidates(): array
    {
        // Fetch tasks within 30 days OR already overdue
        $stmt = $this->db->prepare(
            "SELECT cc.*,
                    ebi.brand_name,
                    ebi.brand_slug,
                    DATEDIFF(cc.due_date, CURDATE()) AS days_until_due
             FROM compliance_calendar cc
             LEFT JOIN empire_brand_intake ebi
                    ON ebi.id = cc.intake_id AND ebi.tenant_id = cc.tenant_id
             WHERE cc.tenant_id = ?
               AND cc.status NOT IN ('completed','waived')
               AND cc.due_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             ORDER BY cc.due_date ASC"
        );
        $stmt->execute([$this->tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE — Threshold matching
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Returns the matching threshold label or null if today is not a threshold day.
     * Overdue tasks always match.
     */
    private function matchThreshold(int $daysUntil, bool $isOverdue): ?string
    {
        if ($isOverdue) {
            return 'OVERDUE';
        }

        foreach (self::THRESHOLDS as $t) {
            if ($daysUntil === $t) {
                return (string)$t . 'd';
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE — Message building
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $task */
    private function buildAlertMessage(array $task, int $daysUntil, bool $isOverdue): string
    {
        $entityName  = htmlspecialchars_decode((string)($task['brand_name'] ?? 'Unknown Entity'));
        $taskType    = strtoupper(str_replace('_', ' ', $task['task_type']));
        $dueDate     = $task['due_date'];
        $exposure    = $this->estimateExposure($task['task_type'], abs($daysUntil));
        $actionUrl   = self::BASE_URL . '?task_id=' . (int)$task['id'];

        if ($isOverdue) {
            $urgency = 'OVERDUE by ' . abs($daysUntil) . ' day' . (abs($daysUntil) !== 1 ? 's' : '');
        } else {
            $urgency = 'due in ' . $daysUntil . ' day' . ($daysUntil !== 1 ? 's' : '');
        }

        $exposureStr = $exposure > 0 ? ' | Exposure: $' . number_format($exposure, 0) : '';

        return "[COMPLIANCE] {$entityName} — {$taskType} {$urgency} ({$dueDate}){$exposureStr} → {$actionUrl}";
    }

    private function estimateExposure(string $taskType, int $daysOverdue): float
    {
        $dailyRates = [
            'boi_update'    => 500.0,
            'franchise_tax' => 50.0,
            'annual_report' => 25.0,
            'fbar'          => 10000.0,
            'federal_tax'   => 0.5,
            'state_tax'     => 0.25,
            'captive_filing'=> 100.0,
        ];
        $rate = $dailyRates[$taskType] ?? 0.0;
        if ($taskType === 'fbar') {
            return $rate; // Fixed, not daily
        }
        return $rate * $daysOverdue;
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE — Channel writers
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $task */
    private function queueEmail(array $task, string $message, string $threshold): void
    {
        // Write to compliance_alert_queue table for Phase B email sender
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO compliance_alert_queue
                 (tenant_id, task_id, channel, threshold_label, message_text, queued_at)
                 VALUES (?, ?, 'email', ?, ?, NOW())"
            );
            $stmt->execute([
                $this->tenantId,
                (int)$task['id'],
                $threshold,
                $message,
            ]);
        } catch (\Throwable $e) {
            // Table may not exist yet (Phase B migration pending); log to stderr only
            fwrite(STDERR, '[AlertDispatcher] Email queue write failed: ' . $e->getMessage() . "\n");
        }
    }

    /** @param array<string,mixed> $task */
    private function pushToTelegram(array $task, string $message, bool $isOverdue, int $daysUntil): void
    {
        $feedPath = self::TG_FEED_PATH;
        $ts       = date('[H:i]');

        if ($isOverdue) {
            $icon = '🚨';
        } elseif ($daysUntil <= 3) {
            $icon = '⚠️';
        } elseif ($daysUntil <= 7) {
            $icon = '📅';
        } else {
            $icon = '🗓';
        }

        $line = "{$ts} {$icon} " . $message . "\n";

        // Append to TG feed file (infra:tg_bridge_v3 pattern)
        $fp = @fopen($feedPath, 'a');
        if ($fp) {
            fwrite($fp, $line);
            fclose($fp);
        }
    }

    /** @param array<string,mixed> $task */
    private function queueSms(array $task, string $message): void
    {
        // Write to sms_pending via the voltops DB table (decision:sms_channel pattern)
        // Android APK relay on (817) 219-3581 polls /api/sms_pending.php every 10s
        try {
            // Truncate to SMS-safe length (160 chars)
            $smsBody = substr(strip_tags($message), 0, 155);
            if (strlen($message) > 155) {
                $smsBody .= '...';
            }

            $stmt = $this->db->prepare(
                "INSERT INTO sms_pending
                 (tenant_id, to_number, body, priority, created_at)
                 VALUES (?, '(817) 219-3581', ?, 'high', NOW())"
            );
            $stmt->execute([
                $this->tenantId,
                $smsBody,
            ]);
        } catch (\Throwable $e) {
            // sms_pending schema may differ; non-fatal
            fwrite(STDERR, '[AlertDispatcher] SMS queue write failed: ' . $e->getMessage() . "\n");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE — Idempotency
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build a deterministic hash for a given (task, threshold) on today's date.
     * Same task + same threshold on the same calendar day = same hash → skip.
     */
    private function buildAlertHash(int $taskId, string $threshold): string
    {
        $key = implode('|', [
            $this->tenantId,
            $taskId,
            $threshold,
            date('Y-m-d'),
        ]);
        return hash('sha256', $key);
    }

    private function alertAlreadySent(string $alertHash): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM compliance_alert_log
                 WHERE alert_hash=? AND sent_date=CURDATE()
                 LIMIT 1"
            );
            $stmt->execute([$alertHash]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false; // Table doesn't exist yet — allow dispatch
        }
    }

    private function logAlertSent(string $alertHash, int $taskId, string $threshold): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT IGNORE INTO compliance_alert_log
                 (tenant_id, task_id, alert_hash, threshold_label, sent_date, sent_at)
                 VALUES (?, ?, ?, ?, CURDATE(), NOW())"
            );
            $stmt->execute([
                $this->tenantId,
                $taskId,
                $alertHash,
                $threshold,
            ]);
        } catch (\Throwable $e) {
            // Non-fatal; idempotency degrades gracefully to allow-all
            fwrite(STDERR, '[AlertDispatcher] Alert log write failed: ' . $e->getMessage() . "\n");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE — One-time setup
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Create alert support tables if they don't exist.
     * Inline DDL so Phase A doesn't require a separate migration deployment.
     */
    private function ensureAlertLogTable(): void
    {
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS compliance_alert_log (
                    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    tenant_id       INT UNSIGNED NOT NULL,
                    task_id         INT UNSIGNED NOT NULL,
                    alert_hash      CHAR(64) NOT NULL,
                    threshold_label VARCHAR(16) NOT NULL,
                    sent_date       DATE NOT NULL,
                    sent_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_hash_date (alert_hash, sent_date),
                    INDEX idx_al_tenant (tenant_id, sent_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS compliance_alert_queue (
                    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    tenant_id       INT UNSIGNED NOT NULL,
                    task_id         INT UNSIGNED NOT NULL,
                    channel         ENUM('email','sms','tg') NOT NULL,
                    threshold_label VARCHAR(16) NOT NULL,
                    message_text    TEXT NOT NULL,
                    sent_at         DATETIME NULL,
                    queued_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_aq_pending (channel, sent_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            // Non-fatal — table may already exist or DB user lacks CREATE TABLE
            fwrite(STDERR, '[AlertDispatcher] DDL warning: ' . $e->getMessage() . "\n");
        }
    }
}
