<?php
/**
 * QSBS1202Playbook — IRC §1202 Qualified Small Business Stock
 *
 * Evaluates eligibility for the §1202 exclusion on gain from Qualified
 * Small Business Stock (QSBS). Up to $10 million (or 10× basis) of gain
 * excluded from federal income tax per taxpayer per issuing corporation.
 *
 * Requirements (2026 law, post-PATH Act):
 *   1. Stock issued by a **domestic C-Corporation** (not S-Corp, LLC, or
 *      partnership) — Treas. Reg. §1.1202-2.
 *   2. Corporation must be a Qualified Small Business (QSB) at time of
 *      issuance: aggregate gross assets ≤ $50M immediately after issuance
 *      (§1202(d)(1)).
 *   3. Stock acquired at **original issuance** (not secondary market) for
 *      money, property, or services (§1202(c)(1)(B)).
 *   4. Taxpayer held for **more than 5 years** (§1202(a)).
 *   5. Active business test: ≥ 80 % of assets used in an active qualified
 *      trade or business (§1202(e)(1)).
 *   6. Excluded sectors (§1202(e)(3)): professional services (health, law,
 *      engineering, financial services, brokerage, consulting, athletics,
 *      performing arts, actuarial science, accounting); hotels/restaurants;
 *      financial services; farming; mining; professional sports.
 *
 * Stacking: each family member + each irrevocable trust = separate $10M cap.
 *   E.g. Mickey + Sabrina + 2 kids + DAPT = 5 × $10M = $50M total exclusion.
 *   See *Rev. Rul. 2023-2* and PLR 201717010 on trust stacking.
 *
 * Exclusion percentages (§1202(a)):
 *   Stock acquired 2010+: 100 % exclusion (no AMT preference item)
 *   Stock acquired 2009:  75 %
 *   Stock acquired before 2009: 50 %
 *   We assume 2026 acquisition = 100 %.
 *
 * Savings estimate: excluded gain × combined federal marginal rate (23.8 %
 *   = 20 % LTCG + 3.8 % NIIT for high-income taxpayer).
 */

namespace Mnmsos\Empire\Playbooks;

class QSBS1202Playbook extends AbstractPlaybook
{
    private const MAX_EXCLUSION_PER_TAXPAYER = 10_000_000.0;
    private const EXCLUSION_RATE             = 1.00;   // 100 % for post-2010 stock
    private const TAX_RATE_AVOIDED           = 0.238;  // 20 % LTCG + 3.8 % NIIT
    private const QSB_GROSS_ASSET_LIMIT      = 50_000_000.0;

    // Disqualified industry verticals per §1202(e)(3)
    private const EXCLUDED_VERTICALS = [
        'healthcare',        // professional health services
        'professional_services', // law, accounting, consulting, financial
        'crypto',            // treated as financial services / brokerage
    ];

    public function getId(): string          { return 'qsbs_1202'; }
    public function getName(): string        { return 'QSBS §1202 Exclusion (C-Corp + 5-Year Hold)'; }
    public function getCodeSection(): string { return '§1202'; }
    public function getAggressionTier(): string { return 'growth'; }
    public function getCategory(): string    { return 'sale'; }

    public function applies(array $intake, array $portfolioContext): bool
    {
        if (!$this->tierAllowed($intake)) {
            return false;
        }
        // Must be a C-Corp (or planning to become one)
        $entityType = $intake['decided_entity_type'] ?? '';
        if (!in_array($entityType, ['c_corp', 'corp'], true)) {
            return false;
        }
        // Disqualified verticals
        $vertical = $intake['industry_vertical'] ?? '';
        if (in_array($vertical, self::EXCLUDED_VERTICALS, true)) {
            return false;
        }
        // Sale horizon must be set (at least "possible" within some period)
        $horizon = $intake['decided_sale_horizon'] ?? $intake['sale_horizon'] ?? 'never';
        if ($horizon === 'never') {
            return false;
        }
        return true;
    }

