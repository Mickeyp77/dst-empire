-- Migration 077: DST Empire — Comprehensive Schema Expansion
-- Date:    2026-04-28
-- Purpose:
--   Adds the full analysis-engine product layer to DST Empire:
--   A. ALTER empire_brand_intake — ~50 new columns (financial, asset, liability,
--      sale optionality, tax posture, aggression tier, industry vertical)
--   B. empire_portfolio_context  — per-tenant owner estate/domicile context
--   C. compliance_calendar       — recurring compliance task tracker per entity
--   D. beneficial_owners         — FinCEN BOI registry per entity
--   E. doc_templates             — ~60-doc template library
--   F. doc_renders               — per-client rendered document instances
--   G. law_changes               — continuous compliance feed (LLM-classified)
--   H. amendments                — per-client triggered amendment workflow
--   I. plaid_transactions        — Plaid veil-audit transaction ledger
--   J. industry_feeds            — feed sources indexed by industry vertical
--
-- LOCAL DEV ONLY — DO NOT apply to prod Galera (192.168.1.132)
-- without Mickey's explicit go-ahead.
-- MariaDB 10.11+ / 11.x. Idempotent (IF NOT EXISTS + column-existence guards).

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- ============================================================
-- A. ALTER empire_brand_intake — add analysis-engine columns
--    All wrapped in existence checks so re-runs are safe.
-- ============================================================

