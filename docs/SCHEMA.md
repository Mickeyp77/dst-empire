# DST Empire — Database Schema Reference

**Generated from migrations 072 & 077**

---

## Overview

DST Empire schema tracks:
1. **Brand intake** — per-brand DST decision record
2. **Portfolio context** — owner estate/domicile info
3. **Beneficial owners** — FinCEN BOI registry
4. **Compliance calendar** — recurring tasks (19 types)
5. **Document templates & renders** — formation/compliance docs
6. **Law changes & amendments** — continuous compliance workflow
7. **Plaid transactions** — veil-piercing audit ledger
8. **Industry feeds** — source registry for law monitoring

---

## Table Reference

### `empire_states` (Static Reference)

Jurisdiction comparison matrix for formation decisions.

| Column | Type | Notes |
|--------|------|-------|
| `code` | CHAR(2) PRIMARY KEY | State abbreviation (TX, WY, DE, NV, SD, NM, FL) |
| `name` | VARCHAR(40) | Full state name |
| `formation_fee` | DECIMAL(8,2) | Filing fee for entity formation |
| `annual_fee` | DECIMAL(8,2) | Annual report filing fee |
| `franchise_tax` | VARCHAR(120) | Franchise tax rules (markdown) |
| `state_income_tax` | VARCHAR(60) | Income tax rules (markdown) |
| `anonymity_score` | TINYINT (0–10) | Member anonymity (10 = fully anonymous) |
| `charging_order_score` | TINYINT (0–10) | Charging-order protection strength |
| `dynasty_trust_score` | TINYINT (0–10) | Dynasty trust support (10 = no rule against perpetuities) |
| `dapt_score` | TINYINT (0–10) | DAPT statutory strength |
| `series_llc_supported` | TINYINT(1) | 1 = Series LLC recognized |
| `case_law_score` | TINYINT (0–10) | Judicial precedent depth (DE = 10) |
| `vc_friendly_score` | TINYINT (0–10) | VC acceptability for preferred stock |
| `notes_md` | TEXT | Commentary (markdown) |

**Indexes:** `idx_anon`, `idx_charging`

**Use case:** BrandPlacement jurisdictional recommendation; StateMatrix comparison.

---

### `empire_brand_intake` (Primary Decision Record)

