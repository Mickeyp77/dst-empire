# DST Empire — Code Examples

**Practical recipes for common tasks.**

---

## 1. Run All Playbooks for a Single Brand

See [examples/01_run_playbooks.php](../examples/01_run_playbooks.php) for full implementation.

```php
<?php
require 'vendor/autoload.php';

use Mnmsos\Empire\Playbooks\PlaybookRegistry;

// Minimal intake record
$intake = [
    'brand_slug'          => 'acme_co',
    'entity_type'         => 'sole_prop',
    'annual_revenue_usd'  => 480000,
    'ebitda_usd'          => 120000,
    'industry_vertical'   => 'professional_services',
    'employee_count'      => 3,
    'aggression_tier'     => 'growth',
    'w2_wages_paid'       => 55000,
    'equipment_value_usd' => 25000,
    'liability_profile'   => 'low_med',
];

// Portfolio context (optional)
$portfolioCtx = [
    'owner_age_years'        => 42,
    'domicile_state'         => 'TX',
    'estate_plan_current'    => true,
    'kids_count'             => 2,
];

// Get registry & run all playbooks
$registry = PlaybookRegistry::getInstance();
$results = $registry->runAllSorted($intake, $portfolioCtx);

// Display results
printf("%-40s %15s %12s\n", "Playbook", "Y1 Savings", "Risk");
printf("%s\n", str_repeat("=", 67));

foreach ($results as $pb) {
    if ($pb['applies']) {
        printf(
            "%-40s $%14s %12s\n",
            $pb['playbook_name'],
            number_format($pb['estimated_savings_y1_usd']),
            $pb['risk_level']
        );
    }
}

// Total savings
$total = array_sum(array_column(
    array_filter($results, fn($r) => $r['applies']),
    'estimated_savings_y1_usd'
));
printf("%s\n", str_repeat("=", 67));
printf("%-40s $%14s\n", "TOTAL YEAR 1 BENEFIT", number_format($total));
```

Output:

```
S-Corp Election                                  $18000     Low
§199A QBI Deduction                             $12600     Low
R&D Credit §41                                   $8500     Medium
IP-Co Separation                                $24000     Medium
Charging-Order Protection LLC (WY)              $0         Low
Captive Insurance §831(b)                       $0         High
Management Fee Transfer Pricing                 $15000     Low
FLP Valuation Discount                          $0         Low
DAPT Domestic Asset Protection                  $0         Medium
Cost Segregation                                $0         Low
Solo 401(k) Maximum                             $8400      Low
QSBS §1202 Exclusion                            $0         Low
===================================================================
TOTAL YEAR 1 BENEFIT                        $86500
```

---

## 2. Synthesize Whole Portfolio

See [examples/02_synthesize_portfolio.php](../examples/02_synthesize_portfolio.php) for full implementation.

```php
<?php
require 'vendor/autoload.php';

use Mnmsos\Empire\Playbooks\PlaybookRegistry;
use Mnmsos\Empire\Synthesis\PortfolioSynthesizer;

// Multi-brand portfolio
$brands = [
    [
        'brand_slug'          => 'voltops',
        'entity_type'         => 'c_corp',
        'annual_revenue_usd'  => 3200000,
        'ebitda_usd'          => 800000,
        'employee_count'      => 12,
        'aggression_tier'     => 'aggressive',
        'decided_jurisdiction' => 'DE',
        'decided_parent_kind' => 'mnms_llc',
        'liability_profile'   => 'medium',
    ],
    [
        'brand_slug'          => 'dfwprinter',
        'entity_type'         => 'llc',
        'annual_revenue_usd'  => 450000,
        'ebitda_usd'          => 90000,
        'employee_count'      => 5,
        'aggression_tier'     => 'growth',
        'decided_jurisdiction' => 'TX',
        'decided_parent_kind' => 'mnms_llc',
        'liability_profile'   => 'med_high',
    ],
    [
        'brand_slug'          => 'printit',
        'entity_type'         => 'llc',
        'annual_revenue_usd'  => 280000,
        'ebitda_usd'          => 70000,
        'employee_count'      => 2,
        'aggression_tier'     => 'growth',
        'decided_jurisdiction' => 'WY',
        'decided_parent_kind' => 'mnms_llc',
        'liability_profile'   => 'low',
    ],
];

$portfolioCtx = [
    'owner_age_years'        => 45,
    'domicile_state'         => 'TX',
    'spouse_member_pct'      => 25,
    'kids_count'             => 2,
    'estate_plan_current'    => true,
    'estate_tax_exemption_used_usd' => 0,
];

// Synthesize
$registry = PlaybookRegistry::getInstance();
$synthesizer = new PortfolioSynthesizer();

$portfolio = $synthesizer->synthesize(
    intakes: $brands,
    portfolioCtx: $portfolioCtx,
    registry: $registry
);

// Display results
echo "=== PORTFOLIO SYNTHESIS ===\n\n";

echo "ORG CHART:\n";
echo json_encode($portfolio['org_chart'], JSON_PRETTY_PRINT) . "\n\n";

echo "CASH FLOW MODEL (Sankey):\n";
echo json_encode($portfolio['cash_flow'], JSON_PRETTY_PRINT) . "\n\n";

echo "TAX PROJECTIONS (5yr):\n";
echo json_encode($portfolio['tax_projection'], JSON_PRETTY_PRINT) . "\n\n";

echo "TOTAL Y1 BENEFIT: $" . number_format($portfolio['total_y1_benefit']) . "\n";
echo "TOTAL 5Y BENEFIT: $" . number_format($portfolio['total_5y_benefit']) . "\n";
```

