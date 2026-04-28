<?php
/**
 * CostSegregationPlaybook — Cost Segregation Study (§168 + §179 + §168(k) Bonus)
 *
 * Evaluates whether a cost segregation study is beneficial for entities
 * with owned real estate or significant equipment/leasehold improvements.
 *
 * Cost segregation mechanics:
 *   A cost segregation study reclassifies real property components from
 *   39-year (commercial) or 27.5-year (residential) depreciation to
 *   shorter asset classes:
 *     5-year:  personal property embedded in building (carpeting, appliances,
 *              specialty lighting, some electrical, plumbing fixtures)
 *     7-year:  office furniture and fixtures identified via segregation
 *     15-year: land improvements (parking lots, sidewalks, landscaping,
 *              fencing, exterior lighting) — also qualifies for 150 % DB
 *     §179 / Bonus depreciation: most 5/7/15-year property qualifies
 *
 * Bonus depreciation (§168(k)):
 *   2025: 40 % bonus (phasing down from 100 %)
 *   2026: 20 % bonus (if not extended by Congress)
 *   Congress has introduced proposals to restore 100 % — flag as variable.
 *   We use 20 % for 2026 in conservative estimate.
 *
 * §179 expensing:
 *   2026 limit: $1,220,000 (indexed); phases out above $3,050,000 in assets.
 *   Applies to qualifying property placed in service during year.
 *
 * Typical cost segregation results:
 *   Reclassification percentage: 20–40 % of building cost to shorter-lived
 *   categories for commercial property.
 *   NPV of tax deferral on $1M building: $30,000–$80,000 (varies by tax rate
 *   and cost structure).
 *
 * IRS ATG (Audit Technique Guide) for cost segregation (Feb 2004):
 *   IRS recognises cost segregation studies as valid when performed by a
 *   qualified engineer with adequate documentation.
 */

namespace Mnmsos\Empire\Playbooks;

class CostSegregationPlaybook extends AbstractPlaybook
{
    private const BONUS_RATE_2026          = 0.20;  // 20 % bonus depreciation in 2026
    private const RECLASSIFICATION_RATE    = 0.30;  // Conservative: 30 % of building to short-lived
    private const SEC179_LIMIT_2026        = 1_220_000.0;
    private const MIN_ASSET_VALUE          = 250_000.0; // Below this, study cost > benefit

    public function getId(): string          { return 'cost_segregation'; }
    public function getName(): string        { return 'Cost Segregation Study (§168 + §168(k) Bonus + §179)'; }
    public function getCodeSection(): string { return '§168 / §168(k) / §179 / IRS ATG (2004)'; }
    public function getAggressionTier(): string { return 'conservative'; }
    public function getCategory(): string    { return 'tax'; }

    public function applies(array $intake, array $portfolioContext): bool
    {
        if (!$this->tierAllowed($intake)) {
            return false;
        }
        $realEstate  = ($intake['real_estate_owned'] ?? false);
        $equip       = $this->f($intake['equipment_value_usd']);
        $costSegDone = ($intake['cost_segregation_done'] ?? false);

        if ($costSegDone) {
            return false; // Already done
        }
        if (!$realEstate && $equip < self::MIN_ASSET_VALUE) {
            return false; // No qualifying property
        }
        return true;
    }

