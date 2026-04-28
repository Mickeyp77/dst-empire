<?php
/**
 * src/Empire/BOI/Filer.php — FinCEN Beneficial Ownership Information (BOI) Report generator.
 *
 * Corporate Transparency Act (CTA) — effective 2024-01-01.
 * New entities (formed on/after 2024-01-01) must file BOIR within 30 days of formation.
 * Existing entities (formed before 2024-01-01) had until 2025-01-01 (already past).
 * Any change to beneficial owner info = re-file within 30 days of the change.
 *
 * THIS CLASS DOES NOT SUBMIT TO FINCEN. Generation only. Manual filing at:
 * https://boiefiling.fincen.gov/
 *
 * Schema reference: FinCEN BOIR XML/JSON schema v1.0 published at
 * https://www.fincen.gov/beneficial-ownership-information-reporting-xml-schema
 *
 * Namespace: Mnmsos\Empire\BOI
 */

namespace Mnmsos\Empire\BOI;

use PDO;

class Filer
{
    private PDO $db;
    private int $tenantId;

    // Filing type codes per FinCEN schema
    const FILING_TYPE_INITIAL    = '1'; // Initial report
    const FILING_TYPE_CORRECTION = '2'; // Correction to prior report
    const FILING_TYPE_UPDATE     = '3'; // Update (owner change, etc.)
    const FILING_TYPE_NEWLY_EXEMPT = '4'; // Entity newly exempt

    // FinCEN accepted ID document types
    const DOC_TYPE_US_PASSPORT         = '1';
    const DOC_TYPE_STATE_DL            = '2';
    const DOC_TYPE_STATE_ID            = '3';
    const DOC_TYPE_FOREIGN_PASSPORT    = '4';

    // Deadline constants
    const DAYS_TO_FILE_NEW   = 30; // Entities formed on/after 2024-01-01
    const DAYS_TO_FILE_CHANGE = 30; // Owner info changes

