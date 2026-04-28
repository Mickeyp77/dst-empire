<?php
/**
 * TaxProjector — deterministic 5-year tax burden projection for DST Empire.
 *
 * Projects federal + state tax under 3 scenarios across Y1–Y5:
 *   status_quo        — current structure, no Empire playbooks
 *   conservative_tier — conservative playbooks only
 *   recommended_tier  — matches client's locked aggression_tier
 *
 * 2026 constants (IRC citations inline):
 *   C-Corp rate     21%    §11(b)
 *   Top individual  37%    §1(j)(2)(D)
 *   NIIT            3.8%   §1411
 *   SE-tax          15.3%  §1401 (≤$168,600 OASDI) / 2.9% Medicare above
 *   §1202 exclusion $10M or 10×basis — §1202(b)
 *   §401(k) elective $23,500 — §402(g)(1); total §415 $70,000
 *   S-Corp SE savings on reasonable salary split — Rev. Rul. 74-44
 *
 * NO LLM calls. All math deterministic.
 */

namespace Mnmsos\Empire\Synthesis;

use PDO;

class TaxProjector
{
    // -----------------------------------------------------------------------
    // 2026 Federal constants
    // -----------------------------------------------------------------------
    private const CCORP_RATE      = 0.21;   // §11(b)
    private const TOP_IND_RATE    = 0.37;   // §1(j)(2)(D)
    private const NIIT_RATE       = 0.038;  // §1411
    private const SE_RATE_FULL    = 0.153;  // §1401 (combined OASDI+Medicare ≤ SS wage base)
    private const SE_RATE_MED     = 0.029;  // §3101(b) Medicare-only above SS wage base
    private const SS_WAGE_BASE    = 168600; // 2026 OASDI wage base
    private const K401_ELECTIVE   = 23500;  // §402(g)(1)
    private const K401_TOTAL_LIMIT = 70000; // §415(c)
    private const QSBS_EXCLUSION  = 10000000; // §1202(b)(1) — first $10M gain excluded
    private const QSBS_ALT_EXCL  = 10;     // §1202(b)(2) — 10× adjusted basis alternative

    // -----------------------------------------------------------------------
    // State rate table (2026) — top marginal income tax rate
    // -----------------------------------------------------------------------
    private const STATE_RATES = [
        'TX' => 0.00,   // No state income tax
        'WY' => 0.00,   // No state income tax
        'NV' => 0.00,   // No state income tax
        'SD' => 0.00,   // No state income tax
        'DE' => 0.087,  // DE corp franchise / individual top — Title 30 §1102
        'FL' => 0.055,  // FL corp income tax 5.5%
        'CA' => 0.133,  // CA top individual
    ];

    private PDO $db;
    private int $tenantId;
    private float $growthRate;
    private float $inflation;

