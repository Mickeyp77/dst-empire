<?php
/**
 * CashFlowModel — per-brand Tax Leakage Waterfall and Sankey diagram data.
 *
 * Traces $1 of revenue through:
 *   Gross revenue → ops costs → intercompany flows (royalty, mgmt fee)
 *   → entity-level tax → retained earnings / distributions → owner tax
 *   → after-tax cash
 *
 * Produces two parallel waterfalls: "with playbooks" vs "status quo baseline"
 * so the UI can show the delta as the "leakage plugged."
 *
 * 2026 tax constants (same as TaxProjector — keep in sync):
 *   C-Corp: 21% §11(b)
 *   DE state corp: 8.7% Title 30 §1102
 *   TX: 0% (no corporate income tax, franchise tax separate)
 *   WY: 0%
 *   Pass-through top: 37% §1(j)(2)(D) + NIIT 3.8% §1411
 *   §199A QBI: 20% deduction on qualifying pass-through
 *
 * NO LLM calls. Pure deterministic math.
 */

namespace Mnmsos\Empire\Synthesis;

use PDO;

class CashFlowModel
{
    // 2026 rates
    private const CCORP_FED   = 0.21;
    private const IND_TOP     = 0.37;
    private const NIIT        = 0.038;
    private const QBI_DEDUCT  = 0.20;   // §199A

    private const STATE_RATES = [
        'TX' => ['corp' => 0.0,    'individual' => 0.0],
        'WY' => ['corp' => 0.0,    'individual' => 0.0],
        'NV' => ['corp' => 0.0,    'individual' => 0.0],
        'SD' => ['corp' => 0.0,    'individual' => 0.0],
        'DE' => ['corp' => 0.087,  'individual' => 0.066],  // Title 30 §1102
        'FL' => ['corp' => 0.055,  'individual' => 0.0],
        'CA' => ['corp' => 0.088,  'individual' => 0.133],
    ];

    private PDO $db;
    private int $tenantId;

