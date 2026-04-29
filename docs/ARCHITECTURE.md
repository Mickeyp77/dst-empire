# DST Empire — Architecture & Design

**Last updated:** 2026-04-28

---

## Overview

DST Empire is a **portfolio analysis engine** for growing businesses. It evaluates your entity structure, tax optimization opportunities, asset protection strategies, and compliance obligations — then synthesizes a whole-portfolio plan.

### Three-Stage Pipeline

```
[INTAKE]     ──────►     [EVALUATE]     ──────►     [STRUCTURE]
Gather data              Run playbooks             Output plan
```

1. **INTAKE** — Ingest financial, liability, asset, sale, tax, and estate context
2. **EVALUATE** — Run 12+ codified playbooks (rule-based + LLM-augmented) against the data
3. **STRUCTURE** — Produce org chart, cash flow diagram, tax projections, compliance calendar, attorney-ready package

---

## Module Map

### Core Engine (`src/Empire/`)

| Module | Purpose | Key Classes |
|--------|---------|-------------|
| **Playbooks/** | 12 tax/structuring playbooks | `AbstractPlaybook`, `PlaybookRegistry`, `SCorpElectionPlaybook`, `QSBS1202Playbook`, `QBI199APlaybook`, `RDCredit41Playbook`, `IPCoSeparationPlaybook`, `CaptiveInsurance831bPlaybook`, `MgmtFeeTransferPricingPlaybook`, `ChargingOrderProtectionPlaybook`, `FLPValuationDiscountPlaybook`, `DAPTDomesticAssetPlaybook`, `CostSegregationPlaybook`, `Solo401kMaxPlaybook` |
| **Synthesis/** | Portfolio aggregation & visualization | `PortfolioSynthesizer`, `OrgChartBuilder`, `CashFlowModel`, `TaxProjector` |
| **BOI/** | FinCEN BOI filing module | `Filer` |
| **Compliance/** | Recurring deadline engine | `CalendarEngine`, `RecurrenceCalculator`, `AlertDispatcher` |
| **LawMonitor/** | Continuous compliance monitoring | `SourcePoller`, `Classifier`, `PerClientImpact`, `AmendmentDrafter`, `Ingester` |
| **Docs/** | Document generation pipeline | `TemplateRenderer`, `PandocConverter`, `CoverMemoGenerator`, `AttorneyPackageBuilder`, `SeedTemplateLoader` |
| **Plaid/** | Bank account audit module | `PlaidClient`, `AccountLinker`, `TransactionFetcher`, `VeilAuditor` |
| **Intake/** | Intake parsing + benchmarking | `NarrativeParser`, `CompetitiveBenchmark`, `ArchetypeMatcher` |
| **Supporting** | Helpers | `BrandPlacement`, `StateMatrix`, `IntakeRepo`, `Advisor`, `TrustBuilder` |

---

## Data Flow Diagram

```
┌─────────────────────────────────────────────────────────────┐
│ INTAKE PHASE                                                │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  empire_brand_intake (9→65 fields)                         │
│  + empire_portfolio_context (owner/domicile/estate)        │
│  + beneficial_owners (BOI registry)                        │
│  + plaid_transactions (veil audit feed)                    │
│                                                             │
│  ↓ Parsed by NarrativeParser + ArchetypeMatcher           │
│  ↓ CompetitiveBenchmark scores                             │
│                                                             │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│ EVALUATE PHASE (PlaybookRegistry)                           │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Per intake record:                                        │
│  └─ Run all 12 playbooks (filtered by aggression_tier)   │
│     ├─ SCorpElectionPlaybook       → savings calc         │
│     ├─ QSBS1202Playbook            → exclusion analysis  │
│     ├─ QBI199APlaybook             → deduction pct       │
│     ├─ RDCredit41Playbook          → credit projection   │
│     ├─ IPCoSeparationPlaybook      → licensing model     │
│     ├─ CaptiveInsurance831bPlaybook→ premium calc        │
│     ├─ MgmtFeeTransferPricingPlaybook→ fee structure     │
│     ├─ ChargingOrderProtectionPlaybook→ jurisdiction     │
│     ├─ FLPValuationDiscountPlaybook → gift discount      │
│     ├─ DAPTDomesticAssetPlaybook   → trust cost/benefit  │
│     ├─ CostSegregationPlaybook     → real estate depr    │
│     └─ Solo401kMaxPlaybook         → retirement shelter  │
│                                                             │
│  Each playbook outputs:                                    │
│  {applies, estimated_savings_y1, risk_level,             │
│   multi_year_impact, playbook_name, recommendation}      │
│                                                             │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│ SYNTHESIZE PHASE (PortfolioSynthesizer)                    │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Aggregate across all brands:                              │
│  ├─ OrgChartBuilder → entity hierarchy + ownership %      │
│  ├─ CashFlowModel  → per-$1 flow trace + Sankey data     │
│  ├─ TaxProjector   → Y1/Y3/Y5/Y10 scenarios              │
│  └─ ComplianceCalendar → recurring tasks (19 types)       │
│                                                             │
│  Outputs:                                                   │
│  ├─ org_chart (JSON: nodes + edges)                       │
│  ├─ cash_flow (JSON: Sankey structure)                    │
│  ├─ tax_projection (JSON: per-year results)               │
│  └─ compliance_tasks (compliance_calendar rows)           │
│                                                             │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│ DOCUMENT GENERATION (AttorneyPackageBuilder)               │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  doc_templates (60+ templates × 50 states)                │
│  ├─ Formation docs (LLC Articles, Corp Bylaws, etc.)      │
│  ├─ IRS forms (SS-4, 2553, 8832, BOIR, etc.)             │
│  ├─ Trust docs (DAPT, Dynasty, IDGT, GRAT, etc.)        │
│  ├─ Intercompany (IP License, Mgmt Fee, Lease, etc.)     │
│  └─ Tax elections (§83(b), §1202 attestation, etc.)      │
│                                                             │
│  TemplateRenderer fills variables from intake              │
│  PandocConverter outputs .docx + .pdf                      │
│  AttorneyPackageBuilder assembles cover memo + package     │
│                                                             │
│  Output: doc_renders (draft → attorney_review → filed)     │
│                                                             │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│ CONTINUOUS COMPLIANCE (LawMonitor)                          │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  SourcePoller polls daily:                                 │
│  ├─ IRS Bulletin (RR, RP, PLR, TIGTA)                     │
│  ├─ Tax Court decisions                                    │
│  ├─ 50-state SOS rule changes                             │
│  ├─ 50-state trust law updates                            │
│  ├─ FinCEN, DOL, USPTO updates                            │
│  └─ Industry-vertical feeds (per industry_feeds table)    │
│                                                             │
│  Classifier (LLM) → law_changes.classification_json        │
│  PerClientImpact finds affected clients                    │
│  AmendmentDrafter generates amendment doc_renders          │
│  AlertDispatcher notifies client                           │
│  amendments table tracks trigger → filed lifecycle         │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## Database Schema Overview

See [docs/SCHEMA.md](SCHEMA.md) for full reference.

**Core tables:**

| Table | Purpose | Rows per tenant |
|-------|---------|-----------------|
| `empire_states` | State matrix (WY/DE/NV/SD/TX/etc.) | ~7 (static) |
| `empire_brand_intake` | Per-brand DST decision record | 1–100 brands |
| `empire_advisor_log` | AI advisor conversation turns | varies (append-only) |
| `empire_trust_thresholds` | DAPT/Dynasty/Bridge trigger rules | ~5 (static) |
| `empire_portfolio_context` | Owner estate/domicile context | 1 per tenant |
| `compliance_calendar` | Recurring compliance tasks | 20–500 per tenant |
| `beneficial_owners` | FinCEN BOI registry | 1–10 per brand |
| `doc_templates` | Formation/compliance templates | ~60 shared |
| `doc_renders` | Per-client rendered docs | varies per project |
| `law_changes` | Continuous compliance feed | 10–100/day (ingested) |
| `amendments` | Amendment workflow tracking | varies (on law changes) |
| `plaid_transactions` | Veil audit ledger | 50–5000/brand/mo |
| `industry_feeds` | Feed source registry | ~10 |
| `boi_audit_log` | BOI filing audit trail | 1–10 per entity |

**Foreign key relationships:**

```
empire_brand_intake
  ├─ FK → formation_entities.id (spawned_entity_id)
  ├─ FK ← empire_advisor_log.intake_id
  ├─ FK ← compliance_calendar.intake_id
  ├─ FK ← beneficial_owners.intake_id
  ├─ FK ← doc_renders.intake_id
  ├─ FK ← amendments.intake_id
  └─ FK ← plaid_transactions.intake_id

doc_templates
  └─ FK ← doc_renders.template_id

law_changes
  └─ FK ← amendments.law_change_id

doc_renders
  └─ FK ← amendments.amendment_doc_render_id
```

---

## LLM Integration

### Local Ollama (hermes3-mythos:70b)

The **Advisor.php** class wraps playbook results for optional narrative augmentation:

```php
use Mnmsos\Empire\Advisor;

$advisor = new Advisor();
$results = $advisor->narrativeExplain(
    intake: $intake,
    playbook_results: $results,
    model: 'hermes3-mythos:70b'  // local Ollama
);
```

**Integration points:**

1. **Narrative intake extraction** — `NarrativeParser` (optional LLM inference)
2. **Per-playbook commentary** — `Advisor::narrativeExplain()` (optional)
3. **Law-change classification** — `Classifier::classify()` for `law_changes.classification_json`
4. **Amendment drafting narrative** — `AmendmentDrafter::draftNarrative()`

**Vault management:** Credentials (Ollama endpoint, API keys) managed via `src/Core/VaultClient.php` from main VoltOps codebase.

**Optional:** Engine is 100% deterministic without LLM. LLM is bolt-on for narrative polish only.

---

## Hosted-Only Features

The **free OSS engine** does NOT include:

| Feature | Why | Hosted at |
|---------|-----|-----------|
| **Premium document templates** | Attorney-reviewed, jurisdiction-specific. Curated asset. | dstempire.com |
| **Compliance data feeds** | Source URLs + API credentials are proprietary/licensed. | dstempire.com |
| **Attorney referral network** | Vetting + relationships require service. | dstempire.com |
| **Multi-tenant SaaS UI** | Hosted on VoltOps codebase (separate). | voltops.net/empire |
| **Aggression slider UI** | Demo in `examples/`, production on SaaS. | dstempire.com |
| **Visual charts (5 Canvas 2D)** | Demo in `examples/`, production on SaaS. | dstempire.com |

The OSS engine is the **rule-based evaluation core**. Moat = data curation + UX + service.

---

## Playbooks at a Glance

### Phase A (Shipping)

| # | Playbook | Benefit | IRC Section | Risk |
|---|----------|---------|------------|------|
| 1 | S-Corp Election | Reduce SE tax 15.3% on distributions | §1361 | Low (common) |
| 2 | §1202 QSBS | $10M gain tax-free (per trust) | §1202 | Med (5yr hold + active test) |
| 3 | §199A QBI | 20% passthrough deduction | §199A | Low (routine) |
| 4 | R&D Credit §41 | 6.5–14% credit on qualified expenses | §41 | Med (time-tracking discipline) |
| 5 | IP-Co Separation | Royalty deduction + asset protection | §482 + § 954 | Med (transfer pricing audit) |
| 6 | Captive Insurance §831(b) | Micro-captive for self-insurance | §831(b) | High (abusive-cap enforcement) |
| 7 | Mgmt Fee Transfer Pricing | Shift profit to passthrough from C-Corp | §482 | Low (arm's-length cost-plus) |
| 8 | Charging-Order Protection | LLC charging-order shield (state-dependent) | State UCC | Low (varies by jurisdiction) |
| 9 | FLP Valuation Discount | Gift LP units at 20–30% discount | §2701, §2704 | Med (IRS challenge on discount %) |
| 10 | DAPT | Asset protection trust (WY/NV/SD) | State law (§4–15yr SOL) | Med (fraudulent-transfer risk if creditor exists) |
| 11 | Cost Segregation | Real estate → 5/7/15yr property classes | §168(i)(6) | Low (IRS-published position) |
| 12 | Solo 401(k) Max | $69k/spouse × 2 spouses = $138k shelter | §401(k) | Low (annual limits) |

---

## State Formation Matrix (empire_states)

Pre-seeded with 7 states. Key metrics:

```
TX (Texas)     — formation $300, no annual fee, PIR required, Series LLC ✓
WY (Wyoming)   — formation $100, $60/yr, anonymous, DAPT strong, cheapest
DE (Delaware)  — formation $90, $300 franchise tax, Chancery Court, VC gold
NV (Nevada)    — formation $425, $350/yr, DAPT + anonymity, business license $200
SD (South Dakota) — formation $150, $50/yr, Dynasty Trust (perpetual), DAPT strong
NM (New Mexico) — formation $50 (cheapest!), no annual, true anonymity, no state income tax
FL (Florida)   — formation $125, $138.75/yr, alternative to TX, no state income
```

Scores per state: anonymity, charging-order strength, dynasty-trust recognition, DAPT statutory language, series-LLC support, Chancery-court case law, VC-friendliness.

---

## Compliance Calendar (19 Task Types)

```
annual_report       — Form 211 / SOS annual filing
franchise_tax       — TX, DE, etc. per-state
federal_tax         — 1040, 1120-S, 1120 due dates
state_tax           — Individual state filing
license_renewal     — Industry-specific (contractor, CPA, etc.)
trust_admin         — Trust accounting due
83b_anniversary     — Track §1202 5-year clock
1202_clock          — §1202 active-business test anniversary
dapt_seasoning      — DAPT statute of limitations anniversary
1031_clock          — §1031 like-kind exchange window
199a_recalc         — Annual §199A QBI re-evaluation
531_recheck         — Accumulated earnings watch (C-Corps)
ptet_election       — Pass-through entity tax state deadlines
insurance_renewal   — E&O, GL, D&O, cyber
tm_renewal          — USPTO trademark renewal
boi_update          — FinCEN BOI update (30d post change)
captive_filing      — Micro-captive annual insurance form
fbar                — Foreign bank account reporting
crummey_letter      — Annual ILIT contribution withdrawal rights
```

---

## Build Roadmap

### Phase A (Complete) — Engine Foundation (12 weeks)
- 12 playbooks + synthesis engine
- BOI compliance module
- Basic doc templates (8 P0 public-domain)
- Compliance calendar
- LawMonitor stubs

### Phase B (Queued) — Attorney Package (8 weeks)
- 60+ templates × 10-state library
- Attorney-ready package generator
- Client checklist + filing instructions
- DocuSign integration stubs
- IRS form walkthroughs

### Phase C (Queued) — Continuous Compliance (12 weeks)
- 50-state law-change monitor
- Amendment auto-drafting
- Multi-state nexus detector
- Industry vertical feeds
- Recurring calendar alerts

### Phase D (Queued) — SaaS Polish (8 weeks)
- Onboarding wizard (narrative + competitive intake)
- Aggression slider UI
- Visual charts (5 Canvas 2D per CARL design system)
- Pricing tiers + disclaimers
- Attorney referral network
- dstempire.com marketing site

### Phase E (Ongoing) — Verticals & Scale
- Industry specialization (SaaS, healthcare, real estate, crypto, etc.)
- 50-state coverage (remaining 40 states)
- Estate-plan deep integration
- CPA/payroll/insurance API hooks
- LLM fine-tuning on case law

---

## Key Design Decisions

### 1. Deterministic by Default
Playbooks are pure rule-based (no LLM required). Advisor is optional narrative wrapper.

**Rationale:** Deterministic results are auditable, repeatable, and cost-free to compute.

### 2. Multi-Tenant Isolation
`tenant_id` on every table. No cross-tenant data leakage.

**Rationale:** GDPR; B2C SaaS requirement; MCN LLC owns MNMS data only.

### 3. State & Industry Vertical as 1st-Class Concepts
`empire_states` matrix + `industry_feeds` allow pluggable rule sets per jurisdiction.

**Rationale:** US tax law is hyperlocal. Scaling to 50 states requires abstraction.

### 4. Aggression Tier Gates Playbooks
`empire_brand_intake.aggression_tier` (conservative / growth / aggressive) filters which playbooks apply.

**Rationale:** Client risk tolerance is binding constraint on structure complexity.

### 5. Doc Templates as Parameterized Markdown
`doc_templates.template_md` uses Jinja-like `{{variable}}` syntax.

**Rationale:** Markdown is version-controllable. Easy for attorneys to edit. Pandoc → .docx/.pdf.

### 6. Law-Change Monitoring is Async & Opt-In
`law_changes` table appended daily by cron. Clients filter per `industry_vertical`.

**Rationale:** Not all law changes affect all clients. Reduce alert fatigue.

### 7. Compliance Calendar as Recurring Task Engine
19 task types. Recurrence rules per state (annual reports have different due dates).

**Rationale:** Compliance is the moat; tracking it manually is error-prone.

---

## Future Extensibility

### Adding a New Playbook

1. Create `src/Empire/Playbooks/NewPlaybook.php` extending `AbstractPlaybook`
2. Implement `applies(array $intake): bool`
3. Implement `analyze(array $intake): array` returning `{estimated_savings_y1_usd, risk_level, multi_year_impact, ...}`
4. Register in `PlaybookRegistry::getInstance()->register(new NewPlaybook())`
5. Add IRC section + testing to `docs/PLAYBOOKS.md`

### Adding a New State

1. Insert row into `empire_states` with jurisdictional scores
2. Add state-specific templates to `doc_templates` if not in existing variant
3. Update `RecurrenceCalculator::getDueDate()` with state annual-report due date

### Adding Industry-Specific Feeds

1. Insert rows into `industry_feeds` with RSS/scraper URLs
2. `SourcePoller` auto-discovers feeds via vertical filter
3. Newly ingested `law_changes` are classified w/ per-vertical impact

---

## Testing & Validation

### Unit Tests (PHPUnit)

```bash
vendor/bin/phpunit tests/ --coverage-html coverage/
```

Coverage targets:
- PlaybookRegistry: all 12 playbooks tested w/ fixture intakes
- TaxProjector: multi-year scenarios vs spreadsheet benchmarks
- TemplateRenderer: variable substitution + conditional blocks
- Compliance calendar: due-date calculation per state
- BOI validator: FinCEN field constraints

### Integration Tests

- Full portfolio synthesis (intake → playbooks → org chart → cash flow)
- Document rendering pipeline (template → filled vars → Pandoc → PDF)
- Law-change classification (feed ingestion → client impact → amendment draft)

### Manual Smoke Tests

```php
php examples/01_run_playbooks.php    # Run 12 playbooks
php examples/02_synthesize_portfolio.php  # Whole-portfolio aggregation
php examples/03_render_template.php  # Doc rendering
```

---

## Performance & Scaling Notes

- **PlaybookRegistry::runAllSorted()** is O(12 × brands). Typical: <100ms for 100 brands.
- **PortfolioSynthesizer** aggregates in memory. For 1000+ brands, batch process.
- **LawMonitor SourcePoller** runs async (cron daily). Non-blocking.
- **Plaid TransactionFetcher** paginated (100 txns/call). Cache Plaid responses for 1hr.

---

## References

- [docs/SCHEMA.md](SCHEMA.md) — Database schema details
- [docs/PLAYBOOKS.md](PLAYBOOKS.md) — All 12 playbooks with IRC citations
- [docs/EXAMPLES.md](EXAMPLES.md) — Code recipes
- [docs/SELF_HOSTING.md](SELF_HOSTING.md) — Production deployment
- [docs/HOSTED_VS_OSS.md](HOSTED_VS_OSS.md) — Feature comparison