One row per brand. Captures answers to formation intake questionnaire + DST advisor decisions.

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT UNSIGNED PK | |
| `tenant_id` | INT UNSIGNED | Multi-tenant isolation |
| `brand_slug` | VARCHAR(80) | Machine-safe identifier (voltops, dfwprinter, etc.) |
| `brand_name` | VARCHAR(120) | Display name |
| `domain` | VARCHAR(120) | Associated domain |
| `tier` | ENUM(T1–T5) | Importance ranking (T1 = flagship, T5 = SEO long-tail) |
| `current_status` | ENUM | dba_only, filed, operating, dormant, sold |
| `current_legal_owner` | VARCHAR(120) | Current owner (usually MNMS LLC for internal portfolio) |
| `revenue_profile` | VARCHAR(120) | Free-text: annual revenue + business model |
| `liability_profile` | ENUM | low, low_med, medium, med_high, high |
| `decided_jurisdiction` | CHAR(2) | →`empire_states.code` after decision |
| `decided_entity_type` | ENUM | keep_dba, sole_prop, llc, series_llc_cell, s_corp, c_corp, nonprofit, trust, shell, pllc, lp, llp |
| `decided_parent_kind` | ENUM | mnms_llc, holdco_llc, dapt, dynasty_trust, bridge_trust, mickey_personal, mixed |
| `decided_parent_entity_id` | INT UNSIGNED | →`formation_entities.id` if internal parent |
| `decided_trust_wrapper` | ENUM | none, dapt_nv, dapt_sd, dapt_wy, dynasty_sd, bridge_trust |
| `decided_sale_horizon` | ENUM | never, 5y_plus, 3y, 1y, active_sale |
| `advisor_notes_md` | MEDIUMTEXT | Advisor scratchpad (conversation accumulation) |
| `decision_status` | ENUM | not_started, in_review, locked, superseded |
| `decision_locked_at` | DATETIME | When locked |
| `decision_locked_by` | INT UNSIGNED | User ID |
| `spawned_entity_id` | INT UNSIGNED | →`formation_entities.id` once created |
| **Financial Layer** | | |
| `annual_revenue_usd` | DECIMAL(14,2) | Trailing-12-month gross |
| `revenue_recurring_pct` | DECIMAL(5,2) | % recurring vs one-time |
| `customer_concentration_pct` | DECIMAL(5,2) | Top-1 customer as % of total |
| `gross_margin_pct` | DECIMAL(5,2) | % |
| `cogs_usd` | DECIMAL(14,2) | Cost of goods sold (TTM) |
| `opex_usd` | DECIMAL(14,2) | Operating expenses excl. COGS (TTM) |
| `ebitda_usd` | DECIMAL(14,2) | Earnings before interest/taxes/depreciation/amort (TTM) |
| `working_capital_usd` | DECIMAL(14,2) | Current assets − current liabilities |
| `ar_balance_usd` | DECIMAL(14,2) | Accounts receivable balance |
| `ap_balance_usd` | DECIMAL(14,2) | Accounts payable balance |
| **Asset Register** | | |
| `ip_owned_md` | TEXT | Patents, trademarks, copyrights, trade secrets (markdown) |
| `equipment_value_usd` | DECIMAL(14,2) | FMV of equipment/fixtures |
| `real_estate_owned` | TINYINT(1) | 1 = entity directly owns real property |
| `receivables_quality` | ENUM | excellent, good, fair, poor (collectibility tier) |
| `inventory_value_usd` | DECIMAL(14,2) | Inventory at cost |
| `built_in_gain_usd` | DECIMAL(14,2) | IRC §1374 built-in gain exposure (S-corp) |
| `nol_carryforward_usd` | DECIMAL(14,2) | Federal NOL carryforward balance |
| `sec382_limited` | TINYINT(1) | 1 = NOL subject to IRC §382 annual limit |
| **Liability Register** | | |
| `active_claims_count` | TINYINT UNSIGNED | Active lawsuits / EEOC / regulatory actions |
| `active_claims_md` | TEXT | Description of active claims (markdown) |
| `contingent_claims_md` | TEXT | Threatened/contingent claims (markdown) |
| `personal_guarantees_md` | TEXT | Personal guarantees outstanding (markdown) |
| `regulatory_exposure_md` | TEXT | Licensing / compliance exposure (markdown) |
| `professional_liability_score` | TINYINT UNSIGNED | 0–10 inherent industry liability |
| `vehicle_count` | SMALLINT UNSIGNED | Vehicles titled to entity |
| `employee_count` | SMALLINT UNSIGNED | W-2 headcount |
| **Sale Optionality** | | |
| `strategic_buyer_pool_md` | TEXT | Known acquirers / strategic interest (markdown) |
| `multiple_target` | DECIMAL(5,2) | Target EBITDA or revenue multiple for exit |
| `lockup_tolerance_years` | TINYINT UNSIGNED | Max post-close lockup acceptable |
| `noncompete_acceptable` | TINYINT(1) | 1 = owner will sign non-compete |
| `earnout_acceptable` | TINYINT(1) | 1 = owner accepts earnout component |
| **Tax Posture** | | |
| `state_nexus_json` | JSON | Array of state codes with economic/physical nexus |
| `sales_tax_registered_states_json` | JSON | Array of state codes with sales-tax registration |
| `payroll_tax_registered_states_json` | JSON | Array of state codes with payroll-tax registration |
| `last_irs_audit_date` | DATE | Most recent IRS examination (NULL = never) |
| `qbi_election_active` | TINYINT(1) | 1 = IRC §199A QBI deduction claimed |
| `ptet_election_active` | TINYINT(1) | 1 = Pass-through entity tax election active |
| `cost_segregation_done` | TINYINT(1) | 1 = Cost-segregation study completed on RE |
| **Metadata** | | |
| `aggression_tier` | ENUM | conservative, growth, aggressive (risk tolerance) |
| `industry_vertical` | ENUM | saas, healthcare, realestate, crypto, professional_services, manufacturing, retail, agency, ecommerce, other |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

