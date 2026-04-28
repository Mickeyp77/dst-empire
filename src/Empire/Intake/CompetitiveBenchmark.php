<?php
/**
 * CompetitiveBenchmark — Static lookup table for industry/revenue-band leakage estimates.
 *
 * Data source: publicly available IRS SOI (Statistics of Income) tables,
 * industry tax studies (NFIB, AICPA surveys), and known tax provision thresholds.
 * All figures are estimates; Phase D will replace with anonymized aggregate from
 * prior client data once N≥30 per cell.
 *
 * CAVEAT: Numbers are directionally accurate, not CPA-prepared. Figures assume
 * TX/WY/NV/DE entities. State-specific deviations may vary. Not tax advice.
 *
 * Phase D swap: replace $BENCHMARKS with a DB query against empire_benchmark_aggregate
 * (a table to be added in migration 080+). Cell lookup key is same.
 *
 * Namespace: Mnmsos\Empire\Intake
 */

namespace Mnmsos\Empire\Intake;

class CompetitiveBenchmark
{
    /**
     * Static benchmark table.
     * Key: "{vertical}:{revenue_band}:{state_tier}"
     * state_tier: "no_income_tax" | "low_income_tax" | "high_income_tax"
     *
     * leakage_items: array of {description, annual_usd, playbook_ref}
     * comparable_structure: textual description of typical peer structure
     */
    private static array $BENCHMARKS = [

        // ─── SaaS ────────────────────────────────────────────────────────
        'saas:under_250k:no_income_tax' => [
            'leakage_items' => [
                ['desc' => 'Missed §199A QBI deduction (sole prop/S-Corp)', 'annual_usd' => 8500,  'playbook' => '§199A QBI'],
                ['desc' => 'Over-paid SE-tax before S-Corp election',       'annual_usd' => 4200,  'playbook' => 'S-Corp W2 split'],
                ['desc' => 'No Solo 401(k) — forgone pre-tax shelter',      'annual_usd' => 3800,  'playbook' => 'Solo 401(k) max'],
                ['desc' => 'Untracked R&D hours — missed §41 credit',       'annual_usd' => 2200,  'playbook' => 'R&D credit §41'],
            ],
            'comparable_structure' => 'TX S-Corp (W2 + distributions) + Solo 401(k)',
        ],
        'saas:250k_1m:no_income_tax' => [
            'leakage_items' => [
                ['desc' => 'Missed §199A QBI deduction',                    'annual_usd' => 14000, 'playbook' => '§199A QBI'],
                ['desc' => 'Over-paid SE-tax pre-S-Corp',                   'annual_usd' => 8000,  'playbook' => 'S-Corp W2 split'],
                ['desc' => 'R&D credit (dev hours untracked)',               'annual_usd' => 6500,  'playbook' => 'R&D credit §41'],
                ['desc' => 'No PTET election — SALT cap leakage (if multi)', 'annual_usd' => 4500,  'playbook' => 'PTET election'],
                ['desc' => 'Missed §174 amortization planning',              'annual_usd' => 3200,  'playbook' => '§174 capitalization'],
            ],
            'comparable_structure' => 'DE C-Corp (§1202 QSBS holder) + TX Op-Co S-Corp + DAPT (WY) at $300k+ assets',
        ],
        'saas:1m_5m:no_income_tax' => [
            'leakage_items' => [
                ['desc' => 'No §1202 QSBS structure — missed $10M exclusion window', 'annual_usd' => 0,     'playbook' => '§1202 QSBS'],
                ['desc' => 'Missed §199A on passthrough income',                      'annual_usd' => 42000, 'playbook' => '§199A QBI'],
                ['desc' => 'R&D credit — dev team hours (est 30% of headcount)',      'annual_usd' => 22000, 'playbook' => 'R&D credit §41'],
                ['desc' => 'IP not in IP-Co — missing cost-plus mgmt fee arbitrage',  'annual_usd' => 18000, 'playbook' => 'IP-Co + management fee'],
                ['desc' => 'Captive insurance premiums not deducted',                 'annual_usd' => 14000, 'playbook' => 'Captive §831(b)'],
            ],
            'comparable_structure' => 'DE C-Corp + TX Op-Co + IP-Co (WY LLC) + DAPT (SD/NV) + micro-captive',
        ],
        'saas:over_5m:no_income_tax' => [
            'leakage_items' => [
                ['desc' => 'No QSBS stacking to non-grantor trusts',         'annual_usd' => 0,     'playbook' => '§1202 QSBS stacking'],
                ['desc' => 'No cost-plus mgmt fee shift to low-tax entity',  'annual_usd' => 55000, 'playbook' => 'Cost-plus management fee'],
                ['desc' => 'R&D credit — significant but under-documented',  'annual_usd' => 45000, 'playbook' => 'R&D credit §41'],
                ['desc' => 'Captive insurance under-sized',                  'annual_usd' => 35000, 'playbook' => 'Captive §831(b)'],
                ['desc' => 'Solo 401(k) not maxed for both spouses',         'annual_usd' => 16000, 'playbook' => 'Solo 401(k) max stack'],
            ],
            'comparable_structure' => 'DE C-Corp + IP-HoldCo + 2-3 Op-Co LLCs + SD Dynasty Trust + micro-captive',
        ],

        // ─── Agency / Professional Services ──────────────────────────────
        'agency:under_250k:no_income_tax' => [
            'leakage_items' => [
                ['desc' => 'No S-Corp election — full SE-tax on all net income', 'annual_usd' => 6800, 'playbook' => 'S-Corp W2 split'],
                ['desc' => 'Missed §199A QBI deduction',                         'annual_usd' => 6200, 'playbook' => '§199A QBI'],
                ['desc' => 'No home-office deduction (qualified principal)',      'annual_usd' => 1800, 'playbook' => 'Home office §280A'],
            ],
            'comparable_structure' => 'TX S-Corp + home office + Solo 401(k)',
        ],
        'agency:250k_1m:no_income_tax' => [
            'leakage_items' => [
                ['desc' => 'Over-paid SE-tax without optimal W2/distribution split', 'annual_usd' => 11000, 'playbook' => 'S-Corp W2 split'],
                ['desc' => 'Missed §199A — phaseout near $232k but still partial',   'annual_usd' => 8500,  'playbook' => '§199A QBI'],
                ['desc' => 'No PTET election if multi-state',                         'annual_usd' => 5500,  'playbook' => 'PTET election'],
                ['desc' => 'Personal guarantees on leases not isolated',              'annual_usd' => 0,     'playbook' => 'Liability isolation'],
            ],
            'comparable_structure' => 'TX or WY LLC + S-Corp election + separate client-funds LLC',
        ],
        'professional_services:250k_1m:no_income_tax' => [
            'leakage_items' => [
                ['desc' => 'PLLC not S-elected — SE-tax leakage',           'annual_usd' => 9800,  'playbook' => 'S-Corp W2 split'],
                ['desc' => 'E&O policy not scoped to entity — personal risk', 'annual_usd' => 0,   'playbook' => 'Liability isolation'],
                ['desc' => 'Missed §199A QBI deduction',                     'annual_usd' => 8200,  'playbook' => '§199A QBI'],
                ['desc' => 'No cost-segregation on home-office improvements', 'annual_usd' => 2100, 'playbook' => '§168 bonus depreciation'],
            ],
            'comparable_structure' => 'TX PLLC (S-election) + holding LLC + E&O in entity name',
        ],

        // ─── eCommerce / Retail ───────────────────────────────────────────
        'ecommerce:under_250k:no_income_tax' => [
            'leakage_items' => [
                ['desc' => 'No S-Corp election — SE-tax on all net',         'annual_usd' => 5200, 'playbook' => 'S-Corp W2 split'],
                ['desc' => 'Inventory costing method not optimized (FIFO vs LIFO)', 'annual_usd' => 2800, 'playbook' => 'LIFO election §472'],
                ['desc' => 'Missed §179 on equipment/fixtures',               'annual_usd' => 2200, 'playbook' => '§179 expensing'],
            ],
            'comparable_structure' => 'TX LLC + S-Corp election + inventory entity for IP',
        ],
        'ecommerce:250k_1m:no_income_tax' => [
            'leakage_items' => [
                ['desc' => 'Multi-state nexus not managed — over-collecting sales tax', 'annual_usd' => 12000, 'playbook' => 'Multi-state nexus'],
                ['desc' => 'No §179/bonus dep on warehouse/equipment',                  'annual_usd' => 9500,  'playbook' => '§168 bonus depreciation'],
                ['desc' => 'Brand IP in Op-Co — not isolated for sale',                 'annual_usd' => 0,     'playbook' => 'IP-Co isolation'],
                ['desc' => 'Missed §199A passthrough',                                  'annual_usd' => 7200,  'playbook' => '§199A QBI'],
            ],
            'comparable_structure' => 'TX Op-Co LLC + WY IP-Co LLC + S-Corp elections',
        ],
        'retail:250k_1m:no_income_tax' => [
            'leakage_items' => [
                ['desc' => 'Personal guarantee on lease — veil exposure',   'annual_usd' => 0,     'playbook' => 'Liability isolation'],
                ['desc' => 'No §199A QBI deduction',                        'annual_usd' => 9000,  'playbook' => '§199A QBI'],
                ['desc' => 'Missed cost-seg on leasehold improvements',     'annual_usd' => 7500,  'playbook' => 'Cost segregation'],
                ['desc' => 'Workers comp / employment claims in Op-Co',     'annual_usd' => 0,     'playbook' => 'Liability isolation - employment'],
            ],
            'comparable_structure' => 'TX S-Corp + prop-co LLC (if owns location) + separate IP/brand LLC',
        ],

        // ─── Real Estate ──────────────────────────────────────────────────
        'realestate:250k_1m:no_income_tax' => [
            'leakage_items' => [
                ['desc' => 'No cost-segregation study — year-1 dep foregone',    'annual_usd' => 28000, 'playbook' => 'Cost segregation'],
                ['desc' => 'Properties in personal name — no charging-order wall', 'annual_usd' => 0,   'playbook' => 'Liability isolation'],
                ['desc' => 'Missed §199A on rental QBI (for non-passive active)',  'annual_usd' => 9500,  'playbook' => '§199A QBI'],
                ['desc' => 'No 1031 exchange strategy on appreciated props',       'annual_usd' => 0,   'playbook' => '§1031 exchange'],
            ],
            'comparable_structure' => 'WY LLC per property (series or individual) + management LLC (S-Corp) + trust wrapper',
        ],
        'realestate:1m_5m:no_income_tax' => [
            'leakage_items' => [
                ['desc' => 'Cost-seg not done on portfolio — est 15% of value as dep acceleration', 'annual_usd' => 45000, 'playbook' => 'Cost segregation'],
                ['desc' => 'Opportunity zone investment not utilized',            'annual_usd' => 0,     'playbook' => 'Opportunity zone §1400Z'],
                ['desc' => 'No Prop-Co / Op-Co split for management income',      'annual_usd' => 22000, 'playbook' => 'Op-Co/Prop-Co split'],
                ['desc' => 'Captive insurance on casualty/liability risk',        'annual_usd' => 18000, 'playbook' => 'Captive §831(b)'],
            ],
            'comparable_structure' => 'WY LLCs (Prop-Co) + TX Management LLC (S-Corp) + DAPT wrapper',
        ],

        // ─── Healthcare ───────────────────────────────────────────────────
        'healthcare:under_250k:no_income_tax' => [
            'leakage_items' => [
                ['desc' => 'PLLC not S-elected — SE-tax on all net income', 'annual_usd' => 7200, 'playbook' => 'S-Corp W2 split'],
                ['desc' => 'HIPAA compliance costs uninsured in entity',    'annual_usd' => 0,    'playbook' => 'Liability isolation'],
                ['desc' => 'Missed §199A QBI deduction',                    'annual_usd' => 6500, 'playbook' => '§199A QBI'],
            ],
            'comparable_structure' => 'TX PLLC (S-election) + management LLC + E&O/malpractice in entity',
        ],
        'healthcare:250k_1m:no_income_tax' => [
            'leakage_items' => [
                ['desc' => 'MSO structure absent — billing/admin income not separated', 'annual_usd' => 15000, 'playbook' => 'MSO / PC split'],
                ['desc' => 'Missed §199A QBI (healthcare is specified service — income cap applies)', 'annual_usd' => 8500, 'playbook' => '§199A QBI'],
                ['desc' => 'No captive for malpractice/cyber risk',                      'annual_usd' => 12000, 'playbook' => 'Captive §831(b)'],
                ['desc' => 'Personal guarantee on EMR/practice lease',                   'annual_usd' => 0,     'playbook' => 'Liability isolation'],
            ],
            'comparable_structure' => 'TX PC/PLLC (clinical) + TX LLC MSO (management) + S-Corp election on MSO',
        ],

        // ─── Manufacturing ────────────────────────────────────────────────
        'manufacturing:250k_1m:no_income_tax' => [
            'leakage_items' => [
                ['desc' => 'No §179 or bonus depreciation on equipment',       'annual_usd' => 18000, 'playbook' => '§179 + §168 bonus dep'],
                ['desc' => 'R&D credit — production process improvements',     'annual_usd' => 12000, 'playbook' => 'R&D credit §41'],
                ['desc' => 'Workers comp + product liability in single entity', 'annual_usd' => 0,    'playbook' => 'Liability isolation'],
                ['desc' => 'Missed §199A QBI deduction',                       'annual_usd' => 9500,  'playbook' => '§199A QBI'],
            ],
            'comparable_structure' => 'TX Op-Co LLC (S-Corp) + IP-Co (patents, tooling) + separate employee-leasing LLC',
        ],

        // ─── Default / Other ──────────────────────────────────────────────
        'other:under_250k:no_income_tax' => [
            'leakage_items' => [
                ['desc' => 'No S-Corp election — SE-tax on all net income', 'annual_usd' => 5500, 'playbook' => 'S-Corp W2 split'],
                ['desc' => 'Missed §199A QBI deduction',                    'annual_usd' => 5000, 'playbook' => '§199A QBI'],
            ],
            'comparable_structure' => 'TX LLC + S-Corp election',
        ],
        'other:250k_1m:no_income_tax' => [
            'leakage_items' => [
                ['desc' => 'Over-paid SE-tax without W2/distribution split', 'annual_usd' => 9500,  'playbook' => 'S-Corp W2 split'],
                ['desc' => 'Missed §199A QBI deduction',                     'annual_usd' => 8000,  'playbook' => '§199A QBI'],
                ['desc' => 'No PTET election if multi-state',                'annual_usd' => 4500,  'playbook' => 'PTET election'],
            ],
            'comparable_structure' => 'TX or WY LLC + S-Corp election + holding LLC',
        ],
    ];

