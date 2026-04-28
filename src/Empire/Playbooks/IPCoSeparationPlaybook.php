<?php
/**
 * IPCoSeparationPlaybook — IP Holding Company Separation (§482 + Asset Protection)
 *
 * Evaluates spinning intellectual property (patents, trademarks, copyrights,
 * trade secrets, software) into a separate Wyoming LLC owned by a DAPT or
 * HoldCo, with a license-back arrangement to the operating entity.
 *
 * Dual purpose:
 *   1. **Asset protection:** IP sits in a separate LLC shielded from
 *      operating-company creditors and litigation. WY LLC charging-order
 *      protection applies (W.S. §17-29-503).
 *   2. **Income shifting / tax planning:** License royalties from OpCo to
 *      IPCo shift income to a lower-tax entity or jurisdiction, or to a
 *      trust that owns the IPCo, potentially reducing owner SE-tax or state
 *      income tax. This must satisfy §482 arm's-length standard.
 *
 * §482 compliance:
 *   - Royalty rate must be arm's-length — comparable uncontrolled transaction (CUT)
 *     or income method (25 % of projected operating income is a common proxy;
 *     actual market rates vary 2–15 % of revenue for technology/software).
 *   - Treas. Reg. §1.482-4 covers intangible property transfer pricing.
 *   - Related-party royalties must be documented annually (§6662 penalty risk
 *     if undocumented: 20 % accuracy penalty; §6662A for reportable transactions).
 *   - License agreement must be in writing, executed before use.
 *
 * IP valuation:
 *   - For transfer pricing, IP must be valued at FMV at time of contribution.
 *   - Low-value IP can be contributed as a §721 (partnership contribution) or §351
 *     (corporate contribution) tax-free exchange.
 *   - High-value IP requires a §482 cost-sharing arrangement or valuation report.
 *
 * Royalty income at IPCo level:
 *   - If IPCo is a SMLLC disregarded entity owned by a DAPT, royalties flow
 *     to the trust, potentially avoiding grantor's SE tax on that income.
 *   - Passive royalty income in trust may qualify for lower rates.
 */

namespace Mnmsos\Empire\Playbooks;

class IPCoSeparationPlaybook extends AbstractPlaybook
{
    // Royalty rate as % of revenue (conservative income-method benchmark)
    private const ROYALTY_RATE_OF_REVENUE = 0.05; // 5 % — conservative for software/brand IP

    public function getId(): string          { return 'ipco_separation'; }
    public function getName(): string        { return 'IP Holding Company Separation (§482 + Asset Protection)'; }
    public function getCodeSection(): string { return '§482 / Treas. Reg. §1.482-4 / WY LLC §17-29-503'; }
    public function getAggressionTier(): string { return 'growth'; }
    public function getCategory(): string    { return 'liability'; }

    public function applies(array $intake, array $portfolioContext): bool
    {
        if (!$this->tierAllowed($intake)) {
            return false;
        }
        // Must have some IP to separate
        $ipOwned = $intake['ip_owned_md'] ?? '';
        if (empty(trim((string)$ipOwned))) {
            return false;
        }
        // Need meaningful revenue to justify license fee
        $revenue = $this->f($intake['annual_revenue_usd']);
        if ($revenue < 100_000.0) {
            return false;
        }
        return true;
    }