-- Helper procedure: idempotent ALTER for empire_brand_intake
DROP PROCEDURE IF EXISTS _empire_add_col;
DELIMITER $$
CREATE PROCEDURE _empire_add_col(
    IN p_col  VARCHAR(64),
    IN p_defn TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'empire_brand_intake'
          AND COLUMN_NAME  = p_col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE empire_brand_intake ADD COLUMN ', p_col, ' ', p_defn);
        PREPARE _s FROM @sql;
        EXECUTE _s;
        DEALLOCATE PREPARE _s;
    END IF;
END$$
DELIMITER ;

-- §2A Financial layer
CALL _empire_add_col('annual_revenue_usd',          'DECIMAL(14,2) NULL COMMENT "Trailing-12-month gross revenue"');
CALL _empire_add_col('revenue_recurring_pct',        'DECIMAL(5,2) NULL COMMENT "% of revenue that is recurring/contracted"');
CALL _empire_add_col('customer_concentration_pct',   'DECIMAL(5,2) NULL COMMENT "Revenue from top-1 customer as % of total"');
CALL _empire_add_col('gross_margin_pct',             'DECIMAL(5,2) NULL COMMENT "Gross margin %"');
CALL _empire_add_col('cogs_usd',                     'DECIMAL(14,2) NULL COMMENT "Cost of goods sold (TTM)"');
CALL _empire_add_col('opex_usd',                     'DECIMAL(14,2) NULL COMMENT "Operating expenses excl COGS (TTM)"');
CALL _empire_add_col('ebitda_usd',                   'DECIMAL(14,2) NULL COMMENT "EBITDA (TTM)"');
CALL _empire_add_col('working_capital_usd',          'DECIMAL(14,2) NULL COMMENT "Current assets minus current liabilities"');
CALL _empire_add_col('ar_balance_usd',               'DECIMAL(14,2) NULL COMMENT "Accounts receivable balance"');
CALL _empire_add_col('ap_balance_usd',               'DECIMAL(14,2) NULL COMMENT "Accounts payable balance"');

-- §2B Asset register
CALL _empire_add_col('ip_owned_md',                  'TEXT NULL COMMENT "Markdown: patents, trademarks, copyrights, trade secrets owned"');
CALL _empire_add_col('equipment_value_usd',          'DECIMAL(14,2) NULL COMMENT "FMV of equipment/fixtures"');
CALL _empire_add_col('real_estate_owned',            'TINYINT(1) NOT NULL DEFAULT 0 COMMENT "Entity directly owns real property"');
CALL _empire_add_col('receivables_quality',          'ENUM(''excellent'',''good'',''fair'',''poor'') NULL COMMENT "Collectibility tier of AR book"');
CALL _empire_add_col('inventory_value_usd',          'DECIMAL(14,2) NULL COMMENT "Inventory at cost"');
CALL _empire_add_col('built_in_gain_usd',            'DECIMAL(14,2) NULL COMMENT "IRC §1374 built-in gain exposure (S-corp)"');
CALL _empire_add_col('nol_carryforward_usd',         'DECIMAL(14,2) NULL COMMENT "Federal NOL carryforward balance"');
CALL _empire_add_col('sec382_limited',               'TINYINT(1) NOT NULL DEFAULT 0 COMMENT "NOL subject to IRC §382 annual limit"');

-- §2C Liability register
CALL _empire_add_col('active_claims_count',          'TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT "Active lawsuits / EEOC / regulatory actions"');
CALL _empire_add_col('active_claims_md',             'TEXT NULL COMMENT "Markdown: description of active claims"');
CALL _empire_add_col('contingent_claims_md',         'TEXT NULL COMMENT "Markdown: threatened / contingent claims"');
CALL _empire_add_col('personal_guarantees_md',       'TEXT NULL COMMENT "Markdown: personal guarantees outstanding"');
CALL _empire_add_col('regulatory_exposure_md',       'TEXT NULL COMMENT "Markdown: known regulatory / licensing exposure"');
CALL _empire_add_col('professional_liability_score', 'TINYINT UNSIGNED NULL COMMENT "0-10: inherent professional liability risk for this industry"');
CALL _empire_add_col('vehicle_count',                'SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT "Vehicles titled to or operated by entity"');
CALL _empire_add_col('employee_count',               'SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT "W-2 employees (headcount)"');

-- §2D Sale optionality
CALL _empire_add_col('strategic_buyer_pool_md',      'TEXT NULL COMMENT "Markdown: known acquirers / strategic interest"');
CALL _empire_add_col('multiple_target',              'DECIMAL(5,2) NULL COMMENT "Target EBITDA or revenue multiple for exit"');
CALL _empire_add_col('lockup_tolerance_years',       'TINYINT UNSIGNED NULL COMMENT "Max post-close lockup owner will accept (years)"');
CALL _empire_add_col('noncompete_acceptable',        'TINYINT(1) NULL COMMENT "Owner willing to sign non-compete at exit"');
CALL _empire_add_col('earnout_acceptable',           'TINYINT(1) NULL COMMENT "Owner willing to accept earnout component"');

-- §2E Tax posture
CALL _empire_add_col('state_nexus_json',                    'JSON NULL COMMENT "Array of state codes where economic/physical nexus established"');
CALL _empire_add_col('sales_tax_registered_states_json',    'JSON NULL COMMENT "Array of state codes with active sales-tax registration"');
CALL _empire_add_col('payroll_tax_registered_states_json',  'JSON NULL COMMENT "Array of state codes with active payroll-tax registration"');
CALL _empire_add_col('last_irs_audit_date',                 'DATE NULL COMMENT "Date of most recent IRS examination (NULL = never)"');
CALL _empire_add_col('qbi_election_active',                 'TINYINT(1) NOT NULL DEFAULT 0 COMMENT "IRC §199A QBI deduction currently claimed"');
CALL _empire_add_col('ptet_election_active',                'TINYINT(1) NOT NULL DEFAULT 0 COMMENT "Pass-through entity tax (SALT workaround) election active"');
CALL _empire_add_col('cost_segregation_done',               'TINYINT(1) NOT NULL DEFAULT 0 COMMENT "Cost-segregation study completed on owned real estate"');

-- §11 Aggression tier
CALL _empire_add_col('aggression_tier',              'ENUM(''conservative'',''growth'',''aggressive'') NOT NULL DEFAULT ''growth'' COMMENT "Owner risk/complexity tolerance"');

-- §25 Industry vertical
CALL _empire_add_col('industry_vertical',            'ENUM(''saas'',''healthcare'',''realestate'',''crypto'',''professional_services'',''manufacturing'',''retail'',''agency'',''ecommerce'',''other'') NULL COMMENT "Primary industry for playbook selection"');

DROP PROCEDURE IF EXISTS _empire_add_col;

-- ============================================================
-- B. empire_portfolio_context — per-tenant owner context
-- ============================================================

CREATE TABLE IF NOT EXISTS empire_portfolio_context (
    tenant_id                    INT UNSIGNED NOT NULL,
    owner_age_years              TINYINT UNSIGNED NULL COMMENT "Owner current age",
    retirement_target_age        TINYINT UNSIGNED NULL COMMENT "Target retirement age",
    spouse_member_pct            DECIMAL(5,2) NULL COMMENT "Spouse ownership % across entities (0=no spouse member)",
    kids_count                   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    estate_plan_current          TINYINT(1) NOT NULL DEFAULT 0 COMMENT "Existing will/trust/POA executed and current",
    estate_tax_exemption_used_usd DECIMAL(14,2) NULL COMMENT "Federal estate-tax exemption already consumed via gifts",
    domicile_state               CHAR(2) NULL COMMENT "Owner legal domicile state code",
    residency_planned_change     TINYINT(1) NOT NULL DEFAULT 0 COMMENT "Owner planning domicile change within 24 months",
    tx_sos_status                ENUM('current','delinquent','forfeited') NOT NULL DEFAULT 'current' COMMENT "Texas SOS standing for all entities",
    irs_status                   ENUM('current','payment_plan','lien','levy','audit_open') NOT NULL DEFAULT 'current',
    franchise_tax_current        TINYINT(1) NOT NULL DEFAULT 1 COMMENT "All franchise tax obligations current",
    annual_filings_calendar_json JSON NULL COMMENT "JSON array of upcoming annual-filing due dates",
    updated_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-tenant owner estate/domicile context for portfolio-level analysis';

-- ============================================================
-- C. compliance_calendar — recurring compliance task tracker
-- ============================================================

CREATE TABLE IF NOT EXISTS compliance_calendar (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    INT UNSIGNED NOT NULL,
    intake_id    INT UNSIGNED NULL COMMENT "FK → empire_brand_intake.id (NULL = tenant-level task)",
    task_type    ENUM(
                    'annual_report','franchise_tax','federal_tax','state_tax',
                    'license_renewal','trust_admin','83b_anniversary','1202_clock',
                    'dapt_seasoning','1031_clock','199a_recalc','531_recheck',
                    'ptet_election','insurance_renewal','tm_renewal','boi_update',
                    'captive_filing','fbar','crummey_letter'
                 ) NOT NULL,
    due_date     DATE NOT NULL,
    status       ENUM('pending','in_progress','completed','overdue','waived') NOT NULL DEFAULT 'pending',
    completed_at DATETIME NULL,
    completed_by INT UNSIGNED NULL COMMENT "user_id of completing user",
    notes_md     TEXT NULL,
    recurrence   ENUM('once','annual','quarterly','monthly','custom') NOT NULL DEFAULT 'annual',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cc_tenant_due    (tenant_id, due_date),
    INDEX idx_cc_intake        (intake_id),
    INDEX idx_cc_status_due    (status, due_date),
    CONSTRAINT fk_cc_intake
        FOREIGN KEY (intake_id) REFERENCES empire_brand_intake(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Recurring compliance tasks per entity and per tenant';

-- ============================================================
-- D. Alter formation_entities — add BOI tracking columns
-- ============================================================

-- Helper procedure: idempotent ALTER for formation_entities
DROP PROCEDURE IF EXISTS _formation_add_col;
DELIMITER $$
CREATE PROCEDURE _formation_add_col(
    IN p_col  VARCHAR(64),
    IN p_defn TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'formation_entities'
          AND COLUMN_NAME  = p_col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE formation_entities ADD COLUMN ', p_col, ' ', p_defn);
        PREPARE _s FROM @sql;
        EXECUTE _s;
        DEALLOCATE PREPARE _s;
    END IF;
END$$
DELIMITER ;

-- Add EIN (may already exist from migration 070, but idempotent check ensures safe re-run)
CALL _formation_add_col('ein',                    'VARCHAR(20) NULL COMMENT "Federal EIN, format XX-XXXXXXX"');
-- BOI filing tracking
CALL _formation_add_col('boi_filed_at',           'DATETIME NULL COMMENT "Timestamp of FinCEN BOI filing submission"');
CALL _formation_add_col('boi_confirmation_hash',  'VARCHAR(80) NULL COMMENT "SHA-256 hash of filed BOIR payload for audit trail"');
-- Formation date (for pre-2024 grandfather rule check per CTA)
CALL _formation_add_col('formed_at',              'DATE NULL COMMENT "Original formation/incorporation date (for CTA pre-2024 exemption check)"');

DROP PROCEDURE IF EXISTS _formation_add_col;

-- ============================================================
-- D.5 beneficial_owners — ensure FK to empire_brand_intake (idempotent)
-- ============================================================

-- Portable guard: works on MySQL 8.0 AND MariaDB 10.3+.
-- Checks INFORMATION_SCHEMA before attempting ADD CONSTRAINT.
DROP PROCEDURE IF EXISTS _empire_ensure_bo_fk;
DELIMITER $$
CREATE PROCEDURE _empire_ensure_bo_fk()
BEGIN
    -- Only run if beneficial_owners table already exists (prior-session scenario).
    -- If the table is about to be created fresh below (Section E), the inline FK
    -- in CREATE TABLE handles this — so this proc is a no-op in the fresh path.
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'beneficial_owners'
    ) THEN
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA        = DATABASE()
              AND TABLE_NAME               = 'beneficial_owners'
              AND CONSTRAINT_NAME          = 'fk_bo_intake'
        ) THEN
            SET @_bo_fk = 'ALTER TABLE beneficial_owners
                ADD CONSTRAINT fk_bo_intake
                FOREIGN KEY (intake_id) REFERENCES empire_brand_intake(id)
                ON DELETE CASCADE';
            PREPARE _s FROM @_bo_fk;
            EXECUTE _s;
            DEALLOCATE PREPARE _s;
        END IF;
    END IF;
END$$
DELIMITER ;
CALL _empire_ensure_bo_fk();
DROP PROCEDURE IF EXISTS _empire_ensure_bo_fk;

-- ============================================================
-- D.6 boi_audit_log — Audit trail for BOI filings
-- ============================================================

CREATE TABLE IF NOT EXISTS boi_audit_log (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                   INT UNSIGNED NOT NULL,
    intake_id                   INT UNSIGNED NULL COMMENT "FK → empire_brand_intake.id ON DELETE SET NULL",
    formation_entity_id         INT UNSIGNED NULL COMMENT "FK → formation_entities.id ON DELETE SET NULL",
    action                      ENUM(
                                    'generated',   -- BOI payload generated
                                    'validated',   -- Validation passed
                                    'filed',       -- Submitted to FinCEN
                                    'updated',     -- Information changed (re-file triggered)
                                    'rejected'     -- FinCEN rejected filing
                                ) NOT NULL,
    payload_hash                VARCHAR(80) NULL COMMENT "SHA-256 of the BOI JSON/XML payload",
    error_md                    TEXT NULL COMMENT "Error message or rejection reason (markdown)",
    performed_by                INT UNSIGNED NULL COMMENT "FK → users.id (NULL if system-generated)",
    performed_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bai_tenant_performed (tenant_id, performed_at),
    INDEX idx_bai_entity            (formation_entity_id),
    CONSTRAINT fk_bai_intake
        FOREIGN KEY (intake_id) REFERENCES empire_brand_intake(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_bai_formation_entity
        FOREIGN KEY (formation_entity_id) REFERENCES formation_entities(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit log for BOI generation, validation, filing, and rejection events';

-- ============================================================
-- E. beneficial_owners — FinCEN BOI registry
-- ============================================================

CREATE TABLE IF NOT EXISTS beneficial_owners (
    id                             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                      INT UNSIGNED NOT NULL,
    intake_id                      INT UNSIGNED NOT NULL,
    full_legal_name                VARCHAR(200) NOT NULL,
    date_of_birth                  DATE NOT NULL,
    residential_address_md         TEXT NOT NULL COMMENT "Markdown: full residential address (NOT business address)",
    identifying_doc_type           ENUM('passport','drivers_license','state_id','foreign_passport') NOT NULL,
    identifying_doc_number         VARCHAR(80) NOT NULL,
    identifying_doc_jurisdiction   VARCHAR(80) NOT NULL COMMENT "Issuing state/country",
    identifying_doc_image_path     VARCHAR(255) NULL COMMENT "Relative path to stored ID image (storage/boi/)",
    ownership_pct                  DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT "Direct or indirect ownership percentage",
    control_role                   ENUM('owner','officer','manager','trustee','other') NOT NULL DEFAULT 'owner',
    control_role_other             VARCHAR(120) NULL COMMENT "Free-text if control_role = other",
    is_company_applicant           TINYINT(1) NOT NULL DEFAULT 0 COMMENT "True if this person filed/directed filing (company applicant)",
    fincen_id                      VARCHAR(40) NULL COMMENT "FinCEN ID if individual obtained one",
    last_filed_at                  DATETIME NULL COMMENT "Most recent BOI report submission date",
    created_at                     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_bo_tenant_intake     (tenant_id, intake_id),
    CONSTRAINT fk_bo_intake
        FOREIGN KEY (intake_id) REFERENCES empire_brand_intake(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='FinCEN Beneficial Ownership Information (BOI) registry per entity';

-- ============================================================
-- F. doc_templates — library of formation/compliance templates
-- ============================================================

CREATE TABLE IF NOT EXISTS doc_templates (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug             VARCHAR(120) NOT NULL UNIQUE COMMENT "Machine-safe identifier, e.g. wy_llc_operating_agreement_v1",
    name             VARCHAR(160) NOT NULL,
    category         ENUM(
                        'formation','irs_form','trust','intercompany',
                        'tax_election','compliance','estate','insurance'
                     ) NOT NULL,
    subcategory      VARCHAR(80) NULL COMMENT "e.g. series_llc, dapt, 83b, cost_segregation",
    jurisdiction     CHAR(2) NULL COMMENT "State code or NULL for federal / multi-jurisdiction",
    entity_types_json JSON NULL COMMENT "JSON array of entity types this template applies to",
    aggression_tier  ENUM('conservative','growth','aggressive','all') NOT NULL DEFAULT 'all',
    source_url       VARCHAR(500) NULL COMMENT "Canonical source / IRS pub / SOS URL",
    source_license   VARCHAR(80) NULL COMMENT "License or attribution for template content",
    template_md      MEDIUMTEXT NULL COMMENT "Markdown template body with {{variable}} placeholders",
    variables_json   JSON NULL COMMENT "JSON array: [{name, type, default, description}]",
    version          VARCHAR(20) NOT NULL DEFAULT '1.0',
    priority         ENUM('P0','P1','P2') NOT NULL DEFAULT 'P1' COMMENT "P0=blocking for formation, P1=recommended, P2=optional",
    notes_md         TEXT NULL COMMENT "Attorney notes, gotchas, filing instructions",
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dt_cat_jur (category, jurisdiction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Library of formation/compliance document templates (~60 docs)';

-- ============================================================
-- G. doc_renders — per-client rendered document instances
-- ============================================================

CREATE TABLE IF NOT EXISTS doc_renders (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id             INT UNSIGNED NOT NULL,
    intake_id             INT UNSIGNED NULL COMMENT "FK → empire_brand_intake.id (NULL = tenant-level doc)",
    template_id           INT UNSIGNED NOT NULL,
    variables_used_json   JSON NULL COMMENT "Snapshot of variable values used at render time",
    rendered_md           MEDIUMTEXT NULL COMMENT "Fully resolved markdown output",
    content_hash          CHAR(64) NULL COMMENT "SHA-256 of rendered_md for change detection",
    version_number        INT UNSIGNED NOT NULL DEFAULT 1,
    status                ENUM(
                             'draft','attorney_review','client_approved',
                             'filed','superseded'
                          ) NOT NULL DEFAULT 'draft',
    filed_at              DATETIME NULL,
    attorney_signed_at    DATETIME NULL,
    file_path_pdf         VARCHAR(255) NULL COMMENT "Relative path: storage/renders/{id}.pdf",
    file_path_docx        VARCHAR(255) NULL COMMENT "Relative path: storage/renders/{id}.docx",
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dr_tenant_intake (tenant_id, intake_id),
    INDEX idx_dr_template      (template_id),
    CONSTRAINT fk_dr_intake
        FOREIGN KEY (intake_id) REFERENCES empire_brand_intake(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_dr_template
        FOREIGN KEY (template_id) REFERENCES doc_templates(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-client rendered document instances (draft → filed lifecycle)';

-- ============================================================
-- H. law_changes — continuous compliance feed
-- ============================================================

CREATE TABLE IF NOT EXISTS law_changes (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source               ENUM(
                            'irs_bulletin','tax_court','state_sos','state_tax',
                            'state_trust_law','fincen','dol','uspto',
                            'scotus','industry_specific'
                         ) NOT NULL,
    jurisdiction         CHAR(2) NULL COMMENT "State code or NULL for federal",
    source_url           VARCHAR(500) NULL,
    title                VARCHAR(300) NOT NULL,
    summary_md           TEXT NULL COMMENT "Short human-readable summary",
    full_text_md         MEDIUMTEXT NULL COMMENT "Full scraped / pasted text",
    effective_date       DATE NULL,
    detected_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    classification_json  JSON NULL COMMENT "LLM output: {affected_playbooks[], severity, action_required, urgency_days}",
    processed            TINYINT(1) NOT NULL DEFAULT 0 COMMENT "1 = classification_json populated + amendment records created",
    INDEX idx_lc_source_det  (source, detected_at),
    INDEX idx_lc_jur_eff     (jurisdiction, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Continuous compliance change feed with LLM classification';

-- ============================================================
-- I. amendments — per-client triggered amendment workflow
-- ============================================================

CREATE TABLE IF NOT EXISTS amendments (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                   INT UNSIGNED NOT NULL,
    intake_id                   INT UNSIGNED NULL COMMENT "FK → empire_brand_intake.id (NULL = portfolio-level)",
    law_change_id               INT UNSIGNED NULL COMMENT "FK → law_changes.id (NULL = non-law trigger)",
    trigger_event_type          ENUM(
                                   'law_change','life_event','sale_offer',
                                   'asset_milestone','nexus_change','manual'
                                ) NOT NULL,
    trigger_description_md      TEXT NULL,
    affected_doc_render_ids_json JSON NULL COMMENT "JSON array of doc_renders.id values impacted",
    amendment_doc_render_id     INT UNSIGNED NULL COMMENT "FK → doc_renders.id for the amendment document itself",
    status                      ENUM(
                                   'detected','drafted','client_notified',
                                   'attorney_review','client_approved',
                                   'filed','dismissed'
                                ) NOT NULL DEFAULT 'detected',
    severity                    ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at                 DATETIME NULL,
    INDEX idx_am_tenant_status  (tenant_id, status),
    INDEX idx_am_severity       (severity, status),
    CONSTRAINT fk_am_intake
        FOREIGN KEY (intake_id) REFERENCES empire_brand_intake(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_am_law_change
        FOREIGN KEY (law_change_id) REFERENCES law_changes(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_am_doc_render
        FOREIGN KEY (amendment_doc_render_id) REFERENCES doc_renders(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-client triggered amendment workflow (law_change → filed)';

-- ============================================================
-- J. plaid_transactions — Plaid veil-audit ledger
-- ============================================================

CREATE TABLE IF NOT EXISTS plaid_transactions (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id             INT UNSIGNED NOT NULL,
    intake_id             INT UNSIGNED NOT NULL,
    plaid_account_id      VARCHAR(80) NOT NULL,
    plaid_transaction_id  VARCHAR(80) NOT NULL UNIQUE,
    txn_date              DATE NOT NULL,
    amount_usd            DECIMAL(12,2) NOT NULL COMMENT "Positive = debit, negative = credit",
    merchant_name         VARCHAR(200) NULL,
    category              VARCHAR(120) NULL COMMENT "Plaid category label",
    classification        ENUM(
                             'business','personal_flagged','intercompany',
                             'distribution','loan','reimbursable','unknown'
                          ) NOT NULL DEFAULT 'unknown',
    flag_reason           VARCHAR(200) NULL COMMENT "Short reason for personal_flagged classification",
    flag_severity         ENUM('none','soft','hard') NOT NULL DEFAULT 'none',
    resolved_at           DATETIME NULL COMMENT "When flag was cleared / reclassified",
    raw_json              JSON NULL COMMENT "Original Plaid transaction object",
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pt_tenant_intake_date (tenant_id, intake_id, txn_date),
    INDEX idx_pt_flag               (flag_severity, resolved_at),
    CONSTRAINT fk_pt_intake
        FOREIGN KEY (intake_id) REFERENCES empire_brand_intake(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Plaid transaction ledger for corporate veil-piercing audit';

-- ============================================================
-- K. industry_feeds — feed sources per industry vertical
-- ============================================================

CREATE TABLE IF NOT EXISTS industry_feeds (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vertical        ENUM(
                       'saas','healthcare','realestate','crypto',
                       'professional_services','manufacturing','retail',
                       'agency','ecommerce','other'
                    ) NOT NULL,
    source_name     VARCHAR(120) NOT NULL,
    source_url      VARCHAR(500) NOT NULL,
    feed_type       ENUM('rss','scraper','api','manual') NOT NULL DEFAULT 'rss',
    enabled         TINYINT(1) NOT NULL DEFAULT 1,
    last_polled_at  DATETIME NULL,
    notes_md        TEXT NULL,
    INDEX idx_if_vertical (vertical, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Feed source registry per industry vertical for law_changes ingestion';

-- ============================================================
-- POST-CHECK (comment out before prod apply — run manually)
-- ============================================================
/*
-- Verify ALTER columns exist on empire_brand_intake
SELECT COLUMN_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'empire_brand_intake'
  AND COLUMN_NAME IN (
    'annual_revenue_usd','aggression_tier','industry_vertical',
    'state_nexus_json','active_claims_count','lockup_tolerance_years',
    'built_in_gain_usd','sec382_limited','ptet_election_active'
  )
ORDER BY COLUMN_NAME;

-- Verify new tables exist
SELECT TABLE_NAME, TABLE_ROWS, CREATE_TIME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'empire_portfolio_context','compliance_calendar',
    'beneficial_owners','doc_templates','doc_renders',
    'law_changes','amendments','plaid_transactions','industry_feeds'
  )
ORDER BY TABLE_NAME;

-- Verify FK constraints
SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IN ('empire_brand_intake','law_changes','doc_renders','doc_templates')
ORDER BY TABLE_NAME, CONSTRAINT_NAME;
*/

-- Migration 077 complete.