    /**
     * Revenue band buckets (in USD).
     */
    private static array $REVENUE_BANDS = [
        250000   => 'under_250k',
        1000000  => '250k_1m',
        5000000  => '1m_5m',
        PHP_INT_MAX => 'over_5m',
    ];

    /**
     * States with no personal income tax (tax-tier: no_income_tax).
     * All others default to "low_income_tax" (same table for now).
     */
    private static array $NO_INCOME_TAX_STATES = ['TX','WY','NV','SD','FL','AK','WA','TN','NH'];

    /**
     * Get benchmark for a given vertical + revenue + state.
     *
     * @param string $vertical  industry_vertical enum value
     * @param float  $revenue   annual_revenue_usd
     * @param string $state     2-char state code (or empty for default)
     * @return array{
     *   vertical: string,
     *   revenue_band: string,
     *   state_tier: string,
     *   leakage_items: array,
     *   typical_loss_md: string,
     *   estimated_recoverable_y1_usd: int,
     *   comparable_structure: string,
     *   phase_d_caveat: string
     * }
     */
    public static function getBenchmark(string $vertical, float $revenue, string $state = ''): array
    {
        $band      = self::revenueBand($revenue);
        $stateTier = self::stateTier(strtoupper(trim($state)));

        // Try exact key, then fallback to no_income_tax, then 'other'
        $key         = "{$vertical}:{$band}:{$stateTier}";
        $fallbackKey = "{$vertical}:{$band}:no_income_tax";
        $otherKey    = "other:{$band}:no_income_tax";

        $data = self::$BENCHMARKS[$key]
             ?? self::$BENCHMARKS[$fallbackKey]
             ?? self::$BENCHMARKS[$otherKey]
             ?? null;

        if ($data === null) {
            $data = [
                'leakage_items'       => [
                    ['desc' => 'Generic: missed SE-tax optimization', 'annual_usd' => 5000, 'playbook' => 'S-Corp W2 split'],
                    ['desc' => 'Generic: missed §199A QBI deduction', 'annual_usd' => 4000, 'playbook' => '§199A QBI'],
                ],
                'comparable_structure' => 'TX LLC + S-Corp election',
            ];
        }

        $items    = $data['leakage_items'];
        $total    = array_sum(array_column($items, 'annual_usd'));

        // Build readable markdown description
        $mdParts = [];
        foreach ($items as $item) {
            if ($item['annual_usd'] > 0) {
                $mdParts[] = '$' . number_format($item['annual_usd']) . '/yr to ' . $item['desc'];
            } else {
                $mdParts[] = '[non-quantified risk] ' . $item['desc'];
            }
        }

        return [
            'vertical'                   => $vertical,
            'revenue_band'               => self::revenueBandLabel($band),
            'state_tier'                 => $stateTier,
            'leakage_items'              => $items,
            'typical_loss_md'            => implode('; ', $mdParts),
            'estimated_recoverable_y1_usd' => $total,
            'comparable_structure'       => $data['comparable_structure'],
            'phase_d_caveat'             => 'Figures based on IRS SOI tables + AICPA surveys. Phase D will replace with anonymized aggregate from prior client data (N≥30 per cell).',
        ];
    }