    public function evaluate(array $intake, array $portfolioContext): array
    {
        if (!$this->applies($intake, $portfolioContext)) {
            return $this->notApplicable(
                'IP separation not applicable: no IP documented in intake, revenue too low, ' .
                'or aggression tier too low.'
            );
        }

        $revenue    = $this->f($intake['annual_revenue_usd']);
        $ebitda     = $this->f($intake['ebitda_usd']);
        $equip      = $this->f($intake['equipment_value_usd']);
        $liab       = $intake['liability_profile'] ?? 'medium';
        $vertical   = $intake['industry_vertical'] ?? 'other';
        $ipOwned    = $intake['ip_owned_md'] ?? '';
        $activeClaims = $this->i($intake['active_claims_count']);

        // Royalty amount: 5 % of revenue (conservative arm's-length proxy)
        $annualRoyalty = $revenue * self::ROYALTY_RATE_OF_REVENUE;

        // Tax benefit: royalty deducted by OpCo (reduces OpCo taxable income)
        // If IPCo is a trust entity at lower rate, net tax savings emerge.
        // Conservative model: royalty shifts income but stays in same owner's hands
        // Primary benefit is ASSET PROTECTION — quantify the IP value at risk.
        // IP FMV proxy: 2× annual revenue × royalty rate = capitalized royalty stream
        $ipFMVProxy = ($annualRoyalty * 10.0); // 10× royalty = rough IP value
        $assetProtectionValue = $ipFMVProxy;

        // Tax arbitrage: only if OpCo is high-tax state and IPCo is WY (no income tax)
        // Estimate state tax savings only: 5 % of royalty (conservative state rate differential)
        $stateTaxSavings = $annualRoyalty * 0.05;

        // SE-tax savings: if IPCo is trust-owned, royalty → passive income, not SE income
        // Approximate: royalty × SE tax rate × 0.5 (only half the SE benefit since already pass-through)
        $seTaxSavings = $annualRoyalty * 0.153 * 0.5;

        $savingsY1 = $stateTaxSavings + $seTaxSavings;
        $savings5y = $savingsY1 * 5.0;

        // Costs: WY LLC formation + transfer pricing study + license agreement
        $setupCost   = 5_000.0;  // WY LLC + attorney + transfer pricing memo
        $ongoingCost = 2_000.0;  // Annual transfer pricing documentation + WY RA

        $urgency = ($activeClaims > 0 || in_array($liab, ['high', 'med_high'], true));

        $appScore = 30;
        if (!empty($ipOwned))       { $appScore += 20; }
        if ($urgency)               { $appScore += 20; }
        if ($revenue > 500_000)     { $appScore += 15; }
        if ($vertical === 'saas')   { $appScore += 10; }

        return [
            'applies'                    => true,
            'applicability_score'        => $this->score($appScore),
            'estimated_savings_y1_usd'   => round($savingsY1, 2),
            'estimated_savings_5y_usd'   => round($savings5y, 2),
            'estimated_setup_cost_usd'   => $setupCost,
            'estimated_ongoing_cost_usd' => $ongoingCost,
            'risk_level'                 => 'medium',
            'audit_visibility'           => 'medium',
            'prerequisites_md'           => implode("\n", [
                '- Must have documented IP: software, trademarks, patents, trade secrets, or copyrights',
                '- IP must be contributed at FMV to IPCo (§351 or §721 contribution, or sale at FMV)',
                '- License agreement must be **in writing and executed before use** of the IP',
                '- Royalty rate must be arm\'s-length (document with CUT method or income method)',
                '- IPCo should be a **separate Wyoming LLC** — strong charging-order jurisdiction',
                '- Consider DAPT as IPCo parent for additional asset protection layer',
                '- Active claims: if litigation is pending, contribution may be fraudulent transfer — get counsel',
            ]),
            'rationale_md'               => implode("\n\n", [
                '**IP Holding Company Separation Analysis**',
                sprintf(
                    "Revenue: **\$%s** | IP documented: **%s**  \n" .
                    "Annual royalty (%s%% of revenue): **\$%s**  \n" .
                    "IP FMV proxy (10× royalty stream): **\$%s**  \n" .
                    "Annual tax savings (state arb + SE): **\$%s**  \n" .
                    "Asset protection value (IP at risk): **\$%s**",
                    number_format($revenue, 0),
                    strlen($ipOwned) > 60 ? substr($ipOwned, 0, 60) . '…' : $ipOwned,
                    number_format(self::ROYALTY_RATE_OF_REVENUE * 100, 0),
                    number_format($annualRoyalty, 0),
                    number_format($ipFMVProxy, 0),
                    number_format($savingsY1, 0),
                    number_format($assetProtectionValue, 0)
                ),
                'Primary driver is **asset protection** — removing IP from the operating entity\'s estate ' .
                'prevents a creditor of OpCo from seizing the IP. Wyoming\'s charging-order-only remedy ' .
                '(W.S. §17-29-503) means a judgment creditor can only attach distributions, not the IP itself.',
                $urgency
                    ? '**URGENT:** Active claims or high liability profile detected. Implement IP separation ' .
                      'BEFORE any litigation threat materialises. Post-claim contributions may be voided as ' .
                      'fraudulent transfers under UFTA/UVTA.'
                    : 'No active claims. Proactive implementation recommended — the window to act is always ' .
                      'before a claim arises.',
            ]),
            'gotchas_md'                 => implode("\n", [
                '- **Fraudulent transfer risk:** IP contributed to IPCo after a creditor claim is filed',
                '  (or when insolvent) can be unwound under UFTA/UVTA. Act proactively.',
                '- **Substance-over-form:** IRS will disregard the license if IPCo has no economic substance.',
                '  IPCo must have a bank account, maintain separate records, and receive royalty payments.',
                '- **Transfer pricing documentation (§6662):** Without contemporaneous documentation,',
                '  20 % accuracy penalty applies. 40 % for gross valuation misstatements.',
                '- **Self-charged royalties in S-Corp:** §1366(e) may recharacterise passive royalty income',
                '  as active if owner-employee controls both entities.',
                '- **State nexus:** If OpCo has nexus in multiple states, royalty payments to WY IPCo',
                '  may be "thrown back" into the OpCo\'s apportionment in some states.',
                '- **IP ownership must be clear before contribution.** Unclear IP ownership (e.g., created',
                '  by contractors without proper assignment) must be resolved first.',
            ]),
            'citations'                  => [
                'IRC §482 — Allocation of Income and Deductions Among Taxpayers',
                'Treas. Reg. §1.482-4 — Methods to determine taxable income in intangible property transfers',
                'IRC §351 — Transfer to corporation controlled by transferor (tax-free contribution)',
                'IRC §721 — Nonrecognition of gain or loss on contribution to partnership',
                'IRC §6662 — Accuracy-related penalty (20 % / 40 % for valuation misstatement)',
                'W.S. §17-29-503 — Wyoming LLC charging order as exclusive remedy',
                'Uniform Fraudulent Transfer Act (UFTA) / Uniform Voidable Transactions Act (UVTA)',
                'Rev. Rul. 55-540 — License vs. sale of patent',
            ],
            'docs_required'              => [
                'wy_llc_articles',          // IPCo formation
                'ip_assignment_agreements', // Transfer of IP to IPCo
                'license_agreement',        // OpCo ↔ IPCo license
                'transfer_pricing_memo',    // §482 documentation
                'ip_valuation_report',      // FMV at contribution (if material value)
                'ipco_bank_account',        // Separate financial records
            ],
            'next_actions'               => [
                'Inventory all IP assets (software, trademarks, trade secrets, domain names)',
                'Engage IP attorney to review ownership chain and draft assignment agreements',
                'Form Wyoming LLC as IPCo (low-cost, strong charging-order statute)',
                'Obtain transfer pricing opinion from CPA or economist (if IP value > $50k)',
                'Execute written license agreement with arm\'s-length royalty rate',
                'Open separate bank account for IPCo; route royalty payments through it',
            ],
        ];
    }
}
