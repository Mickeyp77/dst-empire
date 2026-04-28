<?php
/**
 * ArchetypeMatcher — Ranks the 3 DST Empire structural archetypes against
 * a given brand intake + portfolio context.
 *
 * Archetypes:
 *   lifestyle — Single S-Corp + DBAs, no trusts, simple low-cost stack
 *   growth    — HoldCo S-Corp + ops LLCs + IP-Co + WY DAPT (modest layering)
 *   exit      — Multi-trust nesting + DE C-Corp + IP-Co + DAPT + Dynasty + 501C + FLP
 *
 * Usage:
 *   $ranked = ArchetypeMatcher::match($intakeRow, $portfolioContext);
 *   // [{archetype, score, reasoning_md}, ...]  sorted score DESC
 */

namespace Mnmsos\Empire\Intake;

class ArchetypeMatcher
{
    // Aggression tier recommended per archetype
    private const ARCHETYPE_TIER = [
        'lifestyle' => 'conservative',
        'growth'    => 'growth',
        'exit'      => 'aggressive',
    ];

    /**
     * Score and rank archetypes for the given intake.
     *
     * @param array $intake           empire_brand_intake row (or partial subset)
     * @param array $portfolioContext empire_portfolio_context row (may be [])
     * @return array[] [{archetype:string, score:int, tier:string, reasoning_md:string}, ...] sorted DESC
     */
    public static function match(array $intake, array $portfolioContext): array
    {
        $scores = [
            'lifestyle' => self::scoreLifestyle($intake, $portfolioContext),
            'growth'    => self::scoreGrowth($intake, $portfolioContext),
            'exit'      => self::scoreExit($intake, $portfolioContext),
        ];

        $results = [];
        foreach ($scores as $archetype => $data) {
            $results[] = [
                'archetype'    => $archetype,
                'score'        => $data['score'],
                'tier'         => self::ARCHETYPE_TIER[$archetype],
                'reasoning_md' => $data['reasoning'],
            ];
        }

        usort($results, static fn($a, $b) => $b['score'] - $a['score']);

        return $results;
    }

    // ── Lifestyle scorer ──────────────────────────────────────────────────

    private static function scoreLifestyle(array $i, array $ctx): array
    {
        $score  = 0;
        $points = [];

        $revenue      = (float)($i['annual_revenue_usd']   ?? $i['revenue_usd'] ?? 0);
        $saleHorizon  = $i['sale_horizon']                  ?? '';
        $memberCount  = (int)($i['owner_count']             ?? $ctx['member_count'] ?? 1);
        $entityCount  = (int)($ctx['total_brand_count']     ?? 1);
        $hasVC        = (bool)($i['has_vc_plans']           ?? false);
        $aggrTier     = $i['aggression_tier']               ?? '';

        if ($revenue < 500000) {
            $score += 40;
            $points[] = 'Revenue <$500k → simple stack sufficient.';
        } elseif ($revenue < 1000000) {
            $score += 20;
            $points[] = 'Revenue $500k–$1M → lifestyle still viable.';
        }

        if ($saleHorizon === 'never') {
            $score += 30;
            $points[] = 'Sale horizon = never → no exit-prep overhead needed.';
        } elseif (in_array($saleHorizon, ['active_sale', '1y_minus'], true)) {
            $score -= 20;
            $points[] = 'Active/near-term sale → lifestyle stack sub-optimal for exit.';
        }

        if ($memberCount <= 1) {
            $score += 15;
            $points[] = 'Single member → charging-order risk low, simple structure fits.';
        }

        if ($entityCount <= 3) {
            $score += 10;
            $points[] = 'Small portfolio (≤3 brands) → low mgmt overhead tolerable.';
        }

        if ($hasVC) {
            $score -= 30;
            $points[] = 'VC plans → lifestyle S-Corp blocks preferred stock issuance.';
        }

        if ($aggrTier === 'conservative') {
            $score += 10;
            $points[] = 'Owner preference: conservative tier.';
        }

        $score = max(0, min(100, $score));

        return [
            'score'     => $score,
            'reasoning' => "**Lifestyle** fit score: {$score}/100\n\n" . implode("\n- ", array_merge([''], $points)),
        ];
    }

    // ── Growth scorer ─────────────────────────────────────────────────────

