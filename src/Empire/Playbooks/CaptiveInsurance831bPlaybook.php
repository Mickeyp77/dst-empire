<?php
/**
 * CaptiveInsurance831bPlaybook — IRC §831(b) Micro-Captive Insurance
 *
 * Evaluates whether the entity is a candidate for a §831(b) micro-captive
 * insurance arrangement — a small insurance company owned by the operating
 * company's principals that insures operating risks and elects to be taxed
 * only on investment income (not premium income) up to the annual limit.
 *
 * §831(b) mechanics:
 *   - Captive insurance company with premium income ≤ $2.85M/year (2026,
 *     indexed for inflation) may elect to be taxed ONLY on investment income.
 *   - Premium payments from OpCo to Captive are DEDUCTIBLE by OpCo as
 *     ordinary business expenses (if they constitute bona fide insurance).
 *   - Captive accumulates premium income tax-free (until paid as dividends/
 *     liquidating distributions to owners).
 *   - Captive must be a genuine insurance company: risk distribution,
 *     actuarially based premiums, separate operations.
 *
 * IRS AUDIT RISK — HIGH:
 *   §831(b) micro-captives have been on the IRS "Dirty Dozen" list since 2014.
 *   Listed Transactions (Notice 2016-66) apply to syndicated arrangements.
 *   After *Avrahami v. Commissioner* (2017), *Syzygy Insurance v. Commissioner*
 *   (2019), and *Reserve Mechanical Corp. v. Commissioner* (2018) — IRS won on
 *   all fronts. However, properly structured captives with genuine risks, arm's-
 *   length premiums, and real risk distribution continue to survive audit.
 *
 * Requirements for survival post-Avrahami:
 *   1. Insurance must cover genuine, commercially insurable risks
 *   2. Premiums must be actuarially determined (not reverse-engineered from deduction)
 *   3. Real risk distribution — usually via risk pool with unrelated captives
 *   4. Claims must actually be paid
 *   5. No circular cash flows (captive loans back to owner = red flag)
 *   6. Captive must be in a reputable jurisdiction (WY, MT, DE, UT for domestic)
 *
 * This playbook is flagged 'aggressive' given IRS scrutiny. Only recommend
 * for high-cash, high-liability entities with genuine uninsurable/underinsured risks.
 */

namespace Mnmsos\Empire\Playbooks;

class CaptiveInsurance831bPlaybook extends AbstractPlaybook
{
    private const MAX_PREMIUM_2026 = 2_850_000.0;  // §831(b)(2)(A)(ii) 2026 limit
    private const MIN_EBITDA_TO_JUSTIFY = 500_000.0; // Practical minimum for cost to make sense

    public function getId(): string          { return 'captive_831b'; }
    public function getName(): string        { return '§831(b) Micro-Captive Insurance'; }
    public function getCodeSection(): string { return '§831(b) / §162 / Notice 2016-66'; }
    public function getAggressionTier(): string { return 'aggressive'; }
    public function getCategory(): string    { return 'tax'; }

    public function applies(array $intake, array $portfolioContext): bool
    {
        if (!$this->tierAllowed($intake)) {
            return false;
        }
        $ebitda   = $this->f($intake['ebitda_usd']);
        $liab     = $intake['liability_profile'] ?? 'medium';
        if ($ebitda < self::MIN_EBITDA_TO_JUSTIFY) {
            return false;
        }
        // High-liability or professional-services entities have more genuine insurable risk
        $vertical = $intake['industry_vertical'] ?? 'other';
        return true;
    }