    public function evaluate(array $intake, array $portfolioContext): array
    {
        if (!$this->applies($intake, $portfolioContext)) {
            return $this->notApplicable(
                'Cost segregation not applicable: no real estate owned, equipment below $250k, ' .
                'study already completed, or aggression tier too low.'
            );
        }

        $realEstate  = ($intake['real_estate_owned'] ?? false);
        $equip       = $this->f($intake['equipment_value_usd']);
        $inventory   = $this->f($intake['inventory_value_usd']);
        $vertical    = $intake['industry_vertical'] ?? 'other';
        $entityType  = $intake['decided_entity_type'] ?? 'llc';

        // Building value proxy: if real estate owned, use equipment as proxy for building cost
        // (We don't have a direct building_cost column — flag gap)
        // Conservative proxy: equip × 3 for building when real estate owned
        $buildingCostProxy = $realEstate ? max($equip * 3.0, 500_000.0) : 0.0;
        $totalQualifyingAssets = $equip + $buildingCostProxy;

        // Reclassifiable amount: 30 % of building to shorter-lived categories
        $reclassified = $buildingCostProxy * self::RECLASSIFICATION_RATE;
        $equipClass   = $equip; // Equipment likely already 5 or 7-yr; study may accelerate further

        // Additional first-year depreciation from reclassification:
        // Old: building at 1/39 = 2.56 % / yr
        // New: 5-yr at 20 % (DB) + bonus 20 %
        $oldAnnualDeprec = $reclassified / 39.0;
        $bonusOnReclassified = $reclassified * self::BONUS_RATE_2026;
        $yr1NewDeprec = $bonusOnReclassified + ($reclassified * 0.20 * 0.80); // Bonus + remaining 5yr

        $additionalDeducY1 = max(0.0, $yr1NewDeprec - $oldAnnualDeprec);

        // Tax rate
        $taxRate = ($entityType === 'c_corp') ? 0.21 : 0.37;
        $taxSavedY1 = $additionalDeducY1 * $taxRate;

        // 5-yr NPV: reclassified assets front-load depreciation; cash is time-valuable
        // Approximate: Y1 benefit is the bulk, remaining years lesser
        $savings5y = $taxSavedY1 + ($taxSavedY1 * 0.5); // Y1 + residual benefit discounted

        // Study cost scales with building size
        $studyCost   = $buildingCostProxy > 1_000_000 ? 8_000.0 : 4_500.0;
        $ongoingCost = 500.0; // Annual depreciation schedule update

        $appScore = 30;
        if ($realEstate)              { $appScore += 30; }
        if ($equip > 500_000)         { $appScore += 15; }
        if ($taxSavedY1 > $studyCost) { $appScore += 15; }
        if (in_array($vertical, ['manufacturing', 'retail', 'ecommerce'], true)) { $appScore += 10; }

        return [
            'applies'                    => true,
            'applicability_score'        => $this->score($appScore),
            'estimated_savings_y1_usd'   => round($taxSavedY1, 2),
            'estimated_savings_5y_usd'   => round($savings5y, 2),
            'estimated_setup_cost_usd'   => $studyCost,
            'estimated_ongoing_cost_usd' => $ongoingCost,
            'risk_level'                 => 'low',
            'audit_visibility'           => 'low',
            'prerequisites_md'           => implode("\n", [
                '- Entity must own or have recently purchased real property, or have significant leasehold improvements',
                '- Equipment value ≥ $250k for study to be cost-effective',
                '- Cost segregation study must be performed by a qualified engineer',
                '- Must be placed in service (not just purchased) for the depreciation to apply in the year',
                '- §179 expensing limited to $1.22M total in 2026; phases out above $3.05M in assets',
                '- **Bonus depreciation note:** 2026 = 20 % (unless Congress restores 100 %); monitor legislation',
                '- Passive activity rules (§469) apply: losses may be suspended if activity is passive',
            ]),
            'rationale_md'               => implode("\n\n", [
                '**Cost Segregation Analysis**',
                sprintf(
                    "Real estate owned: **%s** | Equipment: **\$%s**  \n" .
                    "Building cost proxy: **\$%s**  \n" .
                    "Reclassifiable to 5/7/15-yr (30%%): **\$%s**  \n" .
                    "Y1 additional depreciation (20%% bonus + 5-yr MACRS): **\$%s**  \n" .
                    "Tax savings at %s%% rate: **\$%s**  \n" .
                    "Study cost: **\$%s**  \n" .
                    "Net Y1 ROI: **\$%s**",
                    $realEstate ? 'yes' : 'no',
                    number_format($equip, 0),
                    number_format($buildingCostProxy, 0),
                    number_format($reclassified, 0),
                    number_format($additionalDeducY1, 0),
                    number_format($taxRate * 100, 0),
                    number_format($taxSavedY1, 0),
                    number_format($studyCost, 0),
                    number_format(max(0, $taxSavedY1 - $studyCost), 0)
                ),
                'Cost segregation is a **tax deferral** strategy, not permanent avoidance — ' .
                'depreciation recapture (§1250 unrecaptured gain at 25 % rate) applies at sale. ' .
                'However, deferring tax for 10–20 years has significant time-value benefit, and ' .
                'a §1031 exchange or §1202 exit may eliminate the recapture entirely.',
                "**Bonus depreciation trajectory:** 80% (2023) → 60% (2024) → 40% (2025) → 20% (2026). " .
                "If Congress restores 100 % bonus, re-run this analysis — savings approximately 5× higher.",
            ]),
            'gotchas_md'                 => implode("\n", [
                '- **Depreciation recapture at sale:** §1250 unrecaptured gain taxed at 25 % rate.',
                '  §1031 exchange or installment sale can defer; §1202 C-Corp exit avoids entirely.',
                '- **Passive activity limitation (§469):** If this is a passive rental activity and owner',
                '  is not a real estate professional (750 hours/yr), losses may be suspended.',
                '- **Building cost column missing from schema:** We used equipment × 3 as proxy.',
                '  MICKEY-QUEUE: Add `building_cost_usd` column to empire_brand_intake for accurate analysis.',
                '- **§179 phase-out:** Above $3.05M in asset additions, §179 phases out dollar-for-dollar.',
                '  Don\'t double-count with bonus depreciation.',
                '- **Look-back studies:** Cost segregation can be done on prior-year acquisitions via',
                '  §481(a) catch-up or Form 3115 (Change in Accounting Method) — 4-year lookback.',
                '  No amended returns needed.',
                '- **Land is NOT depreciable.** Study must carefully allocate between land (0 %) and',
                '  building components (depreciable).',
            ]),
            'citations'                  => [
                'IRC §168 — Accelerated Cost Recovery System (MACRS)',
                'IRC §168(k) — Additional First-Year Depreciation (Bonus)',
                'IRC §179 — Election to Expense Certain Depreciable Business Assets',
                'IRC §1250 — Gain from Depreciable Real Property (recapture)',
                'IRS Audit Technique Guide — Cost Segregation (Feb 2004)',
                'Rev. Proc. 87-56 — MACRS asset class lives',
                'Treas. Reg. §1.168(k)-2 — Bonus depreciation regulations',
                'Hospital Corp. of America v. Commissioner, 109 T.C. 21 (1997)',
            ],
            'docs_required'              => [
                'cost_segregation_report',  // Engineer-prepared study
                'form_3115',                // Change in accounting method (for look-back)
                'depreciation_schedule',    // Updated asset class schedule
                'purchase_settlement_stmt', // HUD-1 or closing disclosure for real estate
                'building_plans',           // Architectural drawings to support classification
            ],
            'next_actions'               => [
                'Engage a qualified cost segregation firm (engineering-based, not purely accounting)',
                'Provide purchase price, closing documents, and building plans to study firm',
                'For prior-year acquisitions: file Form 3115 for catch-up depreciation (no amended return needed)',
                'Update depreciation schedule with new asset classes after study',
                'Flag `cost_segregation_done = 1` in empire_brand_intake after study completion',
                'Model §1031 exchange strategy if sale planned within 5 years to manage recapture',
            ],
        ];
    }
}
