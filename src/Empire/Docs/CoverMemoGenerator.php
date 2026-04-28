<?php
/**
 * CoverMemoGenerator — static class that drafts the cover memo for an
 * attorney-ready package from intake + playbook data.
 *
 * Pure function: no DB, no I/O. Returns a Markdown string.
 * Called by AttorneyPackageBuilder before template rendering.
 *
 * Sections produced:
 *   1. Client Summary (anonymized initials per tenant privacy)
 *   2. Aggression Tier + reasoning
 *   3. Recommended Structure (entity + jurisdiction + parent_kind)
 *   4. Active Playbooks table (playbook | savings Y1 | risk | citations)
 *   5. Cost Projection (Y1 + ongoing)
 *   6. Risk Disclosures (full list)
 *   7. Scope of Attorney Work Requested
 */

namespace Mnmsos\Empire\Docs;

class CoverMemoGenerator
{
    // ── IRC / statute citation map used in playbook rows ─────────────────
    private const PLAYBOOK_CITATIONS = [
        'qsbs_1202'               => 'IRC §1202 (QSBS exclusion — up to $10M or 10× gain)',
        's_corp_election'         => 'IRC §1361–1379 (S-Corp status); Rev. Rul. 2008-18',
        'qbi_199a'                => 'IRC §199A (QBI deduction — up to 20% of qualified income)',
        'ipco_separation'         => 'IRC §482 (transfer pricing); Treas. Reg. §1.482-7',
        'mgmt_fee_transfer'       => 'IRC §162(a) (ordinary + necessary business expense)',
        'dapt_domestic_asset'     => 'State statutes: WY §4-10-510; NV NRS §166; SD SDCL §55-16',
        'solo_401k_max'           => 'IRC §415(c); §402(g); Treas. Reg. §1.401(k)-1',
        'captive_insurance_831b'  => 'IRC §831(b) (micro-captive); Rev. Rul. 2005-40',
        'cost_segregation'        => 'IRC §168 (MACRS); Rev. Proc. 87-56',
        'charging_order'          => 'TX Bus. Org. Code §101.112; WY §17-29-503',
        'flp_valuation_discount'  => 'IRC §2036; Estate of Powell v. Comm\'r (2017)',
        'rd_credit_41'            => 'IRC §41 (R&D credit); Treas. Reg. §1.41-4',
    ];

    // ── Aggression tier labels ────────────────────────────────────────────
    private const TIER_LABELS = [
        'conservative' => 'Conservative (Tier 1) — Foundational protection only. Low audit risk.',
        'growth'       => 'Growth (Tier 2) — Mainstream tax + liability strategies. Moderate IRS scrutiny.',
        'aggressive'   => 'Aggressive (Tier 3) — Maximum optimization. Elevated audit risk; requires CPA + attorney oversight.',
    ];