**Indexes:** `uk_tenant_brand` (unique), `idx_tenant_status`, `idx_tenant_tier`

**Foreign keys:**
- `spawned_entity_id` → `formation_entities.id` (SET NULL on delete)

**Relationships:**
- ← `empire_advisor_log.intake_id` (append-only conversation)
- ← `compliance_calendar.intake_id` (recurring tasks)
- ← `beneficial_owners.intake_id` (BOI registry)
- ← `doc_renders.intake_id` (rendered documents)
- ← `amendments.intake_id` (amendment triggers)
- ← `plaid_transactions.intake_id` (bank transaction ledger)

---

### `empire_advisor_log` (Conversation History)

One row per turn. Append-only log of AI advisor interactions.

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT UNSIGNED PK | |
| `tenant_id` | INT UNSIGNED | |
| `intake_id` | INT UNSIGNED NULL | →`empire_brand_intake.id` (NULL = portfolio-level) |
| `role` | ENUM | user, advisor, system |
| `body_md` | MEDIUMTEXT | Markdown message body |
| `model` | VARCHAR(80) | LLM name (hermes3-mythos:70b, etc.) |
| `tokens_in` | INT NULL | Tokens consumed |
| `tokens_out` | INT NULL | Tokens generated |
| `cost_usd` | DECIMAL(8,4) | LLM cost (optional tracking) |
| `created_at` | DATETIME | |

**Indexes:** `idx_intake`, `idx_tenant`

**Use case:** Resume multi-turn DST conversations; cost allocation.

---

### `empire_portfolio_context` (Portfolio-Level Metadata)

One row per tenant. Owner estate/domicile context for portfolio-level analysis.

| Column | Type | Notes |
|--------|------|-------|
| `tenant_id` | INT UNSIGNED PK | |
| `owner_age_years` | TINYINT UNSIGNED | Current age |
| `retirement_target_age` | TINYINT UNSIGNED | Target retirement age |
| `spouse_member_pct` | DECIMAL(5,2) | Spouse ownership % across entities (0 = no spouse) |
| `kids_count` | TINYINT UNSIGNED | Number of children |
| `estate_plan_current` | TINYINT(1) | 1 = existing will/trust/POA executed & current |
| `estate_tax_exemption_used_usd` | DECIMAL(14,2) | Federal exemption consumed via prior gifts |
| `domicile_state` | CHAR(2) | Owner legal domicile (state code) |
| `residency_planned_change` | TINYINT(1) | 1 = owner planning domicile change within 24mo |
| `tx_sos_status` | ENUM | current, delinquent, forfeited |
| `irs_status` | ENUM | current, payment_plan, lien, levy, audit_open |
| `franchise_tax_current` | TINYINT(1) | 1 = all franchise tax obligations current |
| `annual_filings_calendar_json` | JSON | Array of upcoming annual-filing due dates |
| `updated_at` | DATETIME | |

**Primary key:** `tenant_id`

**Use case:** Portfolio-level tax/estate planning decisions; estate freeze strategies.

---

### `empire_trust_thresholds` (Reference Rules)

Trigger rules for trust formation recommendations.

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT UNSIGNED PK | |
| `trust_kind` | ENUM | dapt_nv, dapt_sd, dapt_wy, dynasty_sd, bridge_trust |
| `min_assets_usd` | DECIMAL(12,2) | Asset threshold trigger |
| `annual_cost_low_usd` | DECIMAL(8,2) | Low-end annual trustee cost |
| `annual_cost_high_usd` | DECIMAL(8,2) | High-end annual trustee cost |
| `setup_cost_low_usd` | DECIMAL(8,2) | Low-end formation cost |
| `setup_cost_high_usd` | DECIMAL(8,2) | High-end formation cost |
| `when_to_consider_md` | TEXT | Trigger criteria (markdown) |
| `notes_md` | TEXT | Commentary (markdown) |

**Use case:** TrustBuilder recommendations; ROI calculations.

---

### `beneficial_owners` (FinCEN BOI Registry)

