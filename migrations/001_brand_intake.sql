-- Migration 072: DST Empire — Brand Entity-Structure Intake
-- Adds the Empire Builder data layer used by /empire/ pages.
-- Pre-seeds the 24 MNMS House-of-Brands as draft formation_entities rows
-- (tenant_id=1=MNMS) and creates a per-brand intake/decision record.
-- Designed to be run AFTER migration 070 (formation_module).
-- MariaDB 10.11+ / 11.x. Idempotent (IF NOT EXISTS / INSERT IGNORE).

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- =============================================================
-- 072.1 empire_states — multi-state arbitrage reference matrix
-- One row per US state DST recommends. Used to generate side-by-side
-- jurisdiction comparison cards in /empire/arbitrage.php.
-- =============================================================
CREATE TABLE IF NOT EXISTS empire_states (
    code                       CHAR(2) PRIMARY KEY,
    name                       VARCHAR(40) NOT NULL,
    formation_fee              DECIMAL(8,2) NOT NULL DEFAULT 0,
    annual_fee                 DECIMAL(8,2) NOT NULL DEFAULT 0,
    franchise_tax              VARCHAR(120) NULL,
    state_income_tax           VARCHAR(60) NULL,
    anonymity_score            TINYINT NOT NULL DEFAULT 0,   -- 0..10 (10 = members never public)
    charging_order_score       TINYINT NOT NULL DEFAULT 0,   -- 0..10 (10 = exclusive remedy by statute)
    dynasty_trust_score        TINYINT NOT NULL DEFAULT 0,   -- 0..10 (10 = no rule against perpetuities)
    dapt_score                 TINYINT NOT NULL DEFAULT 0,   -- 0..10 (10 = strong DAPT statute)
    series_llc_supported       TINYINT(1) NOT NULL DEFAULT 0,
    case_law_score             TINYINT NOT NULL DEFAULT 0,   -- 0..10 (DE = 10)
    vc_friendly_score          TINYINT NOT NULL DEFAULT 0,
    notes_md                   TEXT NULL,
    INDEX idx_anon (anonymity_score),
    INDEX idx_charging (charging_order_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO empire_states (code,name,formation_fee,annual_fee,franchise_tax,state_income_tax,anonymity_score,charging_order_score,dynasty_trust_score,dapt_score,series_llc_supported,case_law_score,vc_friendly_score,notes_md) VALUES
('TX','Texas',300.00,0.00,'PIR + franchise tax (no-tax-due threshold $2.65M 2026)','None',2,6,0,0,1,5,4,'Home jurisdiction. Series LLC supported. No state income tax. PIR required even below threshold.'),
('WY','Wyoming',100.00,60.00,'None','None',9,9,7,8,0,4,3,'Anonymous member rolls. Strong charging-order. Cheapest ongoing. Not VC-friendly. WY DAPT statute since 2007.'),
('DE','Delaware',90.00,300.00,'$300 flat franchise tax','None for non-DE income',5,7,0,5,1,10,10,'Chancery Court case law gold standard. VC default. Delaware Series LLC since 1996. Not strong on DAPT/Dynasty.'),
('NV','Nevada',425.00,350.00,'None','None',8,9,4,9,1,5,4,'Strongest DAPT statute. No state income tax. State business license $200 + officer list $150 annual. Anonymity good but pierced more often than WY.'),
('SD','South Dakota',150.00,50.00,'None','None',7,8,10,9,0,5,3,'Dynasty Trust kingdom — no rule against perpetuities, perpetual trusts. Strong DAPT. Cheap. Not as well known.'),
('NM','New Mexico',50.00,0.00,'None','5.9%',9,5,0,0,0,3,2,'Cheapest formation. True anonymity. No annual report. State income tax applies if NM-based ops.'),
('FL','Florida',125.00,138.75,'None for LLC','None',3,5,0,0,0,5,5,'Common alternative to TX. No state income tax. Annual report required.');

-- =============================================================
-- 072.2 empire_brand_intake — per-brand DST decision record
-- One row per brand. Captures the answers to the §6 questions in
-- docs/architecture/dstempire_entity_intake_2026-04-28.md.
-- The actual entity gets created in formation_entities once locked.
-- =============================================================
CREATE TABLE IF NOT EXISTS empire_brand_intake (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                INT UNSIGNED NOT NULL,
    brand_slug               VARCHAR(80) NOT NULL,                -- e.g. 'voltops', 'dfwprinter'
    brand_name               VARCHAR(120) NOT NULL,
    domain                   VARCHAR(120) NULL,
    tier                     ENUM('T1','T2','T3','T4','T5') NOT NULL DEFAULT 'T4',
    -- current state
    current_status           ENUM('dba_only','filed','operating','dormant','sold') NOT NULL DEFAULT 'dba_only',
    current_legal_owner      VARCHAR(120) NULL DEFAULT 'MNMS LLC',
    -- revenue/liability profile (captured from intake doc)
    revenue_profile          VARCHAR(120) NULL,
    liability_profile        ENUM('low','low_med','medium','med_high','high') NOT NULL DEFAULT 'low',
    -- Mickey decisions (locked in by tomorrow's session)
    decided_jurisdiction     CHAR(2) NULL,                        -- empire_states.code
    decided_entity_type      ENUM('keep_dba','sole_prop','llc','series_llc_cell','s_corp','c_corp','nonprofit','trust','shell','pllc','lp','llp') NULL,
    decided_parent_kind      ENUM('mnms_llc','holdco_llc','dapt','dynasty_trust','bridge_trust','mickey_personal','mixed') NULL,
    decided_parent_entity_id INT UNSIGNED NULL,                   -- formation_entities.id of parent if internal
    decided_trust_wrapper    ENUM('none','dapt_nv','dapt_sd','dapt_wy','dynasty_sd','bridge_trust') NULL DEFAULT 'none',
    decided_sale_horizon     ENUM('never','5y_plus','3y','1y','active_sale') NULL DEFAULT NULL,
    -- DST advisor scratchpad (markdown, accumulates over conversation)
    advisor_notes_md         MEDIUMTEXT NULL,
    -- decision lifecycle
    decision_status          ENUM('not_started','in_review','locked','superseded') NOT NULL DEFAULT 'not_started',
    decision_locked_at       DATETIME NULL,
    decision_locked_by       INT UNSIGNED NULL,
    spawned_entity_id        INT UNSIGNED NULL,                   -- formation_entities.id once created
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_tenant_brand (tenant_id, brand_slug),
    INDEX idx_tenant_status (tenant_id, decision_status),
    INDEX idx_tenant_tier (tenant_id, tier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 072.3 empire_advisor_log — DST AI advisor conversation log
-- One row per turn. Used by /empire/advisor.php for resumable
-- multi-turn brand-by-brand DST sessions backed by ARIA hermes3-mythos.
-- =============================================================
CREATE TABLE IF NOT EXISTS empire_advisor_log (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    INT UNSIGNED NOT NULL,
    intake_id    INT UNSIGNED NULL,                               -- empire_brand_intake.id (NULL = portfolio-level chat)
    role         ENUM('user','advisor','system') NOT NULL,
    body_md      MEDIUMTEXT NOT NULL,
    model        VARCHAR(80) NULL,                                -- 'hermes3-mythos:70b' etc.
    tokens_in    INT NULL,
    tokens_out   INT NULL,
    cost_usd     DECIMAL(8,4) NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_intake (intake_id, created_at),
    INDEX idx_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 072.4 empire_trust_thresholds — DAPT/Dynasty/Bridge trigger rules
-- Used by src/Empire/TrustBuilder to surface "should you wrap this in a trust?"
-- =============================================================
CREATE TABLE IF NOT EXISTS empire_trust_thresholds (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trust_kind           ENUM('dapt_nv','dapt_sd','dapt_wy','dynasty_sd','bridge_trust') NOT NULL,
    min_assets_usd       DECIMAL(12,2) NOT NULL DEFAULT 0,
    annual_cost_low_usd  DECIMAL(8,2) NOT NULL DEFAULT 0,
    annual_cost_high_usd DECIMAL(8,2) NOT NULL DEFAULT 0,
    setup_cost_low_usd   DECIMAL(8,2) NOT NULL DEFAULT 0,
    setup_cost_high_usd  DECIMAL(8,2) NOT NULL DEFAULT 0,
    when_to_consider_md  TEXT NULL,
    notes_md             TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO empire_trust_thresholds (trust_kind,min_assets_usd,annual_cost_low_usd,annual_cost_high_usd,setup_cost_low_usd,setup_cost_high_usd,when_to_consider_md,notes_md) VALUES
('dapt_nv',500000.00,1500.00,5000.00,3000.00,8000.00,'**Consider when:** any single brand crosses ~$500k of equity/IP value, OR any active liability profile (vehicles + techs + on-customer-site work).','NV DAPT 2-year statute of limitations on fraudulent transfer claim. Strongest DAPT in US. Requires NV trustee.'),
('dapt_sd',500000.00,1500.00,4500.00,3000.00,7500.00,'**Consider when:** combined with Dynasty Trust use. Cheaper trustees than NV.','SD has 2-year statute. Stack with Dynasty for multi-generational play.'),
('dapt_wy',300000.00,800.00,2500.00,2000.00,5000.00,'**Consider when:** smaller asset base + already using WY for entity formation. Cheapest DAPT option.','WY DAPT since 2007. 4-year SOL. Cheapest trustees.'),
('dynasty_sd',1000000.00,2500.00,8000.00,5000.00,15000.00,'**Consider when:** any liquidity event projected $1M+, OR multi-generational wealth transfer goal. Especially for IP-heavy brands (VoltOps, mickeys.ai).','SD has no rule against perpetuities — trust can last forever. Pairs with intentionally defective grantor trust (IDGT) for estate freeze.'),
('bridge_trust',2000000.00,5000.00,15000.00,15000.00,40000.00,'**Consider when:** active threat profile OR international optionality desired. Sits dormant in US, decants to offshore (Cook Islands, Nevis) only on duress.','Hybrid US/offshore. Optionality without immediate IRS/FBAR overhead. Most expensive option.');

-- =============================================================
-- 072.5 Pre-seed: 24 MNMS brands as draft formation_entities + intake rows
-- Source: docs/architecture/dstempire_entity_intake_2026-04-28.md §3
-- All under tenant_id=1 (MNMS). Status=draft until tomorrow's session locks.
-- =============================================================

INSERT IGNORE INTO formation_entities (tenant_id, legal_name, dba_name, entity_type, formation_state, status, industry, purpose, notes) VALUES
(1,'MNMS LLC',NULL,'s_corp','TX','active','Holding / SaaS','Holding company; S-Corp election active 10+ years.','Parent of all 24 brands. DO NOT recommend Form 2553 — already elected.'),
(1,'VoltOps (pending)','VoltOps','c_corp','DE','draft','SaaS','Multi-tenant SaaS platform for managed print services.','Candidate for separate DE C-Corp for VC fundability path.'),
(1,'DFW Printer (pending)','DFW Printer','llc','TX','draft','MPS / Service','Active managed print service operations book.','Med liability — vehicles, techs on-customer-site.'),
(1,'PrintIt (pending)','PrintIt','llc','TX','draft','E-commerce parts','Printer/copier parts e-commerce.','Consolidates printit.tech + canonparts.com (canonparts is SEO-only, brand text never says Canon).'),
(1,'Used Copier Parts (pending)','Used Copier Parts','series_llc_cell','TX','draft','E-commerce parts','Used/refurb copier parts.','Series LLC cell candidate under PrintIt parent.'),
(1,'Used Printer Sales (pending)','Used Printer Sales','series_llc_cell','TX','draft','E-commerce refurb','Refurbished printer sales.','Series LLC cell candidate under PrintIt parent.'),
(1,'Copier Rentals AI (pending)','Copier Rentals','llc','TX','draft','Rental','Copier rental subscription.','Asset-holding LLC; sale-priority MED-HIGH.'),
(1,'Printer Rentals AI (pending)','Printer Rentals','llc','TX','draft','Rental','Printer rental subscription.','Asset-holding LLC.'),
(1,'DFW Copier Repair (pending)','DFW Copier Repair','sole_prop','TX','draft','Service','Service tail brand.','Tier-4 SEO long-tail; DBA candidate.'),
(1,'DFW Printer Repair AI (pending)','DFW Printer Repair','sole_prop','TX','draft','Service','Service tail brand.','Tier-4 SEO long-tail; DBA candidate.'),
(1,'DFW Printer Service (pending)','DFW Printer Service','sole_prop','TX','draft','Service','Service tail brand.','Tier-4 SEO long-tail; DBA candidate.'),
(1,'DFW Printer Tech (pending)','DFW Printer Tech','sole_prop','TX','draft','Service','Service tail brand.','Tier-4 SEO long-tail; DBA candidate.'),
(1,'DFW Printer (.tech) (pending)','DFW Printer (.tech)','sole_prop','TX','draft','Service','Service tail brand.','Tier-4 SEO long-tail; DBA candidate.'),
(1,'Dallas Printer Repair (pending)','Dallas Printer Repair','sole_prop','TX','draft','Service','Service tail brand.','Tier-4 SEO long-tail; DBA candidate.'),
(1,'Canon Specialist Service (pending)','Canon Specialist Service','llc','TX','draft','Niche service','Canon specialist service brand.','TM-RISK on "Canon" — rename or trademark-clean before formation.'),
(1,'Copier Press (pending)','Copier Press','sole_prop','TX','draft','Content / ad','Industry content + ad rev.','DBA or content LLC. Low priority.'),
(1,'PayPerPrint (pending)','PayPerPrint','llc','TX','draft','Subscription print','Subscription print contracts.','Med liability — multi-year contracts.'),
(1,'FreePrinter (pending)','FreePrinter','llc','TX','draft','Lead-gen / hardware','Subsidized hardware lead-gen.','Med liability — contract entity.'),
(1,'Urgent Printer Service (pending)','Urgent Printer Service','sole_prop','TX','draft','Premium service','Premium urgent service tail.','DBA or LLC.'),
(1,'Printer Doc (pending)','Printer Doc','sole_prop','TX','draft','Content / SEO','Content / SEO long-tail.','Fold under MNMS LLC; never separate.'),
(1,'MNMS AI (pending)','MNMS AI','sole_prop','TX','draft','Internal tooling','Internal AI tooling.','Stay under MNMS LLC; never separate.'),
(1,'OfficeSolutions AI (pending)','OfficeSolutions AI','llc','TX','draft','Consulting','AI office solutions consulting.','Sep LLC w/ E&O insurance — advice liability.'),
(1,'Mickey Prasad (pending)','Mickey Prasad','sole_prop','TX','draft','Personal brand','Mickey personal brand.','Personal / pass-through; never separate entity.'),
(1,'PrintIt Parts (canonparts) (pending)','PrintIt Parts','series_llc_cell','TX','draft','E-commerce','PrintIt Parts store on canonparts.com domain.','Fold under PrintIt LLC. Domain is SEO-only; brand text never says Canon (TM risk).');

INSERT IGNORE INTO empire_brand_intake (tenant_id, brand_slug, brand_name, domain, tier, current_status, current_legal_owner, revenue_profile, liability_profile, advisor_notes_md) VALUES
(1,'mnms','MNMS Group','mnmsllc.com','T1','operating','MNMS LLC','HoldCo / parent','low','HoldCo. NEVER spawn separate. Already S-Corp 10+ years.'),
(1,'voltops','VoltOps','voltops.net','T1','operating','MNMS LLC','SaaS, scaling','medium','**Strong candidate for separate DE C-Corp** for VC fundability. IP and code base cleanly separable. Sale-priority HIGH.'),
(1,'dfwprinter','DFW Printer','dfwprinter.com','T2','dba_only','MNMS LLC','Service rev (MPS book)','med_high','**Active ops + vehicles + techs = highest liability.** Strong case for separate LLC + DAPT wrapper as MPS book grows.'),
(1,'printit','PrintIt','printit.tech','T2','dba_only','MNMS LLC','E-comm parts','low_med','Consolidates with canonparts.com (SEO-only). LLC TX or WY (anonymity).'),
(1,'usedcopierparts','Used Copier Parts','usedcopierparts.com','T3','dba_only','MNMS LLC','E-comm parts','low','Series LLC sub-cell of PrintIt parent — single TX filing covers both.'),
(1,'usedprintersales','Used Printer Sales','usedprintersales.com','T3','dba_only','MNMS LLC','E-comm refurb','low_med','Series LLC sub-cell of PrintIt parent.'),
(1,'copierrentalsai','Copier Rentals','copierrentals.ai','T3','dba_only','MNMS LLC','Recurring rental','medium','Asset-holding LLC. Charging-order protection valuable — WY/NV candidate.'),
(1,'printerrentalsai','Printer Rentals','printerrentals.ai','T3','dba_only','MNMS LLC','Recurring rental','low_med','Asset-holding LLC.'),
(1,'dfwcopierrepair','DFW Copier Repair','dfwcopierrepair.com','T4','dba_only','MNMS LLC','Service tail','low','DBA candidate. Cost-of-formation likely > value of separation.'),
(1,'dfwprinterrepairai','DFW Printer Repair','dfwprinterrepair.ai','T4','dba_only','MNMS LLC','Service tail','low','DBA candidate.'),
(1,'dfwprinterservice','DFW Printer Service','dfwprinterservice.com','T4','dba_only','MNMS LLC','Service tail','low','DBA candidate.'),
(1,'dfwprintertech','DFW Printer Tech','dfwprintertech.com','T4','dba_only','MNMS LLC','Service tail','low','DBA candidate.'),
(1,'dfwprinterdottech','DFW Printer (.tech)','dfwprinter.tech','T4','dba_only','MNMS LLC','Service tail','low','DBA candidate.'),
(1,'dallasprinterrepair','Dallas Printer Repair','dallasprinterrepair.org','T4','dba_only','MNMS LLC','Service tail','low','DBA candidate.'),
(1,'canonservice','Canon Specialist Service','canonservice.org','T4','dba_only','MNMS LLC','Niche service','low','**TM RISK** — Canon® is registered trademark. Rename to "Authorized Service Specialists" or similar before forming.'),
(1,'copierpress','Copier Press','copier.press','T4','dba_only','MNMS LLC','Content / ad rev','low','DBA or content LLC.'),
(1,'payperprint','PayPerPrint','payperprint.org','T4','dba_only','MNMS LLC','Subscription print','medium','Multi-year contracts → contract liability. Sep LLC.'),
(1,'freeprinter','FreePrinter','freeprinter.org','T4','dba_only','MNMS LLC','Lead-gen / subsidized hw','medium','Hardware contract entity. Sep LLC.'),
(1,'urgentprinterservice','Urgent Printer Service','urgentprinterservice.com','T4','dba_only','MNMS LLC','Premium service tail','low_med','DBA or LLC.'),
(1,'printerdoc','Printer Doc','printer-doc.com','T4','dba_only','MNMS LLC','Content / SEO','low','Fold under MNMS LLC; do not separate.'),
(1,'mnmsai','MNMS AI','mnmsllc.ai','T5','operating','MNMS LLC','Internal tooling','low','Internal — stay under MNMS LLC.'),
(1,'officesolutionsai','OfficeSolutions AI','officesolutions.ai','T5','operating','MNMS LLC','Consulting','medium','Advice liability → sep LLC w/ E&O insurance.'),
(1,'mickeysai','Mickey Prasad','mickeys.ai','T5','operating','MNMS LLC','Personal brand','low','Personal pass-through; never a separate entity.'),
(1,'canonparts','PrintIt Parts (canonparts SEO)','canonparts.com','T2','dba_only','MNMS LLC','E-comm','low_med','Fold under PrintIt LLC. Domain is SEO-only — brand TEXT never says Canon (TM risk).');

-- =============================================================
-- 072.6 Indexes / FK linkage refinements
-- (Add FK from empire_brand_intake.spawned_entity_id → formation_entities.id
--  but only after both tables exist; SET NULL on delete to keep history.)
-- =============================================================
ALTER TABLE empire_brand_intake
  ADD CONSTRAINT fk_ebi_spawned_entity
  FOREIGN KEY (spawned_entity_id) REFERENCES formation_entities(id) ON DELETE SET NULL;

ALTER TABLE empire_advisor_log
  ADD CONSTRAINT fk_eal_intake
  FOREIGN KEY (intake_id) REFERENCES empire_brand_intake(id) ON DELETE CASCADE;

-- =============================================================
-- Migration 072 complete.
-- =============================================================