Output structure:

```json
{
  "org_chart": {
    "nodes": [
      {
        "id": "mnms",
        "name": "MNMS LLC (S-Corp)",
        "type": "holding",
        "jurisdiction": "TX",
        "ownership_pct": 100
      },
      {
        "id": "voltops",
        "name": "VoltOps (C-Corp)",
        "type": "operating",
        "jurisdiction": "DE",
        "ownership_pct": 100,
        "parent_id": "mnms"
      }
    ],
    "edges": [
      {
        "from": "mnms",
        "to": "voltops",
        "relationship": "ownership"
      }
    ]
  },
  "cash_flow": {
    "sources": [
      {
        "name": "VoltOps Revenue",
        "amount": 3200000
      }
    ],
    "flows": [
      {
        "from": "VoltOps Revenue",
        "to": "COGS",
        "amount": 1280000
      }
    ]
  },
  "tax_projection": {
    "scenarios": [
      {
        "year": 1,
        "total_fed_tax": 156000,
        "total_state_tax": 24000,
        "owner_after_tax": 620000
      }
    ]
  },
  "total_y1_benefit": 156000,
  "total_5y_benefit": 890000
}
```

---

## 3. Render a Document Template

See [examples/03_render_template.php](../examples/03_render_template.php) for full implementation.

```php
<?php
require 'vendor/autoload.php';

use Mnmsos\Empire\Docs\TemplateRenderer;

// Load a template from doc_templates table
// (In real scenario, query from DB; here using hardcoded for demo)
$template_md = <<<'TEMPLATE'
# Operating Agreement — LLC Formation

**Effective Date:** {{effective_date}}

## 1. Formation
This Limited Liability Company ("**Company**") is formed under the laws of {{jurisdiction}}.

**Name:** {{legal_name}}
**Jurisdiction:** {{jurisdiction}}
**Registered Agent:** {{registered_agent}}

## 2. Members
{{#members}}
- {{name}} ({{ownership_pct}}%)
{{/members}}

## 3. Capital Contribution
Each member shall contribute the following:
{{#members}}
- {{name}}: ${{capital_contribution}}
{{/members}}

## 4. Tax Elections
- **S-Corp Election:** {{s_corp_election}}
- **QBI Calculation:** {{qbi_election}}

---

*This document is prepared for {{legal_name}} and is attorney-reviewed.*
TEMPLATE;

// Variables to substitute
$variables = [
    'effective_date'       => '2026-05-01',
    'jurisdiction'         => 'Texas',
    'legal_name'           => 'DFW Printer LLC',
    'registered_agent'     => 'CT Corporation System',
    's_corp_election'      => 'Yes (Form 2553 to be filed)',
    'qbi_election'         => 'Yes (§199A)',
    'members'              => [
        [
            'name'                 => 'Mickey Prasad',
            'ownership_pct'        => 75,
            'capital_contribution' => 50000,
        ],
        [
            'name'                 => 'Sabrina Prasad',
            'ownership_pct'        => 25,
            'capital_contribution' => 16667,
        ],
    ],
];

// Render
$renderer = new TemplateRenderer();
$rendered_md = $renderer->render($template_md, $variables);

echo "=== RENDERED OPERATING AGREEMENT ===\n\n";
echo $rendered_md;
```

Output:

```markdown
# Operating Agreement — LLC Formation

**Effective Date:** 2026-05-01

## 1. Formation
This Limited Liability Company ("**Company**") is formed under the laws of Texas.

**Name:** DFW Printer LLC
**Jurisdiction:** Texas
**Registered Agent:** CT Corporation System

## 2. Members
- Mickey Prasad (75%)
- Sabrina Prasad (25%)

## 3. Capital Contribution
Each member shall contribute the following:
- Mickey Prasad: $50000
- Sabrina Prasad: $16667

## 4. Tax Elections
- **S-Corp Election:** Yes (Form 2553 to be filed)
- **QBI Calculation:** Yes (§199A)

---

*This document is prepared for DFW Printer LLC and is attorney-reviewed.*
```