FinCEN Corporate Transparency Act beneficial owner records.

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT UNSIGNED PK | |
| `tenant_id` | INT UNSIGNED | |
| `intake_id` | INT UNSIGNED | →`empire_brand_intake.id` |
| `full_legal_name` | VARCHAR(200) | Full legal name (FinCEN BO registry format) |
| `date_of_birth` | DATE | DOB |
| `residential_address_md` | TEXT | Residential address (NOT business addr, markdown) |
| `identifying_doc_type` | ENUM | passport, drivers_license, state_id, foreign_passport |
| `identifying_doc_number` | VARCHAR(80) | ID number |
| `identifying_doc_jurisdiction` | VARCHAR(80) | Issuing state/country |
| `identifying_doc_image_path` | VARCHAR(255) | Path to stored ID image (storage/boi/) |
| `ownership_pct` | DECIMAL(5,2) | Direct or indirect ownership % |
| `control_role` | ENUM | owner, officer, manager, trustee, other |
| `control_role_other` | VARCHAR(120) | Free-text if control_role=other |
| `is_company_applicant` | TINYINT(1) | 1 = this person filed/directed filing |
| `fincen_id` | VARCHAR(40) | FinCEN ID if individual obtained one |
| `last_filed_at` | DATETIME | Most recent BOI report submission |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

**Indexes:** `idx_bo_tenant_intake`

**Foreign keys:** `intake_id` → `empire_brand_intake.id` (CASCADE)

**Use case:** BOIR generation + FinCEN filing; beneficial owner tracking per entity.

---

### `compliance_calendar` (Recurring Tasks)

Recurring compliance task tracker per entity & tenant.

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT UNSIGNED PK | |
| `tenant_id` | INT UNSIGNED | |
| `intake_id` | INT UNSIGNED NULL | →`empire_brand_intake.id` (NULL = tenant-level) |
| `task_type` | ENUM | 19 types: annual_report, franchise_tax, federal_tax, state_tax, license_renewal, trust_admin, 83b_anniversary, 1202_clock, dapt_seasoning, 1031_clock, 199a_recalc, 531_recheck, ptet_election, insurance_renewal, tm_renewal, boi_update, captive_filing, fbar, crummey_letter |
| `due_date` | DATE | Due date |
| `status` | ENUM | pending, in_progress, completed, overdue, waived |
| `completed_at` | DATETIME | Completion timestamp |
| `completed_by` | INT UNSIGNED | User ID |
| `notes_md` | TEXT | Task notes (markdown) |
| `recurrence` | ENUM | once, annual, quarterly, monthly, custom |
| `created_at` | DATETIME | |

**Indexes:** `idx_cc_tenant_due`, `idx_cc_intake`, `idx_cc_status_due`

**Foreign keys:** `intake_id` → `empire_brand_intake.id` (CASCADE)

**Use case:** Deadline tracking; alert dispatch per AlertDispatcher.

---

### `doc_templates` (Document Library)

Library of formation/compliance document templates (~60 docs).

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT UNSIGNED PK | |
| `slug` | VARCHAR(120) UNIQUE | Machine-safe identifier (wy_llc_operating_agreement_v1) |
| `name` | VARCHAR(160) | Display name |
| `category` | ENUM | formation, irs_form, trust, intercompany, tax_election, compliance, estate, insurance |
| `subcategory` | VARCHAR(80) | Series LLC, DAPT, §83(b), cost segregation, etc. |
| `jurisdiction` | CHAR(2) NULL | State code (NULL = federal or multi-jurisdiction) |
| `entity_types_json` | JSON | Array of entity types this template applies to |
| `aggression_tier` | ENUM | conservative, growth, aggressive, all |
| `source_url` | VARCHAR(500) | Canonical source / IRS pub / SOS URL |
| `source_license` | VARCHAR(80) | License/attribution for content |
| `template_md` | MEDIUMTEXT | Markdown with `{{variable}}` placeholders |
| `variables_json` | JSON | Array: `[{name, type, default, description}]` |
| `version` | VARCHAR(20) | Version number (1.0, 1.1, 2.0, etc.) |
| `priority` | ENUM | P0 (blocking for formation), P1 (recommended), P2 (optional) |
| `notes_md` | TEXT | Attorney notes, gotchas, filing instructions |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