    public function evaluate(array $intake, array $portfolioContext): array
    {
        if (!$this->applies($intake, $portfolioContext)) {
            return $this->notApplicable(
                '§831(b) captive not applicable: EBITDA below $500k minimum, or aggression tier ' .
                'is conservative or growth (requires aggressive tier).'
            );
        }

        $ebitda   = $this->f($intake['ebitda_usd']);
        $revenue  = $this->f($intake['annual_revenue_usd']);
        $liab     = $intake['liability_profile'] ?? 'medium';
        $vertical = $intake['industry_vertical'] ?? 'other';
        $vehicles = $this->i($intake['vehicle_count']);
        $empCount = $this->i($intake['employee_count']);
        $activeClaims = $this->i($intake['active_claims_count']);

        // Conservative premium: 10–15 % of EBITDA, capped at §831(b) limit
        $targetPremium = min($ebitda * 0.12, self::MAX_PREMIUM_2026);

        // Tax savings: premium deducted at OpCo level (37 % or 21 % for C-Corp)
        $entityType = $intake['decided_entity_type'] ?? 'llc';
        $deductRate = ($entityType === 'c_corp') ? 0.21 : 0.37;
        $taxSavedY1 = $targetPremium * $deductRate;

        // Captive accumulates after-tax (only investment income taxed)
        // Long-term benefit: premiums compound tax-deferred inside captive
        // Conservative 5-yr estimate: 4× Y1 (net of setup, ongoing, and audit-risk discount)
        $savings5y = $taxSavedY1 * 4.0;

        // High setup/ongoing — actuarial, captive management, domicile fees
        $setupCost   = 15_000.0; // Captive formation, actuarial study, domicile filing
        $ongoingCost = 10_000.0; // Annual: actuarial, captive management, state filing, audit

        // Identify genuine risk categories
        $risks = [];
        if ($vehicles > 0)                            { $risks[] = 'fleet/auto liability'; }
        if ($empCount >= 5)                            { $risks[] = 'employment practices liability'; }
        if (in_array($vertical, ['healthcare', 'professional_services'], true)) {
            $risks[] = 'professional liability / E&O';
        }
        if ($intake['real_estate_owned'] ?? 0)         { $risks[] = 'property/casualty'; }
        if (!empty($intake['regulatory_exposure_md']))  { $risks[] = 'regulatory/compliance'; }
        $risks[] = 'cyber liability'; // virtually universal now
        $risks[] = 'business interruption';

        $genuineRiskCount = count($risks);

        $appScore = 20; // Start low — aggressive tier
        if ($ebitda > 1_000_000)      { $appScore += 20; }
        if ($genuineRiskCount >= 3)    { $appScore += 20; }
        if ($activeClaims === 0)       { $appScore += 10; } // Clean history = better audit posture
        if ($taxSavedY1 > 50_000)     { $appScore += 15; }

        return [
            'applies'                    => true,
            'applicability_score'        => $this->score($appScore),
            'estimated_savings_y1_usd'   => round($taxSavedY1, 2),
            'estimated_savings_5y_usd'   => round($savings5y, 2),
            'estimated_setup_cost_usd'   => $setupCost,
            'estimated_ongoing_cost_usd' => $ongoingCost,
            'risk_level'                 => 'high',
            'audit_visibility'           => 'high',
            'prerequisites_md'           => implode("\n", [
                '- EBITDA ≥ $500k (premiums must be economically significant and cashflow-positive)',
                '- Genuine commercially insurable risks that are uninsured or underinsured',
                '- No active IRS audit or payment plan (§831(b) increases audit exposure significantly)',
                '- Must use a qualified actuary to set premiums — **never reverse-engineer from deduction**',
                '- Risk distribution: join a risk-sharing pool with unrelated captives (post-Avrahami req.)',
                '- Captive must be in a regulated jurisdiction (WY, MT, VT, UT, or offshore)',
                '- Captive must pay actual claims — maintain separate claims reserve',
                '- No circular loans from captive back to owner entity',
            ]),
            'rationale_md'               => implode("\n\n", [
                '**§831(b) Micro-Captive Analysis** ⚠️ HIGH IRS SCRUTINY',
                sprintf(
                    "EBITDA: **\$%s** | Target premium: **\$%s** (12%% of EBITDA, capped at §831(b) limit)  \n" .
                    "Deduction tax savings at %s%%: **\$%s/yr**  \n" .
                    "Identified genuine risk categories (%d): %s",
                    number_format($ebitda, 0),
                    number_format($targetPremium, 0),
                    number_format($deductRate * 100, 0),
                    number_format($taxSavedY1, 0),
                    $genuineRiskCount,
                    implode(', ', $risks)
                ),
                '**§831(b) election:** Captive taxed only on investment income (not on premiums received). ' .
                'Premium income accumulates inside the captive until distributed as dividends (qualified ' .
                'dividend rate) or liquidating distributions (capital gains). The combined effect is a ' .
                'rate arbitrage: OpCo deducts at ordinary rates; captive accumulates at near-zero tax.',
                '**Post-Avrahami survival requirements:** IRS won *Avrahami v. Commissioner*, 149 T.C. 7 ' .
                '(2017) on grounds the captive insured implausible risks at inflated premiums and had ' .
                'circular cash flows. Surviving captives must have actuarially sound premiums, genuine ' .
                'risk distribution, and a demonstrated claims history.',
            ]),
            'gotchas_md'                 => implode("\n", [
                '- **IRS Dirty Dozen:** §831(b) captives listed since 2014. High audit selection rate.',
                '- **Notice 2016-66:** Certain micro-captive transactions are "listed transactions"',
                '  requiring disclosure on Form 8886 (reportable transaction). Failure = 75 % penalty.',
                '- **Avrahami / Syzygy / Reserve Mechanical:** IRS won all three leading cases.',
                '  Court disallowed deductions where premiums were not actuarially sound.',
                '- **Circular cash flows:** Captive loaning money back to owner = sham. Document that',
                '  captive funds are genuinely invested in arms-length assets.',
                '- **Captive promoter fees:** Many captive promoters charge 10–15 % of premiums.',
                '  Compare total cost (promoter + actuary + filing) against tax savings.',
                '- **Exit strategy:** Liquidating a captive triggers tax on accumulated premiums.',
                '  Plan the exit before formation.',
                '- **State premium tax:** Most states impose 0.5–3 % premium tax on captive premiums.',
                '- **Minimum viable size:** Below $200k in tax savings/year, costs often exceed benefit.',
            ]),
            'citations'                  => [
                'IRC §831(b) — Tax on Insurance Companies Other Than Life Insurance Companies',
                'IRC §162 — Trade or Business Expenses (deductibility of premiums)',
                'Notice 2016-66 — Micro-captive listed transactions',
                'Avrahami v. Commissioner, 149 T.C. 7 (2017)',
                'Syzygy Insurance Co. v. Commissioner, T.C. Memo 2019-34',
                'Reserve Mechanical Corp. v. Commissioner, 2018 WL 4705967 (10th Cir.)',
                'IRS Chief Counsel Advice 202026010',
                'IRS "Dirty Dozen" Tax Scams (annual)',
            ],
            'docs_required'              => [
                'captive_feasibility_study',  // Economic analysis before formation
                'actuarial_report',           // Premium adequacy certification
                'captive_formation_docs',     // Articles, bylaws, domicile filing
                'risk_pool_agreement',        // Third-party risk distribution
                'insurance_policies',         // Policies issued by captive to OpCo
                'claims_reserve_schedule',    // Documented reserve methodology
                'form_8886',                  // Reportable transaction disclosure if required
            ],
            'next_actions'               => [
                'Engage independent captive feasibility consultant (NOT a captive promoter)',
                'Obtain actuarial premium study from credentialed actuary (FCAS/MAAA)',
                'Join a reputable risk-distribution pool (not a circular arrangement)',
                'Confirm IRS audit posture with tax counsel before formation',
                'Model exit strategy before committing (captive liquidation tax implications)',
                'File Form 8886 if transaction meets Notice 2016-66 disclosure criteria',
            ],
        ];
    }
}
