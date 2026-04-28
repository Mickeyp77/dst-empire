<?php
/**
 * src/Empire/Compliance/CalendarEngine.php
 *
 * Scaffolds, maintains, and surfaces compliance tasks per entity per tenant.
 * Consumes the compliance_calendar table (migration 077).
 *
 * Parallel module to src/Empire/BOI/Filer.php — uses the same
 * daysUntilDue() style contract; Filer drives BOI-specific logic while
 * CalendarEngine owns the recurring calendar scaffold.
 *
 * Namespace: Mnmsos\Empire\Compliance
 */

namespace Mnmsos\Empire\Compliance;

use PDO;

class CalendarEngine
{
    private PDO $db;
    private int $tenantId;

    // ─────────────────────────────────────────────────────────────────────
    // Penalty exposure lookup: dollars per day overdue (approximate)
    // Source: FinCEN CTA $500/day; state franchise tax late fees vary.
    // ─────────────────────────────────────────────────────────────────────

    /** @var array<string,float> Task type → daily penalty (USD) */
    private static array $DAILY_PENALTY = [
        'boi_update'     => 500.00,  // CTA §5336(h)(3)(A): $500/day, up to $10,000
        'franchise_tax'  => 50.00,   // DE: $200 late fee; TX: 5% of tax; use $50/day approx
        'annual_report'  => 25.00,   // Varies; averaged across states
        'fbar'           => 10000.00, // Per violation (one-time, not daily) — use high fixed
        'federal_tax'    => 0.50,    // IRS failure-to-file: 5%/mo of tax; proxy daily
        'state_tax'      => 0.25,
        'captive_filing' => 100.00,
    ];

    // ─────────────────────────────────────────────────────────────────────
    // Default task scaffold per entity/structure type
    // task_type => recurrence
    // ─────────────────────────────────────────────────────────────────────

    /** Tasks seeded for all entity types */
    private const TASKS_ALL = [
        'boi_update'        => 'once',    // Initial BOI; re-triggered on owner change
        'license_renewal'   => 'annual',
        'insurance_renewal' => 'annual',
    ];

    /** Tasks seeded for LLC and Corporation (all variants) */
    private const TASKS_LLC_CORP = [
        'annual_report'  => 'annual',
        'federal_tax'    => 'annual',
        'state_tax'      => 'annual',
    ];

    /** Extra tasks for S-Corp specifically */
    private const TASKS_SCORP = [
        'franchise_tax'       => 'annual',
        '199a_recalc'         => 'annual',
        'ptet_election'       => 'annual',
        'reasonable_comp_review' => 'annual', // non-standard task_type — stored in notes
    ];

    /** Extra tasks for C-Corp */
    private const TASKS_CCORP = [
        'franchise_tax'  => 'annual',
        '531_recheck'    => 'annual',
        '1202_clock'     => 'once',
        'fbar'           => 'annual',
    ];

    /** Extra tasks for Trust (DAPT / Dynasty / Bridge) */
    private const TASKS_TRUST = [
        'trust_admin'    => 'annual',
        'dapt_seasoning' => 'once',
        '83b_anniversary'=> 'annual',
        'crummey_letter' => 'annual',
    ];

    /** Extra tasks for 501(c)(3) */
    private const TASKS_501C3 = [
        'federal_tax'    => 'annual',  // Form 990
        'state_tax'      => 'annual',
        'annual_report'  => 'annual',
    ];

    /** Texas-specific extras (franchise tax) */
    private const TASKS_TX = [
        'franchise_tax' => 'annual',
    ];

