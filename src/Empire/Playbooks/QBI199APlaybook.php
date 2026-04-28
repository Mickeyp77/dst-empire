<?php
/**
 * QBI199APlaybook — IRC §199A Qualified Business Income Deduction
 *
 * Evaluates the 20 % QBI deduction for pass-through entities (sole props,
 * partnerships, S-Corps, trusts) for tax years 2018–2025 (extended through
 * 2029 by the Tax Cuts and Jobs Act Extension Act of 2025 as introduced;
 * we use 2026 limits and treat extension as likely but flag uncertainty).
 *
 * Two-tier structure:
 *   1. Under taxable-income threshold → 20 % of QBI, no wage/UBIA limit.
 *   2. Over threshold → lesser of 20 % of QBI, OR the W-2 wage limitation:
 *        max( 50 % of W-2 wages, 25 % of W-2 wages + 2.5 % of UBIA )
 *
 * 2026 thresholds (inflation-adjusted estimates):
 *   MFJ:        $383,900 phase-in starts, $483,900 fully limited
 *   Single:     $191,950 phase-in starts, $241,950 fully limited
 *   We use $400k (MFJ) / $200k (single) as approximate 2026 values.
 *
 * Specified Service Trade or Business (SSTB) — §199A(d)(1):
 *   Deduction phases out above the income threshold for SSTBs.
 *   Verticals that are SSTBs: healthcare, professional_services, consulting,
 *   law, accounting, financial, brokerage, performing arts, athletics.
 *
 * UBIA = Unadjusted Basis Immediately After Acquisition of qualified property
 *   (depreciable property used in the QBI trade or business).
 */

namespace Mnmsos\Empire\Playbooks;

class QBI199APlaybook extends AbstractPlaybook
{
    // 2026 estimated thresholds
    private const THRESHOLD_MFJ    = 400_000.0;
    private const THRESHOLD_SINGLE = 200_000.0;

    // SSTB verticals — deduction phases out above threshold
    private const SSTB_VERTICALS = [
        'healthcare',
        'professional_services',
    ];

    public function getId(): string          { return 'qbi_199a'; }
    public function getName(): string        { return '§199A QBI Deduction (20 % Pass-Through)'; }
    public function getCodeSection(): string { return '§199A'; }
    public function getAggressionTier(): string { return 'conservative'; }
    public function getCategory(): string    { return 'tax'; }

    public function applies(array $intake, array $portfolioContext): bool
    {
        if (!$this->tierAllowed($intake)) {
            return false;
        }
        // Only applies to pass-through entities
        $entityType = $intake['decided_entity_type'] ?? '';
        if ($entityType === 'c_corp') {
            return false;
        }
        // Need some income to model
        $qbi = $this->f($intake['ebitda_usd']);
        if ($qbi <= 0) {
            return false;
        }
        return true;
    }