    public function __construct(PDO $db, int $tenantId, float $growthRate = 0.15, float $inflation = 0.025)
    {
        $this->db         = $db;
        $this->tenantId   = $tenantId;
        $this->growthRate = $growthRate;
        $this->inflation  = $inflation;
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Project Y1–Y5 tax burden under 3 scenarios.
     * Returns projection array + QSBS/estate estimates.
     *
     * @param array $intakeRows      From empire_brand_intake (all columns, mig 077 cols present)
     * @param array $playbookRecs    Keyed by brand_slug → playbook results (may be empty if A2 not yet wired)
     * @param array $portfolioCtx    Row from empire_portfolio_context
     * @param array &$blockers       Appended in-place for any data gaps
     */
    public function project(
        array $intakeRows,
        array $playbookRecs,
        array $portfolioCtx,
        array &$blockers = []
    ): array {
        // Aggregate baseline revenue and EBITDA across all brands
        $baseRevenue = 0.0;
        $baseEbitda  = 0.0;
        $hasNullData = false;

        foreach ($intakeRows as $row) {
            $rev  = isset($row['annual_revenue_usd']) && $row['annual_revenue_usd'] !== null
                ? (float)$row['annual_revenue_usd']
                : null;
            $ebit = isset($row['ebitda_usd']) && $row['ebitda_usd'] !== null
                ? (float)$row['ebitda_usd']
                : null;

            if ($rev === null || $ebit === null) {
                $hasNullData = true;
                $slug = $row['brand_slug'] ?? ('id#' . ($row['id'] ?? '?'));
                $blockers[] = [
                    'type'    => 'missing_financial_data',
                    'brand'   => $slug,
                    'message' => "Brand '{$slug}' missing annual_revenue_usd or ebitda_usd — TaxProjector Y1–Y5 uses $0 for this brand. Update intake to enable accurate projection.",
                    'queue'   => 'MICKEY-QUEUE',
                ];
                $rev  = $rev ?? 0.0;
                $ebit = $ebit ?? 0.0;
            }

            $baseRevenue += $rev;
            $baseEbitda  += $ebit;
        }

        // Determine aggression tier from portfolio context (or default growth)
        $aggressionTier = $portfolioCtx['aggression_tier'] ?? 'growth';

        // Determine owner domicile for state rate lookup
        $ownerDomicile = $portfolioCtx['owner_domicile_state'] ?? 'TX';
        $ownerStateRate = self::STATE_RATES[$ownerDomicile] ?? 0.0;

        // Determine primary operating jurisdiction (most revenue brand)
        $primaryJurisdiction = $this->primaryJurisdiction($intakeRows);
        $entityStateRate     = self::STATE_RATES[$primaryJurisdiction] ?? 0.0;

        // QSBS eligibility — VoltOps C-Corp clock
        $qsbsInfo = $this->computeQsbs($intakeRows, $portfolioCtx);

        // Build Y1–Y5 rows
        $years = [];
        $cumulativeSavings = 0.0;
        for ($y = 1; $y <= 5; $y++) {
            $multiplier = (1 + $this->growthRate) ** ($y - 1);
            $ebitda     = $baseEbitda * $multiplier;

            $sqTax   = $this->scenarioStatusQuo($ebitda, $ownerStateRate, $entityStateRate);
            $consTax = $this->scenarioConservative($ebitda, $ownerStateRate, $entityStateRate, $playbookRecs);
            $recTax  = $this->scenarioRecommended($ebitda, $ownerStateRate, $entityStateRate, $playbookRecs, $aggressionTier);

            $cumulativeSavings += max(0.0, $sqTax - $recTax);

            $years[] = [
                'year'                => $y,
                'calendar_year'       => (int)date('Y') + ($y - 1),
                'revenue_projected'   => round($baseRevenue * $multiplier, 2),
                'ebitda_projected'    => round($ebitda, 2),
                'status_quo_tax'      => round($sqTax, 2),
                'conservative_tax'    => round($consTax, 2),
                'recommended_tax'     => round($recTax, 2),
                'savings_recommended' => round(max(0.0, $sqTax - $recTax), 2),
            ];
        }

        // Estate value estimate at Y5 for FLP/Dynasty playbook valuation
        $revenueMultiple = $this->revenueMultiple($aggressionTier);
        $estateValueY5   = round($baseRevenue * ((1 + $this->growthRate) ** 4) * $revenueMultiple, 2);

        return [
            'assumptions' => [
                'growth_rate'           => $this->growthRate,
                'inflation'             => $this->inflation,
                'base_revenue_y1'       => round($baseRevenue, 2),
                'base_ebitda_y1'        => round($baseEbitda, 2),
                'aggression_tier'       => $aggressionTier,
                'owner_domicile'        => $ownerDomicile,
                'owner_state_rate'      => $ownerStateRate,
                'primary_jurisdiction'  => $primaryJurisdiction,
                'entity_state_rate'     => $entityStateRate,
                'has_null_financial_data' => $hasNullData,
                'ccorp_fed_rate'        => self::CCORP_RATE,
                'top_individual_rate'   => self::TOP_IND_RATE,
                'niit_rate'             => self::NIIT_RATE,
                'qsbs_eligible'         => $qsbsInfo['eligible'],
                'qsbs_clock_brand'      => $qsbsInfo['brand'],
                'qsbs_start_date'       => $qsbsInfo['start_date'],
                'qsbs_5yr_date'         => $qsbsInfo['five_yr_date'],
            ],
            'years'                    => $years,
            'cumulative_savings_5yr_usd' => round($cumulativeSavings, 2),
            'qsbs_eligible_gain_y5'    => $qsbsInfo['eligible_gain_y5'],
            'qsbs_notes'               => $qsbsInfo['notes'],
            'estate_value_at_y5'       => $estateValueY5,
            'estate_multiple_used'      => $revenueMultiple,
        ];
    }

    // -----------------------------------------------------------------------
    // Scenario engines
    // -----------------------------------------------------------------------

    /**
     * Status-quo: self-employment / pass-through with no restructuring.
     * Assumes sole-prop / S-Corp at MNMS level, owner draws 100% of EBITDA.
     * IRC §1401 SE tax + §1(j) income tax + state.
     */
    private function scenarioStatusQuo(float $ebitda, float $ownerStateRate, float $entityStateRate): float
    {
        if ($ebitda <= 0) {
            return 0.0;
        }
        // SE tax on self-employment income (deduct half SE per §164(f))
        $seDeduction = $ebitda * 0.0765; // half of SE tax (approximate)
        $taxableIncome = $ebitda - $seDeduction - self::K401_ELECTIVE;
        $taxableIncome = max(0.0, $taxableIncome);

        // SE tax (§1401)
        $seTax = $this->seTax($ebitda);

        // Federal income tax at top rate (conservative — owner at top bracket)
        $fedIncomeTax = $taxableIncome * self::TOP_IND_RATE;

        // NIIT on net investment income if passive income exists
        // Conservative: assume 20% of EBITDA is passive/investment — §1411
        $niit = $ebitda * 0.20 * self::NIIT_RATE;

        // State income tax
        $stateTax = $taxableIncome * $ownerStateRate;

        return $seTax + $fedIncomeTax + $niit + $stateTax;
    }

    /**
     * Conservative tier: S-Corp election (already in place at MNMS) + §401(k) max.
     * SE tax only on reasonable salary; remainder flows as distribution.
     * Reasonable salary: min(40% of EBITDA, §415 total limit) — Rev. Rul. 74-44.
     */
    private function scenarioConservative(
        float $ebitda,
        float $ownerStateRate,
        float $entityStateRate,
        array $playbookRecs
    ): float {
        if ($ebitda <= 0) {
            return 0.0;
        }
        // Reasonable salary = 40% of EBITDA, capped at SS wage base
        $salary = min($ebitda * 0.40, self::SS_WAGE_BASE);

        // §401(k) reduces taxable salary — elective deferral
        $deferral = min(self::K401_ELECTIVE, $salary);
        $taxableSalary = max(0.0, $salary - $deferral);

        // SE/payroll tax only on salary — FICA employer + employee = 15.3% to SS wage base
        $ficaTax = $this->ficaTax($salary);

        // Distribution (pass-through)
        $distribution = max(0.0, $ebitda - $salary);

        // Federal income tax on salary + distribution (top bracket)
        $fedIncome = ($taxableSalary + $distribution) * self::TOP_IND_RATE;

        // NIIT on distribution portion (passive-ish) — §1411
        $niit = $distribution * self::NIIT_RATE;

        // State
        $stateTax = ($taxableSalary + $distribution) * $ownerStateRate;

        return $ficaTax + $fedIncome + $niit + $stateTax;
    }

    /**
     * Recommended tier: adds IP royalty split, management fee structure,
     * Augusta Rule (§280A(g)), HRA/defined-benefit contributions,
     * and DAPT/dynasty trust distributions if aggression_tier = aggressive.
     *
     * §280A(g): up to 14 rental days/yr tax-free (Augusta Rule)
     * §199A: 20% QBI deduction for qualifying pass-through income
     * §412(e)(3) / §412: defined benefit plan (aggressive tier only)
     */
    private function scenarioRecommended(
        float $ebitda,
        float $ownerStateRate,
        float $entityStateRate,
        array $playbookRecs,
        string $aggressionTier
    ): float {
        if ($ebitda <= 0) {
            return 0.0;
        }
        $tierOrder = ['conservative' => 0, 'growth' => 1, 'aggressive' => 2];
        $tier      = $tierOrder[$aggressionTier] ?? 1;

        // Start from conservative as base
        $base = $this->scenarioConservative($ebitda, $ownerStateRate, $entityStateRate, $playbookRecs);

        // §199A QBI deduction — 20% of qualified business income, phases out at
        // $383,900 (MFJ 2026) but assume full benefit for simplicity at this rev level.
        // Reduces taxable income on pass-through distributions by 20%.
        // IRC §199A(a).
        $distribution      = max(0.0, $ebitda - min($ebitda * 0.40, self::SS_WAGE_BASE));
        $qbiSavings        = $distribution * 0.20 * self::TOP_IND_RATE;

        // §280A(g) Augusta Rule: rent home office up to 14 days/yr.
        // Typical daily rate $1,000–$3,000 for DFW; assume $2,000 × 14 = $28,000 deductible.
        $augustaDeduction  = 28000.0;
        $augustaSavings    = $augustaDeduction * self::TOP_IND_RATE;

        // Intercompany management fee (10% of EBITDA from ops entity → MNMS)
        // Already captured in S-Corp pass-through; net tax impact neutral at portfolio
        // level but shifts income to lower-rate entity for state tax arbitrage.
        $stateSavings = 0.0;
        if ($entityStateRate > $ownerStateRate) {
            // Shift 15% of EBITDA via mgmt fee to lower-state entity
            $stateSavings = $ebitda * 0.15 * ($entityStateRate - $ownerStateRate);
        }

        $growth = $tier >= 1;
        $aggr   = $tier >= 2;

        // Growth tier: HSA max + §105 HRA
        // §106 / §105(b): employer HRA contributions exclude from income.
        // 2026 HSA self-only $4,300; family $8,550 — §223(b)(2).
        $hraSavings = $growth ? (8550.0 * self::TOP_IND_RATE) : 0.0;

        // Aggressive tier: §412(e)(3) defined benefit plan allows ~$275,000+/yr
        // deductible contribution beyond §415 DC limit; assumes owner age 50.
        // Conservative estimate: additional $80k deductible above DC limit.
        $dbSavings = $aggr ? (80000.0 * self::TOP_IND_RATE) : 0.0;

        // Aggregate playbook savings from PlaybookRegistry results
        $pbSavings = 0.0;
        foreach ($playbookRecs as $slug => $recs) {
            if (!is_array($recs)) {
                continue;
            }
            foreach ($recs as $pb) {
                if (!isset($pb['applies']) || !$pb['applies']) {
                    continue;
                }
                $pbTier = $pb['aggression_tier'] ?? 'conservative';
                $pbTierLevel = $tierOrder[$pbTier] ?? 0;
                if ($pbTierLevel <= $tier) {
                    $pbSavings += (float)($pb['estimated_savings_y1_usd'] ?? 0.0);
                }
            }
        }

        $totalSavings = $qbiSavings + $augustaSavings + $stateSavings + $hraSavings + $dbSavings + $pbSavings;
        return max(0.0, $base - $totalSavings);
    }

    // -----------------------------------------------------------------------
    // QSBS §1202 computation
    // -----------------------------------------------------------------------

    /**
     * Determine QSBS eligibility and compute potential exclusion at Y5.
     *
     * §1202 requirements:
     *   - C-Corp (not S-Corp, LLC, LP)
     *   - Original issue (not secondary market purchase)
     *   - Gross assets ≤ $50M at time of issuance (§1202(d)(1))
     *   - Active business in qualified trade (§1202(e)) — software/tech qualifies
     *   - Held > 5 years (§1202(a)(1))
     *   - Exclusion: 100% of gain (acquired after 9/27/2010) up to §1202(b) limits
     *
     * VoltOps CLOCK NOTE: If VoltOps C-Corp incorporated TODAY (2026-04-28),
     * the 5-year clock starts today → qualifies 2031-04-28.
     * If already incorporated (decided_jurisdiction=DE + entity spawned), clock
     * may already be running — check spawned_entity_id + formation date.
     */
    private function computeQsbs(array $intakeRows, array $portfolioCtx): array
    {
        $eligible    = false;
        $brand       = null;
        $startDate   = null;
        $fiveYrDate  = null;
        $notes       = [];
        $gainY5      = 0.0;

        foreach ($intakeRows as $row) {
            $slug       = $row['brand_slug'] ?? '';
            $entityType = $row['decided_entity_type'] ?? $row['entity_type'] ?? '';
            $jur        = $row['decided_jurisdiction'] ?? '';

            // Only C-Corps qualify for §1202
            if ($entityType !== 'c_corp') {
                continue;
            }

            $eligible = true;
            $brand    = $slug;

            // Check if entity already spawned (formation date available)
            $spawnedId = $row['spawned_entity_id'] ?? null;
            if ($spawnedId) {
                $formDate = $this->fetchFormationDate((int)$spawnedId);
                if ($formDate) {
                    $startDate  = $formDate;
                    $fiveYrDate = date('Y-m-d', strtotime($formDate . ' +5 years'));
                    $notes[]    = "§1202 clock started {$formDate} (entity already spawned). 5-year qualification date: {$fiveYrDate}.";
                } else {
                    // Spawned but no formation date recorded
                    $startDate  = date('Y-m-d');
                    $fiveYrDate = date('Y-m-d', strtotime('+5 years'));
                    $notes[]    = "§1202 clock assumed today {$startDate} — formation_entities row missing incorporation_date. UPDATE formation_entities SET incorporation_date = actual_date.";
                }
            } else {
                // Not yet spawned — clock starts at filing
                $startDate  = date('Y-m-d'); // today = projection baseline
                $fiveYrDate = date('Y-m-d', strtotime('+5 years'));
                $notes[]    = "§1202 clock starts at C-Corp incorporation (not yet spawned). If filed today ({$startDate}), qualifies {$fiveYrDate}.";
            }

            // §1202(b) exclusion amount: greater of $10M or 10× adjusted basis
            // Estimate adjusted basis = setup cost from BrandPlacement (minimal for software)
            // Conservative: use $10M cap
            $revY5   = (float)($row['annual_revenue_usd'] ?? 0) * ((1 + $this->growthRate) ** 4);
            $saasMultiple = 6.0; // conservative SaaS revenue multiple at Y5
            $gainY5  = min($revY5 * $saasMultiple, self::QSBS_EXCLUSION);

            $notes[] = "§1202(b) exclusion capped at min(\$10M, gain). Estimated Y5 gain (6× revenue): \$" . number_format($gainY5, 0) . ". Tax savings if qualifying sale at Y5 (37%+NIIT): ~\$" . number_format($gainY5 * (self::TOP_IND_RATE + self::NIIT_RATE), 0) . ".";
            $notes[] = "§1202(e) active-business test: software/tech qualifies; MPS (copier services) may be borderline as 'service business' — attorney review required before relying on §1202. See §1202(e)(3)(A).";
            break; // Only process first C-Corp found
        }

        if (!$eligible) {
            $notes[] = "No C-Corp entity found in intake. §1202 QSBS exclusion not available. Convert VoltOps to DE C-Corp to start 5-year clock. See BrandPlacement override for voltops.";
        }

        return [
            'eligible'       => $eligible,
            'brand'          => $brand,
            'start_date'     => $startDate,
            'five_yr_date'   => $fiveYrDate,
            'eligible_gain_y5' => round($gainY5, 2),
            'notes'          => $notes,
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Self-employment tax under §1401.
     * OASDI 12.4% on first $168,600; Medicare 2.9% on all.
     * Additional 0.9% Medicare surtax on amounts above $200k — §3103.
     */
    private function seTax(float $netEarnings): float
    {
        // SE income = 92.35% of net earnings (deduct employer-equivalent half — §1402(a))
        $seIncome = $netEarnings * 0.9235;

        $oasdi    = min($seIncome, self::SS_WAGE_BASE) * 0.124;
        $medicare  = $seIncome * 0.029;
        $surtax    = max(0.0, $seIncome - 200000) * 0.009; // §3103

        return $oasdi + $medicare + $surtax;
    }

    /**
     * FICA tax on W-2 salary (employer + employee combined = §3101 + §3111).
     */
    private function ficaTax(float $salary): float
    {
        $oasdi   = min($salary, self::SS_WAGE_BASE) * 0.124; // 6.2% × 2
        $medicare = $salary * 0.029;                          // 1.45% × 2
        $surtax   = max(0.0, $salary - 200000) * 0.009;       // employee only — §3101(b)(2)
        return $oasdi + $medicare + $surtax;
    }

    /**
     * Revenue multiple for estate valuation estimate.
     * Conservative/growth: 4×; aggressive (assumes trust/sale planning): 7×.
     */
    private function revenueMultiple(string $aggressionTier): float
    {
        return match($aggressionTier) {
            'aggressive'   => 7.0,
            'growth'       => 5.0,
            default        => 4.0,
        };
    }

    /**
     * Identify primary operating jurisdiction (brand with highest revenue).
     */
    private function primaryJurisdiction(array $intakeRows): string
    {
        $best     = 'TX';
        $bestRev  = -1.0;
        foreach ($intakeRows as $row) {
            $rev = (float)($row['annual_revenue_usd'] ?? 0);
            $jur = $row['decided_jurisdiction'] ?? ($row['jurisdiction'] ?? 'TX');
            if ($rev > $bestRev) {
                $bestRev = $rev;
                $best    = $jur;
            }
        }
        return $best;
    }

    /**
     * Fetch incorporation_date from formation_entities by ID.
     * Returns null if row missing or date column absent (schema may lag).
     */
    private function fetchFormationDate(int $entityId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT incorporation_date FROM formation_entities WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$entityId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return ($row && !empty($row['incorporation_date'])) ? $row['incorporation_date'] : null;
        } catch (\Throwable $e) {
            // Column may not exist pre-mig-077 — silently return null
            return null;
        }
    }
}