    // ── Jurisdiction labels ───────────────────────────────────────────────
    private const JURISDICTION_LABELS = [
        'WY' => 'Wyoming — best charging-order protection + zero income tax',
        'DE' => 'Delaware — optimal for institutional investors + QSBS',
        'NV' => 'Nevada — strong asset protection statutes',
        'SD' => 'South Dakota — perpetual dynasty trusts',
        'TX' => 'Texas — home state filing; no separate state income tax',
        'AK' => 'Alaska — DAPT enabler; strong creditor protection',
    ];

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Generate the cover memo markdown.
     *
     * @param array $intake           empire_brand_intake row (all columns)
     * @param array $portfolioContext empire_portfolio_context row (may be empty [])
     * @param array $playbookResults  Array of playbook result arrays from PlaybookRegistry.
     *                                Each result: {playbook_key, label, savings_y1,
     *                                risk_level, firing (bool), reasoning}
     * @return string  Markdown memo (UTF-8)
     */
    public static function generate(
        array $intake,
        array $portfolioContext,
        array $playbookResults
    ): string {
        $date       = date('F j, Y');
        $initials   = self::toInitials((string)($intake['brand_name'] ?? 'Unknown Brand'));
        $tier       = (string)($intake['aggression_tier'] ?? 'growth');
        $tierLabel  = self::TIER_LABELS[$tier] ?? $tier;
        $jur        = (string)($intake['decided_jurisdiction'] ?? ($intake['suggested_jurisdiction'] ?? 'TX'));
        $entityType = (string)($intake['decided_entity_type'] ?? ($intake['suggested_entity_type'] ?? 'llc'));
        $parentKind = (string)($intake['decided_parent_kind'] ?? 'standalone');
        $status     = (string)($intake['decision_status'] ?? 'draft');

        $activePBs = array_filter($playbookResults, fn($pb) => !empty($pb['firing']));

        $md  = self::header($initials, $date, $status);
        $md .= self::sectionClientSummary($intake, $portfolioContext, $initials, $jur, $entityType, $parentKind);
        $md .= self::sectionAggressionTier($tier, $tierLabel);
        $md .= self::sectionRecommendedStructure($jur, $entityType, $parentKind);
        $md .= self::sectionActivePlaybooks($activePBs);
        $md .= self::sectionCostProjection($intake, $activePBs);
        $md .= self::sectionRiskDisclosures($intake, $activePBs);
        $md .= self::sectionAttorneyScope($jur, $entityType, $activePBs);
        $md .= self::footer($date);

        return $md;
    }

    // ── Section builders ──────────────────────────────────────────────────

    private static function header(string $initials, string $date, string $status): string
    {
        $statusNote = ($status !== 'locked')
            ? "\n> **DRAFT — Decision not yet locked. This memo reflects the current in-review state.**\n"
            : '';

        return <<<EOT
        # DST Empire — Attorney-Ready Cover Memo

        **Client Reference:** {$initials}
        **Prepared by:** DST Empire (VoltOps SaaS)
        **Date:** {$date}
        **Status:** {$status}
        {$statusNote}
        ---

        *This document is a AI-assisted planning memo, not legal advice. All
        recommendations must be reviewed by a licensed attorney before action.
        Client is responsible for independent legal review of every document in
        this package.*

        ---

        EOT;
    }

    private static function sectionClientSummary(
        array  $intake,
        array  $portfolioContext,
        string $initials,
        string $jur,
        string $entityType,
        string $parentKind
    ): string {
        $brandSlug     = (string)($intake['brand_slug'] ?? '');
        $tier          = (string)($intake['tier'] ?? '');
        $annualRevenue = self::formatMoney($portfolioContext['annual_revenue'] ?? null);
        $ownerCount    = (int)($portfolioContext['owner_count'] ?? 1);
        $stateOfOps    = (string)($intake['state_of_operations'] ?? 'TX');

        return <<<EOT
        ## 1. Client Summary

        | Field | Value |
        |---|---|
        | Client Reference | {$initials} |
        | Brand Slug | {$brandSlug} |
        | Tier Classification | {$tier} |
        | State of Operations | {$stateOfOps} |
        | Recommended Formation State | {$jur} |
        | Recommended Entity Type | {$entityType} |
        | Ownership Structure | {$parentKind} |
        | Owner Count | {$ownerCount} |
        | Annual Revenue (est.) | {$annualRevenue} |

        *PII and full legal name omitted from this memo per tenant privacy settings.
        Full intake data available in the client portal (authenticated access required).*

        ---

        EOT;
    }

    private static function sectionAggressionTier(string $tier, string $tierLabel): string
    {
        $reasoning = match ($tier) {
            'conservative' => 'Client prioritized simplicity and minimal IRS scrutiny. '
                . 'Only foundational entity structure and basic liability protection are active.',
            'aggressive'   => 'Client explicitly opted into maximum tax optimization. '
                . 'All applicable playbooks are active. Requires qualified CPA review '
                . 'annually and active attorney maintenance. Higher audit exposure accepted.',
            default        => 'Balanced approach selected. Core tax strategies (QBI, S-Corp election, '
                . 'charging-order protection) are active. Spicy optionality (DAPT, captive, IP-Co) '
                . 'staged for later engagement once baseline entities are formed.',
        };

        return <<<EOT
        ## 2. Aggression Tier

        **{$tierLabel}**

        {$reasoning}

        ---

        EOT;
    }

