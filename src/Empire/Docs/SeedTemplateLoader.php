<?php
/**
 * SeedTemplateLoader — loads the P0 template set into doc_templates.
 *
 * Phase A: 8 highest-priority P0 templates from public-domain or open-license
 * sources only (CARL rule 4 — no paid services).
 *
 * DO NOT call seedFromInventory() until migration 077 is applied to prod.
 * Mickey runs this ONCE via CLI: php scripts/seed_doc_templates.php
 *
 * Idempotent: INSERT ... ON DUPLICATE KEY UPDATE on slug column.
 *
 * Source licensing:
 *   IRS forms / FinCEN BOIR  — public domain (US federal government works)
 *   TX SOS Form 205           — public domain (TX state government form)
 *   TX DBA / Assumed Name     — public domain (TX county-level form)
 *   83(b) election letter     — composed from public-domain IRS Form 15620 + standard language
 *   LLC Operating Agreement   — composed from Common Accord open clauses (Apache 2.0)
 */

namespace Mnmsos\Empire\Docs;

use PDO;

class SeedTemplateLoader
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Load all P0 templates into doc_templates.
     *
     * @return array{ slug: string, action: string }[]
     *   Each element describes what happened: 'inserted' | 'updated' | 'skipped'
     */
    public function seedFromInventory(): array
    {
        $templates = $this->getP0Templates();
        $results   = [];

        foreach ($templates as $tpl) {
            $action = $this->upsert($tpl);
            $results[] = ['slug' => $tpl['slug'], 'action' => $action];
        }

        return $results;
    }

    // ── Template definitions ──────────────────────────────────────────────

    /**
     * Returns the 8 P0 template definitions. Each is a fully-formed array
     * matching the doc_templates schema from migration 077.
     *
     * @return array[]
     */
    private function getP0Templates(): array
    {
        return [
            $this->tplIrsSS4(),
            $this->tplIrs2553(),
            $this->tplIrs8832(),
            $this->tplFincenBoir(),
            $this->tpl83bElection(),
            $this->tplTxLlcArticles205(),
            $this->tplTxAssumedNameCert(),
            $this->tplSingleMemberLlcOA(),
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // Template 1: IRS Form SS-4 (EIN Application)
    // ────────────────────────────────────────────────────────────────────
    private function tplIrsSS4(): array
    {
        return [
            'slug'            => 'irs_form_ss4',
            'name'            => 'IRS Form SS-4 — EIN Application (Guided Walkthrough)',
            'category'        => 'irs_form',
            'subcategory'     => 'ein',
            'jurisdiction'    => null,  // federal
            'entity_types_json' => json_encode(['llc', 'ccorp', 'scorp', 'series_llc', 'nonprofit']),
            'aggression_tier' => 'all',
            'source_url'      => 'https://www.irs.gov/pub/irs-pdf/fss4.pdf',
            'source_license'  => 'Public Domain (US Federal Government)',
            'priority'        => 'P0',
            'version'         => '2024-01',
            'notes_md'        => "Apply online at IRS.gov/EIN for instant assignment. "
                . "Use paper form only if online application is unavailable. "
                . "Entity must be formed BEFORE applying for EIN.",
            'variables_json'  => json_encode([
                ['name' => 'company_name',        'type' => 'string', 'description' => 'Legal name of entity'],
                ['name' => 'trade_name',          'type' => 'string', 'description' => 'DBA name if applicable', 'default' => ''],
                ['name' => 'executor_name',       'type' => 'string', 'description' => 'Name of responsible party (owner)'],
                ['name' => 'state_of_formation',  'type' => 'string', 'description' => '2-letter state code'],
                ['name' => 'entity_type',         'type' => 'string', 'description' => 'LLC, Corporation, etc.'],
                ['name' => 'formation_date',      'type' => 'date',   'description' => 'Date entity was formed'],
                ['name' => 'principal_address',   'type' => 'string', 'description' => 'Street address of principal place of business'],
                ['name' => 'city_state_zip',      'type' => 'string', 'description' => 'City, State ZIP'],
                ['name' => 'phone_number',        'type' => 'string', 'description' => 'Responsible party phone number'],
                ['name' => 'ssn_or_itin',         'type' => 'string', 'description' => 'SSN or ITIN of responsible party'],
                ['name' => 'employee_count',      'type' => 'integer', 'description' => 'Expected employees in 12 months', 'default' => '0'],
                ['name' => 'fiscal_year_end',     'type' => 'string', 'description' => 'Month fiscal year ends (e.g., December)', 'default' => 'December'],
                ['name' => 'primary_activity',    'type' => 'string', 'description' => 'Principal business activity'],
            ]),
            'template_md' => $this->loadInlineTemplate('irs_ss4'),
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // Template 2: IRS Form 2553 (S-Corp Election)
    // ────────────────────────────────────────────────────────────────────
    private function tplIrs2553(): array
    {
        return [
            'slug'            => 'irs_form_2553',
            'name'            => 'IRS Form 2553 — S-Corp Election',
            'category'        => 'tax_election',
            'subcategory'     => 's_corp_election',
            'jurisdiction'    => null,
            'entity_types_json' => json_encode(['scorp']),
            'aggression_tier' => 'all',
            'source_url'      => 'https://www.irs.gov/forms-pubs/about-form-2553',
            'source_license'  => 'Public Domain (US Federal Government)',
            'priority'        => 'P0',
            'version'         => '2023-12',
            'notes_md'        => "File within 75 days of formation for current-year election, "
                . "OR by March 15 of the following year for next-year election. "
                . "ALL shareholders must sign. Late election relief available under Rev. Proc. 2013-30.",
            'variables_json'  => json_encode([
                ['name' => 'company_name',       'type' => 'string', 'description' => 'Legal name of corporation'],
                ['name' => 'ein',                'type' => 'string', 'description' => 'EIN (must be obtained first)'],
                ['name' => 'formation_date',     'type' => 'date',   'description' => 'Date of incorporation/organization'],
                ['name' => 'election_effective_date', 'type' => 'date', 'description' => 'First tax year for which election is effective'],
                ['name' => 'state_of_formation', 'type' => 'string', 'description' => '2-letter state code'],
                ['name' => 'principal_address',  'type' => 'string', 'description' => 'Street address'],
                ['name' => 'city_state_zip',     'type' => 'string', 'description' => 'City, State ZIP'],
                ['name' => 'fiscal_year_end',    'type' => 'string', 'description' => 'Month (December for calendar year)', 'default' => 'December'],
                ['name' => 'officer_name',       'type' => 'string', 'description' => 'Name of officer signing'],
                ['name' => 'officer_title',      'type' => 'string', 'description' => 'Title of officer', 'default' => 'President'],
                ['name' => 'shareholders',       'type' => 'array',  'description' => 'Array of shareholders: {name, ssn_last4, shares, date_acquired, tax_year_end}'],
            ]),
            'template_md' => $this->loadInlineTemplate('irs_2553'),
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // Template 3: IRS Form 8832 (Entity Classification)
    // ────────────────────────────────────────────────────────────────────
    private function tplIrs8832(): array
    {
        return [
            'slug'            => 'irs_form_8832',
            'name'            => 'IRS Form 8832 — Entity Classification Election',
            'category'        => 'tax_election',
            'subcategory'     => 'entity_classification',
            'jurisdiction'    => null,
            'entity_types_json' => json_encode(['llc', 'ccorp']),
            'aggression_tier' => 'all',
            'source_url'      => 'https://www.irs.gov/forms-pubs/about-form-8832',
            'source_license'  => 'Public Domain (US Federal Government)',
            'priority'        => 'P0',
            'version'         => '2013-03',
            'notes_md'        => "Use to elect C-Corp treatment for a multi-member LLC "
                . "(default = partnership). Or to elect disregarded entity status for a "
                . "foreign entity. 60-month limitation: cannot re-elect for 5 years after election.",
            'variables_json'  => json_encode([
                ['name' => 'company_name',        'type' => 'string', 'description' => 'Legal name of entity'],
                ['name' => 'ein',                 'type' => 'string', 'description' => 'EIN'],
                ['name' => 'principal_address',   'type' => 'string', 'description' => 'Street address'],
                ['name' => 'city_state_zip',      'type' => 'string', 'description' => 'City, State ZIP'],
                ['name' => 'election_type',       'type' => 'string', 'description' => 'Desired classification: corporation | partnership | disregarded_entity'],
                ['name' => 'election_effective_date', 'type' => 'date', 'description' => 'Effective date of election'],
                ['name' => 'consent_person_name', 'type' => 'string', 'description' => 'Name of member/owner consenting'],
                ['name' => 'consent_person_title','type' => 'string', 'description' => 'Title', 'default' => 'Member'],
                ['name' => 'owner_count',         'type' => 'integer','description' => 'Number of eligible entity owners', 'default' => '1'],
            ]),
            'template_md' => $this->loadInlineTemplate('irs_8832'),
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // Template 4: FinCEN BOIR JSON Template
    // ────────────────────────────────────────────────────────────────────
    private function tplFincenBoir(): array
    {
        return [
            'slug'            => 'fincen_boir_template',
            'name'            => 'FinCEN BOIR — Beneficial Ownership Information Report Template',
            'category'        => 'compliance',
            'subcategory'     => 'boi_cta',
            'jurisdiction'    => null,
            'entity_types_json' => json_encode(['llc', 'ccorp', 'scorp', 'series_llc']),
            'aggression_tier' => 'all',
            'source_url'      => 'https://boiefiling.fincen.gov/',
            'source_license'  => 'Public Domain (US Federal Government)',
            'priority'        => 'P0',
            'version'         => '2024-01',
            'notes_md'        => "CRITICAL: File within 30 days of formation for entities formed "
                . "on/after Jan 1 2024. Penalty: \$591/day civil fine (adjusted annually for inflation). "
                . "As of 2026-03-26 domestic entities temporarily exempt per FinCEN interim rule — "
                . "verify current status at fincen.gov before advising client. "
                . "See also: BOI/Filer.php for automated generation.",
            'variables_json'  => json_encode([
                ['name' => 'company_name',          'type' => 'string', 'description' => 'Legal entity name'],
                ['name' => 'ein',                   'type' => 'string', 'description' => 'EIN / Tax ID'],
                ['name' => 'state_of_formation',    'type' => 'string', 'description' => '2-letter state code'],
                ['name' => 'formation_date',        'type' => 'date',   'description' => 'Formation date (YYYY-MM-DD)'],
                ['name' => 'principal_address',     'type' => 'string', 'description' => 'Current US address'],
                ['name' => 'beneficial_owners',     'type' => 'array',  'description' => 'Array of owners: {name, dob, address, id_type, id_number, id_state}'],
            ]),
            'template_md' => $this->loadInlineTemplate('fincen_boir'),
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // Template 5: 83(b) Election Letter
    // ────────────────────────────────────────────────────────────────────
    private function tpl83bElection(): array
    {
        return [
            'slug'            => 'irs_83b_election',
            'name'            => 'IRS §83(b) Election Letter (Restricted Property)',
            'category'        => 'tax_election',
            'subcategory'     => '83b',
            'jurisdiction'    => null,
            'entity_types_json' => json_encode(['ccorp', 'scorp', 'llc']),
            'aggression_tier' => 'all',
            'source_url'      => 'https://www.irs.gov/pub/irs-pdf/f15620.pdf',
            'source_license'  => 'Public Domain (IRS Form 15620 + standard template language)',
            'priority'        => 'P0',
            'version'         => '2024-01',
            'notes_md'        => "HARD DEADLINE: Must be filed with IRS within 30 calendar days "
                . "of property transfer. No late filing under any circumstances. "
                . "Send via certified mail with return receipt requested. "
                . "Keep confirmation copy with tax records indefinitely.",
            'variables_json'  => json_encode([
                ['name' => 'taxpayer_name',       'type' => 'string', 'description' => 'Full legal name of taxpayer making election'],
                ['name' => 'taxpayer_address',    'type' => 'string', 'description' => 'Taxpayer\'s current address'],
                ['name' => 'ssn_or_itin',         'type' => 'string', 'description' => 'SSN or ITIN'],
                ['name' => 'tax_year',            'type' => 'string', 'description' => 'Tax year of transfer (e.g., 2026)'],
                ['name' => 'transfer_date',       'type' => 'date',   'description' => 'Date property was transferred'],
                ['name' => 'property_description','type' => 'string', 'description' => 'Description of restricted property (e.g., 100 shares common stock)'],
                ['name' => 'company_name',        'type' => 'string', 'description' => 'Name of issuing company'],
                ['name' => 'fmv_at_transfer',     'type' => 'decimal','description' => 'Fair market value of property at transfer date'],
                ['name' => 'amount_paid',         'type' => 'decimal','description' => 'Amount paid for property', 'default' => '0.00'],
                ['name' => 'restrictions',        'type' => 'string', 'description' => 'Description of forfeiture conditions / vesting restrictions'],
                ['name' => 'nature_of_restriction','type' => 'string', 'description' => 'Nature of restrictions on property'],
            ]),
            'template_md' => $this->loadInlineTemplate('83b_election'),
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // Template 6: TX LLC Articles of Organization (Form 205)
    // ────────────────────────────────────────────────────────────────────
    private function tplTxLlcArticles205(): array
    {
        return [
            'slug'            => 'tx_llc_articles_205',
            'name'            => 'TX LLC Articles of Organization (Form 205)',
            'category'        => 'formation',
            'subcategory'     => 'articles_of_organization',
            'jurisdiction'    => 'TX',
            'entity_types_json' => json_encode(['llc', 'series_llc', 'scorp']),
            'aggression_tier' => 'all',
            'source_url'      => 'https://www.sos.state.tx.us/corp/forms_boc.shtml',
            'source_license'  => 'Public Domain (Texas Secretary of State)',
            'priority'        => 'P0',
            'version'         => '2024-03',
            'notes_md'        => "File online at SOSDirect (https://www.sos.texas.gov/corp/sosdirect.shtml). "
                . "Filing fee: \$300 (LLC). Expedite available for \$25 extra. "
                . "For series LLC: use this form for parent; each protected series requires "
                . "a Certificate of Formation of Protected Series (Form 206).",
            'variables_json'  => json_encode([
                ['name' => 'company_name',            'type' => 'string', 'description' => 'Legal name of LLC (must end in LLC or L.L.C. or Limited Liability Company)'],
                ['name' => 'registered_agent_name',   'type' => 'string', 'description' => 'Registered agent full legal name'],
                ['name' => 'registered_agent_address','type' => 'string', 'description' => 'Registered agent TX street address (no PO Box)'],
                ['name' => 'registered_agent_city_state_zip','type' => 'string', 'description' => 'City, State ZIP'],
                ['name' => 'manager_managed',         'type' => 'boolean','description' => 'true = manager-managed, false = member-managed', 'default' => 'false'],
                ['name' => 'organizer_name',          'type' => 'string', 'description' => 'Name of organizer (person filing)'],
                ['name' => 'organizer_address',       'type' => 'string', 'description' => 'Organizer mailing address'],
                ['name' => 'is_series_llc',           'type' => 'boolean','description' => 'true if forming a series LLC', 'default' => 'false'],
                ['name' => 'effective_date',          'type' => 'string', 'description' => 'Filing effective date (leave blank for immediate)', 'default' => ''],
                ['name' => 'purpose',                 'type' => 'string', 'description' => 'Business purpose (or: "any lawful purpose")', 'default' => 'any lawful purpose permitted under the Texas Business Organizations Code'],
            ]),
            'template_md' => $this->loadInlineTemplate('tx_llc_articles_205'),
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // Template 7: TX DBA / Assumed Name Certificate
    // ────────────────────────────────────────────────────────────────────
    private function tplTxAssumedNameCert(): array
    {
        return [
            'slug'            => 'tx_dba_assumed_name_cert',
            'name'            => 'TX DBA — Assumed Name Certificate (County Filing)',
            'category'        => 'formation',
            'subcategory'     => 'dba',
            'jurisdiction'    => 'TX',
            'entity_types_json' => json_encode(['llc', 'ccorp', 'scorp', 'series_llc', 'sole_prop']),
            'aggression_tier' => 'all',
            'source_url'      => 'https://www.tarrantcounty.com/en/county-clerk/assumed-names.html',
            'source_license'  => 'Public Domain (TX County form)',
            'priority'        => 'P0',
            'version'         => '2024-01',
            'notes_md'        => "File with county clerk in each TX county where business is conducted. "
                . "Tarrant County fee: \$16 first name + \$4 each additional. "
                . "Valid for 10 years. Required before operating under any name other than legal entity name.",
            'variables_json'  => json_encode([
                ['name' => 'assumed_name',         'type' => 'string', 'description' => 'DBA / trade name being registered'],
                ['name' => 'entity_legal_name',    'type' => 'string', 'description' => 'Full legal name of entity filing'],
                ['name' => 'entity_type',          'type' => 'string', 'description' => 'LLC, Corporation, etc.'],
                ['name' => 'state_of_formation',   'type' => 'string', 'description' => '2-letter state', 'default' => 'TX'],
                ['name' => 'principal_office',     'type' => 'string', 'description' => 'Principal office address'],
                ['name' => 'city_state_zip',       'type' => 'string', 'description' => 'City, State ZIP'],
                ['name' => 'county_of_filing',     'type' => 'string', 'description' => 'TX county where filing', 'default' => 'Tarrant'],
                ['name' => 'filing_period_years',  'type' => 'integer','description' => 'Duration in years (max 10)', 'default' => '10'],
                ['name' => 'officer_name',         'type' => 'string', 'description' => 'Name of officer/member signing'],
                ['name' => 'officer_title',        'type' => 'string', 'description' => 'Title', 'default' => 'Member / Manager'],
                ['name' => 'effective_date',       'type' => 'date',   'description' => 'Date of filing'],
            ]),
            'template_md' => $this->loadInlineTemplate('tx_dba_assumed_name_cert'),
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // Template 8: Generic Single-Member LLC Operating Agreement
    // ────────────────────────────────────────────────────────────────────
    private function tplSingleMemberLlcOA(): array
    {
        return [
            'slug'            => 'tx_llc_oa_single_member',
            'name'            => 'Single-Member LLC Operating Agreement (TX)',
            'category'        => 'formation',
            'subcategory'     => 'operating_agreement',
            'jurisdiction'    => 'TX',
            'entity_types_json' => json_encode(['llc', 'scorp']),
            'aggression_tier' => 'all',
            'source_url'      => 'https://commonaccord.org/',
            'source_license'  => 'Apache License 2.0 (Common Accord open clauses)',
            'priority'        => 'P0',
            'version'         => '2024-01',
            'notes_md'        => "Composed from Common Accord open-license operating agreement clauses. "
                . "Adapted for Texas single-member LLCs (TX BOC Chapter 101). "
                . "Attorney review required before execution. Do not use for multi-member LLCs.",
            'variables_json'  => json_encode([
                ['name' => 'company_name',          'type' => 'string', 'description' => 'Full legal name of LLC'],
                ['name' => 'member_name',           'type' => 'string', 'description' => 'Full legal name of sole member'],
                ['name' => 'member_address',        'type' => 'string', 'description' => 'Member\'s mailing address'],
                ['name' => 'state_of_formation',    'type' => 'string', 'description' => '2-letter state', 'default' => 'TX'],
                ['name' => 'formation_date',        'type' => 'date',   'description' => 'Date Articles were filed with TX SOS'],
                ['name' => 'principal_office',      'type' => 'string', 'description' => 'Principal office address'],
                ['name' => 'city_state_zip',        'type' => 'string', 'description' => 'City, State ZIP'],
                ['name' => 'registered_agent_name', 'type' => 'string', 'description' => 'Registered agent full legal name'],
                ['name' => 'registered_agent_address','type' => 'string','description' => 'Registered agent TX address'],
                ['name' => 'purpose',               'type' => 'string', 'description' => 'Business purpose', 'default' => 'any lawful purpose'],
                ['name' => 'fiscal_year_end',       'type' => 'string', 'description' => 'Month fiscal year ends', 'default' => 'December 31'],
                ['name' => 'tax_election',          'type' => 'string', 'description' => 'Tax classification: disregarded_entity | s_corp', 'default' => 'disregarded_entity'],
                ['name' => 'effective_date',        'type' => 'date',   'description' => 'Agreement effective date'],
            ]),
            'template_md' => $this->loadInlineTemplate('tx_llc_oa_single_member'),
        ];
    }

    // ── Inline template bodies ────────────────────────────────────────────

    /**
     * Returns the markdown template body for a given slug.
     * Templates are stored inline here (not on disk) for portability.
     * They use the {{ variable }} syntax parsed by TemplateRenderer.
     *
     * @param string $slug  Internal template key
     * @return string Markdown template
     */
    private function loadInlineTemplate(string $slug): string
    {
        return match ($slug) {
            'irs_ss4'                => $this->bodyIrsSS4(),
            'irs_2553'               => $this->bodyIrs2553(),
            'irs_8832'               => $this->bodyIrs8832(),
            'fincen_boir'            => $this->bodyFincenBoir(),
            '83b_election'           => $this->body83bElection(),
            'tx_llc_articles_205'    => $this->bodyTxLlcArticles205(),
            'tx_dba_assumed_name_cert' => $this->bodyTxAssumedNameCert(),
            'tx_llc_oa_single_member'  => $this->bodyTxLlcOaSingleMember(),
            default                  => "<!-- Template body for '{$slug}' not yet defined. -->",
        };
    }

    private function bodyIrsSS4(): string
    {
        return <<<'TMPL'
        # IRS Form SS-4 — EIN Application Pre-Fill Worksheet

        **Entity Name:** {{ company_name }}
        **Trade Name (DBA):** {{ trade_name | default('N/A') }}

        ---

        ## Section 1 — Entity Information

        | Field | Value |
        |---|---|
        | Legal name of entity | {{ company_name }} |
        | Trade name | {{ trade_name | default('(same as above)') }} |
        | State/country of formation | {{ state_of_formation }} |
        | Entity type | {{ entity_type }} |
        | Date of formation | {{ formation_date }} |
        | Fiscal year end | {{ fiscal_year_end }} |

        ## Section 2 — Responsible Party

        | Field | Value |
        |---|---|
        | Name | {{ executor_name }} |
        | SSN / ITIN | {{ ssn_or_itin }} |
        | Phone | {{ phone_number }} |

        ## Section 3 — Address

        | Field | Value |
        |---|---|
        | Street address | {{ principal_address }} |
        | City, State ZIP | {{ city_state_zip }} |

        ## Section 4 — Purpose

        | Field | Value |
        |---|---|
        | Primary activity | {{ primary_activity }} |
        | Expected employees (12 mo) | {{ employee_count | default('0') }} |

        ---

        ## Filing Instructions

        1. **Online (recommended):** https://www.irs.gov/businesses/small-businesses-self-employed/apply-for-an-employer-identification-number-ein-online
           - Available Mon–Fri 7am–10pm ET
           - EIN assigned instantly
           - Have Articles of Organization in hand before starting
        2. **Fax:** Complete Form SS-4 and fax to IRS (see current fax numbers at irs.gov)
        3. **Mail:** Allow 4–6 weeks

        > **Note:** You must have a valid SSN, ITIN, or EIN as the responsible party.
        > The entity must be formed BEFORE applying for an EIN.

        *Form SS-4 is public domain — IRS.gov. This is a guided worksheet, not the actual form.*
        TMPL;
    }

    private function bodyIrs2553(): string
    {
        return <<<'TMPL'
        # IRS Form 2553 — Election by a Small Business Corporation (S-Corp)

        **Entity:** {{ company_name }}
        **EIN:** {{ ein }}

        ---

        ## Part I — Election Information

        | Field | Value |
        |---|---|
        | Corporation name | {{ company_name }} |
        | EIN | {{ ein }} |
        | Street address | {{ principal_address }} |
        | City, State ZIP | {{ city_state_zip }} |
        | State of incorporation/organization | {{ state_of_formation }} |
        | Date incorporated / organized | {{ formation_date }} |
        | Election effective date | {{ election_effective_date }} |
        | Selected tax year end | {{ fiscal_year_end }} |

        ## Part II — Shareholders' Consent Statement

        Each shareholder MUST sign. All must consent on or before the election deadline.

        {{#each shareholders}}
        | {{ @number }}. Name | {{ name }} |
        |---|---|
        | SSN (last 4 only) | XXX-XX-{{ ssn_last4 }} |
        | Shares held | {{ shares }} |
        | Date shares acquired | {{ date_acquired }} |
        | Tax year end | {{ tax_year_end }} |
        | Signature | \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ |
        | Date | \_\_\_\_\_\_\_\_ |

        {{/each}}

        ## Officer Certification

        Under penalties of perjury, I declare that I have examined this election,
        including accompanying schedules and statements, and to the best of my
        knowledge and belief, it is true, correct, and complete.

        **Officer Name:** {{ officer_name }}
        **Title:** {{ officer_title }}
        **Date:** \_\_\_\_\_\_\_\_\_\_

        ---

        ## Filing Deadline Reminder

        - **New entity:** Within 75 days of formation date ({{ formation_date }})
        - **Next year election:** By **March 15** of the applicable tax year
        - **Late election:** Available under Rev. Proc. 2013-30 (reasonable cause required)

        > **Send to:** Internal Revenue Service Center for your entity's state.
        > See current addresses at: https://www.irs.gov/instructions/i2553

        *IRS Form 2553 is public domain — IRS.gov. Attorney review recommended before filing.*
        TMPL;
    }

    private function bodyIrs8832(): string
    {
        return <<<'TMPL'
        # IRS Form 8832 — Entity Classification Election

        **Entity:** {{ company_name }}
        **EIN:** {{ ein }}

        ---

        ## Part I — Election Information

        | Field | Value |
        |---|---|
        | Name of eligible entity | {{ company_name }} |
        | EIN | {{ ein }} |
        | Street address | {{ principal_address }} |
        | City, State ZIP | {{ city_state_zip }} |
        | Number of owners | {{ owner_count }} |
        | Election type | {{ election_type }} |
        | Election effective date | {{ election_effective_date }} |

        ## Part II — Consent Statement and Signature

        Under penalties of perjury, I declare that I have examined this election,
        and to the best of my knowledge and belief, it is true, correct, and complete.

        **Name:** {{ consent_person_name }}
        **Title:** {{ consent_person_title }}
        **Signature:** \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_
        **Date:** \_\_\_\_\_\_\_\_\_\_

        ---

        ## Notes

        - **60-month limitation:** Once made, cannot change classification for 60 months
          without IRS consent.
        - **Default classification:**
          - Single-member LLC → disregarded entity (no 8832 needed unless changing)
          - Multi-member LLC → partnership (file 8832 to elect C-Corp treatment)
          - Existing foreign entity → see Form 8832 instructions for default rules

        > **Mail to:** Department of the Treasury, Internal Revenue Service Center
        > (address depends on state — see current instructions at irs.gov/form8832)

        *IRS Form 8832 is public domain — IRS.gov. Attorney review recommended before filing.*
        TMPL;
    }

    private function bodyFincenBoir(): string
    {
        return <<<'TMPL'
        # FinCEN Beneficial Ownership Information Report (BOIR) — Pre-Fill Worksheet

        **Entity:** {{ company_name }}
        **EIN:** {{ ein }}

        > **IMPORTANT:** As of 2026-03-26, FinCEN issued an interim rule temporarily exempting
        > domestic entities from filing. Verify current status at https://fincen.gov/boi
        > before filing. Foreign entities are NOT exempt.

        ---

        ## Reporting Company Information

        | Field | Value |
        |---|---|
        | Legal name | {{ company_name }} |
        | EIN / Tax ID | {{ ein }} |
        | State of formation | {{ state_of_formation }} |
        | Date of formation | {{ formation_date }} |
        | Current US address | {{ principal_address }} |

        ---

        ## Beneficial Owners

        {{#each beneficial_owners}}
        ### Owner {{ @number }}: {{ name }}

        | Field | Value |
        |---|---|
        | Full legal name | {{ name }} |
        | Date of birth | {{ dob }} |
        | Current address | {{ address }} |
        | ID document type | {{ id_type }} |
        | ID number | {{ id_number }} |
        | ID issuing state | {{ id_state }} |

        {{/each}}

        ---

        ## Filing Instructions

        1. Go to: https://boiefiling.fincen.gov/
        2. Select "File BOIR" and choose filing type: Initial / Update / Correction
        3. Enter all information from this worksheet
        4. Upload a copy of each beneficial owner's ID document
        5. Submit and save the confirmation number

        > **Deadline:** Within 30 days of formation (new entities formed after Jan 1, 2024)
        > **Penalty:** Up to $591/day civil fine for willful non-compliance

        *FinCEN BOIR form is public domain — fincen.gov. See also: Empire BOI module (boi.php).*
        TMPL;
    }

    private function body83bElection(): string
    {
        return <<<'TMPL'
        # Section 83(b) Election — Internal Revenue Code

        **SEND VIA CERTIFIED MAIL WITH RETURN RECEIPT. KEEP CONFIRMATION COPY INDEFINITELY.**
        **HARD DEADLINE: 30 calendar days from transfer date. No exceptions.**

        ---

        Department of the Treasury
        Internal Revenue Service Center
        [Use address for your tax return filing location — see irs.gov/form15620]

        **Date:** {{ effective_date }}

        ---

        ## Election Under Section 83(b) of the Internal Revenue Code

        The undersigned taxpayer hereby elects, pursuant to §83(b) of the Internal
        Revenue Code of 1986, to include in gross income for the taxable year of
        transfer the excess of the fair market value of the property described below
        over the amount paid for such property.

        ### 1. Taxpayer Information

        | Field | Value |
        |---|---|
        | Name | {{ taxpayer_name }} |
        | Address | {{ taxpayer_address }} |
        | SSN / ITIN | {{ ssn_or_itin }} |
        | Tax year | {{ tax_year }} |

        ### 2. Property Description

        | Field | Value |
        |---|---|
        | Description of property | {{ property_description }} |
        | Issuing company | {{ company_name }} |
        | Date of transfer | {{ transfer_date }} |
        | Fair market value at transfer | ${{ fmv_at_transfer }} |
        | Amount paid | ${{ amount_paid | default('0.00') }} |
        | Amount includible in gross income | ${{ fmv_at_transfer }} (FMV minus amount paid) |

        ### 3. Restrictions

        The property is subject to the following restrictions:

        {{ restrictions }}

        Nature of restrictions: {{ nature_of_restriction }}

        ### 4. Certification

        The undersigned taxpayer declares under penalties of perjury that the
        foregoing is true and correct.

        **Signature:** \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

        **Name (print):** {{ taxpayer_name }}

        **Date:** \_\_\_\_\_\_\_\_\_\_

        ---

        ## Filing Instructions

        1. Sign this letter
        2. Make **3 copies**: (a) mail to IRS, (b) keep in your records, (c) provide to company
        3. Send original to IRS via **certified mail, return receipt requested**
        4. File with your Form 1040 for the year of transfer
        5. The IRS does NOT send confirmation — the postmark is your proof

        > Based on IRS Form 15620 (public domain). Attorney review recommended.
        TMPL;
    }

    private function bodyTxLlcArticles205(): string
    {
        return <<<'TMPL'
        # Texas Certificate of Formation — Limited Liability Company
        *Form 205 Equivalent — TX Business Organizations Code §101*

        > **File via SOSDirect:** https://www.sos.texas.gov/corp/sosdirect.shtml
        > **Filing fee:** $300. Expedite: $25 additional. Allow 3–5 business days without expedite.

        ---

        ## Article 1 — Entity Name

        The name of the limited liability company is:

        **{{ company_name }}**

        ## Article 2 — Registered Agent and Registered Office

        The name of the registered agent is: **{{ registered_agent_name }}**

        The address of the registered office is:
        {{ registered_agent_address }}, {{ registered_agent_city_state_zip }}

        *The registered agent is a Texas resident or a domestic/foreign entity authorized to do
        business in Texas.*

        ## Article 3 — Governing Authority

        {{#if manager_managed}}
        The limited liability company is **manager-managed**. The name and address of the initial
        manager is:

        **[MANAGER NAME]**
        [MANAGER ADDRESS]

        {{/if}}
        {{#if member_managed}}
        The limited liability company is **member-managed**.
        {{/if}}

        ## Article 4 — Purpose

        The purpose of the limited liability company is: {{ purpose }}

        {{#if is_series_llc}}
        ## Article 5 — Series LLC

        This company is organized as a series limited liability company pursuant to
        Texas Business Organizations Code §101.601 et seq. The company may establish
        one or more series of members, managers, membership interests, or assets.
        {{/if}}

        ## Article {{ is_series_llc | default('5') }} — Organizer

        The name and address of the organizer is:

        **{{ organizer_name }}**
        {{ organizer_address }}

        ---

        ## Execution

        The undersigned organizer signs this Certificate of Formation subject to the
        penalties imposed by law for the submission of a materially false or
        fraudulent instrument.

        **Organizer:** \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Date: \_\_\_\_\_\_\_

        **Name (print):** {{ organizer_name }}

        ---

        *Texas Secretary of State — public domain form. Filing must be submitted via SOSDirect
        or mail. This is a pre-fill worksheet for attorney review, not the official online form.*
        TMPL;
    }

    private function bodyTxAssumedNameCert(): string
    {
        return <<<'TMPL'
        # Texas Assumed Name Certificate (DBA)
        *TX Business & Commerce Code §71.051 — County Clerk Filing*

        > **File with:** {{ county_of_filing }} County Clerk (or all counties where business is conducted)
        > **Fee (Tarrant County):** $16 first name, $4 each additional name
        > **Valid for:** {{ filing_period_years | default('10') }} years

        ---

        ## Assumed Name Certificate

        **STATE OF TEXAS**
        **COUNTY OF {{ county_of_filing }}**

        The undersigned hereby certifies:

        **1. Assumed Name:**
        The assumed name under which the business is or will be conducted is:
        **{{ assumed_name }}**

        **2. Principal Office:**
        The principal office or place of business is located at:
        {{ principal_office }}, {{ city_state_zip }}

        **3. Entity Information:**
        This certificate is filed by:

        | Field | Value |
        |---|---|
        | Legal name of entity | {{ entity_legal_name }} |
        | Type of entity | {{ entity_type }} |
        | State of formation | {{ state_of_formation | default('TX') }} |

        **4. Period:**
        This certificate is effective as of {{ effective_date }} and for
        {{ filing_period_years | default('10') }} years thereafter.

        ---

        ## Execution

        **Signature:** \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

        **Name (print):** {{ officer_name }}

        **Title:** {{ officer_title }}

        **Date:** {{ effective_date }}

        ---

        *Before a notary public (some counties require notarization — verify with county clerk).*

        **Notary acknowledgment:**

        State of Texas, County of \_\_\_\_\_\_\_\_\_\_\_\_

        Subscribed and sworn to before me on \_\_\_\_\_\_\_\_\_\_ by {{ officer_name }}.

        Notary Signature: \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_
        My commission expires: \_\_\_\_\_\_\_\_\_\_

        ---

        *TX B&C Code §71.051 — public domain. Verify current county requirements before filing.*
        TMPL;
    }

    private function bodyTxLlcOaSingleMember(): string
    {
        return <<<'TMPL'
        # Operating Agreement of {{ company_name }}
        *A Texas Limited Liability Company — Single Member*

        *Composed from Common Accord open-license clauses (Apache 2.0). Attorney review required.*

        **Effective Date:** {{ effective_date }}

        ---

        ## RECITALS

        This Operating Agreement ("Agreement") is entered into as of {{ effective_date }} by
        **{{ member_name }}** ("Member"), being the sole member of **{{ company_name }}**
        ("Company"), a Texas limited liability company organized under the Texas Business
        Organizations Code ("TBOC").

        ---

        ## ARTICLE I — FORMATION

        **1.1 Organization.** The Company was formed by filing a Certificate of Formation
        with the Texas Secretary of State on {{ formation_date }} pursuant to TBOC Chapter 101.

        **1.2 Name.** The name of the Company is **{{ company_name }}**.

        **1.3 Principal Office.** The principal office is located at:
        {{ principal_office }}, {{ city_state_zip }}.

        **1.4 Registered Agent.** The registered agent is **{{ registered_agent_name }}**,
        located at {{ registered_agent_address }}.

        **1.5 Purpose.** The purpose of the Company is {{ purpose }}.

        **1.6 Duration.** The Company shall continue until dissolved pursuant to this
        Agreement or as required by law.

        ---

        ## ARTICLE II — MEMBERSHIP

        **2.1 Sole Member.** {{ member_name }} is the sole Member of the Company, holding
        100% of the membership interests.

        **2.2 Address.** The Member's address is: {{ member_address }}.

        **2.3 Additional Members.** No additional Members may be admitted without amendment
        to this Agreement signed by the then-current Member(s).

        ---

        ## ARTICLE III — MANAGEMENT

        **3.1 Member-Managed.** The Company shall be member-managed. The Member shall have
        full authority to manage the Company's business and affairs.

        **3.2 Authority.** Without limiting the foregoing, the Member is authorized to:
        (a) enter into contracts on behalf of the Company;
        (b) open and maintain bank and investment accounts;
        (c) employ and terminate employees;
        (d) make any expenditure and incur any liability;
        (e) acquire, hold, mortgage, pledge, or dispose of Company property.

        **3.3 Signing Authority.** The Member (or any officer appointed by the Member)
        may execute documents on behalf of the Company.

        ---

        ## ARTICLE IV — CAPITAL AND DISTRIBUTIONS

        **4.1 Capital Contributions.** The Member shall contribute such capital as the Member
        determines from time to time.

        **4.2 No Required Return of Capital.** The Company is not required to return capital
        contributions to the Member.

        **4.3 Distributions.** Distributions shall be made to the Member at such times and
        in such amounts as the Member determines, subject to applicable law.

        ---

        ## ARTICLE V — TAX MATTERS

        **5.1 Tax Classification.** The Company intends to be treated as a
        **{{ tax_election | default('disregarded entity') }}** for U.S. federal income tax purposes.

        **5.2 Fiscal Year.** The fiscal year ends on {{ fiscal_year_end }}.

        **5.3 Books and Records.** The Company shall maintain accurate books and records.
        The Member shall have access to all books, records, and accounts at any time.

        ---

        ## ARTICLE VI — INDEMNIFICATION

        **6.1 Indemnification.** The Company shall indemnify the Member and any officers
        to the fullest extent permitted by the TBOC against all claims, liabilities, and
        expenses arising from Company activities, except for gross negligence or willful misconduct.

        ---

        ## ARTICLE VII — DISSOLUTION

        **7.1 Dissolution Events.** The Company shall be dissolved upon:
        (a) written notice of dissolution by the Member;
        (b) entry of a judicial decree of dissolution; or
        (c) any other event requiring dissolution under the TBOC.

        **7.2 Winding Up.** Upon dissolution, the Member shall wind up the Company's affairs,
        pay creditors, and distribute remaining assets to the Member.

        ---

        ## ARTICLE VIII — GENERAL PROVISIONS

        **8.1 Entire Agreement.** This Agreement constitutes the entire agreement of the Member
        with respect to the Company and supersedes all prior agreements.

        **8.2 Amendment.** This Agreement may only be amended in writing signed by the Member.

        **8.3 Governing Law.** This Agreement shall be governed by the laws of the State of Texas.

        **8.4 Severability.** If any provision is held invalid, the remaining provisions
        remain in full force.

        **8.5 Counterparts.** This Agreement may be signed in counterparts, each of which
        shall be deemed an original.

        ---

        ## EXECUTION

        IN WITNESS WHEREOF, the Member has executed this Agreement as of the Effective Date.

        **MEMBER:**

        Signature: \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

        Name: {{ member_name }}

        Date: \_\_\_\_\_\_\_\_\_\_

        ---

        *Composed from Common Accord open clauses (Apache 2.0). Adapted for TX TBOC Chapter 101.
        Attorney review required before execution. Not legal advice.*
        TMPL;
    }

    // ── DB upsert ─────────────────────────────────────────────────────────

    /**
     * Upsert one template into doc_templates.
     * Returns 'inserted', 'updated', or 'skipped' on no-op.
     */
    private function upsert(array $tpl): string
    {
        $stmt = $this->db->prepare(
            "INSERT INTO doc_templates
                (slug, name, category, subcategory, jurisdiction, entity_types_json,
                 aggression_tier, source_url, source_license, template_md,
                 variables_json, version, priority, notes_md)
             VALUES
                (:slug, :name, :category, :subcategory, :jurisdiction, :entity_types_json,
                 :aggression_tier, :source_url, :source_license, :template_md,
                 :variables_json, :version, :priority, :notes_md)
             ON DUPLICATE KEY UPDATE
                name             = VALUES(name),
                category         = VALUES(category),
                subcategory      = VALUES(subcategory),
                jurisdiction     = VALUES(jurisdiction),
                entity_types_json = VALUES(entity_types_json),
                aggression_tier  = VALUES(aggression_tier),
                source_url       = VALUES(source_url),
                source_license   = VALUES(source_license),
                template_md      = VALUES(template_md),
                variables_json   = VALUES(variables_json),
                version          = VALUES(version),
                priority         = VALUES(priority),
                notes_md         = VALUES(notes_md),
                updated_at       = CURRENT_TIMESTAMP"
        );

        $stmt->execute([
            ':slug'             => $tpl['slug'],
            ':name'             => $tpl['name'],
            ':category'         => $tpl['category'],
            ':subcategory'      => $tpl['subcategory'] ?? null,
            ':jurisdiction'     => $tpl['jurisdiction'] ?? null,
            ':entity_types_json' => $tpl['entity_types_json'] ?? null,
            ':aggression_tier'  => $tpl['aggression_tier'] ?? 'all',
            ':source_url'       => $tpl['source_url'] ?? null,
            ':source_license'   => $tpl['source_license'] ?? null,
            ':template_md'      => $tpl['template_md'] ?? '',
            ':variables_json'   => $tpl['variables_json'] ?? null,
            ':version'          => $tpl['version'] ?? '1.0',
            ':priority'         => $tpl['priority'] ?? 'P1',
            ':notes_md'         => $tpl['notes_md'] ?? null,
        ]);

        $rows = $stmt->rowCount();
        // MariaDB: rowCount() = 1 for insert, 2 for update, 0 for no-op
        return match ($rows) {
            1  => 'inserted',
            2  => 'updated',
            default => 'skipped',
        };
    }
}
