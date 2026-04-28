<?php
/**
 * src/Empire/LawMonitor/PerClientImpact.php
 *
 * Determines which law_changes (from the last N days) actually affect a
 * specific tenant's entity portfolio, based on:
 *
 *  1. Jurisdiction match — classification.affected_jurisdictions ∩ tenant's
 *     decided_jurisdiction values across intakes
 *  2. Entity type match — classification.affected_entity_types ∩ intake's
 *     decided_entity_type
 *  3. Playbook match — classification.affected_playbooks ∩ playbooks enabled
 *     for that intake
 *  4. Severity threshold — only law_changes with severity != 'low' trigger
 *     amendment rows (low severity is returned but doesn't auto-create amendments)
 *
 * For each impacting law_change:
 *  - Identifies affected intake rows for THIS tenant
 *  - Creates amendments table rows (status='detected') if action_required != 'none'
 *  - Returns structured result array for the cron + UI layer
 *
 * Tenant isolation: every query filters tenant_id = $this->tenantId.
 *
 * Namespace: Mnmsos\Empire\LawMonitor
 */

namespace Mnmsos\Empire\LawMonitor;

use PDO;

class PerClientImpact
{
    private PDO $db;
    private int $tenantId;

    public function __construct(PDO $db, int $tenantId)
    {
        $this->db       = $db;
        $this->tenantId = $tenantId;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Find law_changes from the last $sinceDays days that affect this tenant.
     *
     * For each matching change, triggers amendments row creation if needed.
     *
     * Returns array of impact objects:
     * [
     *   {
     *     law_change:         array   (full law_changes row),
     *     affected_intakes:   array[] (empire_brand_intake rows),
     *     affected_playbooks: string[],
     *     severity:           string,
     *     amendments_created: int,
     *   }, ...
     * ]
     *
     * @return array<int,array<string,mixed>>
     */
    public function findImpactingChanges(int $sinceDays = 30): array
    {
        // Fetch recent unprocessed (or newly processed) law_changes
        $changes = $this->fetchRecentChanges($sinceDays);

        if (empty($changes)) {
            return [];
        }

        // Fetch this tenant's locked intakes with their decisions
        $intakes = $this->fetchTenantIntakes();

        if (empty($intakes)) {
            return [];
        }

        $impacts = [];

        foreach ($changes as $change) {
            $classification = $this->decodeClassification($change);

            if (empty($classification)) {
                continue;
            }

            // Skip items that the LLM said don't affect DST Empire at all
            if (!($classification['affects_dst_empire'] ?? false)) {
                continue;
            }

            $actionRequired = $classification['action_required'] ?? 'none';

            // Find which of this tenant's intakes are touched
            $affectedIntakes  = $this->matchIntakes($intakes, $classification);
            $affectedPlaybooks = $classification['affected_playbooks'] ?? [];

            if (empty($affectedIntakes)) {
                continue;
            }

            // Create amendment rows for each affected intake (if action needed)
            $amendmentsCreated = 0;
            if ($actionRequired !== 'none') {
                foreach ($affectedIntakes as $intake) {
                    $created = $this->maybeCreateAmendment($change, $intake, $classification);
                    if ($created) {
                        $amendmentsCreated++;
                    }
                }
            }

            $impacts[] = [
                'law_change'         => $change,
                'affected_intakes'   => $affectedIntakes,
                'affected_playbooks' => $affectedPlaybooks,
                'severity'           => $classification['severity'] ?? 'medium',
                'amendments_created' => $amendmentsCreated,
            ];
        }

        return $impacts;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — Data fetchers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fetch law_changes detected in the last $sinceDays with valid classification.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchRecentChanges(int $sinceDays): array
    {
        $stmt = $this->db->prepare(
            "SELECT *
             FROM law_changes
             WHERE detected_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND classification_json IS NOT NULL
             ORDER BY detected_at DESC"
        );
        $stmt->execute([$sinceDays]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch all locked empire_brand_intake rows for this tenant.
     * Only locked intakes have a decided_jurisdiction + decided_entity_type.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchTenantIntakes(): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, brand_name, brand_slug, aggression_tier,
                    decided_jurisdiction, decided_entity_type,
                    playbooks_json, is_locked
             FROM empire_brand_intake
             WHERE tenant_id = ?
               AND is_locked = 1
             ORDER BY id ASC"
        );
        $stmt->execute([$this->tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — Matching logic
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Given a classification JSON, return subset of $intakes that are affected.
     *
     * Matching logic (OR across criteria):
     *  - jurisdiction overlap  (empty affected_jurisdictions = federal = all)
     *  - entity_type overlap
     *  - playbook overlap
     *
     * @param array<int,array<string,mixed>>  $intakes
     * @param array<string,mixed>             $classification
     * @return array<int,array<string,mixed>>
     */
    private function matchIntakes(array $intakes, array $classification): array
    {
        $affectedJurisdictions = array_map('strtoupper', (array)($classification['affected_jurisdictions'] ?? []));
        $affectedEntityTypes   = array_map('strtolower', (array)($classification['affected_entity_types'] ?? []));
        $affectedPlaybooks     = (array)($classification['affected_playbooks'] ?? []);

        // Empty jurisdiction list = federal (affects all jurisdictions)
        $federalOrAll = empty($affectedJurisdictions);

        $matched = [];

        foreach ($intakes as $intake) {
            $intakeJurisdiction = strtoupper((string)($intake['decided_jurisdiction'] ?? ''));
            $intakeEntityType   = strtolower((string)($intake['decided_entity_type'] ?? ''));
            $intakePlaybooks    = $this->decodePlaybooks($intake['playbooks_json'] ?? null);

            $jurisdictionMatch = $federalOrAll
                || ($intakeJurisdiction && in_array($intakeJurisdiction, $affectedJurisdictions, true));

            $entityTypeMatch = empty($affectedEntityTypes)
                || ($intakeEntityType && in_array($intakeEntityType, $affectedEntityTypes, true));

            $playbookMatch = empty($affectedPlaybooks)
                || !empty(array_intersect($affectedPlaybooks, $intakePlaybooks));

            // Must match at least one of: jurisdiction OR entity type OR playbook
            // (AND at least one must be relevant — don't match empty-everything)
            $anyMatch = false;
            if (!empty($affectedJurisdictions) && $jurisdictionMatch) {
                $anyMatch = true;
            }
            if (!empty($affectedEntityTypes) && $entityTypeMatch) {
                $anyMatch = true;
            }
            if (!empty($affectedPlaybooks) && $playbookMatch) {
                $anyMatch = true;
            }
            // Federal changes (empty jurisdictions) with no entity/playbook filter affect all
            if ($federalOrAll && empty($affectedEntityTypes) && empty($affectedPlaybooks)) {
                $anyMatch = true;
            }

            if ($anyMatch) {
                $matched[] = $intake;
            }
        }

        return $matched;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — Amendment creation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create an amendments row for a (law_change, intake) pair if one doesn't
     * already exist with status NOT IN ('filed','dismissed').
     *
     * Returns true if a new row was inserted.
     *
     * @param array<string,mixed> $change
     * @param array<string,mixed> $intake
     * @param array<string,mixed> $classification
     */
    private function maybeCreateAmendment(array $change, array $intake, array $classification): bool
    {
        $lawChangeId = (int)$change['id'];
        $intakeId    = (int)$intake['id'];

        // Idempotency: don't create duplicate open amendments for same (intake, law_change)
        $existsStmt = $this->db->prepare(
            "SELECT 1
             FROM amendments
             WHERE tenant_id = ?
               AND intake_id = ?
               AND law_change_id = ?
               AND status NOT IN ('filed','dismissed')
             LIMIT 1"
        );
        $existsStmt->execute([$this->tenantId, $intakeId, $lawChangeId]);
        if ($existsStmt->fetchColumn()) {
            return false; // Already has an open amendment for this change
        }

        $severity       = $classification['severity'] ?? 'medium';
        $actionRequired = $classification['action_required'] ?? 'monitor';

        // Build affected_doc_render_ids_json — find existing renders for this intake
        $affectedRenderIds = $this->findAffectedRenderIds($intakeId);
        $affectedJson      = json_encode($affectedRenderIds);

        $triggerDesc = "Law change detected: {$change['title']}\n\n"
            . "Source: {$change['source']} | URL: {$change['source_url']}\n\n"
            . "Action required: {$actionRequired}\n\n"
            . ($classification['summary_md'] ?? '');

        $stmt = $this->db->prepare(
            "INSERT INTO amendments
             (tenant_id, intake_id, law_change_id, trigger_event_type,
              trigger_description_md, affected_doc_render_ids_json,
              amendment_doc_render_id, status, severity, created_at)
             VALUES
             (?, ?, ?, 'law_change', ?, ?, NULL, 'detected', ?, NOW())"
        );

        $stmt->execute([
            $this->tenantId,
            $intakeId,
            $lawChangeId,
            $triggerDesc,
            $affectedJson,
            $severity,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Find the most recent doc_renders.id values for a given intake.
     * Returns up to 5 most recent render IDs (the ones most likely to need amending).
     *
     * @return int[]
     */
    private function findAffectedRenderIds(int $intakeId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id
                 FROM doc_renders
                 WHERE intake_id = ?
                   AND status NOT IN ('superseded','archived')
                 ORDER BY created_at DESC
                 LIMIT 5"
            );
            $stmt->execute([$intakeId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            // doc_renders table may not exist yet (Phase A3 pending)
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — JSON helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Decode classification_json from a law_changes row.
     *
     * @param array<string,mixed> $change
     * @return array<string,mixed>
     */
    private function decodeClassification(array $change): array
    {
        $raw = $change['classification_json'] ?? null;
        if (empty($raw)) {
            return [];
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Decode playbooks_json from an intake row into a flat string array.
     *
     * @return string[]
     */
    private function decodePlaybooks(?string $json): array
    {
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        // playbooks_json may be ["qsbs_1202",...] or [{id:"qsbs_1202"},...] — handle both
        $result = [];
        foreach ($decoded as $item) {
            if (is_string($item)) {
                $result[] = $item;
            } elseif (is_array($item) && isset($item['id'])) {
                $result[] = (string)$item['id'];
            }
        }
        return $result;
    }
}
