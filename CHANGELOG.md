# Changelog

All notable changes to DST Empire OSS will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added — Phase A (Engine Foundation)
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
- 4 base tables (migrations 001-002): empire_brand_intake, empire_states, empire_advisor_log, empire_trust_thresholds
- Schema expansion (migration 003): 9 new tables + 40 added columns
  - empire_portfolio_context, compliance_calendar, beneficial_owners
  - doc_templates, doc_renders, law_changes, amendments
  - plaid_transactions, industry_feeds, boi_audit_log

### Developer experience
- PSR-4 autoload (`Mnmsos\Empire\` namespace)
- GitHub Actions CI (PHP 8.1 / 8.2 / 8.3 lint + PHPUnit)
- Code of Conduct (Contributor Covenant 2.1)
- Security policy w/ 90-day disclosure window
- MIT license w/ explicit "not legal advice" disclaimer
