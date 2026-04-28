<?php
/**
 * RDCredit41Playbook — IRC §41 R&D Tax Credit + §174 R&D Expense
 *
 * Evaluates qualification for the Federal R&D Tax Credit (§41) and the
 * amortisation requirement for research and experimentation expenditures
 * under §174 (as amended by TCJA 2017, effective 2022).
 *
 * §41 credit mechanics:
 *   - Regular Credit: 20 % × (QREs − base amount), where base amount =
 *       fixed-base percentage × average of 4 prior years' gross receipts.
 *       For simplicity and conservatism, use the Alternative Simplified
 *       Credit (ASC) method: 14 % × (QREs − 50 % of avg prior-3-year QREs).
 *     For startups with < 3 years QRE history: 6 % × current-year QREs.
 *   - Credit against regular tax; may be carried forward 20 years.
 *   - For qualified small businesses (≤ $5M gross receipts, < 5 years of
 *     gross receipts): credit may be applied against payroll tax (§41(h))
 *     — up to $500k/year. Critical for pre-revenue startups.
 *
 * Qualified Research Expenses (QREs):
 *   - W-2 wages for qualified services
 *   - Supply costs used in qualified research
 *   - 65 % of contractor payments (§41(b)(3))
 *   - Excludes: funded research, social sciences, management studies
 *
 * §174 amortisation (post-2021):
 *   Domestic R&E: amortised over 5 years (15 for foreign).
 *   No longer immediately deductible. Creates a timing difference benefit
 *   in later years. Must track separately from §41 credit.
 *
 * QRE estimation from intake:
 *   Software/SaaS/manufacturing: 10–20 % of revenue as QRE proxy
 *   Conservative default: 8 % of revenue
 *
 * State R&D credits: available in CA, TX (no), NY, MA, GA, etc.
 *   We flag availability based on `state_nexus_json` but don't quantify
 *   state credits here (too state-specific; flag for CPA).
 */

namespace Mnmsos\Empire\Playbooks;

class RDCredit41Playbook extends AbstractPlaybook
{
    // Verticals with high QRE likelihood
    private const HIGH_QRE_VERTICALS = ['saas', 'manufacturing'];
    private const MED_QRE_VERTICALS  = ['ecommerce', 'agency', 'other'];

    // QRE as % of revenue by vertical
    private const QRE_PCT = [
        'saas'          => 0.18,
        'manufacturing' => 0.12,
        'ecommerce'     => 0.06,
        'agency'        => 0.08,
        'other'         => 0.08,
        'healthcare'    => 0.07,
        'professional_services' => 0.05,
        'retail'        => 0.03,
        'realestate'    => 0.02,
        'crypto'        => 0.10,
    ];

    private const ASC_RATE_NORMAL  = 0.14;
    private const ASC_RATE_STARTUP = 0.06;  // < 3 years QRE history
    private const PAYROLL_OFFSET_MAX = 500_000.0; // §41(h) cap per year

    public function getId(): string          { return 'rd_credit_41'; }
    public function getName(): string        { return 'R&D Tax Credit (§41) + §174 Amortisation'; }
    public function getCodeSection(): string { return '§41 / §174'; }
    public function getAggressionTier(): string { return 'conservative'; }
    public function getCategory(): string    { return 'tax'; }

    public function applies(array $intake, array $portfolioContext): bool
    {
        if (!$this->tierAllowed($intake)) {
            return false;
        }
        $revenue  = $this->f($intake['annual_revenue_usd']);
        $vertical = $intake['industry_vertical'] ?? 'other';
        // Real estate and retail rarely qualify
        if (in_array($vertical, ['realestate', 'retail'], true)) {
            return false;
        }
        if ($revenue < 50_000.0) {
            return false; // Negligible benefit
        }
        return true;
    }

