<?php
/**
 * MgmtFeeTransferPricingPlaybook — Management Fee / Cost-Plus Intercompany Pricing (§482)
 *
 * Evaluates whether a management fee arrangement from operating subsidiaries
 * to a HoldCo (MNMS LLC or a management company) is appropriate and how to
 * structure it in compliance with §482 arm's-length standards.
 *
 * Purpose:
 *   1. **Income consolidation:** Centralize profits in HoldCo for reinvestment.
 *   2. **Expense sharing:** HoldCo provides shared services (accounting, HR, IT,
 *      legal, executive time) — subs pay their proportionate share.
 *   3. **State tax planning:** If OpCo has nexus in a high-tax state and HoldCo
 *      is in WY or TX (no income tax), fee shifts taxable income to lower-tax entity.
 *   4. **Owner comp optimization:** HoldCo can pay owner salary/bonus; subs deduct
 *      the management fee rather than having multiple payroll sources.
 *
 * §482 compliance — Cost-Plus Method (Treas. Reg. §1.482-3(d)):
 *   - Cost-plus markup for management services: 5–15 % markup on direct costs
 *     is a commonly accepted arm's-length range for routine support services.
 *   - Services providing higher value (strategic, C-suite) warrant 10–20 % markup.
 *   - Must document: (a) services actually provided, (b) direct and indirect cost
 *     allocation methodology, (c) markup percentage and basis.
 *
 * §162(e)/(m) limits:
 *   - §162(m) disallows deduction of > $1M compensation to covered employees of
 *     public companies — not directly applicable here but note for future.
 *   - Fees must be for services actually rendered — no "parking" fees.
 *
 * Intercompany agreements:
 *   - Must be in writing before services are rendered (not retroactive).
 *   - Annual invoicing preferred; quarterly acceptable.
 *   - Cost allocation key must be objective (revenue %, headcount, time, etc.).
 */

namespace Mnmsos\Empire\Playbooks;

class MgmtFeeTransferPricingPlaybook extends AbstractPlaybook
{
    private const MARKUP_COST_PLUS  = 0.10;  // 10 % cost-plus markup — middle of range
    private const MIN_REVENUE_FLOOR = 200_000.0; // Below this, overhead too small to justify

    public function getId(): string          { return 'mgmt_fee_transfer_pricing'; }
    public function getName(): string        { return 'Management Fee / Cost-Plus Intercompany Pricing (§482)'; }
    public function getCodeSection(): string { return '§482 / Treas. Reg. §1.482-3 / §162'; }
    public function getAggressionTier(): string { return 'conservative'; }
    public function getCategory(): string    { return 'intercompany'; }

    public function applies(array $intake, array $portfolioContext): bool
    {
        if (!$this->tierAllowed($intake)) {
            return false;
        }
        $revenue = $this->f($intake['annual_revenue_usd']);
        if ($revenue < self::MIN_REVENUE_FLOOR) {
            return false;
        }
        // Useful when entity is a subsidiary of a HoldCo or has related entities
        // We apply broadly — BrandPlacement's parent_kind signals this
        return true;
    }

