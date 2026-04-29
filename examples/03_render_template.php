<?php
/**
 * Example 3: Render Document Template with Variable Substitution
 *
 * Demonstrates loading a document template, filling variables,
 * and rendering to markdown. Can be piped to Pandoc for PDF/DOCX.
 *
 * Usage: php examples/03_render_template.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Mnmsos\Empire\Docs\TemplateRenderer;

// ============================================================
// Sample Operating Agreement Template (markdown)
// ============================================================

$template_md = <<<'TEMPLATE'
# Operating Agreement

**LLC Formation Document**

---

## OPERATING AGREEMENT
### OF {{legal_name}}

**This Operating Agreement ("Agreement") is made and entered into effective as of {{effective_date}} ("Effective Date")**

---

## ARTICLE 1: FORMATION AND AUTHORITY

### 1.1 Formation
{{legal_name}} ("Company") is a limited liability company formed under the Limited Liability Company Law of the State of {{jurisdiction}}.

**Company Name:** {{legal_name}}
**State of Formation:** {{jurisdiction}}
**Date of Formation:** {{formed_date}}

### 1.2 Registered Agent
The Company's registered agent is: {{registered_agent_name}}, located at {{registered_agent_address}}, {{jurisdiction}}.

---

## ARTICLE 2: MEMBERS AND OWNERSHIP

The following individuals are members of the Company:

{{#members}}
- **{{name}}** | Ownership: {{ownership_pct}}% | Address: {{address}}
{{/members}}

**Total Outstanding Membership Interest:** 100%

---

## ARTICLE 3: CAPITAL CONTRIBUTIONS

Each member shall make the following capital contribution to the Company:

{{#members}}
- {{name}}: **${{capital_contribution}}** (received {{capital_contribution_date}})
{{/members}}

**Total Capital:** ${{total_capital}}

---

## ARTICLE 4: PROFITS, LOSSES, AND DISTRIBUTIONS

### 4.1 Allocation
Profits and losses shall be allocated to members in proportion to their ownership percentages.

{{#members}}
- {{name}}: {{ownership_pct}}%
{{/members}}

### 4.2 Distributions
The Company shall distribute profits as determined by the {{management_type}} in accordance with applicable law.

---

## ARTICLE 5: MANAGEMENT

### 5.1 Management Structure
{{#is_member_managed}}
**This Company is member-managed.** All members have authority to manage the Company.
{{/is_member_managed}}

{{^is_member_managed}}
**This Company is manager-managed.** Managers have authority to manage the Company.

**Designated Managers:**
{{#managers}}
- {{name}} ({{title}})
{{/managers}}
{{/is_member_managed}}

### 5.2 Voting Rights
Except as otherwise provided in this Agreement or by law, members shall have voting rights in proportion to their ownership percentages.

---

## ARTICLE 6: TAX ELECTIONS

The Company elects the following tax treatment:

{{#tax_elections}}
- **{{election_name}}**: {{is_active}} ({{irc_section}})
{{/tax_elections}}

---

## ARTICLE 7: DISSOLUTION AND WINDING UP

The Company shall continue in perpetuity unless dissolved as provided in this Agreement or under state law.

In the event of dissolution, the Company's assets shall be distributed in accordance with applicable law and the priorities established in this Agreement.

---

## ARTICLE 8: AMENDMENT

This Agreement may be amended only by unanimous written consent of all members.

---

## CERTIFICATION

**In Witness Whereof**, the undersigned have executed this Operating Agreement as of the Effective Date.

{{#members}}

**{{name}}**

Signature: _____________________________ | Date: _____________

{{/members}}

---

**Prepared by:** {{prepared_by}}
**Date Prepared:** {{date_prepared}}

*This document is provided for informational purposes and is NOT LEGAL ADVICE. Client should review with qualified legal counsel before execution.*

TEMPLATE;

// ============================================================
// Sample Variables to Fill Template
// ============================================================

$variables = [
    'legal_name'               => 'DFW Printer LLC',
    'effective_date'           => '2026-05-15',
    'jurisdiction'             => 'Texas',
    'formed_date'              => '2026-05-15',
    'registered_agent_name'    => 'CT Corporation System',
    'registered_agent_address' => '1999 Bryan Street, Suite 900, Dallas, TX 75201',
    'management_type'          => 'member',
    'is_member_managed'        => true,
    'prepared_by'              => 'DST Empire Document Generator',
    'date_prepared'            => date('Y-m-d'),

    'members'                  => [
        [
            'name'                     => 'Michael Prasad',
            'ownership_pct'            => 75,
            'address'                  => '1234 Main Street, Dallas, TX 75201',
            'capital_contribution'     => 150000,
            'capital_contribution_date' => '2026-05-15',
        ],
        [
            'name'                     => 'Sabrina Prasad',
            'ownership_pct'            => 25,
            'address'                  => '1234 Main Street, Dallas, TX 75201',
            'capital_contribution'     => 50000,
            'capital_contribution_date' => '2026-05-15',
        ],
    ],

    'total_capital'            => 200000,

    'managers'                 => [
        [
            'name'  => 'Michael Prasad',
            'title' => 'Managing Member',
        ],
    ],

    'tax_elections'            => [
        [
            'election_name' => 'S-Corporation Election',
            'is_active'     => 'Yes',
            'irc_section'   => '§1361',
        ],
        [
            'election_name' => 'QBI Deduction',
            'is_active'     => 'Yes',
            'irc_section'   => '§199A',
        ],
    ],
];

// ============================================================
// Render Template
// ============================================================

$renderer = new TemplateRenderer();
$rendered_md = $renderer->render($template_md, $variables);

// ============================================================
// Display Rendered Output
// ============================================================

printf("\n%s\n", str_repeat("=", 70));
printf("DOCUMENT RENDERING EXAMPLE\n");
printf("%s\n\n", str_repeat("=", 70));

echo $rendered_md;

printf("\n%s\n", str_repeat("=", 70));

// ============================================================
// Example: Convert to PDF using Pandoc (if available)
// ============================================================

echo "\n**PDF Conversion Example:**\n\n";

$pandoc_path = shell_exec('which pandoc 2>/dev/null');
if ($pandoc_path) {
    echo "Pandoc found at: $pandoc_path\n\n";

    // Save markdown to temp file
    $md_file = tempnam('/tmp', 'dst_empire_');
    file_put_contents($md_file, $rendered_md);

    // Convert to PDF
    $pdf_file = str_replace('.tmp', '.pdf', $md_file);
    $cmd = "pandoc \"$md_file\" -o \"$pdf_file\" -V geometry:margin=1in";

    echo "Converting to PDF...\n";
    exec($cmd, $output, $return_code);

    if ($return_code === 0 && file_exists($pdf_file)) {
        printf("✓ PDF created: %s (%s bytes)\n", $pdf_file, filesize($pdf_file));
    } else {
        echo "✗ PDF conversion failed (return code: $return_code)\n";
    }

    // Clean up
    unlink($md_file);
} else {
    echo "Pandoc not found. To convert to PDF, install Pandoc:\n";
    echo "  Ubuntu: sudo apt-get install pandoc\n";
    echo "  macOS: brew install pandoc\n";
    echo "\nThen run:\n";
    echo "  pandoc document.md -o document.pdf -V geometry:margin=1in\n";
}

printf("\n%s\n\n", str_repeat("=", 70));

echo "Template rendering complete.\n\n";
echo "**Next Steps:**\n";
echo "1. Review rendered markdown above\n";
echo "2. Convert to PDF using Pandoc (see example above)\n";
echo "3. Send to attorney for review (attorney-ready package)\n";
echo "4. Client signs and files with state SOS\n";
echo "\n";