    public function evaluate(array $intake, array $portfolioContext): array
    {
        if (!$this->applies($intake, $portfolioContext)) {
            return $this->notApplicable(
                'R&D credit not applicable: vertical rarely qualifies (real estate / retail), ' .
                'revenue too low, or aggression tier too low.'
            );
        }

        $revenue  = $this->f($intake['annual_revenue_usd']);
        $vertical = $intake['industry_vertical'] ?? 'other';
        $employees = $this->i($intake['employee_count']);
        $ebitda   = $this->f($intake['ebitda_usd']);

        // Estimate QREs
        $qrePct = self::QRE_PCT[$vertical] ?? 0.08;
        $estQRE = $revenue * $qrePct;

        // For startups (< 3 years receipts): use 6 % flat; proxy by low revenue
        $isStartup = ($revenue < 500_000.0);
        $creditRate = $isStartup ? self::ASC_RATE_STARTUP : self::ASC_RATE_NORMAL;

        // ASC: credit = rate × (QREs − 50 % avg prior-3-year QREs)
        // Conservative assumption: prior-year QREs = 80 % of current (growing business)
        $priorAvgQRE = $estQRE * 0.80;
        $creditableBase = max(0.0, $estQRE - ($priorAvgQRE * 0.50));
        $grossCredit    = $creditableBase * $creditRate;

        // §280C(c) reduction: credit reduces §174 deduction; net credit ≈ gross × (1 − tax_rate)
        // Or taxpayer may elect reduced credit (§280C(c)(2)) = credit × (1 − 0.21) for C-Corp
        // For pass-throughs use 37 % rate. Use 21 % C-Corp as conservative floor.
        $entityType = $intake['decided_entity_type'] ?? 'llc';
        $sec280cRate = ($entityType === 'c_corp') ? 0.21 : 0.37;
        $netCredit   = $grossCredit; // If elected reduced credit — no §280C haircut on deduction
        // Simpler: just use gross credit as benefit (taxpayer can elect §280C(c)(2))

        // Payroll offset for small businesses
        $smallBiz = ($revenue <= 5_000_000.0);
        $payrollOffset = $smallBiz ? min($netCredit, self::PAYROLL_OFFSET_MAX) : 0.0;

        // §174 amortisation benefit: pre-2022 full deduction → now 5-yr amortisation.
        // First-year deduction = QREs / 5 × 0.5 (half-year convention)
        // Timing cost Y1 = (QREs × taxRate) − (QREs/5 × 0.5 × taxRate)
        // This is a COST, not a benefit — flag it
        $sec174TimingCostY1 = $estQRE * $sec280cRate * (1.0 - 0.10); // 10 % Y1 deduction vs 100 % old rule

        $savingsY1 = $netCredit;
        $savings5y = $netCredit * 5.0; // Flat; does not model growth

        $setupCost   = 3_000.0; // R&D credit study / CPA preparation
        $ongoingCost = 1_500.0; // Annual QRE tracking and credit claim

        $appScore = 30;
        if (in_array($vertical, self::HIGH_QRE_VERTICALS, true)) { $appScore += 25; }
        if (in_array($vertical, self::MED_QRE_VERTICALS, true))  { $appScore += 10; }
        if ($employees >= 2) { $appScore += 10; }
        if ($savingsY1 > 5_000) { $appScore += 15; }
        if ($smallBiz)          { $appScore += 10; }

        return [
            'applies'                    => true,
            'applicability_score'        => $this->score($appScore),
            'estimated_savings_y1_usd'   => round($savingsY1, 2),
            'estimated_savings_5y_usd'   => round($savings5y, 2),
            'estimated_setup_cost_usd'   => $setupCost,
            'estimated_ongoing_cost_usd' => $ongoingCost,
            'risk_level'                 => 'low',
            'audit_visibility'           => 'medium',
            'prerequisites_md'           => implode("\n", [
                '- Activity must involve technological uncertainty, process of experimentation, ' .
                '  and rely on hard sciences (§41(d)(1)) — "4-part test"',
                '- Qualified services (W-2 wages for R&D work) must be documented with time records',
                '- Supply costs and contractor payments (65 % rule) must be tracked separately',
                '- Gross receipts ≤ $5M → may use credit against payroll tax (§41(h), up to $500k/yr)',
                '- Must file Form 6765 with federal return (or amended return, 3-year lookback)',
                '- §174 amortisation (post-2021): domestic R&E costs amortised over 5 years, not immediately deductible',
            ]),
            'rationale_md'               => implode("\n\n", [
                '**R&D Credit §41 Analysis**',
                sprintf(
                    "Revenue: **\$%s** | Industry vertical: **%s**  \n" .
                    "Estimated QREs (%s%% of revenue): **\$%s**  \n" .
                    "ASC method credit (%s%% × excess): **\$%s**  \n" .
                    "Net credit (after §280C election): **\$%s**  \n" .
                    "%s",
                    number_format($revenue, 0),
                    $vertical,
                    number_format($qrePct * 100, 0),
                    number_format($estQRE, 0),
                    number_format($creditRate * 100, 0),
                    number_format($grossCredit, 0),
                    number_format($netCredit, 0),
                    $smallBiz
                        ? "Small business payroll offset available: **\$" . number_format($payrollOffset, 0) . "**/yr"
                        : "Revenue > \$5M — payroll offset not available; credit offsets income tax only"
                ),
                "**§174 Note:** Post-2021 TCJA change requires amortising domestic R&E costs over 5 " .
                "years (half-year Y1 = 10 % deduction). This creates a ~**\$" .
                number_format($sec174TimingCostY1, 0) . "** Y1 deduction timing headwind. " .
                "Track §174 basis separately from §41 credit. Congress has proposed retroactive fix " .
                "(AICPA-supported) — monitor legislation.",
            ]),
            'gotchas_md'                 => implode("\n", [
                '- **Documentation is everything.** The IRS R&D Credit ATG (Audit Technique Guide) expects',
                '  contemporaneous time records, project narratives, and technical uncertainty documentation.',
                '  Retroactive reconstruction is possible but weaker in audit.',
                '- **§280C election:** Elect reduced credit to avoid reducing the §174 basis. Simpler math.',
                '  Default is reduced credit for most taxpayers.',
                '- **Funded research exclusion (§41(d)(4)(H)):** If a customer contractually owns the',
                '  research or bears the financial risk, those QREs are excluded. Common in government',
                '  contracts and cost-plus consulting.',
                '- **Software-specific rules (Notice 2015-73):** Internal-use software is restricted.',
                '  Externally sold software and back-office automation software have different tests.',
                '- **State addback:** Some states (TX, OH) have no income tax — §41 credit is federal only.',
                '  States with R&D credits (CA, NY, MA) have separate forms and different definitions.',
                '- **Acquisition:** Acquired R&D may not carry credit forward; verify §382 limitations.',
            ]),
            'citations'                  => [
                'IRC §41 — Credit for Increasing Research Activities',
                'IRC §174 — Amortisation of Research and Experimental Expenditures (post-TCJA)',
                'IRC §280C(c) — Certain expenses for which credits are allowable',
                'Treas. Reg. §1.41-4 — Qualified research',
                'IRS Notice 2015-73 — Internal use software',
                'IRS Audit Technique Guide — Research and Development Tax Credit (2017)',
                'Rev. Proc. 2011-42 — Simplified method for ASC election',
                'Siemer Milling Co. v. Commissioner, T.C. Memo 2019-37',
            ],
            'docs_required'              => [
                'form_6765',            // Credit for Increasing Research Activities
                'qre_project_log',      // Project-by-project QRE allocation
                'employee_time_records', // Time allocation to qualified research
                'contractor_agreements', // 65 % rule backup
                'sec174_amortisation_schedule', // Post-2021 basis tracking
            ],
            'next_actions'               => [
                'Engage R&D credit specialist CPA (many work on contingency for Y1)',
                'Start contemporaneous time-tracking for all technical employees immediately',
                'Document technological uncertainty in each R&D project brief',
                'File Form 6765 with current or amended return (3-year lookback available)',
                $smallBiz
                    ? 'Apply payroll offset on Form 8974 if revenue ≤ $5M'
                    : 'Carry credit forward against regular income tax liability',
                'Set up §174 amortisation schedule for all post-2021 R&E costs',
            ],
        ];
    }
}
