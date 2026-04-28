<?php
/**
 * FLPValuationDiscountPlaybook — Family Limited Partnership / §2031 Valuation Discounts
 *
 * Evaluates whether a Family Limited Partnership (FLP) or Family LLC
 * is appropriate for estate planning via valuation discounts on gifted
 * interests.
 *
 * Mechanics:
 *   Parent contributes appreciating assets to an FLP.
 *   Parent (GP) retains control; limited interests are gifted to children
 *   (or trusts for children) at FMV discounted for:
 *     - Lack of marketability (DLOM): 15–30 % typical
 *     - Lack of control (DLOC): 10–25 % typical
 *     - Combined: 20–40 % aggregate discount (Tax Court range)
 *
 *   Gift tax annual exclusion: $19,000/donee/year (2026, indexed).
 *   Lifetime exclusion: $13,990,000 (2026, inflation-adjusted from TCJA).
 *   **SUNSET WARNING:** TCJA exemption halves to ~$7M on 01/01/2026
 *   unless extended. USE NOW.
 *
 * Tax Court precedent on FLPs:
 *   IRS frequently challenges FLPs on §2036 (retained control/interest)
 *   and §2703 (restrictions on use must be bona fide business arrangements).
 *   Key cases:
 *     - *Strangi v. Commissioner* (2003) — §2036 inclusion where parent retained
 *       beneficial enjoyment.
 *     - *Estate of Bongard v. Commissioner* (2005) — IRS won; no legitimate
 *       business purpose beyond tax savings.
 *     - *Estate of Stone v. Commissioner* (2003) — taxpayer won; genuine business
 *       purpose (investment management, creditor protection) validated.
 *     - *Holman v. Commissioner* (2010) — 22.1 % discount allowed; entity had
 *       investment management purpose.
 *
 * Survival requirements:
 *   1. Legitimate non-tax business purpose (investment management, liability protection,
 *      centralised management, facilitate family gifting)
 *   2. Contributor must not retain beneficial enjoyment (§2036)
 *   3. Arm's-length distributions — don't pay personal expenses from FLP
 *   4. Partnership must actually operate (meetings, separate accounts, investments)
 *   5. Qualified appraisal required for each gift (Treas. Reg. §25.2512-2)
 */

namespace Mnmsos\Empire\Playbooks;

class FLPValuationDiscountPlaybook extends AbstractPlaybook
{
    private const GIFT_ANNUAL_EXCLUSION_2026 = 19_000.0;
    private const LIFETIME_EXEMPTION_2026    = 13_990_000.0; // TCJA (likely to halve 01/01/2026 if not extended)
    private const SUNSET_EXEMPTION_EST       = 7_000_000.0;  // Post-sunset estimate
    private const DISCOUNT_CONSERVATIVE      = 0.20;  // 20 % combined discount (conservative)
    private const DISCOUNT_AGGRESSIVE        = 0.35;  // 35 % (aggressive — Tax Court high end)
    private const MIN_ESTATE_SIZE_TO_JUSTIFY = 500_000.0;

    public function getId(): string          { return 'flp_valuation_discount'; }
    public function getName(): string        { return 'Family Limited Partnership Valuation Discount (§2031)'; }
    public function getCodeSection(): string { return '§2031 / §2036 / §2703 / §25.2512-2'; }
    public function getAggressionTier(): string { return 'growth'; }
    public function getCategory(): string    { return 'estate'; }

    public function applies(array $intake, array $portfolioContext): bool
    {
        if (!$this->tierAllowed($intake)) {
            return false;
        }
        // Need owner age data and meaningful asset base
        $ownerAge   = $this->i($portfolioContext['owner_age_years'] ?? 0);
        $kidsCount  = $this->i($portfolioContext['kids_count'] ?? 0);
        $equip      = $this->f($intake['equipment_value_usd']);
        $revenue    = $this->f($intake['annual_revenue_usd']);
        $assetProxy = $equip + ($revenue * 1.5);

        if ($ownerAge < 40) {
            return false; // Estate planning typically starts at 40+
        }
        if ($kidsCount === 0) {
            return false; // FLP is primarily a multi-generational tool
        }
        if ($assetProxy < self::MIN_ESTATE_SIZE_TO_JUSTIFY) {
            return false;
        }
        return true;
    }