    /** Delaware-specific extras */
    private const TASKS_DE = [
        'franchise_tax' => 'annual',
    ];

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
     * Scaffold standard recurring tasks for a newly formed entity.
     *
     * Reads entity_type, decided_jurisdiction, and formation_date from
     * empire_brand_intake to determine which task set applies.
     *
     * Returns the count of new tasks inserted (skips duplicates).
     */
    public function seedTasksForEntity(int $intakeId): int
    {
        $entity = $this->fetchIntake($intakeId);
        if (!$entity) {
            return 0;
        }

        $entityType  = strtolower((string)($entity['decided_entity_type'] ?? $entity['entity_type_hint'] ?? 'llc'));
        $jurisdiction = strtoupper((string)($entity['decided_jurisdiction'] ?? 'TX'));
        $formationDate = $this->parseFormationDate($entity);

        // Build task map: task_type => recurrence
        $tasks = self::TASKS_ALL;

        // LLC / Corp base tasks
        if (str_contains($entityType, 'llc') || str_contains($entityType, 'corp') || str_contains($entityType, 'inc')) {
            $tasks = array_merge($tasks, self::TASKS_LLC_CORP);
        }

        // S-Corp specifics
        if (str_contains($entityType, 's-corp') || str_contains($entityType, 's_corp') || $entityType === 'scorp') {
            $tasks = array_merge($tasks, self::TASKS_SCORP);
        }

        // C-Corp specifics
        if (str_contains($entityType, 'c-corp') || str_contains($entityType, 'c_corp') || $entityType === 'ccorp'
            || str_contains($entityType, 'c corp')) {
            $tasks = array_merge($tasks, self::TASKS_CCORP);
        }

        // Trust
        if (str_contains($entityType, 'trust') || str_contains($entityType, 'dapt')) {
            $tasks = array_merge($tasks, self::TASKS_TRUST);
        }

        // 501(c)(3)
        if (str_contains($entityType, '501') || str_contains($entityType, 'nonprofit')) {
            $tasks = array_merge($tasks, self::TASKS_501C3);
        }

        // State-specific additions
        if ($jurisdiction === 'TX') {
            $tasks = array_merge($tasks, self::TASKS_TX);
        } elseif ($jurisdiction === 'DE') {
            $tasks = array_merge($tasks, self::TASKS_DE);
        }

        // Deduplicate (last recurrence wins for duplicated types)
        $inserted = 0;
        foreach ($tasks as $taskType => $recurrence) {
            try {
                $dueDate = RecurrenceCalculator::dueDateFor($taskType, $jurisdiction, $formationDate);
            } catch (\Throwable $e) {
                // Fallback: 1 year from today
                $dueDate = new \DateTime('+1 year');
            }

            // Build context note
            $note = "Auto-seeded at formation. Entity: {$entityType} / {$jurisdiction}.";

            $newId = $this->addTask($intakeId, $taskType, $dueDate, $recurrence, $note);
            if ($newId > 0) {
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * Insert a single compliance task.
     *
     * Skips insertion if an identical (intake_id, task_type, due_date, status=pending)
     * row already exists — idempotent.
     *
     * Returns the new task ID, or 0 if skipped.
     */
    public function addTask(
        int       $intakeId,
        string    $taskType,
        \DateTime $dueDate,
        string    $recurrence,
        string    $notesMd = ''
    ): int {
        $dueDateStr = $dueDate->format('Y-m-d');

        // Idempotency check
        $check = $this->db->prepare(
            "SELECT id FROM compliance_calendar
             WHERE tenant_id=? AND intake_id=? AND task_type=? AND due_date=? AND status='pending'
             LIMIT 1"
        );
        $check->execute([$this->tenantId, $intakeId, $taskType, $dueDateStr]);
        if ($check->fetchColumn()) {
            return 0; // Already exists
        }

        $stmt = $this->db->prepare(
            "INSERT INTO compliance_calendar
             (tenant_id, intake_id, task_type, due_date, status, recurrence, notes_md, created_at)
             VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW())"
        );
        $stmt->execute([
            $this->tenantId,
            $intakeId,
            $taskType,
            $dueDateStr,
            $recurrence,
            $notesMd,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Mark a task complete and auto-schedule the next occurrence if recurring.
     *
     * Returns true on success.
     */
    public function markComplete(int $taskId, int $userId): bool
    {
        // Fetch the task
        $stmt = $this->db->prepare(
            "SELECT * FROM compliance_calendar WHERE id=? AND tenant_id=? LIMIT 1"
        );
        $stmt->execute([$taskId, $this->tenantId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            return false;
        }

        // Mark complete
        $upd = $this->db->prepare(
            "UPDATE compliance_calendar
             SET status='completed', completed_at=NOW(), completed_by=?
             WHERE id=? AND tenant_id=?"
        );
        $upd->execute([$userId, $taskId, $this->tenantId]);

        // Schedule next occurrence if recurring
        if ($task['recurrence'] !== 'once') {
            $completedDate = new \DateTime($task['due_date']);
            $nextDate      = RecurrenceCalculator::nextOccurrence($completedDate, $task['recurrence']);

            if ($nextDate !== null) {
                $this->addTask(
                    (int)$task['intake_id'],
                    $task['task_type'],
                    $nextDate,
                    $task['recurrence'],
                    'Auto-scheduled from completed task #' . $taskId . '.'
                );
            }
        }

        return true;
    }

    /**
     * All tasks due within $daysAhead days for this tenant, ordered by due_date ASC.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listUpcoming(int $daysAhead = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT cc.*, ebi.brand_name, ebi.brand_slug,
                    DATEDIFF(cc.due_date, CURDATE()) AS days_until_due
             FROM compliance_calendar cc
             LEFT JOIN empire_brand_intake ebi ON ebi.id = cc.intake_id AND ebi.tenant_id = cc.tenant_id
             WHERE cc.tenant_id = ?
               AND cc.status IN ('pending','in_progress','overdue')
               AND cc.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY cc.due_date ASC"
        );
        $stmt->execute([$this->tenantId, $daysAhead]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * All overdue tasks for this tenant (due_date < today, status != completed/waived).
     *
     * @return array<int,array<string,mixed>>
     */
    public function listOverdue(): array
    {
        $stmt = $this->db->prepare(
            "SELECT cc.*, ebi.brand_name, ebi.brand_slug,
                    DATEDIFF(cc.due_date, CURDATE()) AS days_until_due
             FROM compliance_calendar cc
             LEFT JOIN empire_brand_intake ebi ON ebi.id = cc.intake_id AND ebi.tenant_id = cc.tenant_id
             WHERE cc.tenant_id = ?
               AND cc.status NOT IN ('completed','waived')
               AND cc.due_date < CURDATE()
             ORDER BY cc.due_date ASC"
        );
        $stmt->execute([$this->tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cron-callable: for every completed recurring task, ensure the next
     * occurrence exists. Idempotent — addTask() deduplicates.
     *
     * Returns count of new tasks created.
     */
    public function regenerateRecurring(): int
    {
        $stmt = $this->db->prepare(
            "SELECT cc.*
             FROM compliance_calendar cc
             WHERE cc.tenant_id = ?
               AND cc.status = 'completed'
               AND cc.recurrence != 'once'
             ORDER BY cc.due_date DESC"
        );
        $stmt->execute([$this->tenantId]);
        $completed = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $created = 0;
        foreach ($completed as $task) {
            $baseDate = new \DateTime($task['due_date']);
            $nextDate = RecurrenceCalculator::nextOccurrence($baseDate, $task['recurrence']);

            if ($nextDate === null) {
                continue;
            }

            // Only create if next date is in the future
            $today = new \DateTime('today');
            if ($nextDate <= $today) {
                // Advance further if needed (e.g. stale completed tasks)
                $nextDate = RecurrenceCalculator::nextOccurrence($nextDate, $task['recurrence']);
                if ($nextDate === null || $nextDate <= $today) {
                    continue;
                }
            }

            $newId = $this->addTask(
                (int)$task['intake_id'],
                $task['task_type'],
                $nextDate,
                $task['recurrence'],
                'Auto-regenerated by cron from task #' . $task['id'] . '.'
            );
            if ($newId > 0) {
                $created++;
            }
        }

        // Also flip any pending tasks that are now past-due to 'overdue'
        $this->db->prepare(
            "UPDATE compliance_calendar
             SET status='overdue'
             WHERE tenant_id=? AND status='pending' AND due_date < CURDATE()"
        )->execute([$this->tenantId]);

        return $created;
    }

    /**
     * Dashboard KPI summary for this tenant.
     *
     * @return array{upcoming_30d:int, overdue:int, completed_y2d:int, penalty_exposure_usd:float}
     */
    public function dashboardKpis(): array
    {
        // Upcoming 30 days
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM compliance_calendar
             WHERE tenant_id=?
               AND status IN ('pending','in_progress','overdue')
               AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
        );
        $stmt->execute([$this->tenantId]);
        $upcoming30d = (int)$stmt->fetchColumn();

        // Overdue tasks with days overdue
        $stmt = $this->db->prepare(
            "SELECT task_type, DATEDIFF(CURDATE(), due_date) AS days_overdue
             FROM compliance_calendar
             WHERE tenant_id=?
               AND status NOT IN ('completed','waived')
               AND due_date < CURDATE()"
        );
        $stmt->execute([$this->tenantId]);
        $overdueRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $overdue     = count($overdueRows);

        // Completed year-to-date
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM compliance_calendar
             WHERE tenant_id=?
               AND status='completed'
               AND YEAR(completed_at) = YEAR(NOW())"
        );
        $stmt->execute([$this->tenantId]);
        $completedYtd = (int)$stmt->fetchColumn();

        // Penalty exposure: sum daily penalty × days overdue per task
        // BOI: also multiply by entity count (all overdue BOI tasks)
        $penaltyExposure = 0.0;
        $boiDaysOverdue  = 0;
        $boiEntityCount  = 0;

        foreach ($overdueRows as $row) {
            $taskType   = $row['task_type'];
            $daysOver   = (int)$row['days_overdue'];
            $dailyRate  = self::$DAILY_PENALTY[$taskType] ?? 0.0;

            if ($taskType === 'boi_update') {
                // Accumulate BOI separately for entity-count multiplication
                $boiDaysOverdue += $daysOver;
                $boiEntityCount++;
                continue;
            }

            if ($taskType === 'fbar') {
                // FBAR: fixed per violation, not daily
                $penaltyExposure += $dailyRate;
                continue;
            }

            $penaltyExposure += $dailyRate * $daysOver;
        }

        // BOI: $500/day × days × entity count, capped at $10k per entity per CTA
        if ($boiEntityCount > 0) {
            $boiExposure     = min(500.0 * $boiDaysOverdue, 10000.0 * $boiEntityCount);
            $penaltyExposure += $boiExposure;
        }

        return [
            'upcoming_30d'          => $upcoming30d,
            'overdue'               => $overdue,
            'completed_y2d'         => $completedYtd,
            'penalty_exposure_usd'  => round($penaltyExposure, 2),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function fetchIntake(int $intakeId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM empire_brand_intake WHERE id=? AND tenant_id=? LIMIT 1"
        );
        $stmt->execute([$intakeId, $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function parseFormationDate(array $entity): \DateTime
    {
        foreach (['formation_date', 'incorporation_date', 'created_at'] as $col) {
            if (!empty($entity[$col])) {
                $dt = \DateTime::createFromFormat('Y-m-d', substr((string)$entity[$col], 0, 10));
                if ($dt) {
                    return $dt;
                }
            }
        }
        // Fallback: today
        return new \DateTime('today');
    }
}
