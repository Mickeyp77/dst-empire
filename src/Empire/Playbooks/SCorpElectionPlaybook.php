<?php
/**
 * SCorpElectionPlaybook — IRC §1361/§1362 + Form 2553
 *
 * Evaluates when an entity should elect S-Corp status, models the
 * reasonable-compensation split, and estimates SE-tax savings.
 *
 * Key mechanics:
 *   Self-employment (SE) tax = 15.3 % on net profit up to SS wage base
 *   ($176,100 for 2026) + 2.9 % Medicare above base.
 *   S-Corp election allows splitting profit between W-2 salary (subject
 *   to payroll taxes) and S-Corp distributions (not subject to SE tax).
 *   Savings = SE tax avoided on distributions.
 *
 * Conservative reasonable-comp benchmark: median industry W-2 wage
 *   — we use 50 % of net income as a safe harbor proxy when no salary
 *   data is provided (conservative; IRS expects a facts-and-circumstances
 *   analysis per Rev. Rul. 74-44, Watson v. Commissioner, T.C. 2010).
 *
 * Eligibility gate (§1361):
 *   - Domestic corporation only
 *   - ≤ 100 shareholders
 *   - Only one class of stock
 *   - No non-resident alien shareholders
 *   - No C-Corp, partnership, or most trust shareholders
 *
 * Note on Sabrina / MNMS LLC:
 *   MNMS LLC is a multi-member entity — cannot elect S-Corp directly.
 *   However individual single-member-LLC brands CAN elect. This playbook
 *   fires on single-owner entities. The Sabrina flag is surfaced in
 *   gotchas_md, not used to block the playbook.
 */

namespace Mnmsos\Empire\Playbooks;

class SCorpElectionPlaybook extends AbstractPlaybook
{
    // 2026 constants
    private const SS_WAGE_BASE    = 176_100.0;
    private const SE_RATE_BELOW   = 0.153;   // 15.3 % up to SS base
    private const SE_RATE_ABOVE   = 0.029;   // 2.9 % Medicare above base
    private const MIN_PROFIT_TO_BENEFIT = 40_000.0; // below this, CPA + payroll cost > benefit

    public function getId(): string          { return 'scorp_election'; }
    public function getName(): string        { return 'S-Corp Election (§1361/§1362)'; }
    public function getCodeSection(): string { return '§1361 / §1362 / Form 2553'; }
    public function getAggressionTier(): string { return 'conservative'; }
    public function getCategory(): string    { return 'tax'; }

    public function applies(array $intake, array $portfolioContext): bool
    {
        if (!$this->tierAllowed($intake)) {
            return false;
        }
        // Need meaningful net income
        $netIncome = $this->f($intake['ebitda_usd']);
        if ($netIncome < self::MIN_PROFIT_TO_BENEFIT) {
            return false;
        }
        // C-Corps should use §1202 path — don't double-recommend
        if (($intake['decided_entity_type'] ?? '') === 'c_corp') {
            return false;
        }
        // Already elected? Check qbi_election_active as proxy (field may not exist)
        // No direct "is_scorp" column in schema — can't gate on it; surface in rationale
        return true;
    }

