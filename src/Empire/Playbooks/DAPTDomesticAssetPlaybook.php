<?php
/**
 * DAPTDomesticAssetPlaybook — Domestic Asset Protection Trust (DAPT)
 *
 * Evaluates whether a Domestic Asset Protection Trust is appropriate for
 * the entity owner and what triggering thresholds apply.
 *
 * DAPT overview:
 *   A DAPT is an irrevocable self-settled spendthrift trust in a state
 *   that permits the settlor to be a discretionary beneficiary. This means
 *   the settlor can potentially benefit from the trust while shielding assets
 *   from future creditors (after the seasoning period).
 *
 * States with DAPT statutes (as of 2026):
 *   Wyoming  (W.S. §4-10-504): 2-year seasoning, no exception creditors for tort claims
 *   Nevada   (NRS §166.170):   2-year seasoning, very strong — no exception for divorce
 *   South Dakota (SDCL §55-16): 2-year seasoning, no state income tax, no exception creditors
 *   Delaware (12 Del. C. §3570): 4-year seasoning
 *   Alaska   (AS §34.40.110):   4-year seasoning
 *
 * Preferred: Wyoming or Nevada (strongest statutes, no state income tax)
 * For Mickey: SD + WY are optimal (SD has best overall statute, no income tax,
 *   and integrates with SD's strong DAPT + WY's charging order).
 *
 * Seasoning period:
 *   Contributions made outside the seasoning period are generally protected.
 *   Contributions during insolvency or with actual intent to defraud are
 *   NEVER protected (UFTA §4).
 *
 * Trigger thresholds for recommendation:
 *   - Net worth > $500k (smaller estates → simpler structures sufficient)
 *   - High professional liability (healthcare, legal, engineering)
 *   - Active litigation or regulatory exposure
 *   - Real estate ownership
 *   - Significant IP or business equity
 *
 * DAPT as QSBS stacking tool:
 *   DAPT owning IPCo LLC = separate §1202 exclusion cap.
 *   See QSBS1202Playbook for combined strategy.
 */

namespace Mnmsos\Empire\Playbooks;

class DAPTDomesticAssetPlaybook extends AbstractPlaybook
{
    // State seasoning periods (years)
    private const SEASONING = [
        'WY' => 2,
        'NV' => 2,
        'SD' => 2,
        'DE' => 4,
        'AK' => 4,
    ];

    private const MIN_NET_WORTH_PROXY = 500_000.0; // Below this, simpler structures suffice

    public function getId(): string          { return 'dapt_domestic_asset'; }
    public function getName(): string        { return 'Domestic Asset Protection Trust (DAPT)'; }
    public function getCodeSection(): string { return 'W.S. §4-10-504 / NRS §166.170 / SDCL §55-16'; }
    public function getAggressionTier(): string { return 'growth'; }
    public function getCategory(): string    { return 'estate'; }

    public function applies(array $intake, array $portfolioContext): bool
    {
        if (!$this->tierAllowed($intake)) {
            return false;
        }
        // Estimate net worth proxy from business assets + revenue
        $revenue   = $this->f($intake['annual_revenue_usd']);
        $equip     = $this->f($intake['equipment_value_usd']);
        $ebitda    = $this->f($intake['ebitda_usd']);
        $netWorthProxy = ($ebitda > 0 ? $ebitda * 4.0 : $revenue * 1.0) + $equip;

        if ($netWorthProxy < self::MIN_NET_WORTH_PROXY) {
            return false;
        }

        $liab     = $intake['liability_profile'] ?? 'low';
        $hasRisk  = in_array($liab, ['medium', 'med_high', 'high'], true);
        $hasIP    = !empty(trim((string)($intake['ip_owned_md'] ?? '')));
        $hasRE    = ($intake['real_estate_owned'] ?? 0);

        return $hasRisk || $hasIP || $hasRE || $netWorthProxy >= $this->f(1_000_000);
    }