    public function evaluate(array $intake, array $portfolioContext): array
    {
        if (!$this->applies($intake, $portfolioContext)) {
            return $this->notApplicable(
                'QSBS §1202 not applicable: entity is not a C-Corp, is in a disqualified ' .
                'industry vertical (professional services / healthcare / crypto / financial), ' .
                'no sale horizon set, or aggression tier too low.'
            );
        }

        // Estimate exit value from EBITDA × multiple_target
        $ebitda        = $this->f($intake['ebitda_usd']);
        $revenue       = $this->f($intake['annual_revenue_usd']);
        $multipleTarget = $this->f($intake['multiple_target']) ?: 5.0; // default 5× EBITDA
        $baseVal       = ($ebitda > 0) ? $ebitda * $multipleTarget : $revenue * 1.5;
        $baseVal       = max($baseVal, 0.0);

        // Check QSB gross-asset limit
        $equipment = $this->f($intake['equipment_value_usd']);
        $inventory = $this->f($intake['inventory_value_usd']);
        $ar        = $this->f($intake['ar_balance_usd']);
        $approxAssets = $revenue + $equipment + $inventory + $ar; // rough total assets proxy
        $withinQsbLimit = ($approxAssets <= self::QSB_GROSS_ASSET_LIMIT);

        // Stackable taxpayers: owner + spouse + kids + trust
        $spousePct  = $this->f($portfolioContext['spouse_member_pct'] ?? 0);
        $kidsCount  = $this->i($portfolioContext['kids_count'] ?? 0);
        $hasSpouse  = $spousePct > 0;
        $taxpayers  = 1 + ($hasSpouse ? 1 : 0) + min($kidsCount, 4) + 1; // +1 for DAPT
        $maxExclusion = $taxpayers * self::MAX_EXCLUSION_PER_TAXPAYER;

        // Excluded gain (capped)
        // Assume cost basis ≈ $0 (founder stock) for maximum gain scenario
        $gain          = $baseVal;
        $excludedGain  = min($gain, $maxExclusion);
        $taxSaved      = $excludedGain * self::TAX_RATE_AVOIDED * self::EXCLUSION_RATE;

        // If gain > 10× basis, the 10× rule may permit more exclusion — note it
        $tenXRule = $gain > self::MAX_EXCLUSION_PER_TAXPAYER;

        // Setup: DE C-Corp formation + attorney for QSBS compliance memo
        $setupCost   = 3_500.0;
        $ongoingCost = 1_500.0; // annual C-Corp maintenance + §1202 compliance tracking

        $appScore = 30;
        if ($withinQsbLimit)           { $appScore += 20; }
        if ($baseVal > 1_000_000)      { $appScore += 20; }
        if ($baseVal > 5_000_000)      { $appScore += 15; }
        if ($taxpayers >= 3)           { $appScore += 10; }

        return [
            'applies'                    => true,
            'applicability_score'        => $this->score($appScore),
            'estimated_savings_y1_usd'   => 0.0, // no tax event until exit
            'estimated_savings_5y_usd'   => round($taxSaved, 2), // at exit
            'estimated_setup_cost_usd'   => $setupCost,
            'estimated_ongoing_cost_usd' => $ongoingCost,
            'risk_level'                 => 'low',
            'audit_visibility'           => 'medium',
            'prerequisites_md'           => implode("\n", [
                '- Entity must be a **domestic C-Corporation** formed in Delaware (preferred)',
                '- Aggregate gross assets ≤ $50M at time of stock issuance (§1202(d)(1))',
                '- Stock must be acquired at **original issuance** — not purchased on secondary market',
                '- Owner must hold stock for **> 5 continuous years** before sale',
                '- Corporation must pass the **active business test** (≥ 80 % of assets in qualified trade)',
                '- Not in disqualified sectors: professional services, financial, hospitality, farming, mining',
                '- Consider splitting stock among family members + irrevocable trusts to stack exclusion caps',
            ]),
            'rationale_md'               => implode("\n\n", [
                "**QSBS §1202 Exclusion Analysis**",
                sprintf(
                    "Estimated exit value: **\$%s** (%s× EBITDA of \$%s)  \n" .
                    "Stackable taxpayers: **%d** (owner%s%s + DAPT)  \n" .
                    "Max exclusion available: **\$%s**  \n" .
                    "Excluded gain estimate: **\$%s**  \n" .
                    "Federal tax avoided (20%% LTCG + 3.8%% NIIT): **\$%s**",
                    number_format($baseVal, 0),
                    number_format($multipleTarget, 1),
                    number_format($ebitda, 0),
                    $taxpayers,
                    $hasSpouse ? ' + spouse' : '',
                    $kidsCount > 0 ? ' + ' . $kidsCount . ' kid(s)' : '',
                    number_format($maxExclusion, 0),
                    number_format($excludedGain, 0),
                    number_format($taxSaved, 0)
                ),
                'Stock issued after 09/27/2010 qualifies for **100 % exclusion** with no AMT ' .
                'preference item (§1202(a)(4) as amended by PATH Act 2015, made permanent).',
                $tenXRule
                    ? '**10× Basis Rule:** §1202 allows exclusion of the greater of $10M or 10× ' .
                      'adjusted basis. If founder stock basis is very low, the $10M cap governs. ' .
                      'Issue stock at nominal value ($0.0001/share) and document basis precisely.'
                    : '',
                $withinQsbLimit
                    ? 'Entity is within the $50M gross-asset QSB limit based on financial data provided.'
                    : '**WARNING:** Estimated gross assets may exceed $50M QSB limit. Verify with counsel — ' .
                      'issuances above the limit do not qualify for §1202 treatment.',
            ]),
            'gotchas_md'                 => implode("\n", [
                '- **5-year clock starts at issuance, not today.** Structure before revenue ramps if possible.',
                '- **C-Corp means double taxation until exit.** Factor in accumulated retained earnings tax',
                '  drag vs. passthrough savings. Model both paths — QBI199A on the passthrough side may win',
                '  for lower exits.',
                '- **State conformity varies.** CA does NOT conform to §1202 — state gain still taxable.',
                '  TX has no income tax. DE conforms. Check domicile state carefully.',
                '- **Rollover (§1045):** Gain can be rolled into another QSBS within 60 days to preserve',
                '  the clock if you must exit before 5 years.',
                '- **Trust stacking requires irrevocable trusts.** Revocable living trusts are grantor',
                '  trusts and do NOT get a separate cap (they share the grantor\'s cap).',
                '- **Anti-abuse rules:** §1202(f) disqualifies issuances for redemption of prior stock.',
                '  Document that no redemption occurred in 2 years before or after issuance.',
                '- **Professional services exclusion is broad.** Consulting, financial advisory, and some',
                '  SaaS companies with significant consulting revenue may be partially disqualified.',
                '  Get an opinion letter if industry is borderline.',
            ]),
            'citations'                  => [
                'IRC §1202 — Partial Exclusion for Gain from Certain Small Business Stock',
                'IRC §1045 — Rollover of Gain from Empowerment Zone Assets',
                'Treas. Reg. §1.1202-2 — Qualified Small Business Stock',
                'PATH Act of 2015 — Permanent 100 % exclusion for post-09/27/2010 stock',
                'Rev. Rul. 2023-2 — Stepped-up basis in irrevocable trusts',
                'PLR 201717010 — Trust stacking of §1202 exclusion caps',
                'IRS FAQ on QSBS (IR-2021-203)',
            ],
            'docs_required'              => [
                'de_corp_articles',       // Delaware C-Corp formation
                'stock_purchase_agreement', // Documents original issuance
                'qsbs_compliance_memo',   // Attorney opinion on §1202 qualification
                'cap_table',              // Ownership structure with issuance dates
                'trust_documents',        // If stacking via irrevocable trusts
                'form_8949',              // Capital gains reporting at exit
            ],
            'next_actions'               => [
                'Form Delaware C-Corp (recommend incorporating before first material revenue event)',
                'Issue founder stock at nominal par value — document §1202 compliance at issuance',
                'Obtain attorney QSBS qualification opinion letter within 90 days of issuance',
                'Set 5-year hold reminder in compliance_calendar (task_type: 1202_clock)',
                'If stacking: establish irrevocable trusts for spouse + kids before issuance',
                'Confirm state conformity with CPA (critical if domicile is CA or MA)',
            ],
        ];
    }
}
