<?php
/**
 * Example 2: Synthesize Whole Portfolio
 *
 * Demonstrates multi-brand portfolio aggregation across all playbooks,
 * org chart generation, cash flow modeling, and tax projections.
 *
 * Usage: php examples/02_synthesize_portfolio.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Mnmsos\Empire\Playbooks\PlaybookRegistry;
use Mnmsos\Empire\Synthesis\PortfolioSynthesizer;

// ============================================================
// Sample 3-brand portfolio (MNMS House of Brands)
// ============================================================

$brands = [
    [
        'id'                       => 1,
        'brand_slug'               => 'voltops',
        'brand_name'               => 'VoltOps',
        'entity_type'              => 'c_corp',
        'annual_revenue_usd'       => 3200000,
        'revenue_recurring_pct'    => 85,
        'customer_concentration_pct' => 22,
        'gross_margin_pct'         => 60,
        'cogs_usd'                 => 1280000,
        'opex_usd'                 => 1200000,
        'ebitda_usd'               => 800000,
        'working_capital_usd'      => 320000,
        'equipment_value_usd'      => 180000,
        'employee_count'           => 12,
        'w2_wages_paid'            => 480000,
        'aggression_tier'          => 'aggressive',
        'decided_jurisdiction'     => 'DE',
        'decided_entity_type'      => 'c_corp',
        'decided_parent_kind'      => 'mnms_llc',
        'decided_trust_wrapper'    => 'none',
        'decided_sale_horizon'     => '5y_plus',
        'liability_profile'        => 'medium',
        'professional_liability_score' => 4,
        'vehicle_count'            => 2,
        'active_claims_count'      => 0,
        'multiple_target'          => 5.0,
        'strategic_buyer_pool_md'  => 'Adobe, ServiceTitan, Xerox, Ricoh, HP',
        'qbi_election_active'      => false,  // C-Corp, no §199A
        'ptet_election_active'     => false,
        'cost_segregation_done'    => false,
        'industry_vertical'        => 'saas',
    ],
    [
        'id'                       => 2,
        'brand_slug'               => 'dfwprinter',
        'brand_name'               => 'DFW Printer',
        'entity_type'              => 'llc',
        'annual_revenue_usd'       => 450000,
        'revenue_recurring_pct'    => 45,
        'customer_concentration_pct' => 18,
        'gross_margin_pct'         => 45,
        'cogs_usd'                 => 247500,
        'opex_usd'                 => 157500,
        'ebitda_usd'               => 90000,
        'working_capital_usd'      => 45000,
        'equipment_value_usd'      => 85000,
        'employee_count'           => 5,
        'w2_wages_paid'            => 165000,
        'aggression_tier'          => 'growth',
        'decided_jurisdiction'     => 'TX',
        'decided_entity_type'      => 'llc',
        'decided_parent_kind'      => 'mnms_llc',
        'decided_trust_wrapper'    => 'dapt_nv',
        'decided_sale_horizon'     => '3y',
        'liability_profile'        => 'med_high',
        'professional_liability_score' => 7,
        'vehicle_count'            => 4,
        'active_claims_count'      => 0,
        'multiple_target'          => 4.0,
        'strategic_buyer_pool_md'  => 'MSP consolidators, Gartner, Ricoh dealers',
        'qbi_election_active'      => true,
        'ptet_election_active'     => false,
        'cost_segregation_done'    => false,
        'industry_vertical'        => 'professional_services',
    ],
    [
        'id'                       => 3,
        'brand_slug'               => 'printit',
        'brand_name'               => 'PrintIt',
        'entity_type'              => 'llc',
        'annual_revenue_usd'       => 280000,
        'revenue_recurring_pct'    => 25,
        'customer_concentration_pct' => 12,
        'gross_margin_pct'         => 55,
        'cogs_usd'                 => 126000,
        'opex_usd'                 => 84000,
        'ebitda_usd'               => 70000,
        'working_capital_usd'      => 28000,
        'equipment_value_usd'      => 35000,
        'employee_count'           => 2,
        'w2_wages_paid'            => 75000,
        'aggression_tier'          => 'growth',
        'decided_jurisdiction'     => 'WY',
        'decided_entity_type'      => 'llc',
        'decided_parent_kind'      => 'mnms_llc',
        'decided_trust_wrapper'    => 'none',
        'decided_sale_horizon'     => 'never',
        'liability_profile'        => 'low',
        'professional_liability_score' => 2,
        'vehicle_count'            => 1,
        'active_claims_count'      => 0,
        'multiple_target'          => 3.5,
        'strategic_buyer_pool_md'  => 'Keep long-term (SEO + brand)',
        'qbi_election_active'      => true,
        'ptet_election_active'     => false,
        'cost_segregation_done'    => false,
        'industry_vertical'        => 'ecommerce',
    ],
];

// Portfolio-level context
$portfolioCtx = [
    'owner_age_years'                 => 45,
    'retirement_target_age'           => 60,
    'spouse_member_pct'               => 25,
    'kids_count'                      => 2,
    'estate_plan_current'             => true,
    'estate_tax_exemption_used_usd'   => 0,
    'domicile_state'                  => 'TX',
    'residency_planned_change'        => false,
    'tx_sos_status'                   => 'current',
    'irs_status'                      => 'current',
    'franchise_tax_current'           => true,
];

// ============================================================
// Run Synthesis
// ============================================================

$registry = PlaybookRegistry::getInstance();
$synthesizer = new PortfolioSynthesizer();

$portfolio = $synthesizer->synthesize(
    intakes: $brands,
    portfolioCtx: $portfolioCtx,
    registry: $registry
);

// ============================================================
// Display Results
// ============================================================

printf("\n%s\n", str_repeat("=", 70));
printf("PORTFOLIO SYNTHESIS\n");
printf("%s\n\n", str_repeat("=", 70));

// Overall summary
printf("**PORTFOLIO OVERVIEW**\n");
printf("Brands: %d | Total Revenue: $%s | Total EBITDA: $%s\n",
    count($brands),
    number_format($portfolio['total_revenue_usd']),
    number_format($portfolio['total_ebitda_usd'])
);
printf("Owner: Mickey & Sabrina Prasad (45 yo) | Estate plan: Current | Domicile: TX\n\n");

// Tax benefit summary
printf("**TAX & STRATEGY BENEFITS**\n");
printf("Year 1 Total Benefit: $%s\n", number_format($portfolio['total_y1_benefit']));
printf("5-Year Cumulative: $%s\n", number_format($portfolio['total_5y_benefit']));
printf("Strategy Complexity: %s\n\n", $portfolio['aggression_rating']);

// Org chart (simplified)
printf("**ENTITY HIERARCHY**\n");
if (isset($portfolio['org_chart']['nodes'])) {
    foreach ($portfolio['org_chart']['nodes'] as $node) {
        $indent = ($node['type'] === 'holding') ? '' : '  └─ ';
        printf("%s%s (%s, %s)\n",
            $indent,
            $node['name'],
            $node['type'],
            $node['jurisdiction']
        );
    }
}
printf("\n");

// Cash flow summary
printf("**CASH FLOW MODEL**\n");
printf("Total Revenue: $%s\n", number_format($portfolio['total_revenue_usd']));
printf("Total COGS: $%s\n", number_format($portfolio['total_cogs_usd']));
printf("Total OpEx: $%s\n", number_format($portfolio['total_opex_usd']));
printf("Subtotal (pre-tax): $%s\n", number_format($portfolio['total_ebitda_usd']));
printf("Est. Entity-level tax: $%s\n", number_format($portfolio['est_entity_tax_usd']));
printf("Owner take-home (est.): $%s\n\n", number_format($portfolio['owner_take_home_est_usd']));

// Tax projection scenarios
if (isset($portfolio['tax_projection'])) {
    printf("**TAX PROJECTIONS (Multi-Year)**\n");
    printf("%-6s %-18s %-18s %-18s\n", "Year", "Entity Tax", "Owner Tax", "After-Tax Income");
    printf("%s\n", str_repeat("-", 60));
    foreach ($portfolio['tax_projection'] as $scenario) {
        printf("%-6d $%-17s $%-17s $%-17s\n",
            $scenario['year'],
            number_format($scenario['entity_tax']),
            number_format($scenario['owner_tax']),
            number_format($scenario['after_tax_income'])
        );
    }
    printf("\n");
}

// Per-brand playbook summary
printf("**PER-BRAND PLAYBOOK SUMMARY**\n");
printf("%-20s %-20s %-15s %-15s\n", "Brand", "Tier", "Y1 Savings", "Risk Profile");
printf("%s\n", str_repeat("-", 70));

foreach ($portfolio['brand_results'] as $result) {
    $savings = array_sum(array_column(
        array_filter($result['playbooks'], fn($p) => $p['applies']),
        'estimated_savings_y1_usd'
    ));
    $risk = array_reduce(
        array_filter($result['playbooks'], fn($p) => $p['applies']),
        fn($carry, $p) => max($carry, ($p['risk_level'] === 'High' ? 3 : ($p['risk_level'] === 'Medium' ? 2 : 1))),
        0
    );
    $risk_text = ($risk === 3) ? 'High' : (($risk === 2) ? 'Medium' : 'Low');

    printf("%-20s %-20s $%-14s %s\n",
        $result['brand_slug'],
        $result['aggression_tier'],
        number_format($savings),
        $risk_text
    );
}
printf("\n");

// Recommendations
printf("**RECOMMENDATIONS**\n");
if (isset($portfolio['recommendations'])) {
    foreach ($portfolio['recommendations'] as $i => $rec) {
        printf("%d. %s\n", $i + 1, $rec);
    }
}
printf("\n");

printf("%s\n", str_repeat("=", 70));
echo "Synthesis complete.\n\n";