    private static function sectionRecommendedStructure(
        string $jur,
        string $entityType,
        string $parentKind
    ): string {
        $jurLabel    = self::JURISDICTION_LABELS[$jur] ?? $jur;
        $entityLabel = strtoupper($entityType);
        $parentLabel = match ($parentKind) {
            'holdco_llc'      => 'Holding company LLC (parent entity)',
            'holdco_corp'     => 'Holding company C-Corp (QSBS-ready)',
            'series_parent'   => 'Series LLC parent (cells for each asset class)',
            'trust_wrapper'   => 'Trust-wrapped (DAPT or Dynasty Trust as parent)',
            'standalone'      => 'Standalone entity (no parent layer)',
            default           => $parentKind,
        };

        return <<<EOT
        ## 3. Recommended Structure

        | Component | Recommendation |
        |---|---|
        | Formation Jurisdiction | {$jurLabel} |
        | Entity Type | {$entityLabel} |
        | Ownership Structure | {$parentLabel} |

        ### Rationale
        - **Jurisdiction**: {$jurLabel}.
        - **Entity type**: {$entityLabel} provides the optimal liability + tax treatment
          for the client's current revenue profile and ownership structure.
        - **Parent kind**: {$parentLabel}. Enables clean asset segregation and future
          sale-readiness (buyer due diligence on a standalone entity is simpler).

        ---

        EOT;
    }

    private static function sectionActivePlaybooks(array $activePBs): string
    {
        if (empty($activePBs)) {
            return <<<EOT
            ## 4. Active Playbooks

            *No playbooks are currently active for this intake record.
            Run PlaybookRegistry::runAll() to populate recommendations.*

            ---

            EOT;
        }

        $rows = '';
        foreach ($activePBs as $pb) {
            $label     = htmlspecialchars_decode((string)($pb['label'] ?? ($pb['playbook_key'] ?? '—')));
            $savingsY1 = self::formatMoney($pb['savings_y1'] ?? null);
            $risk      = (string)($pb['risk_level'] ?? '—');
            $citation  = self::PLAYBOOK_CITATIONS[$pb['playbook_key'] ?? ''] ?? '—';
            $rows .= "| {$label} | {$savingsY1} | {$risk} | {$citation} |\n";
        }

        return <<<EOT
        ## 4. Active Playbooks

        | Playbook | Est. Savings Y1 | Risk Level | Key Citations |
        |---|---|---|---|
        {$rows}
        *Savings estimates are illustrative ranges based on intake financial data.
        Actual results depend on implementation fidelity, CPA execution, and IRS
        audit outcome. These are NOT guarantees.*

        ---

        EOT;
    }

    private static function sectionCostProjection(array $intake, array $activePBs): string
    {
        // Derive costs from decided_jurisdiction and playbook count
        $jur      = (string)($intake['decided_jurisdiction'] ?? ($intake['suggested_jurisdiction'] ?? 'TX'));
        $pbCount  = count($activePBs);

        // Formation cost estimates by jurisdiction
        $formationCosts = [
            'TX' => 300,
            'WY' => 100,
            'DE' => 90,
            'NV' => 425,
            'SD' => 150,
            'AK' => 250,
        ];
        $formationCost = $formationCosts[$jur] ?? 200;

        // Attorney cost estimate: $350–700/hr, assume 5–15 hrs depending on playbook count
        $attyHrsMin = 5  + ($pbCount * 1);
        $attyHrsMax = 10 + ($pbCount * 2);
        $attyY1Min  = $attyHrsMin * 350;
        $attyY1Max  = $attyHrsMax * 700;

        // Registered agent ongoing
        $raOngoing  = ($jur === 'TX') ? 0 : 150;

        $y1Min = self::formatMoney($formationCost + $attyY1Min);
        $y1Max = self::formatMoney($formationCost + $attyY1Max);
        $yrMin = self::formatMoney($raOngoing + 500);   // franchise + RA
        $yrMax = self::formatMoney($raOngoing + 2000);  // with annual compliance

        return <<<EOT
        ## 5. Cost Projection

        | Item | Y1 Estimate | Ongoing (annual) |
        |---|---|---|
        | State filing fees ({$jur}) | \${$formationCost} | — |
        | Registered agent | — | \${$raOngoing}/yr (0 if TX) |
        | Attorney review + filing | {$y1Min}–{$y1Max} | — |
        | Annual state compliance | — | {$yrMin}–{$yrMax} |

        *Attorney cost range assumes {$attyHrsMin}–{$attyHrsMax} hours @ \$350–\$700/hr depending
        on complexity. Flat-fee engagements often available for straightforward formations;
        request a flat-fee quote for package review.*

        ---

        EOT;
    }