Convert to PDF (if Pandoc installed):

```php
use Mnmsos\Empire\Docs\PandocConverter;

$converter = new PandocConverter();
$pdf_path = $converter->markdownToPdf(
    markdown: $rendered_md,
    output_path: '/tmp/operating_agreement.pdf'
);

echo "PDF saved to: $pdf_path\n";
```

---

## 4. Generate BOI Report

```php
<?php
require 'vendor/autoload.php';

use Mnmsos\Empire\BOI\Filer;

$filer = new Filer();

// Entity info
$entity = [
    'legal_name'    => 'VoltOps Inc',
    'entity_type'   => 'C-Corp',
    'jurisdiction'  => 'DE',
    'ein'           => '12-3456789',
    'formed_date'   => '2020-01-15',
];

// Beneficial owners
$beneficial_owners = [
    [
        'full_legal_name'             => 'Michael Prasad',
        'date_of_birth'               => '1978-06-15',
        'residential_address'         => '1234 Main St, Dallas, TX 75201',
        'identifying_doc_type'        => 'drivers_license',
        'identifying_doc_number'      => 'D12345678',
        'identifying_doc_jurisdiction' => 'TX',
        'ownership_pct'               => 75,
        'control_role'                => 'owner',
        'is_company_applicant'        => true,
    ],
    [
        'full_legal_name'             => 'Sabrina Prasad',
        'date_of_birth'               => '1980-11-22',
        'residential_address'         => '1234 Main St, Dallas, TX 75201',
        'identifying_doc_type'        => 'passport',
        'identifying_doc_number'      => 'P123456',
        'identifying_doc_jurisdiction' => 'US',
        'ownership_pct'               => 25,
        'control_role'                => 'owner',
        'is_company_applicant'        => false,
    ],
];

// Generate BOIR
$boir = $filer->generateBOIR($entity, $beneficial_owners);

echo "=== FinCEN BOIR ===\n\n";
echo json_encode($boir, JSON_PRETTY_PRINT);

// Validate
$is_valid = $filer->validateBOIR($boir);
echo "\nValidation: " . ($is_valid ? "PASS" : "FAIL") . "\n";

// In production, submit to FinCEN
// $response = $filer->submitToFinCEN($boir);
// echo "FinCEN confirmation: " . $response['confirmation_number'] . "\n";
```

---

## 5. Create Custom Playbook

Extend `AbstractPlaybook` to add your own tax strategy:

```php
<?php
namespace Mnmsos\Empire\Playbooks;

class CustomEstateFreezePlaybook extends AbstractPlaybook
{
    public function getName(): string
    {
        return "Estate Freeze (IDGT + GRAT Stack)";
    }

    public function applies(array $intake): bool
    {
        // Only applies if brand is in aggressive tier + has significant equity
        return $intake['aggression_tier'] === 'aggressive'
            && ($intake['ebitda_usd'] ?? 0) > 250000
            && isset($intake['decided_jurisdiction']);
    }

    public function analyze(array $intake): array
    {
        $ebitda = $intake['ebitda_usd'] ?? 0;
        $multiple = 5;  // Conservative multiple
        $projected_gain = $ebitda * $multiple;

        // Estimate estate tax saved via freeze (assumes 40% rate)
        $estate_tax_saved = $projected_gain * 0.40 * 0.5;  // 50% of gain shifted to IDGT

        return [
            'applies'                    => true,
            'playbook_name'              => $this->getName(),
            'estimated_savings_y1_usd'   => 0,  // Estate planning ≠ immediate tax
            'estimated_savings_5y_usd'   => 0,
            'estimated_savings_lifetime_usd' => $estate_tax_saved,
            'multi_year_impact'          => [
                'Y1'  => 0,
                'Y5'  => 0,
                'Y10' => $estate_tax_saved / 2,  // Freeze effect compounds
            ],
            'risk_level'                 => 'Medium',
            'recommendation'             => "Set up IDGT to purchase {$intake['brand_slug']} equity at current valuation. Gift appreciates tax-free.",
            'irc_section'                => '§645 (IDGT) + §2503 (annual exclusion)',
            'jurisdictional_notes'       => $intake['decided_jurisdiction'],
        ];
    }
}

// Register it
$registry = PlaybookRegistry::getInstance();
$registry->register(new CustomEstateFreezePlaybook());

// Use it
$results = $registry->runAllSorted($intake, $portfolioCtx);
```

---

## 6. Add New State SOS Form

Extend `doc_templates` table with a new state-specific template:

```php
<?php
// In a PHP script or migration

$templates = [
    [
        'slug'               => 'nm_llc_articles_of_organization_2026',
        'name'               => 'Articles of Organization (New Mexico LLC)',
        'category'           => 'formation',
        'jurisdiction'       => 'NM',
        'entity_types_json'  => json_encode(['llc']),
        'aggression_tier'    => 'all',
        'source_url'         => 'https://www.env.nm.gov/business/llc-formation/',
        'version'            => '1.0',
        'priority'           => 'P0',
        'template_md'        => <<<'TEMPLATE'
# Articles of Organization
## New Mexico LLC

**Form filed with:** New Mexico Environment Department

### Article 1: Name
The name of this Limited Liability Company is: {{legal_name}}

### Article 2: Resident Agent
The street address of its designated resident agent is: {{agent_address}}

### Article 3: Managers/Members
{{#is_manager_managed}}
The company is managed by:
{{#managers}}
- {{name}}
{{/managers}}
{{/is_manager_managed}}

{{^is_manager_managed}}
The company is member-managed. Members are:
{{#members}}
- {{name}}
{{/members}}
{{/is_manager_managed}}

### Article 4: Duration
This company shall continue perpetually.

---

**Filing Fee:** $50 (New Mexico)
**Processing Time:** 5–10 business days
TEMPLATE,
        'variables_json'     => json_encode([
            ['name' => 'legal_name', 'type' => 'string', 'description' => 'Full legal name of LLC'],
            ['name' => 'agent_address', 'type' => 'string', 'description' => 'Resident agent street address'],
            ['name' => 'is_manager_managed', 'type' => 'boolean', 'description' => 'true = manager-managed, false = member-managed'],
            ['name' => 'managers', 'type' => 'array', 'description' => 'List of managers (if manager-managed)'],
            ['name' => 'members', 'type' => 'array', 'description' => 'List of members (if member-managed)'],
        ]),
        'notes_md'           => 'NM has no annual reporting requirement. No franchise tax. Cheapest formation fee ($50).',
    ],
];

// Insert into doc_templates
foreach ($templates as $t) {
    $sql = "
        INSERT INTO doc_templates (slug, name, category, jurisdiction, entity_types_json, aggression_tier, source_url, version, priority, template_md, variables_json, notes_md)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $t['slug'],
        $t['name'],
        $t['category'],
        $t['jurisdiction'],
        $t['entity_types_json'],
        $t['aggression_tier'],
        $t['source_url'],
        $t['version'],
        $t['priority'],
        $t['template_md'],
        $t['variables_json'],
        $t['notes_md'],
    ]);
}

echo "Template inserted.\n";
```

---

## 7. Wire Plaid for Multi-Account Veil Audit

```php
<?php
require 'vendor/autoload.php';

use Mnmsos\Empire\Plaid\AccountLinker;
use Mnmsos\Empire\Plaid\TransactionFetcher;
use Mnmsos\Empire\Plaid\VeilAuditor;

$linker = new AccountLinker($_ENV['PLAID_CLIENT_ID'], $_ENV['PLAID_SECRET']);
$fetcher = new TransactionFetcher();
$auditor = new VeilAuditor();

// Step 1: Get Plaid Link token for client
$link_token = $linker->createLinkToken(
    user_id: 'tenant_123',
    client_name: 'DST Empire',
    language: 'en'
);
echo "Send this link to client: " . $link_token['link'] . "\n";

// Step 2: Client completes Plaid Link, returns public_token
$public_token = 'public-xxx-yyy-zzz';  // received from client

// Step 3: Exchange for access_token
$access_token = $linker->exchangePublicToken($public_token);
echo "Access token: $access_token\n";

// Step 4: Store access_token in DB (linked to empire_brand_intake)
// ... update plaid_account_id in database ...

// Step 5: Fetch transactions (last 90 days)
$transactions = $fetcher->getTransactions(
    access_token: $access_token,
    start_date: date('Y-m-d', strtotime('-90 days')),
    end_date: date('Y-m-d')
);

// Step 6: Audit for veil-piercing risks
$veil_score = $auditor->analyzeTransactions($transactions);

echo "=== VEIL AUDIT ===\n";
echo "Veil Strength Score: " . $veil_score['score'] . "/100\n";
echo "Flagged Transactions: " . count($veil_score['personal_flagged']) . "\n";
echo "Recommendations:\n";
foreach ($veil_score['recommendations'] as $rec) {
    echo "  - " . $rec . "\n";
}
```

---

## References

- [examples/01_run_playbooks.php](../examples/01_run_playbooks.php) — Full working example
- [examples/02_synthesize_portfolio.php](../examples/02_synthesize_portfolio.php) — Portfolio synthesis
- [examples/03_render_template.php](../examples/03_render_template.php) — Document rendering
- [docs/ARCHITECTURE.md](ARCHITECTURE.md) — System design
- [docs/SCHEMA.md](SCHEMA.md) — Database schema
