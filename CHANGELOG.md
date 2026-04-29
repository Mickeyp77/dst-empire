# Changelog

All notable changes to DST Empire OSS will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added — Phase A (Engine Foundation) ✓
- 12-playbook analysis engine (`src/Empire/Playbooks/`)
  - SCorpElectionPlaybook, QSBS1202Playbook, QBI199APlaybook, RDCredit41Playbook
  - IPCoSeparationPlaybook, CaptiveInsurance831bPlaybook, MgmtFeeTransferPricingPlaybook
  - ChargingOrderProtectionPlaybook, FLPValuationDiscountPlaybook, DAPTDomesticAssetPlaybook
  - CostSegregationPlaybook, Solo401kMaxPlaybook
- AbstractPlaybook contract + PlaybookRegistry
- Synthesis engine (`src/Empire/Synthesis/`)
  - PortfolioSynthesizer (whole-portfolio aggregation)
  - OrgChartBuilder (hierarchical node-graph data)
  - CashFlowModel (per-$1 trace + Sankey data)
  - TaxProjector (1/3/5/10yr scenarios w/ aggression-tier comparisons)
- BOI/CTA compliance module (`src/Empire/BOI/`)
  - FinCEN BOIR generator + validator + audit trail
  - 30-day deadline tracker w/ $500/day penalty exposure calc
- Document generation pipeline (`src/Empire/Docs/`)
  - TemplateRenderer w/ mustache-like substitution + conditionals + loops
  - PandocConverter (.md → .docx/.pdf)
  - CoverMemoGenerator
  - AttorneyPackageBuilder (cover memo + drafts + checklists + red flags)
  - SeedTemplateLoader (8 P0 public-domain templates)
- Compliance calendar engine (`src/Empire/Compliance/`)
  - 19 task types covered (annual reports, BOI updates, 83(b) anniversaries, etc.)
  - RecurrenceCalculator w/ 50-state schedule lookup
  - AlertDispatcher (TG + email queue + SMS)
- Law-change monitoring (`src/Empire/LawMonitor/`)
  - SourcePoller (IRS bulletin, Tax Court, FinCEN, SCOTUS, 10 state SOS)
  - LLM-based Classifier (local Ollama via hermes3-mythos)
  - PerClientImpact + AmendmentDrafter
- Plaid veil audit (`src/Empire/Plaid/`)
  - PlaidClient (free dev tier wrapper)
  - VeilAuditor w/ veil strength score 0-100
- Intake module (`src/Empire/Intake/`)
  - NarrativeParser (LLM extraction of structured fields from prose)
  - CompetitiveBenchmark (per-vertical leakage estimates)
  - ArchetypeMatcher (lifestyle/growth/exit-track scoring)

### Database schema
- 4 base tables (migrations 072-073): empire_brand_intake, empire_states, empire_advisor_log, empire_trust_thresholds
- Schema expansion (migration 077): 9 new tables + 40 added columns
  - empire_portfolio_context, compliance_calendar, beneficial_owners
  - doc_templates, doc_renders, law_changes, amendments
  - plaid_transactions, industry_feeds, boi_audit_log

### Documentation (Phase A)
- ARCHITECTURE.md: System design, module map, data flow, playbooks overview
- SCHEMA.md: All 15 tables, ER diagram, sample queries
- SELF_HOSTING.md: Production deployment, cron jobs, backups, security hardening
- EXAMPLES.md: 7 code recipes (playbooks, synthesis, rendering, BOI, custom playbooks, templates, Plaid)
- HOSTED_VS_OSS.md: Feature comparison table + decision tree
- 3 working example scripts:
  - examples/01_run_playbooks.php (12 playbooks on 1 brand)
  - examples/02_synthesize_portfolio.php (multi-brand portfolio aggregation)
  - examples/03_render_template.php (document template rendering)

### Developer experience
- PSR-4 autoload (`Mnmsos\Empire\` namespace)
- GitHub Actions CI (PHP 8.1 / 8.2 / 8.3 lint + PHPUnit)
- Code of Conduct (Contributor Covenant 2.1)
- Security policy w/ 90-day disclosure window
- MIT license w/ explicit "not legal advice" disclaimer

---

## [Planned] Phase B — Document Library & Attorney Package (8 weeks)

### In Development
- 60+ document templates (formation, IRS forms, trusts, intercompany, tax elections, compliance, estate, insurance)
- Multi-state coverage (TX, DE, WY, NV, SD, CA, NY, FL, NM, IL = ~600 variants)
- Attorney-ready package generator
- Client-side checklist + filing instructions
- DocuSign integration stubs
- IRS form auto-fill walkthroughs (SS-4, 2553, 8832, BOIR)
- State SOS form pre-population

### Expected: Q3 2026

---

## [Planned] Phase C — Continuous Compliance (12 weeks)

### In Development
- 50-state law-change monitor
- Amendment auto-drafting engine
- Multi-state nexus detector (sales tax, payroll tax, income tax)
- Industry-vertical feeds (7 verticals: SaaS, healthcare, real estate, crypto, professional services, manufacturing, retail)
- Recurring calendar alerts (email, SMS, Telegram integration)
- CPA/payroll/insurance API hooks (Drake, Gusto, Justworks, broker specs)
- Annual filing automation (30d pre-filing, auto-submit where available)
- $382 / §469 / §531 compliance recalc engine

### Expected: Q4 2026

---

## [Planned] Phase D — SaaS Polish & UI (8 weeks)

### In Development
- Onboarding wizard (narrative intake + competitive benchmark + archetype matching)
- Aggression slider UI (conservative / growth / aggressive playbook gating)
- Visual UX (5 Canvas 2D charts per CARL design system):
  - Entity map (hierarchical org chart, clickable nodes)
  - Tax leakage waterfall (shows $ saved by each playbook)
  - Risk heat map (audit likelihood × impact 3×3 matrix)
  - Implementation roadmap (Gantt-style timeline, critical path)
  - Sankey cash flow diagram (revenue → expenses → owner pocket)
- Pricing tiers + feature gating
- UPL disclaimers + attorney referral network integration
- Marketing site (dstempire.com landing page + docs)
- First 10 paying customers

### Expected: Q1 2027

---

## [Planned] Phase E — Verticals & Scale (ongoing from Q1 2027)

### In Development
- Industry specialization modules
  - Healthcare (HIPAA, Stark, Anti-Kickback, state licensing, Medicare/Medicaid)
  - Real estate (§1031, conservation easement, cost-seg, opportunity zones)
  - Crypto (Form 1099-DA, wash sale, staking, DeFi, money-transmitter state laws)
  - Manufacturing (OSHA, EPA, workers comp class codes, product liability)
  - Retail / e-commerce (50-state sales tax, marketplace facilitator, PCI-DSS)
- 50-state template library (all 50 states for all 8 doc categories)
- Estate plan deep integration (FLP, IDGT, GRAT, Dynasty, CLAT/CRUT, ILIT)
- LLM fine-tuning on tax case law + IRS rulings (hermes3-mythos via local Ollama)
- Partnership with attorney network (revenue share + white-label options)
- Open-source contributions back to this repo (playbook enhancements, feed integrations)

### Expected: Continuous from Q1 2027+

---

## How This Repo Fits the Roadmap

**Phase A (this release):** Core deterministic engine, OSS + auditable
**Phases B–E:** Proprietary enhancements stay on hosted SaaS (dstempire.com)

The open-source engine is the strategic moat. Proprietary = data curation (feeds, templates, network), not the code.