    public function evaluate(array $intake, array $portfolioContext): array
    {
        if (!$this->applies($intake, $portfolioContext)) {
            return $this->notApplicable(
                'S-Corp election not applicable: net income below threshold, entity is C-Corp, ' .
                'or aggression tier too low.'
            );
        }

        $netIncome    = $this->f($intake['ebitda_usd']);
        $employees    = $this->i($intake['employee_count']);
        $entityType   = $intake['decided_entity_type'] ?? $intake['tier'] ?? 'llc';

        // Reasonable comp: 50 % of net income, floored at $35k, capped at SS wage base
        $reasonableComp = max(35_000.0, min(self::SS_WAGE_BASE, $netIncome * 0.50));

        // SE tax as sole proprietor / single-member LLC (full net income)
        $seTaxWithout = $this->calcSETax($netIncome);

        // After S-Corp: payroll taxes only on reasonable comp
        $payrollTaxScorp = $this->calcPayrollTax($reasonableComp);

        // Distribution (employer matches payroll taxes, so multiply by 2 for employer share)
        $grossSavings = $seTaxWithout - $payrollTaxScorp;

        // Costs: S-Corp election filing, additional tax prep, payroll processing
        $setupCost   = 500.0;   // Form 2553 + attorney review
        $ongoingCost = 2_200.0; // $100/mo payroll service + incremental CPA fees

        // Net savings Y1
        $netY1 = max(0.0, $grossSavings - $setupCost - $ongoingCost);
        $net5y = max(0.0, ($grossSavings - $ongoingCost) * 5.0 - $setupCost);

        // Applicability score: higher profit = higher score; discount for complex ownership
        $baseScore = 40;
        if ($netIncome >= 100_000) { $baseScore += 25; }
        if ($netIncome >= 200_000) { $baseScore += 20; }
        if ($employees === 0)      { $baseScore += 10; } // clean single-owner scenario
        $appScore = $this->score($baseScore);

        $multiMember = ($intake['tier'] ?? '') === 'T2' || $employees > 1;

        return [
            'applies'                    => true,
            'applicability_score'        => $appScore,
            'estimated_savings_y1_usd'   => round($netY1, 2),
            'estimated_savings_5y_usd'   => round($net5y, 2),
            'estimated_setup_cost_usd'   => $setupCost,
            'estimated_ongoing_cost_usd' => $ongoingCost,
            'risk_level'                 => 'low',
            'audit_visibility'           => 'low',
            'prerequisites_md'           => implode("\n", [
                '- Entity must be a domestic LLC or corporation (not a partnership or C-Corp)',
                '- ≤ 100 shareholders; only one class of stock/interest',
                '- No non-resident alien members',
                '- Must establish and document a **reasonable compensation** salary for owner-employees',
                '- Form 2553 must be filed by the 15th day of the 3rd month of the tax year (or any time during prior year)',
                '- Existing C-Corp with §1374 built-in gain exposure: 5-year BIG recognition period applies',
            ]),
            'rationale_md'               => implode("\n\n", [
                "**S-Corp Election — SE Tax Savings Analysis**",
                sprintf(
                    "Net income (EBITDA proxy): **\$%s**  \nReasonable comp: **\$%s**  \n" .
                    "SE tax as sole-prop/SMLLC: **\$%s**  \nPayroll tax on comp only: **\$%s**  \n" .
                    "Gross annual savings: **\$%s**  \nAnnual overhead cost: **\$%s**  \n" .
                    "**Net Y1 savings: \$%s**",
                    number_format($netIncome, 0),
                    number_format($reasonableComp, 0),
                    number_format($seTaxWithout, 0),
                    number_format($payrollTaxScorp, 0),
                    number_format($grossSavings, 0),
                    number_format($ongoingCost, 0),
                    number_format($netY1, 0)
                ),
                "Reasonable comp set at 50 % of EBITDA (\$" . number_format($reasonableComp, 0) . "). " .
                "IRS expects a facts-and-circumstances analysis — consult BLS Occupational Outlook or " .
                "RCReports.com for industry-specific median salary data. See *Watson v. Commissioner*, " .
                "668 F.3d 1008 (8th Cir. 2012) — court upheld IRS recharacterisation of distributions " .
                "as wages where comp was unreasonably low.",
                "**2026 limits:** SS wage base \$176,100; §1402(a) SE tax rate 15.3 % up to base, " .
                "2.9 % above. Employer/employee FICA split reduces owner W-2 net by ~7.65 % of comp.",
            ]),
            'gotchas_md'                 => implode("\n", [
                '- **Multi-member LLC (MNMS):** MNMS LLC is a multi-member entity — it cannot directly elect',
                '  S-Corp status. Individual single-member LLCs that are wholly owned by MNMS CAN elect.',
                '  Sabrina\'s 50 %+ ownership in MNMS means S-Corp election on the HoldCo is not available.',
                '- **Built-in gains:** C-Corp converting to S-Corp faces §1374 built-in gain tax for 5 years',
                '  on any gain that existed at conversion. Check `built_in_gain_usd` before advising conversion.',
                '- **Reasonable comp is the IRS\'s primary audit hook.** Salary must be defensible with',
                '  documented industry comps. *David E. Watson, P.C. v. U.S.*, 668 F.3d 1008.',
                '- **PTET election interaction:** If state pass-through entity tax (PTET) is elected,',
                '  S-Corp status may affect the PTET deduction calculation — coordinate with state CPA.',
                '- **QBI interaction:** S-Corp wages count toward the §199A W-2 wage limitation.',
                '  See QBI199APlaybook for combined optimisation.',
                '- **Form 2553 deadline is strict.** Late election relief available under Rev. Proc. 2013-30,',
                '  but requires proof of reasonable cause.',
            ]),
            'citations'                  => [
                'IRC §1361 — S Corporation Defined',
                'IRC §1362 — Election; Revocation; Termination',
                'IRC §1402(a) — Self-Employment Income',
                'Treas. Reg. §31.3121(d)-1 — Employee definition for FICA',
                'Rev. Rul. 74-44 — Reasonable compensation standard',
                'Watson v. Commissioner, T.C. Memo 2010-239; aff\'d 668 F.3d 1008 (8th Cir. 2012)',
                'Rev. Proc. 2013-30 — Late S-Corp election relief',
                'IRS Publication 589 — Tax Information on S Corporations',
            ],
            'docs_required'              => [
                'form_2553',          // Election by Small Business Corporation
                'corp_articles',      // Articles of incorporation or org
                'bylaws_or_oa',       // Bylaws (corp) or Operating Agreement (LLC)
                'reasonable_comp_memo', // Written documentation of salary benchmark
                'payroll_setup',      // Payroll service enrollment
            ],
            'next_actions'               => [
                'Obtain median industry salary data (BLS or RCReports) to defend reasonable comp',
                'File Form 2553 — due by 15th day of 3rd month of tax year (or by year-end for next year)',
                'Enroll in payroll service (Gusto, ADP, or manual state filings)',
                'Set owner W-2 salary at documented reasonable comp amount',
                'Coordinate with CPA on PTET and §199A interaction before election',
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function calcSETax(float $netIncome): float
    {
        // SE tax = 92.35 % × net × rate (deductible employer-equivalent portion)
        $seNet = $netIncome * 0.9235;
        if ($seNet <= self::SS_WAGE_BASE) {
            return round($seNet * self::SE_RATE_BELOW, 2);
        }
        return round(
            self::SS_WAGE_BASE * self::SE_RATE_BELOW +
            ($seNet - self::SS_WAGE_BASE) * self::SE_RATE_ABOVE,
            2
        );
    }

    private function calcPayrollTax(float $salary): float
    {
        // Employer FICA: 7.65 % up to SS base + 1.45 % above
        if ($salary <= self::SS_WAGE_BASE) {
            return round($salary * 0.0765 * 2, 2); // both halves
        }
        return round(
            self::SS_WAGE_BASE * 0.0765 * 2 +
            ($salary - self::SS_WAGE_BASE) * 0.0145 * 2,
            2
        );
    }
}