**Indexes:** `idx_dt_cat_jur`

**Use case:** Template library for document rendering; attorney package assembly.

---

### `doc_renders` (Rendered Document Instances)

Per-client rendered document instances (draft → filed lifecycle).

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT UNSIGNED PK | |
| `tenant_id` | INT UNSIGNED | |
| `intake_id` | INT UNSIGNED NULL | →`empire_brand_intake.id` (NULL = tenant-level) |
| `template_id` | INT UNSIGNED | →`doc_templates.id` |
| `variables_used_json` | JSON | Snapshot of variable values at render time |
| `rendered_md` | MEDIUMTEXT | Fully resolved markdown output |
| `content_hash` | CHAR(64) | SHA-256 of rendered_md (change detection) |
| `version_number` | INT UNSIGNED | Version counter (amendment tracking) |
| `status` | ENUM | draft, attorney_review, client_approved, filed, superseded |
| `filed_at` | DATETIME | When actually filed |
| `attorney_signed_at` | DATETIME | When attorney signed |
| `file_path_pdf` | VARCHAR(255) | storage/renders/{id}.pdf |
| `file_path_docx` | VARCHAR(255) | storage/renders/{id}.docx |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

**Indexes:** `idx_dr_tenant_intake`, `idx_dr_template`

**Foreign keys:**
- `intake_id` → `empire_brand_intake.id` (SET NULL)
- `template_id` → `doc_templates.id` (RESTRICT)

**Use case:** Document lifecycle tracking; attorney package delivery; filing proof.

---

### `law_changes` (Continuous Compliance Feed)

Ingested law changes with LLM classification.

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT UNSIGNED PK | |
| `source` | ENUM | irs_bulletin, tax_court, state_sos, state_tax, state_trust_law, fincen, dol, uspto, scotus, industry_specific |
| `jurisdiction` | CHAR(2) NULL | State code (NULL = federal) |
| `source_url` | VARCHAR(500) | Link to original source |
| `title` | VARCHAR(300) | Change title |
| `summary_md` | TEXT | Short human-readable summary (markdown) |
| `full_text_md` | MEDIUMTEXT | Full scraped text (markdown) |
| `effective_date` | DATE | Effective date |
| `detected_at` | DATETIME | When detected by monitor |
| `classification_json` | JSON | LLM output: `{affected_playbooks[], severity, action_required, urgency_days}` |
| `processed` | TINYINT(1) | 1 = classification populated + amendments created |

**Indexes:** `idx_lc_source_det`, `idx_lc_jur_eff`

**Use case:** Law-change ingestion; per-client amendment triggering.

---

### `amendments` (Amendment Workflow)

Per-client triggered amendment workflow (law_change → filed).

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT UNSIGNED PK | |
| `tenant_id` | INT UNSIGNED | |
| `intake_id` | INT UNSIGNED NULL | →`empire_brand_intake.id` (NULL = portfolio-level) |
| `law_change_id` | INT UNSIGNED NULL | →`law_changes.id` |
| `trigger_event_type` | ENUM | law_change, life_event, sale_offer, asset_milestone, nexus_change, manual |
| `trigger_description_md` | TEXT | Why amendment triggered (markdown) |
| `affected_doc_render_ids_json` | JSON | Array of `doc_renders.id` values impacted |
| `amendment_doc_render_id` | INT UNSIGNED NULL | →`doc_renders.id` for amendment document |
| `status` | ENUM | detected, drafted, client_notified, attorney_review, client_approved, filed, dismissed |
| `severity` | ENUM | low, medium, high, critical |
| `created_at` | DATETIME | |
| `resolved_at` | DATETIME | |

**Indexes:** `idx_am_tenant_status`, `idx_am_severity`

**Foreign keys:**
- `intake_id` → `empire_brand_intake.id` (SET NULL)
- `law_change_id` → `law_changes.id` (SET NULL)
- `amendment_doc_render_id` → `doc_renders.id` (SET NULL)

