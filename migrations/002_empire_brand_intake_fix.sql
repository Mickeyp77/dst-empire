-- =============================================================
-- Migration 073 — DST Empire brand intake — add missing brands
-- =============================================================
-- 072 seeded 24 rows but missed canonimagepress.com and printerlabs.ai.
-- Both are live self-hosted brand sites and need to participate in
-- the DST advisor (jurisdiction selection, trust placement, etc.).
--
-- Idempotent: INSERT IGNORE (UNIQUE on tenant_id,brand_slug from 072).
-- =============================================================

INSERT IGNORE INTO empire_brand_intake
    (tenant_id, brand_slug, brand_name, domain, tier, current_status, current_legal_owner, revenue_profile, liability_profile, advisor_notes_md)
VALUES
(1,'canonimagepress','imagePRESS Specialist','canonimagepress.com','T4','dba_only','MNMS LLC','Niche service / production press','low_med','Specialty imagePRESS production-print service. **TM RISK** — `imagePRESS` is a Canon® mark; brand text uses "imagePRESS Specialist" as descriptive nominative use only. Fold under DFW Printer LLC or hold as DBA.'),
(1,'printerlabs','PrinterLabs','printerlabs.ai','T5','operating','MNMS LLC','R&D / IP / API platform','low','**IP-creation entity.** Mail pilot domain. Strong case for separate LLC to ring-fence patents / models / API IP. WY recommended (anonymity + IP holding). Sale-priority MED — eventually packageable as a tech asset.');

-- Companion formation_entities placeholders so DST advisor has spawn targets ready.
INSERT IGNORE INTO formation_entities
    (tenant_id, legal_name, dba_name, entity_type, formation_state, status, notes)
VALUES
(1,'imagePRESS Specialist (pending)','imagePRESS Specialist','llc','TX','draft','Specialty service','Production press service brand. Fold under DFW Printer LLC or DBA — decide via DST advisor.'),
(1,'PrinterLabs (pending)','PrinterLabs','llc','WY','draft','R&D / IP holding','Tech/IP holding entity. Wyoming recommended for anonymity + IP protection.');