    public function evaluate(array $intake, array $portfolioContext): array
    {
        if (!$this->applies($intake, $portfolioContext)) {
            return $this->notApplicable(
                'FLP valuation discount not applicable: owner age < 40, no children, ' .
                'asset base too small, or aggression tier too low.'
            );
        }

        $ownerAge    = $this->i($portfolioContext['owner_age_years'] ?? 50);
        $kidsCount   = $this->i($portfolioContext['kids_count'] ?? 1);
        $retireAge   = $this->i($portfolioContext['retirement_target_age'] ?? 65);
        $estateUsed  = $this->f($portfolioContext['estate_tax_exemption_used_usd'] ?? 0);
        $spousePct   = $this->f($portfolioContext['spouse_member_pct'] ?? 0);
        $hasSpouse   = $spousePct > 0;

        $equip       = $this->f($intake['equipment_value_usd']);
        $revenue     = $this->f($intake['annual_revenue_usd']);
        $ebitda      = $this->f($intake['ebitda_usd']);

        // Asset base suitable for FLP
        $enterpriseValue = ($ebitda > 0) ? $ebitda * 5.0 : $revenue * 1.5;
        $physicalAssets  = $equip;
        $totalAssets     = $enterpriseValue + $physicalAssets;

        // Discount at conservative rate
        $discountedValue = $totalAssets * (1.0 - self::DISCOUNT_CONSERVATIVE);
        $discountSavings = $totalAssets - $discountedValue; // value removed from taxable estate

        // Estate tax savings (40 % federal rate above exemption)
        $remainingExemption = max(0.0, self::LIFETIME_EXEMPTION_2026 - $estateUsed);
        $postSunsetExemption = max(0.0, self::SUNSET_EXEMPTION_EST - $estateUsed);

        // If estate exceeds exemption, savings = discount × 40 %
        $taxableEstate = max(0.0, $totalAssets - $remainingExemption);
        $estTaxSaved   = min($discountSavings, $taxableEstate) * 0.40;

        // Annual gifting capacity using discounts:
        // Each child can receive $19k/yr without gift tax; FLP units at discount stretch further
        // Pre-discount unit value can be gifted: gift_excl / (1 - discount) per child
        $annualGiftCapacity = $kidsCount * (self::GIFT_ANNUAL_EXCLUSION_2026 / (1.0 - self::DISCOUNT_CONSERVATIVE));
        if ($hasSpouse) {
            $annualGiftCapacity *= 2; // Gift-splitting
        }

        $setupCost   = 8_000.0;  // Attorney: FLP agreement, formation, initial gifts, appraisal
        $ongoingCost = 3_000.0;  // Annual: appraisal updates, K-1s, meetings, filings

        $appScore = 20;
        if ($totalAssets > 1_000_000)   { $appScore += 20; }
        if ($totalAssets > 3_000_000)   { $appScore += 15; }
        if ($kidsCount >= 2)            { $appScore += 10; }
        if ($ownerAge >= 55)            { $appScore += 15; }
        if ($estTaxSaved > 0)          { $appScore += 15; }

        return [
            'applies'                    => true,
            'applicability_score'        => $this->score($appScore),
            'estimated_savings_y1_usd'   => 0.0, // Estate tax avoided at death (not Y1 income)
            'estimated_savings_5y_usd'   => round($estTaxSaved, 2), // Present value of estate tax avoided
            'estimated_setup_cost_usd'   => $setupCost,
            'estimated_ongoing_cost_usd' => $ongoingCost,
            'risk_level'                 => 'medium',
            'audit_visibility'           => 'high',
            'prerequisites_md'           => implode("\n", [
                '- Owner must have children (or trust beneficiaries) to transfer interests to',
                '- Owner must have meaningful assets to transfer (enterprise value or real assets)',
                '- **Non-tax business purpose required** — creditor protection, investment management, etc.',
                '- Qualified appraisal required for each gift (Treas. Reg. §25.2512-2)',
                '- Owner must NOT retain beneficial enjoyment of transferred assets (§2036 risk)',
                '- FLP must actually function: separate bank account, meetings, distributions policy',
                '- Attorney with estate planning / FLP experience required — DIY is not safe here',
                '- **SUNSET URGENCY:** TCJA exemption likely halves on 01/01/2026. Act before sunset.',
            ]),
            'rationale_md'               => implode("\n\n", [
                '**Family Limited Partnership — Valuation Discount Analysis**',
                sprintf(
                    "Owner age: **%d** | Kids: **%d** | Has spouse: **%s**  \n" .
                    "Enterprise value proxy: **\$%s** | Physical assets: **\$%s**  \n" .
                    "Total FLP asset base: **\$%s**  \n" .
                    "Post-discount value (20%% combined discount): **\$%s**  \n" .
                    "Value removed from taxable estate: **\$%s**  \n" .
                    "Estate tax avoided at 40%% rate: **\$%s**  \n" .
                    "Annual gifting capacity (with discounts): **\$%s/yr**",
                    $ownerAge,
                    $kidsCount,
                    $hasSpouse ? 'yes' : 'no',
                    number_format($enterpriseValue, 0),
                    number_format($physicalAssets, 0),
                    number_format($totalAssets, 0),
                    number_format($discountedValue, 0),
                    number_format($discountSavings, 0),
                    number_format($estTaxSaved, 0),
                    number_format($annualGiftCapacity, 0)
                ),
                '**Sunset urgency:** The TCJA doubled the lifetime estate/gift tax exemption. Unless ' .
                'Congress extends it, the exemption reverts to ~$7M on 01/01/2026. Any exemption used ' .
                'before sunset is locked in (IRS anti-clawback regulations, Treas. Reg. §20.2010-1(c)). ' .
                'Act before 12/31/2025 to use the full ~$14M exemption.',
                'Conservative 20 % combined discount is defensible based on Tax Court precedent ' .
                '(*Holman v. Commissioner*, 130 T.C. 170 (2008) — 22.1 % allowed). Aggressive range ' .
                '(35 %) requires stronger appraisal support and genuine investment management purpose.',
            ]),
            'gotchas_md'                 => implode("\n", [
                '- **§2036 inclusion:** If parent effectively retains the income or control after transfer,',
                '  IRS includes the full value in the estate — destroying the discount benefit.',
                '  *Strangi* and *Turner* cases failed on this ground.',
                '- **Deathbed formations:** FLP formed shortly before death raises §2036 red flags.',
                '  Form years in advance.',
                '- **Only business-purpose assets:** Contribute investment assets / business interests.',
                '  Personal-use assets (homes, cars) are not appropriate FLP assets.',
                '- **Qualified appraisal required:** Gift tax return (Form 709) requires qualified',
                '  appraisal. Undisclosed discounts = 40 % gross valuation misstatement penalty.',
                '- **State estate taxes:** Many states have lower exemptions (~$1–3M). FLP helps here too.',
                '- **Gift-splitting election (§2513):** Spouse must consent annually to split gifts.',
                '  File Form 709 even if below annual exclusion when using gift-splitting.',
                '- **Anti-freeze rules (§2701):** If GP retains preferred economic rights, §2701',
                '  may value retained interest at zero — overpricing the gift. Structure carefully.',
            ]),
            'citations'                  => [
                'IRC §2031 — Definition of Gross Estate',
                'IRC §2036 — Transfers with Retained Life Estate',
                'IRC §2703 — Certain Rights and Restrictions Disregarded',
                'IRC §2513 — Gift-Splitting Election',
                'Treas. Reg. §25.2512-2 — Stocks and Bonds (valuation)',
                'Treas. Reg. §20.2010-1(c) — Anti-clawback rule for sunset',
                'Strangi v. Commissioner, 85 T.C.M. 1331 (2003)',
                'Estate of Bongard v. Commissioner, 124 T.C. 95 (2005)',
                'Holman v. Commissioner, 130 T.C. 170 (2008)',
                'Estate of Stone v. Commissioner, T.C. Memo 2003-309',
            ],
            'docs_required'              => [
                'flp_agreement',           // Partnership or LLC operating agreement
                'qualified_appraisal',     // For each gift of FLP interests
                'form_709',                // Gift tax return
                'flp_bank_account',        // Separate entity financials
                'minutes_resolutions',     // Annual meetings and decisions
                'k1_package',              // K-1s to all partners/members
                'non_tax_purpose_memo',    // Document legitimate business purpose
            ],
            'next_actions'               => [
                'Engage estate planning attorney with FLP experience immediately (sunset deadline pressure)',
                'Obtain independent business valuation for enterprise value',
                'Determine appropriate non-tax business purpose (investment management recommended)',
                'Form FLP/LLC; transfer assets at FMV with qualified appraisal',
                'File Form 709 for any gifts exceeding annual exclusion',
                'Schedule annual FLP meetings and document distributions policy',
            ],
        ];
    }
}