**Use case:** Amendment lifecycle tracking; alert dispatch.

---

### `plaid_transactions` (Veil-Piercing Audit Ledger)

Plaid transaction feed for corporate veil audit.

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT UNSIGNED PK | |
| `tenant_id` | INT UNSIGNED | |
| `intake_id` | INT UNSIGNED | →`empire_brand_intake.id` |
| `plaid_account_id` | VARCHAR(80) | Plaid account identifier |
| `plaid_transaction_id` | VARCHAR(80) UNIQUE | Unique transaction ID from Plaid |
| `txn_date` | DATE | Transaction date |
| `amount_usd` | DECIMAL(12,2) | Positive = debit, negative = credit |
| `merchant_name` | VARCHAR(200) | Merchant/counterparty name |
| `category` | VARCHAR(120) | Plaid category label |
| `classification` | ENUM | business, personal_flagged, intercompany, distribution, loan, reimbursable, unknown |
| `flag_reason` | VARCHAR(200) | Reason for personal_flagged (if flagged) |
| `flag_severity` | ENUM | none, soft, hard |
| `resolved_at` | DATETIME | When flag was cleared/reclassified |
| `raw_json` | JSON | Original Plaid transaction object |
| `created_at` | DATETIME | |

**Indexes:** `idx_pt_tenant_intake_date`, `idx_pt_flag`

**Foreign keys:** `intake_id` → `empire_brand_intake.id` (CASCADE)

**Use case:** Veil-piercing risk detection; personal-expense categorization; audit trail.

---

### `industry_feeds` (Feed Source Registry)

Feed source registry per industry vertical.

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT UNSIGNED PK | |
| `vertical` | ENUM | saas, healthcare, realestate, crypto, professional_services, manufacturing, retail, agency, ecommerce, other |
| `source_name` | VARCHAR(120) | Feed name |
| `source_url` | VARCHAR(500) | RSS/scraper URL |
| `feed_type` | ENUM | rss, scraper, api, manual |
| `enabled` | TINYINT(1) | 1 = actively monitored |
| `last_polled_at` | DATETIME | Last polling timestamp |
| `notes_md` | TEXT | Commentary (markdown) |

**Indexes:** `idx_if_vertical`

**Use case:** SourcePoller feed discovery; vertical-specific law-change filtering.

---

### `boi_audit_log` (BOI Filing Audit Trail)

Audit log for BOI generation, validation, filing.

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT UNSIGNED PK | |
| `tenant_id` | INT UNSIGNED | |
| `intake_id` | INT UNSIGNED NULL | →`empire_brand_intake.id` (SET NULL) |
| `formation_entity_id` | INT UNSIGNED NULL | →`formation_entities.id` (SET NULL) |
| `action` | ENUM | generated, validated, filed, updated, rejected |
| `payload_hash` | VARCHAR(80) | SHA-256 of BOI JSON/XML payload |
| `error_md` | TEXT | Error message / rejection reason (markdown) |
| `performed_by` | INT UNSIGNED | User ID |
| `performed_at` | DATETIME | Action timestamp |

**Indexes:** `idx_bai_tenant_performed`, `idx_bai_entity`

**Foreign keys:**
- `intake_id` → `empire_brand_intake.id` (SET NULL)
- `formation_entity_id` → `formation_entities.id` (SET NULL)

**Use case:** BOI filing audit trail; FinCEN submission tracking.

---

## Entity Relationship Diagram

```
empire_brand_intake (hub)
├─ empire_advisor_log (append-only conversation)
├─ compliance_calendar (recurring tasks)
├─ beneficial_owners (FinCEN BOI)
├─ doc_renders (via template_id)
│  └─ doc_templates (template library)
├─ amendments (triggered by law_changes)
│  └─ law_changes (ingested + classified)
├─ plaid_transactions (bank audit)
└─ formation_entities (once spawned)

empire_states (static reference)
└─ empire_brand_intake (decided_jurisdiction)

empire_trust_thresholds (static reference)
└─ TrustBuilder logic

empire_portfolio_context (1 per tenant)
└─ Portfolio-level decisions

industry_feeds (static reference)
└─ SourcePoller (feed discovery)

boi_audit_log
└─ Audit trail for BOI filings
```