    public function evaluate(array $intake, array $portfolioContext): array
    {
        if (!$this->applies($intake, $portfolioContext)) {
            return $this->notApplicable(
                '§199A QBI deduction not applicable: entity is a C-Corp, QBI is ≤ $0, ' .
                'or aggression tier too low.'
            );
        }

        $qbi        = $this->f($intake['ebitda_usd']);
        $revenue    = $this->f($intake['annual_revenue_usd']);
        $employees  = $this->i($intake['employee_count']);
        $equip      = $this->f($intake['equipment_value_usd']);
        $vertical   = $intake['industry_vertical'] ?? 'other';
        $isSSTB     = in_array($vertical, self::SSTB_VERTICALS, true);

        // Estimate W-2 wages: employees × $55k average (conservative)
        $w2Wages = $employees > 0 ? $employees * 55_000.0 : 0.0;
        // Add owner reasonable comp if S-Corp is elected
        $ownerComp = ($intake['decided_entity_type'] ?? '') === 's_corp' ? max(35_000.0, $qbi * 0.35) : 0.0;
        $totalW2   = $w2Wages + $ownerComp;

        // UBIA of qualified property (equipment at cost)
        $ubia = $equip;

        // Base 20 % of QBI
        $rawDeduction = $qbi * 0.20;

        // Wage + UBIA limitation
        $wageLimA = $totalW2 * 0.50;
        $wageLimB = ($totalW2 * 0.25) + ($ubia * 0.025);
        $wageLimitation = max($wageLimA, $wageLimB);

        // Determine owner taxable income proxy (QBI + owner comp + other income)
        // We don't have full owner income — use conservative estimate = QBI × 1.2
        $ownerTaxableIncome = $qbi * 1.2;
        $isSpouse = $this->f($portfolioContext['spouse_member_pct'] ?? 0) > 0;
        $threshold = $isSpouse ? self::THRESHOLD_MFJ : self::THRESHOLD_SINGLE;

        $effectiveDeduction = $rawDeduction;
        $limitedByWages     = false;

        if ($ownerTaxableIncome > $threshold) {
            if (!$isSSTB) {
                // Phase-in wage limitation over $100k range above threshold
                $phaseIn = $threshold + 100_000.0;
                if ($ownerTaxableIncome >= $phaseIn) {
                    // Fully limited
                    $effectiveDeduction = min($rawDeduction, $wageLimitation);
                } else {
                    // Partial phase-in
                    $ratio = ($ownerTaxableIncome - $threshold) / 100_000.0;
                    $reduction = ($rawDeduction - min($rawDeduction, $wageLimitation)) * $ratio;
                    $effectiveDeduction = $rawDeduction - $reduction;
                }
                $limitedByWages = ($effectiveDeduction < $rawDeduction);
            } else {
                // SSTB: deduction phases out entirely above threshold + $100k
                $phaseIn = $threshold + 100_000.0;
                if ($ownerTaxableIncome >= $phaseIn) {
                    $effectiveDeduction = 0.0;
                } else {
                    $ratio = 1.0 - ($ownerTaxableIncome - $threshold) / 100_000.0;
                    $effectiveDeduction = $rawDeduction * $ratio;
                }
            }
        }

        // Tax savings at assumed 37 % marginal rate
        $taxRate = 0.37;
        $savingsY1 = $effectiveDeduction * $taxRate;
        $savings5y = $savingsY1 * 5.0; // flat; growth ignored for conservatism

        // Setup / ongoing — mostly advisory, very low cost
        $setupCost   = 500.0;  // CPA QBI calculation and entity review
        $ongoingCost = 300.0;  // Annual QBI allocation calculation

        $appScore = 50;
        if ($savingsY1 > 10_000)   { $appScore += 15; }
        if ($savingsY1 > 30_000)   { $appScore += 15; }
        if (!$isSSTB)              { $appScore += 10; }
        if ($ownerTaxableIncome < $threshold) { $appScore += 10; }

        return [
            'applies'                    => true,
            'applicability_score'        => $this->score($appScore),
            'estimated_savings_y1_usd'   => round($savingsY1, 2),
            'estimated_savings_5y_usd'   => round($savings5y, 2),
            'estimated_setup_cost_usd'   => $setupCost,
            'estimated_ongoing_cost_usd' => $ongoingCost,
            'risk_level'                 => 'low',
            'audit_visibility'           => 'low',
            'prerequisites_md'           => implode("\n", [
                '- Entity must be a pass-through (sole-prop, partnership, LLC, S-Corp, trust)',
                '- Must have positive qualified business income (EBITDA proxy used here)',
                '- SSTB businesses (healthcare, professional services) lose deduction above income threshold',
                '- W-2 wage limitation applies above ~$400k MFJ / ~$200k single taxable income',
                '- **§199A was enacted 2018 with 2025 sunset; Congress has introduced extension through 2029**',
                '  — confirm current law with CPA before multi-year planning',
            ]),
            'rationale_md'               => implode("\n\n", [
                '**§199A QBI Deduction Analysis**',
                sprintf(
                    "QBI (EBITDA proxy): **\$%s**  \nRaw 20%% deduction: **\$%s**  \n" .
                    "W-2 wages (est.): **\$%s** | UBIA: **\$%s**  \nW-2 limitation: **\$%s**  \n" .
                    "Effective deduction (after limits): **\$%s**  \n" .
                    "Tax savings at 37%% marginal rate: **\$%s/yr**",
                    number_format($qbi, 0),
                    number_format($rawDeduction, 0),
                    number_format($totalW2, 0),
                    number_format($ubia, 0),
                    number_format($wageLimitation, 0),
                    number_format($effectiveDeduction, 0),
                    number_format($savingsY1, 0)
                ),
                $isSSTB
                    ? '**SSTB Warning:** This entity is in a Specified Service Trade or Business. ' .
                      'The §199A deduction phases out above the threshold and is zero above threshold + $100k. ' .
                      'Consider restructuring service vs. product revenue streams if feasible.'
                    : 'Entity is **not** an SSTB — full deduction available regardless of income level ' .
                      '(subject only to W-2 wage limitation above threshold).',
                $limitedByWages
                    ? '**W-2 Wage Limitation Active:** Adding W-2 employees or owner salary (via S-Corp ' .
                      'election) increases the wage limitation and may restore part of the deduction. ' .
                      'Model the S-Corp election (SCorpElectionPlaybook) jointly with this analysis.'
                    : 'W-2 wage limitation is not the binding constraint at current income level.',
            ]),
            'gotchas_md'                 => implode("\n", [
                '- **Sunset risk:** §199A expires after 2025 unless extended. Plan conservatively.',
                '- **Multiple entities:** Each pass-through entity computes QBI separately. Aggregation',
                '  election (Treas. Reg. §1.199A-4) can combine entities to share W-2 wage limits.',
                '- **Loss year:** Negative QBI carries forward and reduces future QBI deduction.',
                '- **PTET interaction:** Some states\' pass-through entity tax deductions reduce federal AGI,',
                '  which in turn reduces QBI. Calculate net effect.',
                '- **S-Corp reasonable comp:** Owner W-2 salary reduces QBI; higher salary = higher W-2',
                '  limitation benefit but lower raw QBI. Optimisation is a joint calculation with',
                '  SCorpElectionPlaybook.',
                '- **Rental real estate:** Qualifies as QBI under Rev. Proc. 2019-38 safe harbor (250+',
                '  hours/year rental services). Track hours carefully.',
                '- **Patron reduction:** §199A(b)(7) reduces deduction for cooperatives — rare but note.',
            ]),
            'citations'                  => [
                'IRC §199A — Qualified Business Income Deduction',
                'Treas. Reg. §1.199A-1 through §1.199A-6 (Final Regs, Jan 2019)',
                'Rev. Proc. 2019-38 — Rental real estate safe harbor',
                'IRS Notice 2019-7 — Safe harbor guidance',
                'Tax Cuts and Jobs Act of 2017, §11011',
                'Treas. Reg. §1.199A-4 — Aggregation of trade or business',
            ],
            'docs_required'              => [
                'form_8995',      // QBI Deduction (simplified)
                'form_8995a',     // QBI Deduction (aggregation or SSTB)
                'k1_package',     // K-1 from each pass-through entity
                'w2_records',     // W-2 wages paid
                'ubia_schedule',  // Cost basis of qualified property
            ],
            'next_actions'               => [
                'Ask CPA to run Form 8995/8995-A projection with actual W-2 data',
                'If wage-limited: model S-Corp election to increase W-2 wages',
                'If SSTB and above threshold: explore revenue disaggregation (separate non-SSTB product revenue)',
                'Consider aggregation election if multiple pass-through entities are operated',
                'Confirm §199A extension status with CPA for post-2025 planning',
            ],
        ];
    }
}
