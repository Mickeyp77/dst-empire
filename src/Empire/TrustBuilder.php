<?php
/**
 * DST Empire — Portfolio Analysis Engine for Entity Formation
 * Copyright (c) 2026 MNMS LLC
 * Licensed under the MIT License — see LICENSE in the project root.
 */

/**
 * TrustBuilder — reads empire_trust_thresholds and recommends trust structures.
 */
class TrustBuilder {

    /**
     * Recommend a trust structure given estimated brand asset value, liability
     * profile, and sale horizon.
     * Returns {recommended, reasons[], threshold_met, ranked_options[]}.
     */
    public static function recommendTrust(
        float  $estimatedAssets,
        string $liability,
        string $saleHorizon
    ): array {
        $rows = Database::get()
            ->query("SELECT * FROM empire_trust_thresholds ORDER BY min_assets_usd ASC")
            ->fetchAll();

        $thresholdMet = $estimatedAssets >= 300000;
        $reasons      = [];
        $recommended  = 'none';

        if ($estimatedAssets < 300000) {
            $reasons[] = "Asset base below \$300k threshold — trust overhead exceeds benefit at this stage.";
            $reasons[] = "Revisit when brand crosses \$300k in equity/IP value OR first active liability event.";
        } elseif ($estimatedAssets < 500000) {
            $recommended = 'dapt_wy';
            $reasons[]   = "WY DAPT at \$300k–\$500k: cheapest entry point (\$2k–\$5k setup), 4-yr SOL.";
            $reasons[]   = "Ideal if already using WY for entity formation (single-jurisdiction stack).";
        } elseif ($estimatedAssets < 1000000) {
            $recommended = in_array($liability, ['med_high','high'], true) ? 'dapt_nv' : 'dapt_sd';
            if ($recommended === 'dapt_nv') {
                $reasons[] = "NV DAPT recommended at \$500k–\$1M + high-liability: strongest statute, 2-yr SOL.";
                $reasons[] = "Requires NV trustee — \$1.5k–\$5k/yr ongoing.";
            } else {
                $reasons[] = "SD DAPT recommended at \$500k–\$1M: cheaper trustees than NV, stacks with Dynasty Trust.";
                $reasons[] = "SD 2-yr SOL statute — comparable to NV at lower cost.";
            }
        } elseif ($estimatedAssets < 2000000) {
            $recommended = 'dynasty_sd';
            $reasons[]   = "SD Dynasty Trust at \$1M–\$2M: perpetual trust, no rule against perpetuities.";
            $reasons[]   = "Stack with DAPT layer (SD or NV) for full asset-protection + generational-wealth play.";
            if (in_array($saleHorizon, ['never','5y_plus'], true)) {
                $reasons[] = "Long/never sale horizon amplifies Dynasty value — estate freeze opportunity via IDGT.";
            }
        } else {
            $recommended = 'bridge_trust';
            $reasons[]   = "\$2M+ with active liability: Bridge Trust provides US/offshore optionality.";
            $reasons[]   = "Sits dormant domestically; decants to Cook Islands/Nevis only under duress.";
            $reasons[]   = "Avoids immediate FBAR/IRS overhead — no offshore account until trigger event.";
        }

        // Build ranked_options from DB rows (eligible = assets >= min_assets_usd)
        $rankedOptions = [];
        foreach ($rows as $row) {
            if ($estimatedAssets >= (float)$row['min_assets_usd']) {
                $rankedOptions[] = [
                    'trust_kind'   => $row['trust_kind'],
                    'min_assets'   => $row['min_assets_usd'],
                    'setup_range'  => "\${$row['setup_cost_low_usd']}–\${$row['setup_cost_high_usd']}",
                    'annual_range' => "\${$row['annual_cost_low_usd']}–\${$row['annual_cost_high_usd']}/yr",
                    'summary'      => $row['when_to_consider_md'],
                ];
            }
        }

        return [
            'recommended'   => $recommended,
            'reasons'       => $reasons,
            'threshold_met' => $thresholdMet,
            'ranked_options' => $rankedOptions,
        ];
    }

    /** Return midpoint cost (annual_cost_low + annual_cost_high) / 2 for a trust kind. */
    public static function annualCost(string $trustKind): float {
        $stmt = Database::get()->prepare(
            "SELECT annual_cost_low_usd, annual_cost_high_usd FROM empire_trust_thresholds WHERE trust_kind=? LIMIT 1"
        );
        $stmt->execute([$trustKind]);
        $row = $stmt->fetch();
        if (!$row) return 0.0;
        return ((float)$row['annual_cost_low_usd'] + (float)$row['annual_cost_high_usd']) / 2;
    }

    /** Return the full trust_thresholds row + when_to_consider_md for a trust kind. */
    public static function summaryFor(string $trustKind): array {
        $stmt = Database::get()->prepare(
            "SELECT * FROM empire_trust_thresholds WHERE trust_kind=? LIMIT 1"
        );
        $stmt->execute([$trustKind]);
        return $stmt->fetch() ?: [];
    }
}