    private static function sectionRiskDisclosures(array $intake, array $activePBs): string
    {
        $tier     = (string)($intake['aggression_tier'] ?? 'growth');
        $jur      = (string)($intake['decided_jurisdiction'] ?? ($intake['suggested_jurisdiction'] ?? 'TX'));
        $entityType = (string)($intake['decided_entity_type'] ?? 'llc');

        $risks = [];

        // Universal risks
        $risks[] = '**Piercing the corporate veil** — entity protection is lost if owner commingles '
            . 'personal and business funds. Maintain a separate business bank account and annual minutes.';
        $risks[] = '**BOI filing required within 30 days** of formation (FinCEN CTA §6403). '
            . 'Penalty: \$591/day civil fine + potential criminal liability if willful.';
        $risks[] = '**Registered agent requirement** — entity must maintain a registered agent '
            . 'in the formation state at all times. Lapse = administrative dissolution.';

        // DE-specific
        if ($jur === 'DE') {
            $risks[] = '**Delaware franchise tax** — C-Corps owe annual franchise tax (min \$50, '
                . 'max \$200k+ under authorized shares method). File using assumed par value method '
                . 'to avoid over-assessment.';
        }

        // DAPT / aggressive risks
        if ($tier === 'aggressive') {
            $risks[] = '**DAPT fraudulent transfer risk** — assets transferred to DAPT can be '
                . 'clawed back by creditors if transfer is within the applicable statute of limitations '
                . 'and made while insolvent. Min. 2-year seasoning period recommended.';
            $risks[] = '**Captive insurance §831(b) IRS scrutiny** — micro-captives are on the '
                . 'IRS dirty dozen list. Requires proper actuarial support, arm\'s-length premiums, '
                . 'and substantive risk distribution. Do NOT use without a captive specialist.';
            $risks[] = '**Transfer pricing (§482)** — intercompany service fees must be '
                . 'at arm\'s-length rates. Document with contemporaneous benchmarking or face '
                . 'IRS reallocation and penalties.';
        }

        // QSBS
        foreach ($activePBs as $pb) {
            if (($pb['playbook_key'] ?? '') === 'qsbs_1202') {
                $risks[] = '**QSBS §1202 active business requirement** — company must be an '
                    . 'active C-Corp (no S-Corp, no LLC) with gross assets under \$50M at time '
                    . 'of stock issuance. Conversion from LLC to C-Corp triggers a new clock.';
            }
        }

        // S-Corp election
        foreach ($activePBs as $pb) {
            if (($pb['playbook_key'] ?? '') === 's_corp_election') {
                $risks[] = '**S-Corp reasonable compensation** — owner-employees must draw '
                    . 'reasonable W-2 salary before taking distributions. IRS will recharacterize '
                    . 'distributions as wages if salary is below market rate.';
            }
        }

        $riskList = implode("\n\n", array_map(fn($r) => "- {$r}", $risks));

        return <<<EOT
        ## 6. Risk Disclosures

        The following risks are material to the recommended structure. Attorney must
        review each with the client before signing engagement letter.

        {$riskList}

        *This list is not exhaustive. Client and attorney should independently assess
        all applicable risks. DST Empire is a planning tool, not a law firm.*

        ---

        EOT;
    }

