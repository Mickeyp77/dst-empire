<?php
/**
 * PortfolioSynthesizer — master orchestrator for the DST Empire synthesis engine.
 *
 * Wires together:
 *   PlaybookRegistry  → runs all playbooks per brand (src/Empire/Playbooks/PlaybookRegistry.php)
 *   OrgChartBuilder   → hierarchical entity graph
 *   CashFlowModel     → per-brand Tax Leakage Waterfall + Sankey
 *   TaxProjector      → Y1–Y5 scenario projections
 *
 * Output is a single JSON-serializable array (see synthesize() return docblock).
 *
 * Depends on schema from migrations 072+073+074+077:
 *   empire_brand_intake (main table)
 *   empire_portfolio_context (owner meta)
 *   compliance_calendar (from 077)
 *
 * NO LLM calls. Pure deterministic aggregation.
 */

namespace Mnmsos\Empire\Synthesis;

use PDO;

class PortfolioSynthesizer
{
    private PDO $db;
    private int $tenantId;

    // Path to PlaybookRegistry — written by parallel A2 agent.
    // Resolved relative to this file's directory.
    private const REGISTRY_CLASS = 'Mnmsos\\Empire\\Playbooks\\PlaybookRegistry';

    public function __construct(PDO $db, int $tenantId)
    {
        $this->db       = $db;
        $this->tenantId = $tenantId;
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Run full portfolio synthesis.
     *
     * Returns:
     * [
     *   'as_of_date'                    => string (ISO8601),
     *   'tenant_id'                     => int,
     *   'aggression_tier'               => string,
     *   'org_chart'                     => array,   // OrgChartBuilder output
     *   'cash_flow'                     => array,   // CashFlowModel output
     *   'tax_projection'                => array,   // TaxProjector output (Y1–Y5)
     *   'risk_register'                 => array,   // aggregated risks across brands
     *   'compliance_calendar'           => array,   // upcoming filings/deadlines
     *   'aggregate_savings_y1_usd'      => float,
     *   'aggregate_savings_5y_usd'      => float,
     *   'aggregate_setup_cost_usd'      => float,
     *   'aggregate_ongoing_cost_usd'    => float,
     *   'roi_5yr'                       => float,   // savings / total costs
     *   'recommended_playbooks_by_brand'=> array,
     *   'unresolved_blockers'           => array,   // MICKEY-QUEUE items
     * ]
     */
    public function synthesize(): array
    {
        $blockers = [];

        // ------------------------------------------------------------------
        // 1. Load intake rows + portfolio context
        // ------------------------------------------------------------------
        $intakeRows   = $this->fetchAllIntake($blockers);
        $portfolioCtx = $this->fetchPortfolioContext($blockers);

        // Determine aggression tier from portfolio context (fallback: growth)
        $aggressionTier = $portfolioCtx['aggression_tier'] ?? 'growth';

        // ------------------------------------------------------------------
        // 2. Run PlaybookRegistry across every brand
        // ------------------------------------------------------------------
        $playbookRecs = $this->runAllPlaybooks($intakeRows, $portfolioCtx, $blockers);

        // ------------------------------------------------------------------
        // 3. Build sub-components in isolation
        // ------------------------------------------------------------------
        $orgChart = (new OrgChartBuilder($this->db, $this->tenantId))
            ->build($intakeRows, $playbookRecs, $portfolioCtx, $blockers);

        $cashFlow = (new CashFlowModel($this->db, $this->tenantId))
            ->model($intakeRows, $playbookRecs, $portfolioCtx, $blockers);

        $taxProjection = (new TaxProjector($this->db, $this->tenantId))
            ->project($intakeRows, $playbookRecs, $portfolioCtx, $blockers);

        // ------------------------------------------------------------------
        // 4. Aggregate risk register
        // ------------------------------------------------------------------
        $riskRegister = $this->aggregateRisks($intakeRows, $playbookRecs);

        // ------------------------------------------------------------------
        // 5. Compliance calendar
        // ------------------------------------------------------------------
        $complianceCalendar = $this->fetchComplianceCalendar($blockers);

        // ------------------------------------------------------------------
        // 6. Aggregate cost + savings metrics
        // ------------------------------------------------------------------
        $aggSetupCost   = 0.0;
        $aggOngoingCost = 0.0;

        foreach ($playbookRecs as $slug => $recs) {
            foreach ($recs as $pb) {
                if (!isset($pb['applies']) || !$pb['applies']) {
                    continue;
                }
                $aggSetupCost   += (float)($pb['estimated_setup_cost_usd'] ?? 0.0);
                $aggOngoingCost += (float)($pb['estimated_ongoing_cost_usd'] ?? 0.0);
            }
        }

        // Also add entity formation costs from BrandPlacement (per intake)
        foreach ($intakeRows as $row) {
            $entityType = $row['decided_entity_type'] ?? 'keep_dba';
            if ($entityType !== 'keep_dba') {
                $jur = $row['decided_jurisdiction'] ?? 'TX';
                // Formation cost estimate (mirrors BrandPlacement::analyze cost_breakdown)
                $formFee   = $this->jurisdictionFormFee($jur);
                $raCost    = 150.0;
                $ftCost    = ($jur === 'DE') ? 300.0 : 0.0;
                $aggSetupCost   += $formFee + $raCost;
                $aggOngoingCost += $raCost + $ftCost;
            }
        }

        $aggSavingsY1 = (float)($cashFlow['portfolio_total']['savings_vs_baseline'] ?? 0.0);
        $aggSavings5y = (float)($taxProjection['cumulative_savings_5yr_usd'] ?? 0.0);

        $totalCosts5y = $aggSetupCost + ($aggOngoingCost * 5);
        $roi5yr       = $totalCosts5y > 0
            ? round($aggSavings5y / $totalCosts5y, 4)
            : null;

        // ------------------------------------------------------------------
        // 7. Collect cross-brand blockers
        // ------------------------------------------------------------------
        $unresolvedBlockers = $this->collectUnresolvedBlockers($intakeRows, $portfolioCtx, $blockers);

        // ------------------------------------------------------------------
        // 8. Assemble final output
        // ------------------------------------------------------------------
        return [
            'as_of_date'                     => date('c'),
            'tenant_id'                      => $this->tenantId,
            'aggression_tier'                => $aggressionTier,
            'org_chart'                      => $orgChart,
            'cash_flow'                      => $cashFlow,
            'tax_projection'                 => $taxProjection,
            'risk_register'                  => $riskRegister,
            'compliance_calendar'            => $complianceCalendar,
            'aggregate_savings_y1_usd'       => round($aggSavingsY1, 2),
            'aggregate_savings_5y_usd'       => round($aggSavings5y, 2),
            'aggregate_setup_cost_usd'       => round($aggSetupCost, 2),
            'aggregate_ongoing_cost_usd'     => round($aggOngoingCost, 2),
            'roi_5yr'                        => $roi5yr,
            'recommended_playbooks_by_brand' => $this->formatRecommended($playbookRecs),
            'unresolved_blockers'            => $unresolvedBlockers,
        ];
    }

    // -----------------------------------------------------------------------
    // PlaybookRegistry integration
    // -----------------------------------------------------------------------

    /**
     * Run all playbooks via PlaybookRegistry::runAll().
     *
     * PlaybookRegistry (A2 parallel agent) is expected at:
     *   src/Empire/Playbooks/PlaybookRegistry.php
     * with static method: PlaybookRegistry::runAll(array $intake, array $portfolioCtx): array
     *
     * If the class does not exist yet (A2 not deployed), we return [] per brand
     * and add a blocker. This keeps PortfolioSynthesizer independently testable.
     */
    private function runAllPlaybooks(array $intakeRows, array $portfolioCtx, array &$blockers): array
    {
        $registryClass = self::REGISTRY_CLASS;

        // Lazy-load if not yet autoloaded
        if (!class_exists($registryClass, false)) {
            $registryPath = dirname(__DIR__) . '/Playbooks/PlaybookRegistry.php';
            if (file_exists($registryPath)) {
                require_once $registryPath;
            }
        }

        if (!class_exists($registryClass, false)) {
            $blockers[] = [
                'type'    => 'missing_dependency',
                'brand'   => null,
                'message' => 'PlaybookRegistry class not found at src/Empire/Playbooks/PlaybookRegistry.php. Deploy A2 playbook agent output to enable playbook evaluation. All playbook-dependent savings will be $0 until resolved.',
                'queue'   => 'MICKEY-QUEUE',
            ];
            // Return empty recs keyed by slug so downstream code gets [] per brand
            $empty = [];
            foreach ($intakeRows as $row) {
                $slug = $row['brand_slug'] ?? null;
                if ($slug) {
                    $empty[$slug] = [];
                }
            }
            return $empty;
        }

        // Registry exists — run it
        $recs = [];
        foreach ($intakeRows as $row) {
            $slug = $row['brand_slug'] ?? null;
            if (!$slug) {
                continue;
            }
            try {
                // PlaybookRegistry::runAll(array $intake, array $portfolioCtx): array
                // Returns array of playbook result arrays (keyed by playbook ID)
                $recs[$slug] = $registryClass::runAll($row, $portfolioCtx);
            } catch (\Throwable $e) {
                $blockers[] = [
                    'type'    => 'playbook_error',
                    'brand'   => $slug,
                    'message' => "PlaybookRegistry::runAll failed for '{$slug}': " . $e->getMessage(),
                    'queue'   => null,
                ];
                $recs[$slug] = [];
            }
        }

        return $recs;
    }

    // -----------------------------------------------------------------------
    // Data fetchers
    // -----------------------------------------------------------------------

    /**
     * Fetch all intake rows for this tenant.
     * Uses direct PDO (mirrors IntakeRepo::list() but avoids static-class dependency).
     */
    private function fetchAllIntake(array &$blockers): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM empire_brand_intake WHERE tenant_id = ? ORDER BY tier DESC, id ASC"
            );
            $stmt->execute([$this->tenantId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                $blockers[] = [
                    'type'    => 'empty_portfolio',
                    'brand'   => null,
                    'message' => 'No brand intake records found for tenant ' . $this->tenantId . '. Add brands via /empire/intake.php before running synthesis.',
                    'queue'   => 'MICKEY-QUEUE',
                ];
            }
            return $rows;
        } catch (\Throwable $e) {
            $blockers[] = [
                'type'    => 'db_error',
                'brand'   => null,
                'message' => 'Failed to fetch empire_brand_intake: ' . $e->getMessage(),
                'queue'   => 'MICKEY-QUEUE',
            ];
            return [];
        }
    }

    /**
     * Fetch empire_portfolio_context row for this tenant.
     * Returns empty array if table missing or no row (mig 077 may not be applied yet).
     */
    private function fetchPortfolioContext(array &$blockers): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM empire_portfolio_context WHERE tenant_id = ? LIMIT 1"
            );
            $stmt->execute([$this->tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $blockers[] = [
                    'type'    => 'missing_portfolio_context',
                    'brand'   => null,
                    'message' => 'empire_portfolio_context row missing for tenant ' . $this->tenantId . '. Apply migration 077 and fill in owner data (age, domicile, estate plan, IRS status) to unlock full synthesis.',
                    'queue'   => 'MICKEY-QUEUE',
                ];
                return [];
            }
            return $row;
        } catch (\Throwable $e) {
            // Table may not exist pre-mig-077
            $blockers[] = [
                'type'    => 'missing_schema',
                'brand'   => null,
                'message' => 'empire_portfolio_context table missing — apply migration 077 (LOCAL DEV ONLY — do not push to prod Galera without Mickey approval). Error: ' . $e->getMessage(),
                'queue'   => 'MICKEY-QUEUE',
            ];
            return [];
        }
    }

    /**
     * Fetch upcoming compliance calendar items for the next 90 days.
     */
    private function fetchComplianceCalendar(array &$blockers): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM compliance_calendar
                 WHERE tenant_id = ? AND due_date >= CURDATE() AND status NOT IN ('completed','waived')
                 ORDER BY due_date ASC
                 LIMIT 50"
            );
            $stmt->execute([$this->tenantId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            // Table missing (pre-mig-077) — not a hard blocker, return empty
            return [];
        }
    }

    // -----------------------------------------------------------------------
    // Risk aggregation
    // -----------------------------------------------------------------------

    /**
     * Aggregate risk entries across all brands from playbook results.
     * Adds hardcoded known risks from BrandPlacement analysis (Canon TM, VoltOps IP, etc.)
     */
    private function aggregateRisks(array $intakeRows, array $playbookRecs): array
    {
        $risks = [];

        // Known structural risks from BrandPlacement/intake analysis
        foreach ($intakeRows as $row) {
            $slug = $row['brand_slug'] ?? '';
            $liab = $row['liability_profile'] ?? '';

            // Canon trademark risk — always present for these slugs
            if (in_array($slug, ['canonservice', 'canonparts'], true)) {
                $risks[] = [
                    'brand'       => $slug,
                    'risk_id'     => 'canon_trademark',
                    'severity'    => 'high',
                    'title'       => 'Canon Inc. trademark conflict',
                    'description' => '"Canon" is a registered trademark of Canon Inc. Do not use in legal entity name. SOS TX/DE will likely reject filing.',
                    'mitigation'  => 'File as "Parts Direct LLC" or similar. DBA as canonparts.com is fine for domain/marketing.',
                    'irc'         => '15 U.S.C. §1114 (Lanham Act)',
                ];
            }

            // VoltOps IP assignment risk
            if ($slug === 'voltops') {
                $risks[] = [
                    'brand'       => $slug,
                    'risk_id'     => 'voltops_ip_valuation',
                    'severity'    => 'high',
                    'title'       => 'VoltOps IP assignment valuation complexity',
                    'description' => 'IP lives in mnmsos-saas codebase. Sabrina majority-ownership of MNMS LLC creates IRC §351 valuation complexity. Assignment without FMV creates phantom income risk.',
                    'mitigation'  => 'Get IP assignment valued by qualified appraiser before C-Corp formation. Consider §351 exchange to avoid recognition.',
                    'irc'         => '§351 (control-group exchange), §482 (arm\'s-length)',
                ];

                // 83(b) election deadline risk
                $risks[] = [
                    'brand'       => $slug,
                    'risk_id'     => 'voltops_83b_deadline',
                    'severity'    => 'critical',
                    'title'       => '83(b) election — 30-day hard deadline',
                    'description' => 'If VoltOps issues founder restricted stock, §83(b) election must be filed within 30 days of transfer. Missing this deadline is unrecoverable — cannot be fixed retroactively.',
                    'mitigation'  => 'File IRS Form 15620 within 30 days of stock issuance. Calendar immediately. No extension possible.',
                    'irc'         => '§83(b), IRS Form 15620',
                ];
            }

            // Series LLC parent formation risk
            if (in_array($slug, ['usedcopierparts', 'usedprintersales', 'canonparts'], true)) {
                $risks[] = [
                    'brand'       => $slug,
                    'risk_id'     => 'series_parent_required',
                    'severity'    => 'medium',
                    'title'       => 'Series LLC cell requires parent entity first',
                    'description' => "Brand '{$slug}' is a Series LLC cell candidate. Parent PrintIt LLC (Series) must be formed before cells can be added.",
                    'mitigation'  => 'File PrintIt Series LLC in TX/DE before any cell formation.',
                    'irc'         => 'TX BOC §101.601 (Series LLC)',
                ];
            }

            // High-liability brands without entity wrapper
            $entityType = $row['decided_entity_type'] ?? $row['entity_type'] ?? 'keep_dba';
            if (in_array($liab, ['med_high', 'high'], true) && $entityType === 'keep_dba') {
                $risks[] = [
                    'brand'       => $slug,
                    'risk_id'     => 'unprotected_high_liability_' . $slug,
                    'severity'    => 'high',
                    'title'       => "High-liability brand '{$slug}' operating as DBA",
                    'description' => 'No entity wrapper. Personal assets of MNMS LLC owner exposed to claims from this brand.',
                    'mitigation'  => 'Form LLC in appropriate jurisdiction. Priority filing.',
                    'irc'         => 'State law LLC Act',
                ];
            }
        }

        // Collect risk entries from playbook results
        foreach ($playbookRecs as $slug => $recs) {
            foreach ($recs as $pb) {
                if (!isset($pb['applies']) || !$pb['applies']) {
                    continue;
                }
                $riskLevel = $pb['risk_level'] ?? 'low';
                if ($riskLevel === 'low') {
                    continue; // Only surface medium+ risks in register
                }
                $risks[] = [
                    'brand'       => $slug,
                    'risk_id'     => ($pb['playbook_id'] ?? 'pb') . '_risk_' . $slug,
                    'severity'    => $riskLevel,
                    'title'       => ($pb['name'] ?? 'Playbook') . ' — ' . $riskLevel . ' risk',
                    'description' => $pb['gotchas_md'] ?? '',
                    'mitigation'  => implode('; ', (array)($pb['next_actions'] ?? [])),
                    'audit_visibility' => $pb['audit_visibility'] ?? 'medium',
                    'irc'         => implode(', ', (array)($pb['citations'] ?? [])),
                ];
            }
        }

        // Sort: critical first, then high, medium, low
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($risks, fn($a, $b) =>
            ($order[$a['severity']] ?? 4) <=> ($order[$b['severity']] ?? 4)
        );

        return $risks;
    }

    // -----------------------------------------------------------------------
    // Blocker aggregation
    // -----------------------------------------------------------------------

    /**
     * Collect all cross-brand blockers: data gaps + known gating items.
     * Includes §5 Sabrina questions as explicit blockers.
     */
    private function collectUnresolvedBlockers(
        array $intakeRows,
        array $portfolioCtx,
        array &$blockers
    ): array {
        // Sabrina §5 Qs — always present until answered (from caveman plan + morning briefing)
        $sabrinarBlockers = [
            [
                'type'    => 'sabrina_approval',
                'brand'   => 'voltops',
                'message' => 'SABRINA Q1: Ownership split on VoltOps C-Corp — what % does Sabrina want vs Mickey? This determines cap table, voting rights, and §1202 QSBS allocations. GATE for all VoltOps filings.',
                'queue'   => 'MICKEY-QUEUE',
            ],
            [
                'type'    => 'sabrina_approval',
                'brand'   => 'all',
                'message' => 'SABRINA Q2: QBO architecture — 1 file with class tracking vs 9 separate files? $6k/yr swing in accounting fees. Decision required before any entity formation to avoid retroactive book migration.',
                'queue'   => 'MICKEY-QUEUE',
            ],
            [
                'type'    => 'sabrina_approval',
                'brand'   => 'all',
                'message' => 'SABRINA Q3: Banking strategy — 9 separate accounts vs 1 master + sub-accounts? Affects veil-piercing defense and QBO reconciliation complexity.',
                'queue'   => 'MICKEY-QUEUE',
            ],
            [
                'type'    => 'sabrina_approval',
                'brand'   => 'all',
                'message' => 'SABRINA Q4: Sale horizons per brand — confirm which brands are "never sell" vs "5-7yr exit." Drives jurisdiction choice (DE vs TX/WY), trust structure, and §1202 clock priority.',
                'queue'   => 'MICKEY-QUEUE',
            ],
            [
                'type'    => 'sabrina_approval',
                'brand'   => 'all',
                'message' => 'SABRINA Q5: Insurance assignment — which policies transfer to new entities? Workers comp, GL, E&O must follow the operating entity or coverage lapses.',
                'queue'   => 'MICKEY-QUEUE',
            ],
        ];

        // Check for missing BOI data on any spawned entity
        foreach ($intakeRows as $row) {
            $spawnedId  = $row['spawned_entity_id'] ?? null;
            $decStatus  = $row['decision_status'] ?? '';
            $slug       = $row['brand_slug'] ?? '';

            if ($spawnedId && $decStatus === 'locked') {
                // Check if beneficial_owners row exists for this entity
                try {
                    $stmt = $this->db->prepare(
                        "SELECT COUNT(*) FROM beneficial_owners WHERE entity_id = ? LIMIT 1"
                    );
                    $stmt->execute([$spawnedId]);
                    $count = (int)$stmt->fetchColumn();
                    if ($count === 0) {
                        $blockers[] = [
                            'type'    => 'boi_missing',
                            'brand'   => $slug,
                            'message' => "Entity #{$spawnedId} ({$slug}) is spawned but has no beneficial_owners row. FinCEN BOI filing required within 30 days of formation (CTA effective 2024). Add owner data via /empire/boi.php.",
                            'queue'   => 'MICKEY-QUEUE',
                        ];
                    }
                } catch (\Throwable $e) {
                    // Table may not exist pre-mig-077 — skip silently
                }
            }
        }

        // Check portfolio context completeness
        $requiredCtxFields = ['owner_age', 'owner_domicile_state', 'estate_plan_status', 'irs_status'];
        foreach ($requiredCtxFields as $field) {
            if (empty($portfolioCtx[$field])) {
                $blockers[] = [
                    'type'    => 'missing_portfolio_field',
                    'brand'   => null,
                    'message' => "empire_portfolio_context.{$field} is empty. Fill in owner profile to enable trust threshold analysis and state tax modeling.",
                    'queue'   => 'MICKEY-QUEUE',
                ];
            }
        }

        // Merge Sabrina blockers with accumulated data-gap blockers
        return array_merge($sabrinarBlockers, $blockers);
    }

    // -----------------------------------------------------------------------
    // Output formatting
    // -----------------------------------------------------------------------

    /**
     * Format playbook recs into a clean summary keyed by brand_slug.
     * Only includes applied playbooks; strips verbose markdown fields for list view.
     */
    private function formatRecommended(array $playbookRecs): array
    {
        $out = [];
        foreach ($playbookRecs as $slug => $recs) {
            $applied = [];
            foreach ($recs as $pb) {
                if (!isset($pb['applies']) || !$pb['applies']) {
                    continue;
                }
                $applied[] = [
                    'playbook_id'                => $pb['playbook_id'] ?? '',
                    'name'                       => $pb['name'] ?? '',
                    'code_section'               => $pb['code_section'] ?? '',
                    'aggression_tier'            => $pb['aggression_tier'] ?? '',
                    'category'                   => $pb['category'] ?? '',
                    'applicability_score'        => $pb['applicability_score'] ?? 0,
                    'estimated_savings_y1_usd'   => $pb['estimated_savings_y1_usd'] ?? 0.0,
                    'estimated_savings_5y_usd'   => $pb['estimated_savings_5y_usd'] ?? 0.0,
                    'estimated_setup_cost_usd'   => $pb['estimated_setup_cost_usd'] ?? 0.0,
                    'estimated_ongoing_cost_usd' => $pb['estimated_ongoing_cost_usd'] ?? 0.0,
                    'risk_level'                 => $pb['risk_level'] ?? 'low',
                    'audit_visibility'           => $pb['audit_visibility'] ?? 'low',
                    'docs_required'              => $pb['docs_required'] ?? [],
                    'next_actions'               => $pb['next_actions'] ?? [],
                    'citations'                  => $pb['citations'] ?? [],
                ];
            }
            if (!empty($applied)) {
                $out[$slug] = $applied;
            }
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Static helpers
    // -----------------------------------------------------------------------

    /** Formation fee by jurisdiction (mirrors BrandPlacement cost logic). */
    private function jurisdictionFormFee(string $jur): float
    {
        return match(strtoupper($jur)) {
            'DE'    => 90.0,   // LLC formation
            'WY'    => 100.0,
            'NV'    => 75.0,
            'SD'    => 165.0,
            'TX'    => 300.0,
            default => 100.0,
        };
    }
}
