<?php
/**
 * DST Empire — Portfolio Analysis Engine for Entity Formation
 * Copyright (c) 2026 MNMS LLC
 * Licensed under the MIT License — see LICENSE in the project root.
 */

/**
 * StateMatrix — reads empire_states and drives multi-state arbitrage comparisons.
 */
class StateMatrix {

    /** Return all empire_states rows ordered by code. */
    public static function all(): array {
        return Database::get()
            ->query("SELECT * FROM empire_states ORDER BY code")
            ->fetchAll();
    }

    /** Fetch a single state row by 2-letter code; null if not found. */
    public static function get(string $code): ?array {
        $stmt = Database::get()->prepare(
            "SELECT * FROM empire_states WHERE code=? LIMIT 1"
        );
        $stmt->execute([strtoupper($code)]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Build side-by-side comparison map for the given state codes.
     * Returns {field_label => [code => value, ...]} — ready for a comparison table.
     */
    public static function compare(array $codes): array {
        if (empty($codes)) return [];

        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = Database::get()->prepare(
            "SELECT * FROM empire_states WHERE code IN ({$placeholders}) ORDER BY FIELD(code,{$placeholders})"
        );
        $stmt->execute(array_merge($codes, $codes));
        $rows = $stmt->fetchAll();

        $fields = [
            'name'                 => 'State Name',
            'formation_fee'        => 'Formation Fee ($)',
            'annual_fee'           => 'Annual Fee ($)',
            'franchise_tax'        => 'Franchise Tax',
            'state_income_tax'     => 'State Income Tax',
            'anonymity_score'      => 'Anonymity Score (0–10)',
            'charging_order_score' => 'Charging-Order Score (0–10)',
            'dynasty_trust_score'  => 'Dynasty Trust Score (0–10)',
            'dapt_score'           => 'DAPT Score (0–10)',
            'series_llc_supported' => 'Series LLC Supported',
            'case_law_score'       => 'Case Law Score (0–10)',
            'vc_friendly_score'    => 'VC-Friendly Score (0–10)',
            'notes_md'             => 'Notes',
        ];

        $comparison = [];
        foreach ($fields as $col => $label) {
            $comparison[$label] = [];
            foreach ($rows as $row) {
                $val = $row[$col];
                if ($col === 'series_llc_supported') {
                    $val = $val ? 'Yes' : 'No';
                }
                $comparison[$label][$row['code']] = $val;
            }
        }
        return $comparison;
    }

    /**
     * Score and rank states for a given brand profile.
     * $brandProfile keys: liability_profile (low/low_med/medium/med_high/high),
     *   sale_horizon (never/5y_plus/3y/1y/active_sale), anonymity_pref (low/medium/high),
     *   asset_protection_pref (low/medium/high).
     * Returns top 3 jurisdictions: [{code, name, score, reasons[], cons[]}, ...].
     */
    public static function recommendFor(array $brandProfile): array {
        $states = self::all();

        $liabilityHigh      = in_array($brandProfile['liability_profile'] ?? 'low', ['med_high','high'], true);
        $saleActive         = in_array($brandProfile['sale_horizon'] ?? 'never', ['1y','active_sale'], true);
        $anonymityHigh      = ($brandProfile['anonymity_pref'] ?? 'low') === 'high';
        $assetProtHigh      = ($brandProfile['asset_protection_pref'] ?? 'low') === 'high';

        $scored = [];
        foreach ($states as $s) {
            $score   = 0;
            $reasons = [];
            $cons    = [];

            // Base score (average of scored dimensions)
            $base = (
                $s['anonymity_score'] +
                $s['charging_order_score'] +
                $s['dynasty_trust_score'] +
                $s['dapt_score'] +
                $s['case_law_score'] +
                $s['vc_friendly_score']
            ) / 6;
            $score += $base;

            // Liability-high: DAPT x2, charging_order x2
            if ($liabilityHigh) {
                $score  += $s['dapt_score'] * 2 + $s['charging_order_score'] * 2;
                if ($s['dapt_score'] >= 7) {
                    $reasons[] = "Strong DAPT statute ({$s['code']}, score {$s['dapt_score']}/10) — priority for high-liability ops.";
                }
                if ($s['charging_order_score'] >= 8) {
                    $reasons[] = "Charging-order protection score {$s['charging_order_score']}/10 — creditor-exclusive remedy.";
                }
            }

            // Active sale / VC: DE case law x2, vc_friendly x2
            if ($saleActive) {
                $score += $s['case_law_score'] * 2 + $s['vc_friendly_score'] * 2;
                if ($s['vc_friendly_score'] >= 8) {
                    $reasons[] = "VC-friendly score {$s['vc_friendly_score']}/10 — clean cap table diligence path.";
                }
                if ($s['case_law_score'] >= 9) {
                    $reasons[] = "Chancery Court case law depth ({$s['case_law_score']}/10) — essential for M&A diligence.";
                }
            }

            // Anonymity-high: anonymity x3
            if ($anonymityHigh) {
                $score += $s['anonymity_score'] * 3;
                if ($s['anonymity_score'] >= 8) {
                    $reasons[] = "Anonymity score {$s['anonymity_score']}/10 — member rolls not publicly accessible.";
                }
            }

            // Asset protection high: DAPT + dynasty combined x2
            if ($assetProtHigh) {
                $combined  = (int)$s['dapt_score'] + (int)$s['dynasty_trust_score'];
                $score    += $combined * 2;
                if ($combined >= 15) {
                    $reasons[] = "Combined DAPT+Dynasty score {$combined}/20 — best-in-class asset protection stack.";
                }
            }

            // Formation cost penalty (minor — cheap shouldn't override legal strength)
            if ($s['formation_fee'] > 300) {
                $score -= 2;
                $cons[] = "Higher formation fee \${$s['formation_fee']}.";
            }
            if ($s['annual_fee'] > 200) {
                $score -= 1;
                $cons[] = "Annual fee \${$s['annual_fee']}/yr ongoing.";
            }
            if ((float)$s['formation_fee'] === 0.0 && (float)$s['annual_fee'] === 0.0) {
                $reasons[] = "Lowest cost structure: \$0 annual fee.";
            }

            // Generic cons
            if ($s['vc_friendly_score'] <= 3 && $saleActive) {
                $cons[] = "Low VC-friendly score ({$s['vc_friendly_score']}/10) — unfavorable for near-term institutional sale.";
            }
            if ($s['anonymity_score'] <= 3 && $anonymityHigh) {
                $cons[] = "Public member disclosure required.";
            }

            if (empty($reasons)) {
                $reasons[] = "Balanced scores across all dimensions.";
            }

            $scored[] = [
                'code'    => $s['code'],
                'name'    => $s['name'],
                'score'   => round(min($score, 100), 1),
                'reasons' => $reasons,
                'cons'    => $cons,
            ];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, 3);
    }
}
