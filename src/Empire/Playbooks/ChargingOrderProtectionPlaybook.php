<?php
/**
 * ChargingOrderProtectionPlaybook — Charging Order Protection (WY/NV/SD state law)
 *
 * Evaluates whether assets should be held in an LLC formed in a
 * "charging-order-only" jurisdiction (Wyoming, Nevada, or South Dakota)
 * to maximise protection against judgment creditors.
 *
 * What is a charging order?
 *   A charging order is the exclusive remedy available to a creditor of an
 *   LLC member — the creditor can attach future distributions from the LLC
 *   but CANNOT: force a liquidation, vote the member's interests, or take
 *   control of the LLC. This makes the LLC interest largely worthless to
 *   the creditor (no distributions = no income, but the creditor may owe
 *   phantom income tax on their share of LLC income without receiving cash).
 *
 * Jurisdiction comparison:
 *   Wyoming (W.S. §17-29-503):
 *     - Strongest domestic statute. Single-member LLCs explicitly protected.
 *     - Charging order is the EXCLUSIVE remedy (no foreclosure of interest).
 *     - No state income tax. Low maintenance fees ($52/yr).
 *   Nevada (NRS §86.401):
 *     - Strong charging-order protection, explicit single-member coverage.
 *     - No state income tax. Higher fees ($350 initial + $350/yr).
 *   South Dakota (SDCL §47-34A-504):
 *     - Strong DAPT statutes also available in same state.
 *     - No state income tax. Low fees.
 *   Florida (§608.433):
 *     - Only charging order for multi-member LLCs; single-member may be exposed.
 *     - NOTE: *Olmstead v. FTC* (Fla. 2010) — court ordered member to transfer
 *       single-member LLC interest; no longer recommended for asset protection.
 *
 * Delaware:
 *   Delaware does NOT have charging-order-only protection — use WY or NV
 *   for asset-holding LLCs even if operational entity is DE.
 *
 * Best structure: WY LLC (holding) → operates or holds assets; separate
 *   OpCo LLC in formation state of choice.
 *
 * This playbook focuses on asset-holding LLCs for:
 *   - Real estate
 *   - IP (see IPCoSeparationPlaybook)
 *   - Equipment / high-value personal property
 *   - Investment accounts
 *   - Receivables / financial assets
 */

namespace Mnmsos\Empire\Playbooks;

class ChargingOrderProtectionPlaybook extends AbstractPlaybook
{
    // Jurisdictions ranked by protection strength
    private const STRONG_CO_STATES = ['WY', 'NV', 'SD'];

    public function getId(): string          { return 'charging_order_protection'; }
    public function getName(): string        { return 'Charging Order Protection (WY/NV/SD LLC)'; }
    public function getCodeSection(): string { return 'W.S. §17-29-503 / NRS §86.401 / SDCL §47-34A-504'; }
    public function getAggressionTier(): string { return 'conservative'; }
    public function getCategory(): string    { return 'liability'; }

    public function applies(array $intake, array $portfolioContext): bool
    {
        if (!$this->tierAllowed($intake)) {
            return false;
        }
        // Applies whenever there are tangible assets or meaningful revenue at risk
        $revenue   = $this->f($intake['annual_revenue_usd']);
        $equip     = $this->f($intake['equipment_value_usd']);
        $realEstate = ($intake['real_estate_owned'] ?? 0);
        $liab      = $intake['liability_profile'] ?? 'low';
        $ipOwned   = $intake['ip_owned_md'] ?? '';

        $hasAssets = ($revenue > 100_000 || $equip > 10_000 || $realEstate || !empty($ipOwned));
        $hasRisk   = in_array($liab, ['medium', 'med_high', 'high'], true);

        return $hasAssets || $hasRisk;
    }

