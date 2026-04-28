<?php
/**
 * Solo401kMaxPlaybook — Solo 401(k) Maximum Contributions (§401(k) + §415)
 *
 * Evaluates and maximises solo 401(k) contributions for a self-employed
 * owner and spouse W-2 employees.
 *
 * 2026 limits (IRC §415(c) and §402(g)):
 *   Elective deferral: $23,500 (employee pre-tax + Roth, indexed)
 *   Catch-up (age 50+): $7,500 additional → $31,000 total employee
 *   SECURE 2.0 super catch-up (age 60–63): $11,250 (2026 amount)
 *   Employer profit-sharing: up to 25 % of W-2 compensation (for S-Corp)
 *     or 20 % of net self-employment income (for sole-prop / SMLLC)
 *   §415(c) annual additions cap: $70,000 (2026) [$77,500 with age 50+ catch-up]
 *   Combined employee + employer max: $70,000 (under 50) or $77,500 (50+)
 *   SECURE 2.0 super catch-up: $81,250 (age 60–63 in 2026)
 *
 * Solo 401(k) (also called Individual 401(k) or Self-Employed 401(k)):
 *   Available to self-employed individuals and business owners with NO
 *   full-time W-2 employees other than the owner and owner's spouse.
 *   Employee side: elective deferral (pre-tax or Roth)
 *   Employer side: profit-sharing contribution
 *
 * Spouse W-2:
 *   If spouse works for the business as a bona fide W-2 employee, spouse
 *   gets identical contribution limits (not shared with owner's limits).
 *   This doubles total tax-deferred contributions.
 *
 * After-tax mega-backdoor Roth:
 *   If plan document allows, after-tax contributions + in-plan Roth
 *   conversion can push total to §415(c) limit ($70k).
 *
 * SIMPLE IRA vs Solo 401(k):
 *   Solo 401(k) always wins for maximum deferral; SIMPLE IRA limited to
 *   $16,500 (2026) — only use if you have employees you must cover.
 *
 * Tax savings:
 *   Each $1 of pre-tax contribution saves owner's marginal tax rate.
 *   At 37 % federal + ~5 % state average = 42 % effective savings rate.
 */

namespace Mnmsos\Empire\Playbooks;

class Solo401kMaxPlaybook extends AbstractPlaybook
{
    // 2026 limits
    private const ELECTIVE_DEFERRAL_2026    = 23_500.0;
    private const CATCHUP_50_PLUS_2026      = 7_500.0;
    private const SUPER_CATCHUP_60_63_2026  = 11_250.0; // SECURE 2.0 §401(k)(6)(A)(ii)
    private const ANNUAL_ADDITIONS_CAP_2026 = 70_000.0; // §415(c)
    private const EMPLOYER_PCT_SCORP        = 0.25;  // 25 % of W-2 compensation
    private const EMPLOYER_PCT_SELFEMPL     = 0.20;  // 20 % of net SE income (approx)
    private const EFFECTIVE_TAX_RATE        = 0.42;  // 37 % federal + 5 % state approx

    public function getId(): string          { return 'solo_401k_max'; }
    public function getName(): string        { return 'Solo 401(k) Maximum Contributions (§401(k) + §415)'; }
    public function getCodeSection(): string { return '§401(k) / §415 / §402(g) / SECURE 2.0 Act'; }
    public function getAggressionTier(): string { return 'conservative'; }
    public function getCategory(): string    { return 'tax'; }

    public function applies(array $intake, array $portfolioContext): bool
    {
        if (!$this->tierAllowed($intake)) {
            return false;
        }
        // Need income to contribute
        $ebitda = $this->f($intake['ebitda_usd']);
        if ($ebitda < 30_000.0) {
            return false;
        }
        // Solo 401(k) requires no W-2 employees OTHER than owner + spouse
        $employees = $this->i($intake['employee_count']);
        // Allow owner (0 count = solo) or owner + spouse (1 count = plausible)
        // If > 2 employees, standard 401(k) needed — different playbook
        if ($employees > 2) {
            return false; // Non-solo plan territory; different analysis
        }
        return true;
    }