    private static function scoreGrowth(array $i, array $ctx): array
    {
        $score  = 0;
        $points = [];

        $revenue      = (float)($i['annual_revenue_usd']   ?? $i['revenue_usd'] ?? 0);
        $saleHorizon  = $i['sale_horizon']                  ?? '';
        $hasIP        = (bool)($i['has_ip_assets']          ?? $ctx['has_ip'] ?? false);
        $entityCount  = (int)($ctx['total_brand_count']     ?? 1);
        $memberCount  = (int)($i['owner_count']             ?? $ctx['member_count'] ?? 1);
        $hasVC        = (bool)($i['has_vc_plans']           ?? false);
        $aggrTier     = $i['aggression_tier']               ?? '';

        if ($revenue >= 500000 && $revenue <= 5000000) {
            $score += 40;
            $points[] = 'Revenue $500k–$5M → growth-tier layering yields meaningful savings.';
        } elseif ($revenue > 5000000) {
            $score += 15;
            $points[] = 'Revenue >$5M → growth viable but exit track may dominate.';
        } elseif ($revenue >= 200000) {
            $score += 20;
            $points[] = 'Revenue $200k–$500k → approaching growth-tier thresholds.';
        }

        if (in_array($saleHorizon, ['5y_plus', '3y_to_5y'], true)) {
            $score += 30;
            $points[] = 'Sale horizon 3–5y+ → growth layering pays before exit window.';
        } elseif ($saleHorizon === 'never') {
            $score += 10;
            $points[] = 'Long-term hold → growth structure durable.';
        }

        if ($hasIP) {
            $score += 15;
            $points[] = 'IP assets → IP-Co separation unlocks royalty income shifting.';
        }

        if ($entityCount >= 3 && $entityCount <= 10) {
            $score += 10;
            $points[] = 'Mid-size portfolio → mgmt fee layer adds transfer-pricing flexibility.';
        }

        if ($memberCount >= 2) {
            $score += 5;
            $points[] = 'Multiple members → DAPT adds charging-order protection layer.';
        }

        if ($hasVC) {
            $score -= 10;
            $points[] = 'VC plans → growth S-Corp may need C-Corp conversion later.';
        }

        if ($aggrTier === 'growth') {
            $score += 10;
            $points[] = 'Owner preference: growth tier.';
        }

        $score = max(0, min(100, $score));

        return [
            'score'     => $score,
            'reasoning' => "**Growth Track** fit score: {$score}/100\n\n" . implode("\n- ", array_merge([''], $points)),
        ];
    }

    // ── Exit scorer ───────────────────────────────────────────────────────

    private static function scoreExit(array $i, array $ctx): array
    {
        $score  = 0;
        $points = [];

        $revenue      = (float)($i['annual_revenue_usd']   ?? $i['revenue_usd'] ?? 0);
        $saleHorizon  = $i['sale_horizon']                  ?? '';
        $hasVC        = (bool)($i['has_vc_plans']           ?? false);
        $entityCount  = (int)($ctx['total_brand_count']     ?? 1);
        $hasIP        = (bool)($i['has_ip_assets']          ?? $ctx['has_ip'] ?? false);
        $hasRE        = (bool)($i['has_real_estate']        ?? false);
        $aggrTier     = $i['aggression_tier']               ?? '';

        if ($revenue > 5000000) {
            $score += 40;
            $points[] = 'Revenue >$5M → full trust nesting + FLP valuation discounts material.';
        } elseif ($revenue >= 2000000) {
            $score += 25;
            $points[] = 'Revenue $2M–$5M → Dynasty + DAPT begin paying.';
        }

        if (in_array($saleHorizon, ['active_sale', '1y_minus'], true)) {
            $score += 35;
            $points[] = 'Active/near-term sale → exit track maximizes after-tax proceeds.';
        } elseif ($saleHorizon === '3y_to_5y') {
            $score += 20;
            $points[] = '3–5yr horizon → enough runway to install full exit stack.';
        }

        if ($hasVC) {
            $score += 20;
            $points[] = 'VC plans → DE C-Corp + QSBS §1202 exclusion critical path.';
        }

        if ($entityCount >= 8) {
            $score += 15;
            $points[] = 'Large portfolio → HoldCo + multi-tier trusts justify complexity.';
        }

        if ($hasIP) {
            $score += 10;
            $points[] = 'IP assets → Dynasty Trust as IP-Co owner = multi-gen tax shield.';
        }

        if ($hasRE) {
            $score += 10;
            $points[] = 'Real estate → FLP + §1031 + cost-seg stack adds significant value.';
        }

        if ($aggrTier === 'aggressive') {
            $score += 10;
            $points[] = 'Owner preference: aggressive tier.';
        }

        $score = max(0, min(100, $score));

        return [
            'score'     => $score,
            'reasoning' => "**Exit Track** fit score: {$score}/100\n\n" . implode("\n- ", array_merge([''], $points)),
        ];
    }
}