    public function evaluate(array $intake, array $portfolioContext): array
    {
        if (!$this->applies($intake, $portfolioContext)) {
            return $this->notApplicable(
                'Charging order protection not applicable: no material assets at risk, ' .
                'liability profile is low, or aggression tier too low.'
            );
        }

        $revenue     = $this->f($intake['annual_revenue_usd']);
        $equip       = $this->f($intake['equipment_value_usd']);
        $inventory   = $this->f($intake['inventory_value_usd']);
        $ar          = $this->f($intake['ar_balance_usd']);
        $realEstate  = ($intake['real_estate_owned'] ?? 0);
        $liab        = $intake['liability_profile'] ?? 'medium';
        $activeClaims = $this->i($intake['active_claims_count']);
        $vehicles    = $this->i($intake['vehicle_count']);
        $ipOwned     = $intake['ip_owned_md'] ?? '';
        $jurisdiction = $intake['decided_jurisdiction'] ?? 'TX';

        // Assets at risk (rough proxy)
        $assetsAtRisk = $equip + $inventory + ($ar * 0.8) + ($realEstate ? 200_000.0 : 0.0);
        // Add revenue-based goodwill proxy: 1.5× annual revenue for SaaS-like businesses
        $goingConcernValue = $revenue * 1.5;
        $totalAtRisk = $assetsAtRisk + $goingConcernValue;

        // Protection value: probability a creditor would collect without CO protection
        // Assume 15 % collection risk reduction from strong CO statute
        $protectionValue = $totalAtRisk * 0.15;

        // Is entity already in a strong CO jurisdiction?
        $alreadyProtected = in_array($jurisdiction, self::STRONG_CO_STATES, true);

        // WY LLC formation cost
        $setupCost   = 800.0;   // WY LLC formation + RA (first year)
        $ongoingCost = 200.0;   // Annual WY RA + filing fee

        // Recommend jurisdiction
        $recommendedState = 'WY'; // Default: best domestic option

        $urgencyFlag = ($activeClaims > 0);

        $appScore = 30;
        if ($totalAtRisk > 100_000)   { $appScore += 15; }
        if ($totalAtRisk > 500_000)   { $appScore += 15; }
        if (!empty($ipOwned))         { $appScore += 10; }
        if ($realEstate)              { $appScore += 10; }
        if (in_array($liab, ['med_high', 'high'], true)) { $appScore += 15; }
        if ($urgencyFlag)             { $appScore -= 20; } // Urgency = can't act freely

        return [
            'applies'                    => true,
            'applicability_score'        => $this->score($appScore),
            'estimated_savings_y1_usd'   => 0.0,  // No direct tax savings
            'estimated_savings_5y_usd'   => round($protectionValue, 2), // Risk mitigation value
            'estimated_setup_cost_usd'   => $alreadyProtected ? 0.0 : $setupCost,
            'estimated_ongoing_cost_usd' => $alreadyProtected ? 0.0 : $ongoingCost,
            'risk_level'                 => 'low',
            'audit_visibility'           => 'low',
            'prerequisites_md'           => implode("\n", [
                '- No active pending lawsuits (contribution after claim = fraudulent transfer risk)',
                '- Entity must be formed (or domesticated) in WY, NV, or SD',
                '- Operating agreement must designate charging order as exclusive creditor remedy',
                '- Must maintain genuine separation between personal and entity finances',
                '- Adequate capitalisation: under-capitalised entities may have veil pierced',
                '- Annual state filings must be current (forfeiture destroys protection)',
            ]),
            'rationale_md'               => implode("\n\n", [
                '**Charging Order Protection Analysis**',
                sprintf(
                    "Assets at risk: **\$%s** (equipment + AR + inventory)  \n" .
                    "Going-concern value proxy (1.5× revenue): **\$%s**  \n" .
                    "Total at-risk value: **\$%s**  \n" .
                    "Risk mitigation value (15%% protection premium): **\$%s**  \n" .
                    "Current formation jurisdiction: **%s** (%s)",
                    number_format($assetsAtRisk, 0),
                    number_format($goingConcernValue, 0),
                    number_format($totalAtRisk, 0),
                    number_format($protectionValue, 0),
                    $jurisdiction,
                    $alreadyProtected
                        ? 'already in strong CO jurisdiction'
                        : 'WEAK charging-order protection — recommend WY LLC'
                ),
                '**Why Wyoming?** W.S. §17-29-503 explicitly states that charging order is the ' .
                '*exclusive remedy* for a creditor of a member, covering both single-member and ' .
                'multi-member LLCs. Contrast with Florida (*Olmstead v. FTC*, 2010) where the Supreme ' .
                'Court ordered a single-member LLC interest to be surrendered directly.',
                $urgencyFlag
                    ? '**WARNING:** Active claims detected. DO NOT transfer assets to a new LLC ' .
                      'until an attorney reviews the transfer for fraudulent transfer exposure. ' .
                      'Implement this AFTER current matters resolve or get a clean opinion first.'
                    : 'No active claims — optimal time to implement proactive structure.',
            ]),
            'gotchas_md'                 => implode("\n", [
                '- **Alter ego / veil piercing:** Charging order protection fails if the entity is',
                '  a sham, co-mingles funds, or is under-capitalised. Keep meticulous books.',
                '- **Federal courts:** Some federal courts (bankruptcy courts in particular) have',
                '  declined to follow state charging-order exclusivity statutes. *In re Ashley Albright*',
                '  (Bankr. D. Colo. 2003) — trustee obtained LLC interest in bankruptcy.',
                '- **Foreclosure in weak states:** DE, CA, and most other states allow creditors to',
                '  foreclose on the LLC interest (not just attach distributions). WY/NV/SD block this.',
                '- **Phantom income:** Creditor with charging order may owe tax on their share of',
                '  LLC income even without receiving distributions. This makes the interest unattractive',
                '  and may encourage settlement — a strategic feature, not a bug.',
                '- **Domestication vs. new formation:** Existing entities can file a Certificate of',
                '  Domestication to move to WY without dissolving and re-forming.',
                '- **IP separate LLC:** IP should be in its own LLC (see IPCoSeparationPlaybook),',
                '  not co-mingled with operating assets. Separate entity = separate creditor shield.',
            ]),
            'citations'                  => [
                'W.S. §17-29-503 — Wyoming LLC Charging Order (exclusive remedy)',
                'NRS §86.401 — Nevada LLC Charging Order',
                'SDCL §47-34A-504 — South Dakota LLC Charging Order',
                'Olmstead v. FTC, 44 So.3d 76 (Fla. 2010) — Florida single-member LLC exposed',
                'In re Ashley Albright, 291 B.R. 538 (Bankr. D. Colo. 2003) — federal bankruptcy exception',
                'Uniform LLC Act §503 — Charging Order (model statute)',
                'Uniform Fraudulent Transfer Act / Uniform Voidable Transactions Act',
            ],
            'docs_required'              => [
                'wy_llc_articles',         // Wyoming LLC formation
                'operating_agreement',     // Must specify charging order as exclusive remedy
                'asset_transfer_docs',     // Deed/bill of sale for transferred assets
                'wy_ra_agreement',         // Registered agent agreement
                'adequate_capitalisation_memo', // Document initial capital contribution
            ],
            'next_actions'               => [
                $alreadyProtected
                    ? 'Confirm operating agreement explicitly designates charging order as exclusive remedy'
                    : 'Form Wyoming LLC for asset-holding (separate from operational entity)',
                'Transfer key assets (IP, equipment, receivables) to WY LLC via written assignment',
                'Open separate bank account for WY LLC; maintain clean books',
                'File annual report and pay WY filing fee ($52) each year',
                'Review with asset protection attorney annually for any new case law changes',
            ],
        ];
    }
}
