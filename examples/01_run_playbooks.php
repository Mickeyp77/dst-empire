<?php
declare(strict_types=1);

/**
 * Example 1 — Run all playbooks for a single brand
 *
 * Demonstrates the core engine: feed in intake data + portfolio context,
 * get back structured recommendations from 12 playbooks.
 *
 * Run: php examples/01_run_playbooks.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Mnmsos\Empire\Playbooks\PlaybookRegistry;

$intake = [
    'id' => 1,
    'tenant_id' => 1,
    'brand_slug' => 'acme_saas',
    'brand_name' => 'Acme SaaS Inc.',
    'tier' => 'T2',
    'liability_profile' => 'medium',
    'current_status' => 'operating',
    'annual_revenue_usd' => 850000,
    'revenue_recurring_pct' => 85,
    'customer_concentration_pct' => 22,
    'gross_margin_pct' => 78,
    'cogs_usd' => 187000,
    'opex_usd' => 425000,
    'ebitda_usd' => 238000,
    'employee_count' => 4,
    'aggression_tier' => 'aggressive',
    'industry_vertical' => 'saas',
    'real_estate_owned' => false,
    'equipment_value_usd' => 35000,
    'decided_jurisdiction' => 'DE',
    'decided_entity_type' => 'c_corp',
    'decided_sale_horizon' => '5y_plus',
];

$portfolioCtx = [
    'tenant_id' => 1,
    'owner_age_years' => 38,
    'spouse_member_pct' => 0,
    'kids_count' => 2,
    'estate_plan_current' => false,
    'domicile_state' => 'TX',
    'estate_tax_exemption_used_usd' => 0,
];

echo "=== DST Empire — Playbook Engine Demo ===\n\n";
echo "Brand: {$intake['brand_name']} ({$intake['brand_slug']})\n";
echo "Revenue: \$" . number_format($intake['annual_revenue_usd']) . "\n";
echo "EBITDA: \$" . number_format($intake['ebitda_usd']) . "\n";
echo "Aggression: {$intake['aggression_tier']}\n";
echo "Industry: {$intake['industry_vertical']}\n\n";

$results = PlaybookRegistry::runAll($intake, $portfolioCtx);

$totalY1 = 0;
$totalSetup = 0;
$applicableCount = 0;

foreach ($results as $r) {
    $applies = $r['applies'] ? '✓' : '✗';
    $name = str_pad($r['name'] ?? 'unknown', 40);
    if ($r['applies']) {
        $applicableCount++;
        $totalY1 += (float)($r['estimated_savings_y1_usd'] ?? 0);
        $totalSetup += (float)($r['estimated_setup_cost_usd'] ?? 0);
        $savings = '$' . number_format((float)$r['estimated_savings_y1_usd'], 0);
        $risk = $r['risk_level'] ?? 'low';
        echo "{$applies} {$name} — {$savings}/yr [{$risk} risk]\n";
    } else {
        echo "{$applies} {$name} — N/A\n";
    }
}

echo "\n=== Summary ===\n";
echo "Applicable playbooks: {$applicableCount}/" . count($results) . "\n";
echo "Total Y1 savings: \$" . number_format($totalY1, 2) . "\n";
echo "Total setup cost: \$" . number_format($totalSetup, 2) . "\n";
$roi = $totalSetup > 0 ? round(($totalY1 / $totalSetup) * 100) : 0;
echo "ROI Y1: {$roi}%\n";

echo "\n=== Next Steps ===\n";
echo "- Read docs/PLAYBOOKS.md for full playbook details\n";
echo "- See examples/02_synthesize_portfolio.php for whole-portfolio synthesis\n";
echo "- Visit dstempire.com for hosted SaaS w/ curated templates + law monitor\n";