    public function evaluate(array $intake, array $portfolioContext): array
    {
        if (!$this->applies($intake, $portfolioContext)) {
            return $this->notApplicable(
                'Management fee arrangement not applicable: revenue too low, or aggression tier too low.'
            );
        }

        $revenue    = $this->f($intake['annual_revenue_usd']);
        $ebitda     = $this->f($intake['ebitda_usd']);
        $opex       = $this->f($intake['opex_usd']);
        $employees  = $this->i($intake['employee_count']);
        $vertical   = $intake['industry_vertical'] ?? 'other';

        // Estimate shared-service costs absorbed by HoldCo:
        // Assume HoldCo provides: accounting, IT/systems, executive oversight, HR, legal
        // Conservative: 8 % of revenue as proxy for shared overhead pool
        $sharedServiceCosts = $revenue * 0.08;

        // Management fee = shared costs × (1 + markup)
        $mgmtFee = $sharedServiceCosts * (1.0 + self::MARKUP_COST_PLUS);

        // Tax benefit: OpCo deducts fee; HoldCo receives fee as income
        // Net benefit only materialises if HoldCo is in lower-tax jurisdiction
        // Conservative: assume 5 % effective state tax rate differential
        $stateTaxBenefit = $mgmtFee * 0.05;

        // Administrative benefit: centralised services reduce per-entity overhead
        // Estimate: 1 FTE saved at $50k across portfolio (split among entities)
        $adminSavingsY1 = 10_000.0; // Conservative per-entity share

        $savingsY1 = $stateTaxBenefit + $adminSavingsY1;
        $savings5y = $savingsY1 * 5.0;

        $setupCost   = 2_500.0;  // Legal: intercompany agreement + cost-allocation schedule
        $ongoingCost = 1_000.0;  // Annual: invoicing, allocation update, CPA review

        $appScore = 40;
        if ($revenue > 500_000)    { $appScore += 15; }
        if ($employees > 3)        { $appScore += 10; }
        if ($ebitda > 100_000)     { $appScore += 15; }
        if ($vertical === 'saas')  { $appScore += 5; }

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
                '- A parent HoldCo (MNMS LLC or a management LLC) must exist or be formed',
                '- HoldCo must **actually provide** identifiable services to each subsidiary',
                '- Written **Intercompany Services Agreement** must predate service delivery',
                '- Cost allocation key must be objective and consistently applied (revenue %, headcount)',
                '- Invoice and pay the fee regularly — annual accruals without payment create audit risk',
                '- Markup must be within arm\'s-length range (5–15 % for routine support services)',
            ]),
            'rationale_md'               => implode("\n\n", [
                '**Management Fee / Cost-Plus Analysis**',
                sprintf(
                    "Revenue: **\$%s** | EBITDA: **\$%s**  \n" .
                    "Estimated shared-service cost pool (8%% of revenue): **\$%s**  \n" .
                    "Management fee at 10%% cost-plus markup: **\$%s/yr**  \n" .
                    "State tax differential savings (~5%%): **\$%s/yr**  \n" .
                    "Admin centralisation savings (per entity): **\$%s/yr**",
                    number_format($revenue, 0),
                    number_format($ebitda, 0),
                    number_format($sharedServiceCosts, 0),
                    number_format($mgmtFee, 0),
                    number_format($stateTaxBenefit, 0),
                    number_format($adminSavingsY1, 0)
                ),
                '**Cost-plus method (Treas. Reg. §1.482-3(d)):** HoldCo charges subs its direct costs ' .
                'plus a markup. The 10 % markup is within the IRS-accepted range for routine support ' .
                'services and matches market rates for outsourced back-office services (per OECD Transfer ' .
                'Pricing Guidelines, Chapter VII — Intragroup Services).',
                '**MNMS HoldCo context:** MNMS LLC already serves as the umbrella entity for 24+ brands. ' .
                'Formalising a management fee structure converts informal overhead absorption into a ' .
                'documented, deductible expense at each sub and generates taxable income at the HoldCo ' .
                'level — which can then be deployed for portfolio-level investment or owner distributions.',
            ]),
            'gotchas_md'                 => implode("\n", [
                '- **No sham fees.** IRS will disallow fees if no services were actually rendered.',
                '  *Hospital Corp. of America v. Commissioner*, 81 T.C. 520 (1983) — fees upheld only',
                '  where documented benefit to payor. Keep service logs.',
                '- **Circular argument:** HoldCo cannot charge subs for services the subs already perform',
                '  themselves. Map services explicitly.',
                '- **Consistent methodology:** Once you establish an allocation key (e.g. revenue %),',
                '  stick to it. Changing annually triggers scrutiny.',
                '- **§267 related-party rules:** Deduction at payor entity is limited to the year the',
                '  amount is INCLUDED in payee\'s income. Cash the check in the same tax year.',
                '- **Sabrina ownership:** If Sabrina owns 50 %+ of both HoldCo and sub, §267(a)(2)',
                '  deferred-deduction rules apply — sub cannot deduct until HoldCo includes in income.',
                '  Keep books on accrual or cash, whichever matches across entities.',
                '- **Consolidated return:** If HoldCo and subs are C-Corps with >80 % ownership, consider',
                '  a consolidated return — intercompany fees eliminate entirely and you get loss offsets.',
            ]),
            'citations'                  => [
                'IRC §482 — Allocation of Income and Deductions Among Taxpayers',
                'Treas. Reg. §1.482-3(d) — Cost-plus method',
                'Treas. Reg. §1.482-9 — Methods for services',
                'OECD Transfer Pricing Guidelines, Chapter VII (Intragroup Services)',
                'IRC §267 — Losses, Expenses, and Interest Between Related Taxpayers',
                'Hospital Corp. of America v. Commissioner, 81 T.C. 520 (1983)',
                'IRC §162 — Trade or Business Expense Deduction',
            ],
            'docs_required'              => [
                'intercompany_services_agreement', // Must be in writing before services
                'cost_allocation_schedule',        // Objective allocation key
                'service_delivery_log',            // Evidence services were rendered
                'annual_invoices',                 // Regular invoicing
                'transfer_pricing_memo',           // §6662 documentation
            ],
            'next_actions'               => [
                'Draft Intercompany Services Agreement between MNMS HoldCo and each sub',
                'Define cost allocation key (recommend: revenue % allocation)',
                'Document services HoldCo provides: executive time, accounting, IT, HR, legal',
                'Issue quarterly invoices and collect payment promptly',
                'Prepare annual transfer pricing memo (1–2 pages) documenting markup basis',
                'Confirm with CPA that HoldCo income and sub deduction fall in same tax year',
            ],
        ];
    }
}