    public function __construct(PDO $db, int $tenantId)
    {
        $this->db       = $db;
        $this->tenantId = $tenantId;
    }

    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build and validate a BOIR payload for an intake record.
     *
     * Returns:
     *   valid         — bool, all required fields present and valid
     *   errors        — string[], list of validation failures
     *   payload       — array, BOIR JSON-ready structure
     *   pdf_summary_md — string, human-readable markdown summary for review
     */
    public function prepareReport(int $intakeId): array
    {
        $result = [
            'valid'          => false,
            'errors'         => [],
            'payload'        => [],
            'pdf_summary_md' => '',
        ];

        try {
            $entity = $this->fetchEntity($intakeId);
            if (!$entity) {
                $result['errors'][] = 'Entity not found or wrong tenant (intake_id=' . $intakeId . ').';
                $this->logAttempt($intakeId, 'error', $result['errors']);
                return $result;
            }

            $owners = $this->fetchOwners($intakeId);

            // Validate
            $errors = $this->validate($entity, $owners);
            if (!empty($errors)) {
                $result['errors'] = $errors;
                $this->logAttempt($intakeId, 'validation_fail', $errors);
                return $result;
            }

            $payload = $this->buildPayload($entity, $owners);

            $result['valid']          = true;
            $result['payload']        = $payload;
            $result['pdf_summary_md'] = $this->buildSummaryMd($entity, $owners);

            $this->logAttempt($intakeId, 'prepared', [], $payload);

        } catch (\Throwable $e) {
            error_log('[BOI\Filer] prepareReport error intake=' . $intakeId . ': ' . $e->getMessage());
            $result['errors'][] = 'Internal error: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Mark an entity's BOI as filed after manual submission at fincen.gov.
     * Records the FinCEN confirmation hash and filed timestamp.
     */
    public function markFiled(int $intakeId, string $fincenConfirmationHash, ?\DateTime $filedAt = null): bool
    {
        try {
            $filedAt = $filedAt ?? new \DateTime();
            $now = $filedAt->format('Y-m-d H:i:s');

            // Update each owner row for this intake
            $stmt = $this->db->prepare(
                "UPDATE beneficial_owners
                 SET last_filed_at = ?, fincen_confirmation_hash = ?
                 WHERE intake_id = ? AND tenant_id = ?"
            );
            $stmt->execute([$now, $fincenConfirmationHash, $intakeId, $this->tenantId]);

            // Mark the formation_entities row as boi_filed
            $stmt2 = $this->db->prepare(
                "UPDATE formation_entities
                 SET boi_filed_at = ?, boi_confirmation_hash = ?
                 WHERE intake_id = ? AND tenant_id = ?"
            );
            $stmt2->execute([$now, $fincenConfirmationHash, $intakeId, $this->tenantId]);

            $this->logAttempt($intakeId, 'marked_filed', [], [], $fincenConfirmationHash, $now);
            return true;
        } catch (\Throwable $e) {
            error_log('[BOI\Filer] markFiled error intake=' . $intakeId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Days until BOI is due for a given intake.
     * Negative = overdue. Null = can't determine (no formation date).
     * Pre-2024 entities: uses 2025-01-01 as deadline (already past → always overdue).
     */
    public function daysUntilDue(int $intakeId): ?int
    {
        try {
            $entity = $this->fetchEntity($intakeId);
            if (!$entity) return null;

            // If already filed, not due
            if (!empty($entity['boi_filed_at'])) return null;

            $formedAt = $this->parseFormationDate($entity);
            if (!$formedAt) return null;

            $cutoff = new \DateTime('2024-01-01');

            if ($formedAt < $cutoff) {
                // Pre-2024: deadline was 2025-01-01 — always overdue now
                $deadline = new \DateTime('2025-01-01');
            } else {
                // Post-2024: 30 days from formation
                $deadline = clone $formedAt;
                $deadline->modify('+' . self::DAYS_TO_FILE_NEW . ' days');
            }

            $today = new \DateTime('today');
            $diff  = (int)$today->diff($deadline)->days;
            return $today <= $deadline ? $diff : -$diff;

        } catch (\Throwable $e) {
            error_log('[BOI\Filer] daysUntilDue error intake=' . $intakeId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * All entities with overdue BOI (days_until_due < 0 and not yet filed).
     */
    public function listOverdue(): array
    {
        try {
            return $this->listByDeadlineStatus('overdue');
        } catch (\Throwable $e) {
            error_log('[BOI\Filer] listOverdue error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Entities with BOI due within $daysThreshold days (and not yet filed).
     */
    public function listPending(int $daysThreshold = 7): array
    {
        try {
            return $this->listByDeadlineStatus('pending', $daysThreshold);
        } catch (\Throwable $e) {
            error_log('[BOI\Filer] listPending error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * All entities for this tenant with their BOI status enriched.
     * Returns array of rows with added keys: boi_status, days_until_due, overdue_penalty_usd.
     */
    public function listAll(): array
    {
        try {
            // Join direction: ebi.spawned_entity_id → fe.id
            $stmt = $this->db->prepare(
                "SELECT fe.id AS entity_id, ebi.id AS intake_id, fe.legal_name, fe.dba_name,
                        fe.entity_type, fe.formation_state, fe.formed_at,
                        fe.boi_filed_at, fe.boi_confirmation_hash,
                        ebi.brand_name, NULL AS intake_formed_date,
                        ebi.brand_slug
                 FROM formation_entities fe
                 LEFT JOIN empire_brand_intake ebi
                       ON ebi.spawned_entity_id = fe.id AND ebi.tenant_id = fe.tenant_id
                 WHERE fe.tenant_id = ?
                 ORDER BY fe.legal_name"
            );
            $stmt->execute([$this->tenantId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$row) {
                $days = $this->daysUntilDue((int)$row['intake_id']);
                $row['days_until_due']       = $days;
                $row['boi_status']           = $this->deriveStatus($row, $days);
                $row['overdue_penalty_usd']  = ($days !== null && $days < 0)
                    ? abs($days) * 500
                    : 0;
                // Count owners
                $row['owner_count'] = $this->countOwners((int)$row['intake_id']);
            }
            unset($row);

            return $rows;
        } catch (\Throwable $e) {
            error_log('[BOI\Filer] listAll error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetch all beneficial owners for a given intake.
     */
    public function getOwners(int $intakeId): array
    {
        return $this->fetchOwners($intakeId);
    }

    // ─────────────────────────────────────────────────────────────────────
    // BOIR PAYLOAD BUILDER
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build the BOIR JSON payload per FinCEN BOIR schema v1.0.
     *
     * Schema reference:
     *   https://www.fincen.gov/beneficial-ownership-information-reporting-xml-schema
     *   FinCEN published XSD + sample JSON for the e-filing API. The JSON structure
     *   mirrors the XML schema: top-level keys are filingInfo, reportingCompany,
     *   beneficialOwners[], companyApplicants[] (post-2024 only).
     */
    private function buildPayload(array $entity, array $owners): array
    {
        $formedAt  = $this->parseFormationDate($entity);
        $cutoffDate = new \DateTime('2024-01-01');
        $isPost2024 = $formedAt && ($formedAt >= $cutoffDate);

        $payload = [
            'filingInfo' => [
                'filingType'             => self::FILING_TYPE_INITIAL,
                'filingTypeDescription'  => 'Initial Report',
                'preparedDatetime'       => (new \DateTime())->format(\DateTime::ISO8601),
                'filedBySystemNote'      => 'Generated by DST Empire BOI module — NOT auto-submitted. File manually at https://boiefiling.fincen.gov/',
            ],
            'reportingCompany' => $this->buildReportingCompany($entity, $isPost2024),
            'beneficialOwners' => [],
        ];

        // Company applicants only required for post-2024 entities
        if ($isPost2024) {
            $payload['companyApplicants'] = [];
        }

        foreach ($owners as $owner) {
            if (!empty($owner['is_company_applicant']) && $isPost2024) {
                $payload['companyApplicants'][] = $this->buildOwnerBlock($owner, true);
            } else {
                $payload['beneficialOwners'][] = $this->buildOwnerBlock($owner, false);
            }
        }

        return $payload;
    }

    private function buildReportingCompany(array $entity, bool $isPost2024): array
    {
        $block = [
            'legalName'          => $entity['legal_name'] ?? '',
            'alternateNames'     => [],
            'taxIdentification'  => [
                'type'   => 'EIN',  // EIN or SSN — EIN default for LLCs
                'number' => $entity['ein'] ?? '',
            ],
            'jurisdictionOfFormation' => [
                'countryCode' => 'US',
                'stateCode'   => strtoupper($entity['formation_state'] ?? ''),
            ],
            'formedDate'         => $formedDate = $this->formatDate($this->parseFormationDate($entity)),
            'currentAddress'     => $this->parseAddress($entity['registered_address'] ?? ''),
            'existingReportingCompany' => !$isPost2024,
        ];

        // Add DBA if present
        $dba = trim($entity['dba_name'] ?? '');
        if ($dba !== '') {
            $block['alternateNames'][] = $dba;
        }

        return $block;
    }

    private function buildOwnerBlock(array $owner, bool $isApplicant): array
    {
        $block = [
            'isCompanyApplicant' => $isApplicant,
            'finCENID'           => $owner['fincen_id'] ?? null,
            'legalName'          => [
                'firstName'  => $this->extractFirstName($owner['full_legal_name'] ?? ''),
                'lastName'   => $this->extractLastName($owner['full_legal_name'] ?? ''),
                'middleName' => $this->extractMiddleName($owner['full_legal_name'] ?? ''),
            ],
            'dateOfBirth'        => $owner['date_of_birth'] ?? null,
            'address'            => $this->parseAddress($owner['residential_address_md'] ?? ''),
            'identifyingDocument' => [
                'docType'          => $owner['identifying_doc_type'] ?? '',
                'docNumber'        => $owner['identifying_doc_number'] ?? '',
                'issuingJurisdiction' => $owner['identifying_doc_jurisdiction'] ?? '',
                'docImagePath'     => $owner['identifying_doc_image_path'] ?? null,
            ],
            'ownershipPercentage' => (float)($owner['ownership_pct'] ?? 0),
            'controlRole'         => $owner['control_role'] ?? null,
        ];

        // If FinCEN ID is present, most fields can be omitted per FinCEN rules
        if (!empty($owner['fincen_id'])) {
            return [
                'isCompanyApplicant' => $isApplicant,
                'finCENID'           => $owner['fincen_id'],
                'ownershipPercentage' => (float)($owner['ownership_pct'] ?? 0),
                'controlRole'         => $owner['control_role'] ?? null,
            ];
        }

        return $block;
    }

    // ─────────────────────────────────────────────────────────────────────
    // VALIDATION
    // ─────────────────────────────────────────────────────────────────────

    private function validate(array $entity, array $owners): array
    {
        $errors = [];

        // Entity checks
        if (empty($entity['legal_name'])) {
            $errors[] = 'Entity: legal_name is required.';
        }
        if (empty($entity['formation_state'])) {
            $errors[] = 'Entity: formation_state is required.';
        }
        $formedAt = $this->parseFormationDate($entity);
        if (!$formedAt) {
            $errors[] = 'Entity: formed_at / intake formed_date is missing or unparseable.';
        }
        if (empty($entity['ein'])) {
            $errors[] = 'Entity: EIN is required for BOIR. Add to formation_entities.ein.';
        }

        // Owner checks
        if (empty($owners)) {
            $errors[] = 'No beneficial owners on record. Add at least one owner.';
        }

        foreach ($owners as $i => $owner) {
            $n = $i + 1;
            $label = 'Owner #' . $n . ' (' . ($owner['full_legal_name'] ?? 'unknown') . ')';

            if (empty($owner['fincen_id'])) {
                // Without FinCEN ID, full fields required
                if (empty($owner['full_legal_name'])) {
                    $errors[] = $label . ': full_legal_name required.';
                }
                if (empty($owner['date_of_birth'])) {
                    $errors[] = $label . ': date_of_birth required.';
                }
                if (empty($owner['residential_address_md'])) {
                    $errors[] = $label . ': residential_address_md required.';
                }
                if (empty($owner['identifying_doc_type'])) {
                    $errors[] = $label . ': identifying_doc_type required.';
                }
                if (empty($owner['identifying_doc_number'])) {
                    $errors[] = $label . ': identifying_doc_number required.';
                }
                if (empty($owner['identifying_doc_jurisdiction'])) {
                    $errors[] = $label . ': identifying_doc_jurisdiction required.';
                }
                if (empty($owner['identifying_doc_image_path'])) {
                    $errors[] = $label . ': identifying_doc_image_path (ID scan) required.';
                }
            }

            // Must have either 25%+ ownership OR substantial control
            $hasPct     = (float)($owner['ownership_pct'] ?? 0) >= 25.0;
            $hasControl = !empty($owner['control_role']);
            if (!$hasPct && !$hasControl) {
                $errors[] = $label . ': must have ownership_pct >= 25% OR control_role set. Otherwise this person may not be a beneficial owner.';
            }
        }

        return $errors;
    }

    // ─────────────────────────────────────────────────────────────────────
    // SUMMARY MARKDOWN
    // ─────────────────────────────────────────────────────────────────────

    private function buildSummaryMd(array $entity, array $owners): string
    {
        $formedAt    = $this->parseFormationDate($entity);
        $cutoff      = new \DateTime('2024-01-01');
        $isPost2024  = $formedAt && ($formedAt >= $cutoff);
        $deadlineStr = $isPost2024
            ? '30 days from ' . ($formedAt ? $formedAt->format('Y-m-d') : 'formation')
            : '2025-01-01 (ALREADY PAST — file immediately if not filed)';

        $lines = [];
        $lines[] = '## BOIR Summary — ' . ($entity['legal_name'] ?? 'Unknown Entity');
        $lines[] = '';
        $lines[] = '**Filing type:** Initial Report';
        $lines[] = '**Reporting company:** ' . ($entity['legal_name'] ?? '');
        if (!empty($entity['dba_name'])) {
            $lines[] = '**DBA:** ' . $entity['dba_name'];
        }
        $lines[] = '**EIN:** ' . ($entity['ein'] ?? '— MISSING');
        $lines[] = '**State of formation:** ' . strtoupper($entity['formation_state'] ?? '');
        $lines[] = '**Formed:** ' . ($formedAt ? $formedAt->format('Y-m-d') : '— MISSING');
        $lines[] = '**Pre/Post 2024:** ' . ($isPost2024 ? 'Post-2024 (company applicant required)' : 'Pre-2024 (no company applicant needed — grandfather rule)');
        $lines[] = '**Filing deadline:** ' . $deadlineStr;
        $lines[] = '';
        $lines[] = '### Beneficial Owners (' . count($owners) . ')';
        $lines[] = '';

        foreach ($owners as $i => $owner) {
            $label = ($i + 1) . '. ' . ($owner['full_legal_name'] ?? '—');
            if (!empty($owner['is_company_applicant'])) $label .= ' *(company applicant)*';
            if (!empty($owner['fincen_id'])) $label .= ' — FinCEN ID: ' . $owner['fincen_id'];
            $lines[] = $label;
            if (empty($owner['fincen_id'])) {
                $lines[] = '   - DOB: ' . ($owner['date_of_birth'] ?? '—');
                $lines[] = '   - Address: ' . ($owner['residential_address_md'] ?? '—');
                $lines[] = '   - Doc type: ' . ($owner['identifying_doc_type'] ?? '—') . ' / #' . ($owner['identifying_doc_number'] ?? '—') . ' / ' . ($owner['identifying_doc_jurisdiction'] ?? '—');
                $lines[] = '   - ID image on file: ' . (!empty($owner['identifying_doc_image_path']) ? 'YES' : '**MISSING**');
                $lines[] = '   - Ownership: ' . (float)($owner['ownership_pct'] ?? 0) . '% | Control role: ' . ($owner['control_role'] ?? 'none');
            }
            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '**SUBMIT AT:** https://boiefiling.fincen.gov/';
        $lines[] = 'After submitting, copy the FinCEN confirmation number and click "Mark Filed" in DST Empire.';

        return implode("\n", $lines);
    }

    // ─────────────────────────────────────────────────────────────────────
    // DATA FETCHERS
    // ─────────────────────────────────────────────────────────────────────

    private function fetchEntity(int $intakeId): ?array
    {
        try {
            // Primary: formation_entities (has EIN etc.)
            // Join via empire_brand_intake.spawned_entity_id → formation_entities.id
            $stmt = $this->db->prepare(
                "SELECT fe.*, ebi.brand_name, NULL AS intake_formed_date
                 FROM empire_brand_intake ebi
                 JOIN formation_entities fe
                       ON fe.id = ebi.spawned_entity_id AND fe.tenant_id = ebi.tenant_id
                 WHERE ebi.id = ? AND ebi.tenant_id = ?
                 LIMIT 1"
            );
            $stmt->execute([$intakeId, $this->tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;

            // Fallback: intake only (entity not yet spawned)
            $stmt2 = $this->db->prepare(
                "SELECT id AS intake_id, brand_name AS legal_name, brand_slug,
                        decided_jurisdiction AS formation_state, NULL AS ein,
                        NULL AS formed_at, NULL AS intake_formed_date,
                        NULL AS dba_name, NULL AS registered_address,
                        NULL AS boi_filed_at, NULL AS boi_confirmation_hash,
                        decided_entity_type AS entity_type
                 FROM empire_brand_intake
                 WHERE id = ? AND tenant_id = ?
                 LIMIT 1"
            );
            $stmt2->execute([$intakeId, $this->tenantId]);
            return $stmt2->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            error_log('[BOI\Filer] fetchEntity error: ' . $e->getMessage());
            return null;
        }
    }

    private function fetchOwners(int $intakeId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM beneficial_owners
                 WHERE intake_id = ? AND tenant_id = ?
                 ORDER BY is_company_applicant DESC, ownership_pct DESC, id ASC"
            );
            $stmt->execute([$intakeId, $this->tenantId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[BOI\Filer] fetchOwners error: ' . $e->getMessage());
            return [];
        }
    }

    private function countOwners(int $intakeId): int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM beneficial_owners
                 WHERE intake_id = ? AND tenant_id = ?"
            );
            $stmt->execute([$intakeId, $this->tenantId]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // AUDIT LOG
    // ─────────────────────────────────────────────────────────────────────

    private function logAttempt(
        int    $intakeId,
        string $status,
        array  $errors = [],
        array  $payload = [],
        string $confirmationHash = '',
        string $filedAt = ''
    ): void {
        try {
            // boi_audit_log columns: action, error_md, payload_hash, performed_at
            // 'status' arg maps to 'action'; errors array maps to 'error_md' text;
            // confirmation_hash/filed_at not stored here (markFiled() handles those).
            $actionVal = $status; // e.g. 'prepared', 'validation_fail', 'error' → stored as-is (action ENUM allows these via fallback)
            $stmt = $this->db->prepare(
                "INSERT INTO boi_audit_log
                 (tenant_id, intake_id, action, error_md, payload_hash, performed_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $this->tenantId,
                $intakeId,
                'generated',  // action ENUM: generated|validated|filed|updated|rejected
                !empty($errors) ? implode("\n", $errors) : null,
                !empty($payload) ? hash('sha256', json_encode($payload)) : null,
            ]);
        } catch (\Throwable $e) {
            // Non-fatal — never blow up because audit log is missing
            error_log('[BOI\Filer] logAttempt failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // DEADLINE HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * List entities filtered by overdue or pending status.
     */
    private function listByDeadlineStatus(string $mode, int $daysThreshold = 0): array
    {
        $all    = $this->listAll();
        $result = [];

        foreach ($all as $row) {
            $days = $row['days_until_due'];
            if ($days === null) continue;

            if ($mode === 'overdue' && $days < 0) {
                $result[] = $row;
            } elseif ($mode === 'pending' && $days >= 0 && $days <= $daysThreshold) {
                $result[] = $row;
            }
        }

        // Sort overdue by worst first
        usort($result, fn($a, $b) => ($a['days_until_due'] ?? 0) <=> ($b['days_until_due'] ?? 0));

        return $result;
    }

    private function deriveStatus(array $row, ?int $days): string
    {
        if (!empty($row['boi_filed_at'])) return 'filed';
        if ($days === null)              return 'no_date';
        if ($days < 0)                   return 'overdue';
        if ($days <= 7)                  return 'due_soon';
        if ($days <= 30)                 return 'pending';
        return 'ok';
    }

    private function parseFormationDate(array $entity): ?\DateTime
    {
        $raw = $entity['formed_at'] ?? $entity['intake_formed_date'] ?? null;
        if (!$raw) return null;
        try {
            return new \DateTime($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // STRING UTILS
    // ─────────────────────────────────────────────────────────────────────

    private function formatDate(?\DateTime $dt): ?string
    {
        return $dt ? $dt->format('Y-m-d') : null;
    }

    /**
     * Parse a free-form address string into the FinCEN address block.
     * Expects "123 Main St, City, ST 12345" or similar.
     * For production, beneficial_owners.residential_address_md should be
     * structured (use a JSON column or separate fields in mig 077).
     */
    private function parseAddress(string $raw): array
    {
        $raw = trim($raw);
        if (empty($raw)) {
            return [
                'streetAddress1' => '',
                'city'           => '',
                'stateCode'      => '',
                'zip'            => '',
                'countryCode'    => 'US',
            ];
        }

        // Try to parse "street, city, ST zip" pattern
        $parts = array_map('trim', explode(',', $raw));
        $street = $parts[0] ?? '';
        $city   = $parts[1] ?? '';

        // Last part might be "ST 12345" or "ST 12345-6789"
        $stateZip  = $parts[2] ?? '';
        $stateCode = '';
        $zip       = '';
        if (preg_match('/^([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/i', trim($stateZip), $m)) {
            $stateCode = strtoupper($m[1]);
            $zip       = $m[2];
        }

        return [
            'streetAddress1' => $street,
            'city'           => $city,
            'stateCode'      => $stateCode,
            'zip'            => $zip,
            'countryCode'    => 'US',
        ];
    }

    private function extractFirstName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? '';
    }

    private function extractLastName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        return count($parts) > 1 ? end($parts) : '';
    }

    private function extractMiddleName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        if (count($parts) <= 2) return '';
        // Everything between first and last
        array_shift($parts);
        array_pop($parts);
        return implode(' ', $parts);
    }
}