---

## Sample Queries

### All pending compliance tasks (next 30 days)

```sql
SELECT
    cc.due_date,
    cc.task_type,
    ebi.brand_name,
    cc.status,
    cc.notes_md
FROM compliance_calendar cc
JOIN empire_brand_intake ebi ON cc.intake_id = ebi.id
WHERE cc.tenant_id = ?
  AND cc.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)
  AND cc.status IN ('pending', 'in_progress')
ORDER BY cc.due_date ASC;
```

### Brand intake by tier and liability

```sql
SELECT
    tier,
    liability_profile,
    COUNT(*) as count,
    ROUND(AVG(annual_revenue_usd)) as avg_revenue
FROM empire_brand_intake
WHERE tenant_id = ?
GROUP BY tier, liability_profile
ORDER BY tier, liability_profile;
```

### Overdue BOI filings

```sql
SELECT
    bo.full_legal_name,
    ebi.brand_name,
    MAX(bai.performed_at) as last_boi_action
FROM beneficial_owners bo
JOIN empire_brand_intake ebi ON bo.intake_id = ebi.id
LEFT JOIN boi_audit_log bai ON bo.intake_id = bai.intake_id
WHERE bo.tenant_id = ?
  AND (bo.last_filed_at IS NULL OR bo.last_filed_at < DATE_SUB(NOW(), INTERVAL 30 DAY))
GROUP BY bo.id
ORDER BY last_boi_action ASC;
```

### Law changes affecting this tenant

```sql
SELECT
    lc.title,
    lc.source,
    lc.effective_date,
    lc.classification_json,
    COUNT(am.id) as amendment_count
FROM law_changes lc
LEFT JOIN amendments am ON lc.id = am.law_change_id AND am.tenant_id = ?
WHERE lc.jurisdiction IS NULL OR lc.jurisdiction IN (
    SELECT DISTINCT decided_jurisdiction
    FROM empire_brand_intake
    WHERE tenant_id = ?
)
  AND lc.detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
ORDER BY lc.detected_at DESC;
```

### Veil audit: flagged personal expenses

```sql
SELECT
    pt.txn_date,
    pt.amount_usd,
    pt.merchant_name,
    pt.flag_reason,
    ebi.brand_name,
    pt.resolved_at
FROM plaid_transactions pt
JOIN empire_brand_intake ebi ON pt.intake_id = ebi.id
WHERE pt.tenant_id = ?
  AND pt.classification = 'personal_flagged'
  AND pt.flag_severity = 'hard'
  AND pt.resolved_at IS NULL
ORDER BY pt.txn_date DESC;
```

---

## Migration Path

**Phase A** — migrations/072 & 077:
- 4 core tables (empire_states, empire_brand_intake, empire_advisor_log, empire_trust_thresholds)
- Schema expansion (9 new tables + 40 added columns)

**Phase B+** (future):
- Pre-filled template library (doc_templates seed data)
- Law-change ingestion (law_changes + amendments populated)
- Industry vertical feed sources (industry_feeds seed data)

---

## Performance Notes

- **Indexes on tenure + date** for compliance calendar queries (millions of tasks)
- **JSON columns** (state_nexus_json, etc.) are queryable via `JSON_EXTRACT()` but not indexed; consider jsonpath indexes for large datasets
- **MEDIUMTEXT** for advisor_notes_md, law full text — reasonable for ~1000 char limit; migrate to external blob storage if >10GB corpus
- **Plaid transaction feed** — expect 100–1000 txns/entity/month; pagination + hourly archival recommended for <100ms queries

---

## References

- [docs/ARCHITECTURE.md](ARCHITECTURE.md) — System design overview
- [docs/EXAMPLES.md](EXAMPLES.md) — Code recipes
- [migrations/072_dst_empire_brand_intake.sql](../migrations/072_dst_empire_brand_intake.sql)
- [migrations/077_dstempire_schema_expansion.sql](../migrations/077_dstempire_schema_expansion.sql)