    public function evaluate(array $intake, array $portfolioContext): array
    {
        if (!$this->applies($intake, $portfolioContext)) {
            return $this->notApplicable(
                'Solo 401(k) not applicable: EBITDA below $30k, or > 2 employees (standard ' .
                '401(k) plan required instead of solo plan).'
            );
        }

        $ebitda     = $this->f($intake['ebitda_usd']);
        $entityType = $intake['decided_entity_type'] ?? 'llc';
        $employees  = $this->i($intake['employee_count']);
        $ownerAge   = $this->i($portfolioContext['owner_age_years'] ?? 40);
        $spousePct  = $this->f($portfolioContext['spouse_member_pct'] ?? 0);
        $hasSpouse  = $spousePct > 0;

        // Determine owner compensation base
        // For S-Corp: reasonable comp (use 50 % of EBITDA proxy)
        // For sole-prop / SMLLC: net SE income (use EBITDA × 0.9235 for SE adjustment)
        $isSCorp    = ($entityType === 's_corp');
        $ownerComp  = $isSCorp
            ? max(35_000.0, $ebitda * 0.50)
            : $ebitda * 0.9235;

        // Elective deferral (employee contribution)
        $electiveDeferral = self::ELECTIVE_DEFERRAL_2026;
        if ($ownerAge >= 60 && $ownerAge <= 63) {
            $electiveDeferral += self::SUPER_CATCHUP_60_63_2026;
        } elseif ($ownerAge >= 50) {
            $electiveDeferral += self::CATCHUP_50_PLUS_2026;
        }

        // Employer profit-sharing
        $employerRate     = $isSCorp ? self::EMPLOYER_PCT_SCORP : self::EMPLOYER_PCT_SELFEMPL;
        $employerContrib  = $ownerComp * $employerRate;

        // Total for owner — capped at §415(c)
        $catchupCap = $ownerAge >= 50 ? self::ANNUAL_ADDITIONS_CAP_2026 + ($ownerAge >= 60 && $ownerAge <= 63 ? self::SUPER_CATCHUP_60_63_2026 : self::CATCHUP_50_PLUS_2026) : self::ANNUAL_ADDITIONS_CAP_2026;
        $ownerTotal = min($electiveDeferral + $employerContrib, $catchupCap);

        // Spouse contribution (if employed and $ebitda covers spouse salary)
        $spouseTotal = 0.0;
        if ($hasSpouse && $employees >= 1) {
            // Spouse W-2 assumed at $50k (conservative)
            $spouseSalary    = 50_000.0;
            $spouseElective  = self::ELECTIVE_DEFERRAL_2026;
            $spouseEmployer  = $spouseSalary * self::EMPLOYER_PCT_SCORP;
            $spouseTotal     = min($spouseElective + $spouseEmployer, self::ANNUAL_ADDITIONS_CAP_2026);
        }

        $totalAnnualContrib = $ownerTotal + $spouseTotal;
        $taxSavedY1 = $totalAnnualContrib * self::EFFECTIVE_TAX_RATE;
        $savings5y  = $taxSavedY1 * 5.0;

        // Opportunity cost / investment growth not modelled — pure tax deferral only

        // Setup / ongoing — minimal for solo plan
        $setupCost   = 300.0;  // Plan document (many custodians provide free)
        $ongoingCost = 0.0;    // Most solo 401(k) custodians charge $0 annual fee

        $appScore = 50; // Always valuable when it applies
        if ($ownerAge >= 50)          { $appScore += 15; }
        if ($hasSpouse)               { $appScore += 15; }
        if ($taxSavedY1 > 10_000)    { $appScore += 10; }
        if ($taxSavedY1 > 20_000)    { $appScore += 10; }

        return [
            'applies'                    => true,
            'applicability_score'        => $this->score($appScore),
            'estimated_savings_y1_usd'   => round($taxSavedY1, 2),
            'estimated_savings_5y_usd'   => round($savings5y, 2),
            'estimated_setup_cost_usd'   => $setupCost,
            'estimated_ongoing_cost_usd' => $ongoingCost,
            'risk_level'                 => 'low',
            'audit_visibility'           => 'low',
            'prerequisites_md'           => implode("\n", [
                '- Business must have NO full-time W-2 employees except owner and owner\'s spouse',
                '- Owner (and spouse) must have earned income from the business',
                '- Plan document must be adopted by December 31 of the tax year',
                '- Solo 401(k) must be established before first contribution',
                '- If S-Corp: owner must take reasonable W-2 compensation (required for employer match calc)',
                '- Spouse must be a bona fide W-2 employee of the business (not just a nominal hire)',
                '- Mega-backdoor Roth requires plan document that explicitly allows after-tax contributions',
            ]),
            'rationale_md'               => implode("\n\n", [
                '**Solo 401(k) Maximum Contribution Analysis**',
                sprintf(
                    "Owner age: **%d** | Entity type: **%s**  \n" .
                    "Owner comp base: **\$%s**  \n" .
                    "Owner elective deferral: **\$%s** (incl. catch-up: %s)  \n" .
                    "Employer profit-sharing (%s%% of comp): **\$%s**  \n" .
                    "Owner total contribution: **\$%s** (§415(c) cap: \$%s)  \n" .
                    "%s" .
                    "Total household contributions: **\$%s**  \n" .
                    "Annual tax savings at 42%% (37%% + 5%% state): **\$%s**",
                    $ownerAge,
                    $entityType,
                    number_format($ownerComp, 0),
                    number_format(self::ELECTIVE_DEFERRAL_2026, 0),
                    $ownerAge >= 60 && $ownerAge <= 63 ? 'super catch-up $' . number_format(self::SUPER_CATCHUP_60_63_2026, 0) : ($ownerAge >= 50 ? 'catch-up $' . number_format(self::CATCHUP_50_PLUS_2026, 0) : 'none'),
                    number_format($employerRate * 100, 0),
                    number_format($employerContrib, 0),
                    number_format($ownerTotal, 0),
                    number_format(self::ANNUAL_ADDITIONS_CAP_2026, 0),
                    $hasSpouse
                        ? sprintf("Spouse contribution (est.): **\$%s**  \n", number_format($spouseTotal, 0))
                        : '',
                    number_format($totalAnnualContrib, 0),
                    number_format($taxSavedY1, 0)
                ),
                'Solo 401(k) contributions compound **tax-deferred** (traditional) or **tax-free** ' .
                '(Roth). At a 7 % annual return, $70k/yr for 10 years = ~$966k. At 42 % effective ' .
                'tax rate, the Y1 tax deferral alone funds a significant portion of ongoing contributions.',
                $ownerAge >= 60 && $ownerAge <= 63
                    ? '**SECURE 2.0 Super Catch-Up:** You qualify for the §401(k)(6)(A)(ii) super ' .
                      'catch-up contribution of $11,250 (2026) as your age is 60–63. This is in ' .
                      'ADDITION to the standard catch-up. Use it — this window closes at 64.'
                    : '',
            ]),
            'gotchas_md'                 => implode("\n", [
                '- **Plan must be adopted by 12/31.** Late adoption = no deduction for that year.',
                '  (SECURE Act extended deadline for new businesses to tax-filing date, but existing',
                '  businesses still use 12/31.)',
                '- **Employee hiring trigger:** The moment you hire a W-2 employee (non-spouse) who',
                '  works 1,000 hours, you must convert to a standard 401(k) — potentially with',
                '  matching obligations. Plan ahead.',
                '- **Elective deferral per-person, not per-plan:** If owner has another W-2 job,',
                '  the $23,500 elective deferral is shared across ALL plans. Employer match is separate.',
                '- **Loans:** Solo 401(k) permits loans (up to $50k or 50 % of balance). Document',
                '  repayment carefully — failure = taxable distribution + 10 % penalty.',
                '- **Roth conversion:** In-plan Roth conversion available if plan document allows.',
                '  Best in low-income years (e.g., startup phase).',
                '- **Required Minimum Distributions (RMDs):** Begin at age 73 (SECURE 2.0). Roth 401(k)',
                '  RMDs eliminated starting 2024. Traditional still has RMDs.',
                '- **SIMPLE IRA comparison:** SIMPLE IRA has lower limits ($16,500 + $3,500 catch-up)',
                '  and requires employer to cover eligible employees. Solo 401(k) wins unless you have',
                '  employees to cover.',
            ]),
            'citations'                  => [
                'IRC §401(k) — Cash or Deferred Arrangements',
                'IRC §415(c) — Annual Additions Limit ($70,000 for 2026)',
                'IRC §402(g) — Elective Deferrals ($23,500 for 2026)',
                'SECURE Act of 2019 — Extended new plan adoption deadline',
                'SECURE 2.0 Act of 2022 — Super catch-up §401(k)(6)(A)(ii)',
                'IRS Publication 560 — Retirement Plans for Small Business',
                'Treas. Reg. §1.401(k)-1 — Qualification requirements',
                'Rev. Proc. 2024-40 — 2025/2026 retirement contribution limits',
            ],
            'docs_required'              => [
                'solo_401k_plan_document',    // Prototype plan (free from Fidelity, Vanguard, Schwab)
                'adoption_agreement',         // Plan adoption before 12/31
                'annual_contribution_memo',   // Calculation documentation
                'form_5500ez',                // Required if plan assets > $250k
                'loan_agreement',             // If plan loans are used
            ],
            'next_actions'               => [
                'Open solo 401(k) at zero-fee custodian (Fidelity, Vanguard, or Schwab — all free)',
                'Adopt plan document by December 31 of current tax year',
                'Maximize employee elective deferral: contribute $' . number_format($electiveDeferral, 0) . ' by year-end',
                'Make employer profit-sharing contribution by tax-filing deadline (+ extensions)',
                $hasSpouse ? 'Set up spouse as W-2 employee and maximize spouse contributions' : 'Consider adding spouse to payroll to double household contribution limit',
                'Review plan document for after-tax / mega-backdoor Roth provision if desired',
            ],
        ];
    }
}