    /**
     * Get all available vertical keys for display in the select dropdown.
     * @return array<string,string> key → label
     */
    public static function getVerticals(): array
    {
        return [
            'saas'                 => 'SaaS / Software',
            'agency'               => 'Marketing / Creative Agency',
            'professional_services'=> 'Professional Services (Legal/CPA/Consulting)',
            'ecommerce'            => 'eCommerce',
            'retail'               => 'Retail / Brick-and-Mortar',
            'healthcare'           => 'Healthcare / Medical',
            'realestate'           => 'Real Estate',
            'manufacturing'        => 'Manufacturing / Industrial',
            'crypto'               => 'Crypto / Digital Assets',
            'other'                => 'Other',
        ];
    }

    // ─── Private helpers ──────────────────────────────────────────────────

    private static function revenueBand(float $revenue): string
    {
        foreach (self::$REVENUE_BANDS as $threshold => $band) {
            if ($revenue <= $threshold) return $band;
        }
        return 'over_5m';
    }

    private static function revenueBandLabel(string $band): string
    {
        return match ($band) {
            'under_250k' => 'Under $250k',
            '250k_1m'    => '$250k–$1M',
            '1m_5m'      => '$1M–$5M',
            'over_5m'    => 'Over $5M',
            default      => $band,
        };
    }

    private static function stateTier(string $state): string
    {
        return in_array($state, self::$NO_INCOME_TAX_STATES, true)
            ? 'no_income_tax'
            : 'low_income_tax';
    }
}