    public function __construct(PDO $db, int $tenantId)
    {
        $this->db       = $db;
        $this->tenantId = $tenantId;
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Build per-brand + portfolio cash flow model.
     *
     * @param array $intakeRows    Rows from empire_brand_intake (mig 077 cols present)
     * @param array $playbookRecs  Keyed by brand_slug → array of playbook result arrays
     * @param array $portfolioCtx  Row from empire_portfolio_context
     * @param array &$blockers     Appended in-place for data gaps
     */
    public function model(
        array $intakeRows,
        array $playbookRecs,
        array $portfolioCtx,
        array &$blockers = []
    ): array {
        $perBrand         = [];
        $portfolioRevenue = 0.0;
        $portfolioBaseline = []; // flow_steps accumulator for portfolio total

        $ownerDomicile   = $portfolioCtx['owner_domicile_state'] ?? 'TX';
        $ownerStateRates = self::STATE_RATES[$ownerDomicile] ?? ['corp' => 0.0, 'individual' => 0.0];

        foreach ($intakeRows as $row) {
            $slug = $row['brand_slug'] ?? null;
            if (!$slug) {
                continue;
            }

            $revenue = isset($row['annual_revenue_usd']) && $row['annual_revenue_usd'] !== null
                ? (float)$row['annual_revenue_usd']
                : null;

            if ($revenue === null) {
                $blockers[] = [
                    'type'    => 'missing_revenue',
                    'brand'   => $slug,
                    'message' => "Brand '{$slug}' has null annual_revenue_usd — CashFlowModel skipped. Update intake to enable waterfall.",
                    'queue'   => 'MICKEY-QUEUE',
                ];
                $perBrand[$slug] = $this->nullResult($slug, $row);
                continue;
            }

            if ($revenue <= 0) {
                $perBrand[$slug] = $this->zeroResult($slug, $row);
                continue;
            }

            $entityType = $row['decided_entity_type'] ?? 'llc';
            $jur        = $row['decided_jurisdiction'] ?? 'TX';
            $entityRates = self::STATE_RATES[$jur] ?? ['corp' => 0.0, 'individual' => 0.0];
            $opsCostPct  = $this->opsCostPct($row);
            $brandPbRecs = $playbookRecs[$slug] ?? [];

            // Waterfall WITH playbooks applied
            $withPb = $this->buildWaterfall(
                slug: $slug,
                revenue: $revenue,
                row: $row,
                entityType: $entityType,
                entityStateRate: $entityRates['corp'],
                ownerStateRate: $ownerStateRates['individual'],
                opsCostPct: $opsCostPct,
                playbookRecs: $brandPbRecs,
                applyPlaybooks: true
            );

            // Waterfall WITHOUT playbooks (status quo baseline)
            $baseline = $this->buildWaterfall(
                slug: $slug,
                revenue: $revenue,
                row: $row,
                entityType: $entityType,
                entityStateRate: $entityRates['corp'],
                ownerStateRate: $ownerStateRates['individual'],
                opsCostPct: $opsCostPct,
                playbookRecs: [],
                applyPlaybooks: false
            );

            $afterTaxWith     = $withPb['after_tax_cash'];
            $afterTaxBaseline = $baseline['after_tax_cash'];
            $savings          = round($afterTaxWith - $afterTaxBaseline, 2);

            $perBrand[$slug] = [
                'brand_slug'           => $slug,
                'brand_name'           => $row['brand_name'] ?? $slug,
                'revenue_y1_usd'       => $revenue,
                'entity_type'          => $entityType,
                'jurisdiction'         => $jur,
                'flow_steps'           => $withPb['steps'],
                'baseline_steps'       => $baseline['steps'],
                'after_tax_cash_y1'    => $afterTaxWith,
                'baseline_after_tax'   => $afterTaxBaseline,
                'savings_vs_baseline'  => $savings,
                'effective_tax_rate'   => $revenue > 0 ? round(($revenue - $afterTaxWith) / $revenue, 4) : null,
                'baseline_effective_rate' => $revenue > 0 ? round(($revenue - $afterTaxBaseline) / $revenue, 4) : null,
            ];

            $portfolioRevenue += $revenue;
            // Accumulate for portfolio total (parallel to per-brand logic)
            $this->accumulatePortfolio($portfolioBaseline, $withPb['steps'], $baseline['steps'], $revenue);
        }

        // ----------------------------------------------------------------
        // Portfolio totals
        // ----------------------------------------------------------------
        $portfolioTotal = $this->buildPortfolioTotal($portfolioBaseline, $perBrand);

        // ----------------------------------------------------------------
        // Sankey nodes + links
        // ----------------------------------------------------------------
        [$sankeyNodes, $sankeyLinks] = $this->buildSankey($perBrand, $portfolioCtx);

        return [
            'per_brand'        => $perBrand,
            'portfolio_total'  => $portfolioTotal,
            'sankey_nodes'     => $sankeyNodes,
            'sankey_links'     => $sankeyLinks,
        ];
    }

    // -----------------------------------------------------------------------
    // Waterfall builder
    // -----------------------------------------------------------------------

    /**
     * Build a single-brand cash flow waterfall (with or without playbooks).
     * Returns ['steps' => [...], 'after_tax_cash' => float].
     */
    private function buildWaterfall(
        string $slug,
        float  $revenue,
        array  $row,
        string $entityType,
        float  $entityStateRate,
        float  $ownerStateRate,
        float  $opsCostPct,
        array  $playbookRecs,
        bool   $applyPlaybooks
    ): array {
        $steps = [];

        // --- Step 1: Gross revenue ---
        $steps[] = ['label' => 'Gross revenue', 'amount' => $revenue, 'type' => 'inflow'];

        // --- Step 2: Ops cost ---
        $opsCost = -round($revenue * $opsCostPct, 2);
        $steps[] = ['label' => 'Operating costs', 'amount' => $opsCost, 'type' => 'outflow'];
        $afterOps = $revenue + $opsCost;

        // --- Step 3: Intercompany flows (only when playbooks applied) ---
        $royaltyPct  = 0.0;
        $mgmtFeePct  = 0.0;
        $royaltyAmt  = 0.0;
        $mgmtFeeAmt  = 0.0;

        if ($applyPlaybooks) {
            foreach ($playbookRecs as $pb) {
                if (!isset($pb['applies']) || !$pb['applies']) {
                    continue;
                }
                if (!empty($pb['royalty_pct'])) {
                    $royaltyPct = max($royaltyPct, (float)$pb['royalty_pct']);
                }
                if (!empty($pb['mgmt_fee_pct'])) {
                    $mgmtFeePct = max($mgmtFeePct, (float)$pb['mgmt_fee_pct']);
                }
            }
            // Default intercompany flows if T3+ brand has own entity
            $tier = $row['tier'] ?? 'T1';
            if ($entityType !== 'keep_dba' && in_array($tier, ['T3', 'T4', 'T5'], true)) {
                $royaltyPct = $royaltyPct ?: 5.0;
                $mgmtFeePct = $mgmtFeePct ?: 10.0;
            }
        }

        if ($royaltyPct > 0) {
            $royaltyAmt = -round($afterOps * ($royaltyPct / 100), 2);
            $steps[] = [
                'label' => "IP royalty to MNMS IP Holdings ({$royaltyPct}%)",
                'amount' => $royaltyAmt,
                'type'  => 'intercompany',
                'irc'   => '§482 arm\'s-length standard',
            ];
        }

        if ($mgmtFeePct > 0) {
            $mgmtFeeAmt = -round($afterOps * ($mgmtFeePct / 100), 2);
            $steps[] = [
                'label' => "Mgmt fee to MNMS LLC ({$mgmtFeePct}%)",
                'amount' => $mgmtFeeAmt,
                'type'  => 'intercompany',
                'irc'   => '§482 arm\'s-length standard',
            ];
        }

        $preTaxIncome = $afterOps + $royaltyAmt + $mgmtFeeAmt;
        $steps[] = ['label' => 'Pre-tax income', 'amount' => round($preTaxIncome, 2), 'type' => 'subtotal'];

        // --- Step 4: Entity-level taxes ---
        $entityTax  = 0.0;
        $retained   = 0.0;

        if ($entityType === 'c_corp') {
            // C-Corp pays entity-level tax (§11(b) 21% + state)
            $fedCorpTax   = round($preTaxIncome * self::CCORP_FED, 2);
            $stateCorpTax = round($preTaxIncome * $entityStateRate, 2);

            $steps[] = [
                'label'  => 'Fed C-Corp tax (21%)',
                'amount' => -$fedCorpTax,
                'type'   => 'tax',
                'irc'    => '§11(b)',
            ];

            if ($stateCorpTax > 0) {
                $stateName = array_search(['corp' => $entityStateRate, 'individual' => 0.0], self::STATE_RATES)
                    ?? array_key_first(array_filter(self::STATE_RATES, fn($r) => $r['corp'] === $entityStateRate))
                    ?? 'state';
                $steps[] = [
                    'label'  => "State corp tax ({$entityStateRate})",
                    'amount' => -$stateCorpTax,
                    'type'   => 'tax',
                    'irc'    => 'State law',
                ];
            }

            $entityTax = $fedCorpTax + $stateCorpTax;
            $retained  = $preTaxIncome - $entityTax;
            $steps[] = ['label' => 'Retained (C-Corp)', 'amount' => round($retained, 2), 'type' => 'subtotal'];

            // Dividend distribution to owner (assume 100% distribution for modeling)
            $qualifiedDivRate = 0.20; // §1(h)(11) QLTCG rate for qualified dividends
            $niitOnDiv        = round($retained * self::NIIT, 2);
            $divTax           = round($retained * $qualifiedDivRate, 2);

            $steps[] = [
                'label'  => 'Qualified dividend tax (20%)',
                'amount' => -$divTax,
                'type'   => 'tax',
                'irc'    => '§1(h)(11)',
            ];
            $steps[] = [
                'label'  => 'NIIT on dividend (3.8%)',
                'amount' => -$niitOnDiv,
                'type'   => 'tax',
                'irc'    => '§1411',
            ];

            $afterOwnerTax = $retained - $divTax - $niitOnDiv;

        } else {
            // Pass-through (LLC/S-Corp): no entity-level income tax
            $steps[] = [
                'label'  => 'Entity-level tax (pass-through — $0)',
                'amount' => 0.0,
                'type'   => 'tax',
                'irc'    => '§1363 (S-Corp) / §701 (partnership)',
            ];

            // §199A QBI deduction reduces effective rate (only if applyPlaybooks or conservative baseline)
            $qbiDeduction = $applyPlaybooks
                ? round($preTaxIncome * self::QBI_DEDUCT, 2)
                : 0.0;

            if ($qbiDeduction > 0) {
                $steps[] = [
                    'label'  => '§199A QBI deduction (20%)',
                    'amount' => $qbiDeduction, // positive — reduces taxable income
                    'type'   => 'deduction',
                    'irc'    => '§199A',
                ];
            }

            $taxablePassThrough = $preTaxIncome - $qbiDeduction;
            $fedIndividualTax   = round($taxablePassThrough * self::IND_TOP, 2);
            $niitPassive        = round($preTaxIncome * 0.20 * self::NIIT, 2); // 20% passive assumption
            $stateIndTax        = round($taxablePassThrough * $ownerStateRate, 2);

            $steps[] = [
                'label'  => 'Fed individual tax (37%)',
                'amount' => -$fedIndividualTax,
                'type'   => 'tax',
                'irc'    => '§1(j)(2)(D)',
            ];

            if ($niitPassive > 0) {
                $steps[] = [
                    'label'  => 'NIIT passive portion (3.8%)',
                    'amount' => -$niitPassive,
                    'type'   => 'tax',
                    'irc'    => '§1411',
                ];
            }

            if ($stateIndTax > 0) {
                $steps[] = [
                    'label'  => "State individual tax ({$ownerStateRate})",
                    'amount' => -$stateIndTax,
                    'type'   => 'tax',
                    'irc'    => 'State law',
                ];
            }

            $afterOwnerTax = $preTaxIncome - $fedIndividualTax - $niitPassive - $stateIndTax;
        }

        $steps[] = [
            'label'  => 'After-tax cash (owner)',
            'amount' => round($afterOwnerTax, 2),
            'type'   => 'result',
        ];

        return [
            'steps'          => $steps,
            'after_tax_cash' => round($afterOwnerTax, 2),
        ];
    }

    // -----------------------------------------------------------------------
    // Portfolio aggregation
    // -----------------------------------------------------------------------

    private function accumulatePortfolio(array &$acc, array $withSteps, array $baselineSteps, float $revenue): void
    {
        // Track by label → sum amounts
        foreach ($withSteps as $step) {
            $label = $step['label'];
            if (!isset($acc['with'][$label])) {
                $acc['with'][$label] = ['amount' => 0.0, 'type' => $step['type'], 'irc' => $step['irc'] ?? ''];
            }
            $acc['with'][$label]['amount'] += $step['amount'];
        }
        foreach ($baselineSteps as $step) {
            $label = $step['label'];
            if (!isset($acc['baseline'][$label])) {
                $acc['baseline'][$label] = ['amount' => 0.0, 'type' => $step['type'], 'irc' => $step['irc'] ?? ''];
            }
            $acc['baseline'][$label]['amount'] += $step['amount'];
        }
    }

    private function buildPortfolioTotal(array $acc, array $perBrand): array
    {
        $totalRevenue      = array_sum(array_column(array_values($perBrand), 'revenue_y1_usd'));
        $totalAfterTax     = 0.0;
        $totalBaselineAT   = 0.0;
        $totalSavings      = 0.0;

        foreach ($perBrand as $brand) {
            if (isset($brand['after_tax_cash_y1'])) {
                $totalAfterTax   += (float)$brand['after_tax_cash_y1'];
                $totalBaselineAT += (float)($brand['baseline_after_tax'] ?? $brand['after_tax_cash_y1']);
                $totalSavings    += (float)($brand['savings_vs_baseline'] ?? 0.0);
            }
        }

        $withSteps = [];
        foreach ($acc['with'] ?? [] as $label => $data) {
            $withSteps[] = ['label' => $label, 'amount' => round($data['amount'], 2), 'type' => $data['type']];
        }
        $baselineSteps = [];
        foreach ($acc['baseline'] ?? [] as $label => $data) {
            $baselineSteps[] = ['label' => $label, 'amount' => round($data['amount'], 2), 'type' => $data['type']];
        }

        return [
            'revenue_y1_usd'       => round($totalRevenue, 2),
            'flow_steps'           => $withSteps,
            'baseline_steps'       => $baselineSteps,
            'after_tax_cash_y1'    => round($totalAfterTax, 2),
            'baseline_after_tax'   => round($totalBaselineAT, 2),
            'savings_vs_baseline'  => round($totalSavings, 2),
            'effective_tax_rate'   => $totalRevenue > 0
                ? round(($totalRevenue - $totalAfterTax) / $totalRevenue, 4)
                : null,
            'baseline_effective_rate' => $totalRevenue > 0
                ? round(($totalRevenue - $totalBaselineAT) / $totalRevenue, 4)
                : null,
        ];
    }

    // -----------------------------------------------------------------------
    // Sankey diagram data
    // -----------------------------------------------------------------------

    /**
     * Build Sankey nodes + links for Canvas 2D renderer.
     * Format: nodes = [{id, label}], links = [{source, target, value, type}]
     */
    private function buildSankey(array $perBrand, array $portfolioCtx): array
    {
        $nodeIds = [];
        $links   = [];

        $addNode = function (string $id, string $label) use (&$nodeIds) {
            if (!isset($nodeIds[$id])) {
                $nodeIds[$id] = $label;
            }
        };

        $addNode('gross_revenue', 'Gross Revenue');
        $addNode('ops_costs', 'Operating Costs');
        $addNode('intercompany', 'Intercompany (Royalty/Mgmt)');
        $addNode('entity_tax', 'Entity Tax');
        $addNode('owner_tax', 'Owner Tax (Fed+State+NIIT)');
        $addNode('after_tax_cash', 'After-Tax Cash');

        foreach ($perBrand as $slug => $brand) {
            if (!isset($brand['revenue_y1_usd']) || $brand['revenue_y1_usd'] <= 0) {
                continue;
            }
            $rev       = $brand['revenue_y1_usd'];
            $afterTax  = $brand['after_tax_cash_y1'] ?? 0;
            $opsCost   = 0.0;
            $interco   = 0.0;
            $entityTax = 0.0;
            $ownerTax  = 0.0;

            foreach ($brand['flow_steps'] ?? [] as $step) {
                $amt = (float)$step['amount'];
                switch ($step['type']) {
                    case 'outflow':
                        $opsCost += abs($amt);
                        break;
                    case 'intercompany':
                        $interco += abs($amt);
                        break;
                    case 'tax':
                        // Distinguish entity vs owner tax by label
                        if (strpos($step['label'], 'C-Corp') !== false || strpos($step['label'], 'corp tax') !== false) {
                            $entityTax += abs($amt);
                        } else {
                            $ownerTax += abs($amt);
                        }
                        break;
                }
            }

            $addNode("brand_{$slug}", $brand['brand_name'] ?? $slug);
            $links[] = ['source' => 'gross_revenue', 'target' => "brand_{$slug}", 'value' => $rev, 'type' => 'inflow'];
            if ($opsCost > 0) {
                $links[] = ['source' => "brand_{$slug}", 'target' => 'ops_costs', 'value' => round($opsCost, 2), 'type' => 'outflow'];
            }
            if ($interco > 0) {
                $links[] = ['source' => "brand_{$slug}", 'target' => 'intercompany', 'value' => round($interco, 2), 'type' => 'intercompany'];
            }
            if ($entityTax > 0) {
                $links[] = ['source' => "brand_{$slug}", 'target' => 'entity_tax', 'value' => round($entityTax, 2), 'type' => 'tax'];
            }
            if ($ownerTax > 0) {
                $links[] = ['source' => "brand_{$slug}", 'target' => 'owner_tax', 'value' => round($ownerTax, 2), 'type' => 'tax'];
            }
            if ($afterTax > 0) {
                $links[] = ['source' => "brand_{$slug}", 'target' => 'after_tax_cash', 'value' => round($afterTax, 2), 'type' => 'result'];
            }
        }

        $nodes = [];
        foreach ($nodeIds as $id => $label) {
            $nodes[] = ['id' => $id, 'label' => $label];
        }

        return [$nodes, $links];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Estimate ops cost as % of revenue.
     * Uses ebitda_usd if available: ops_cost_pct = 1 - (ebitda/revenue).
     * Falls back to tier-based defaults.
     */
    private function opsCostPct(array $row): float
    {
        $rev   = (float)($row['annual_revenue_usd'] ?? 0);
        $ebit  = isset($row['ebitda_usd']) && $row['ebitda_usd'] !== null ? (float)$row['ebitda_usd'] : null;

        if ($rev > 0 && $ebit !== null) {
            $margin = $ebit / $rev;
            // EBITDA margin → ops cost is complement, but cap between 10–90%
            return max(0.10, min(0.90, 1.0 - $margin));
        }

        // Tier-based fallback
        return match($row['tier'] ?? 'T2') {
            'T5'    => 0.20, // High-revenue, high-margin businesses
            'T4'    => 0.35,
            'T3'    => 0.45,
            'T2'    => 0.55,
            default => 0.65, // T1 / unknown — high ops cost assumed
        };
    }

    private function nullResult(string $slug, array $row): array
    {
        return [
            'brand_slug'          => $slug,
            'brand_name'          => $row['brand_name'] ?? $slug,
            'revenue_y1_usd'      => null,
            'flow_steps'          => [],
            'baseline_steps'      => [],
            'after_tax_cash_y1'   => null,
            'baseline_after_tax'  => null,
            'savings_vs_baseline' => null,
            'effective_tax_rate'  => null,
            'error'               => 'missing_revenue',
        ];
    }

    private function zeroResult(string $slug, array $row): array
    {
        return [
            'brand_slug'          => $slug,
            'brand_name'          => $row['brand_name'] ?? $slug,
            'revenue_y1_usd'      => 0.0,
            'flow_steps'          => [
                ['label' => 'Gross revenue', 'amount' => 0.0, 'type' => 'inflow'],
                ['label' => 'After-tax cash', 'amount' => 0.0, 'type' => 'result'],
            ],
            'baseline_steps'      => [],
            'after_tax_cash_y1'   => 0.0,
            'baseline_after_tax'  => 0.0,
            'savings_vs_baseline' => 0.0,
            'effective_tax_rate'  => null,
        ];
    }
}