    public function evaluate(array $intake, array $portfolioContext): array
    {
        if (!$this->applies($intake, $portfolioContext)) {
            return $this->notApplicable(
                'DAPT not applicable: estimated net worth proxy below $500k, no significant liability ' .
                'exposure or assets, or aggression tier too low.'
            );
        }

        $revenue     = $this->f($intake['annual_revenue_usd']);
        $equip       = $this->f($intake['equipment_value_usd']);
        $ebitda      = $this->f($intake['ebitda_usd']);
        $liab        = $intake['liability_profile'] ?? 'medium';
        $activeClaims = $this->i($intake['active_claims_count']);
        $ipOwned     = $intake['ip_owned_md'] ?? '';
        $realEstate  = ($intake['real_estate_owned'] ?? 0);
        $ownerAge    = $this->i($portfolioContext['owner_age_years'] ?? 45);
        $domicile    = $portfolioContext['domicile_state'] ?? 'TX';
        $estPlanCurrent = ($portfolioContext['estate_plan_current'] ?? 0);

        // Net worth proxy for protected asset size
        $enterpriseValue = ($ebitda > 0) ? $ebitda * 4.0 : $revenue * 1.0;
        $totalNetWorth   = $enterpriseValue + $equip + ($realEstate ? 200_000.0 : 0.0);

        // Best DAPT jurisdiction recommendation
        $recommendedState   = 'SD'; // Best overall statute
        $seasoningYears     = self::SEASONING[$recommendedState];

        // Asset protection value: risk of uninsured loss × probability
        // Conservative: 10 % of net worth × probability of claim (30 % for high-liability, 10 % for medium)
        $claimProbability   = in_array($liab, ['high', 'med_high'], true) ? 0.30 : 0.10;
        $protectionValue    = $totalNetWorth * $claimProbability * 0.70; // 70 % of claim protected

        $setupCost   = 7_500.0;  // Trust drafting, SD trustee, initial transfer
        $ongoingCost = 2_500.0;  // Annual trustee fee, filings, trust admin

        // QSBS stacking bonus flag
        $qsbsStackable = ($intake['decided_entity_type'] ?? '') === 'c_corp';

        $appScore = 25;
        if ($totalNetWorth > 1_000_000)   { $appScore += 20; }
        if ($totalNetWorth > 3_000_000)   { $appScore += 15; }
        if (in_array($liab, ['high', 'med_high'], true)) { $appScore += 15; }
        if (!empty($ipOwned))             { $appScore += 10; }
        if ($qsbsStackable)               { $appScore += 10; }
        if ($activeClaims > 0)            { $appScore -= 20; } // Can't form under fire

        return [
            'applies'                    => true,
            'applicability_score'        => $this->score($appScore),
            'estimated_savings_y1_usd'   => 0.0,  // Asset protection, not income tax savings
            'estimated_savings_5y_usd'   => round($protectionValue, 2), // Risk-adjusted protection value
            'estimated_setup_cost_usd'   => $setupCost,
            'estimated_ongoing_cost_usd' => $ongoingCost,
            'risk_level'                 => 'medium',
            'audit_visibility'           => 'low',
            'prerequisites_md'           => implode("\n", [
                '- No active litigation, creditor claims, or imminent threats (fraudulent transfer risk)',
                '- Owner must not be insolvent at time of contribution',
                "- **{$seasoningYears}-year seasoning period in {$recommendedState}** before DAPT protection is fully established",
                '- Qualified trustee in the chosen state required (cannot be the settlor as sole trustee)',
                '- Assets must be re-titled into the DAPT',
                '- DAPT is irrevocable — think carefully about which assets to contribute',
                '- For QSBS stacking: contribute IPCo LLC interests (pre-issuance) into DAPT',
            ]),
            'rationale_md'               => implode("\n\n", [
                '**Domestic Asset Protection Trust Analysis**',
                sprintf(
                    "Net worth proxy: **\$%s** | Liability profile: **%s**  \n" .
                    "Recommended state: **%s** (%d-year seasoning)  \n" .
                    "Protection value (risk-adjusted): **\$%s**  \n" .
                    "Owner age: **%d** | Estate plan current: **%s**",
                    number_format($totalNetWorth, 0),
                    $liab,
                    $recommendedState,
                    $seasoningYears,
                    number_format($protectionValue, 0),
                    $ownerAge,
                    $estPlanCurrent ? 'yes' : 'no — UPDATE NEEDED'
                ),
                "**Why {$recommendedState}?** South Dakota has the strongest DAPT statute: 2-year " .
                'seasoning, no exception creditors for general tort claims, no state income tax, and the ' .
                'most permissive trust law in the US. Combined with Wyoming\'s charging-order protection ' .
                'for the underlying LLCs, this creates a two-layer shield: DAPT owns WY LLC; WY LLC ' .
                'holds assets. Creditor must defeat both layers.',
                '**DAPT as §1202 stacking vehicle:** If this entity is a C-Corp candidate for QSBS, ' .
                'having the DAPT own shares at issuance creates a separate $10M exclusion cap. ' .
                'Coordinate with QSBS1202Playbook for combined estate + exit tax planning.',
            ]),
            'gotchas_md'                 => implode("\n", [
                '- **Fraudulent transfer:** Contributions made with actual intent to defraud, hinder, or',
                '  delay any creditor are NEVER protected — not even after seasoning.',
                '- **Federal bankruptcy:** DAPT protection may not survive Chapter 7 bankruptcy.',
                '  Bankruptcy trustee can reach trust assets contributed within 10 years if made with',
                '  intent to defraud (11 U.S.C. §548(e)).',
                '- **Divorce exception:** Most states (but not NV) allow a divorcing spouse to pierce',
                '  the DAPT for equitable distribution claims. Consider domicile carefully.',
                '- **Tax: no income tax benefit.** DAPT is typically a grantor trust — income still',
                '  taxed to the settlor. No income tax savings unless trust is non-grantor structured.',
                '- **Irrevocability:** Once contributed, assets are in the DAPT unless the trustee',
                '  distributes them back (at trustee discretion). Plan carefully for liquidity needs.',
                '- **Spendthrift clause:** Must be in the trust document to block assignment by beneficiary.',
                '- **Multiple DAPTs:** Allowed — each has its own seasoning clock.',
            ]),
            'citations'                  => [
                'W.S. §4-10-504 — Wyoming Qualified Spendthrift Trust Act',
                'NRS §166.170 — Nevada Spendthrift Trust Act',
                'SDCL §55-16 — South Dakota Domestic Asset Protection Trust',
                '11 U.S.C. §548(e) — Federal bankruptcy fraudulent transfer (10-year lookback)',
                'Uniform Trust Code §505 — Creditor\'s Claim Against Revocable Trust',
                'Uniform Fraudulent Transfer Act / Uniform Voidable Transactions Act',
                'Riehl v. Riehl (2017) — SD DAPT divorce claim test',
            ],
            'docs_required'              => [
                'dapt_trust_agreement',    // Irrevocable trust document (state-specific)
                'trustee_agreement',       // Qualified SD/NV/WY trustee engagement
                'asset_transfer_docs',     // Re-titling into trust
                'solvency_affidavit',      // Settlor solvency at time of contribution
                'trustee_acceptance',      // Independent trustee acceptance
            ],
            'next_actions'               => [
                'Engage estate planning attorney specializing in DAPT formation (SD or NV preferred)',
                'Confirm no active or threatened claims before contribution',
                'Select a qualified independent trustee in the chosen state',
                'Identify which assets to contribute (start with appreciating assets)',
                'If QSBS stacking: time DAPT formation before C-Corp stock issuance',
                'Set seasoning-period reminder in compliance_calendar (task_type: dapt_seasoning)',
            ],
        ];
    }
}