    private static function sectionAttorneyScope(
        string $jur,
        string $entityType,
        array  $activePBs
    ): string {
        $entityLabel = strtoupper($entityType);
        $scope = [];

        // Universal scope items
        $scope[] = "Review and finalize all formation documents in this package "
            . "({$entityLabel} in {$jur}).";
        $scope[] = 'Advise client on BOI filing requirement and confirm filing within 30-day deadline.';
        $scope[] = 'Review operating agreement / bylaws for state-specific compliance gaps.';
        $scope[] = 'Supervise execution of IP assignment agreement (founder → entity).';

        // Playbook-specific scope
        foreach ($activePBs as $pb) {
            switch ($pb['playbook_key'] ?? '') {
                case 'qsbs_1202':
                    $scope[] = 'Verify C-Corp structure meets §1202 active business requirement '
                        . 'and advise on stock issuance documentation.';
                    break;
                case 's_corp_election':
                    $scope[] = 'File Form 2553 within 75 days of formation (or by March 15 '
                        . 'for next-year election). Confirm all shareholders consent.';
                    break;
                case 'dapt_domestic_asset':
                    $scope[] = 'Review DAPT structure for fraudulent conveyance exposure. '
                        . 'Confirm asset list and transfer mechanics.';
                    break;
                case 'ipco_separation':
                    $scope[] = 'Review IP assignment chain (Founder → IP-Co → Op-Co license). '
                        . 'Confirm §482 arm\'s-length royalty rate documentation.';
                    break;
                case 'captive_insurance_831b':
                    $scope[] = 'Refer client to captive insurance specialist for actuarial review '
                        . 'before any 831(b) election. Confirm risk distribution meets IRS standards.';
                    break;
                case 'solo_401k_max':
                    $scope[] = 'Review Solo 401(k) plan document for IRS compliance. '
                        . 'Confirm employer contribution limits for entity structure.';
                    break;
            }
        }

        $scopeList = implode("\n", array_map(fn($s, $i) => ($i + 1) . ". {$s}", $scope, array_keys($scope)));

        return <<<EOT
        ## 7. Scope of Attorney Work Requested

        We are requesting the attorney's assistance with the following specific items:

        {$scopeList}

        **NOT in scope** (client handles independently with DST Empire system guidance):
        - EIN application (IRS.gov, free, 5 minutes)
        - Business bank account opening (Mercury / Chase)
        - BOI filing at fincen.gov (guided checklist in client package)
        - QBO file setup
        - Domain / Google Business Profile update to entity name

        *See client self-do checklist (client_checklist.md) in this package for
        the full non-attorney task list.*

        ---

        EOT;
    }

    private static function footer(string $date): string
    {
        return <<<EOT
        ---

        *Generated by DST Empire — VoltOps SaaS. {$date}.*
        *All templates are starting points for attorney review. No warranty of
        fitness for purpose is made. Client assumes full responsibility for
        filing accuracy and legal sufficiency.*
        EOT;
    }

    // ── Utility ───────────────────────────────────────────────────────────

    /**
     * Convert a brand name to anonymized initials for the memo.
     * "VoltOps SaaS LLC" → "VS"
     */
    private static function toInitials(string $name): string
    {
        // Strip common suffixes that don't identify the business
        $name = preg_replace('/\b(LLC|Inc|Corp|Ltd|LLP|LP|PLLC|PC|Co\.?)\b/i', '', $name);
        $words = preg_split('/\s+/', trim($name));
        $initials = '';
        foreach ($words as $word) {
            if (strlen($word) > 0) {
                $initials .= strtoupper($word[0]);
            }
        }
        return $initials ?: 'XX';
    }

    /** Format a numeric value as a dollar string, or "N/A" if null/zero. */
    private static function formatMoney($value): string
    {
        if ($value === null || $value === '' || $value == 0) {
            return 'N/A';
        }
        return '$' . number_format((float)$value, 0);
    }
}
