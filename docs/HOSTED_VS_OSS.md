# DST Empire — Hosted vs Open-Source Comparison

**Feature comparison table: free open-source engine vs hosted SaaS at dstempire.com**

---

## Feature Matrix

| Feature | Open-Source (This Repo) | Hosted SaaS (dstempire.com) |
|---------|---|---|
| **Core Engine** | | |
| 12 playbooks (S-Corp, QSBS, QBI, R&D, IP-Co, Captive, Mgmt Fee, Charging Order, FLP, DAPT, Cost Seg, Solo 401k) | ✓ MIT-licensed, run locally | ✓ Same engine |
| Portfolio synthesis (org chart, cash flow, tax projection) | ✓ Included | ✓ Included |
| Compliance calendar (19 task types) | ✓ Included | ✓ Enhanced with alerts |
| BOI / FinCEN filing module | ✓ Included | ✓ Included |
| LawMonitor stubs (feed polling, classification) | ✓ Bring your own feeds | ✓ Pre-wired feeds |
| Plaid veil audit stub | ✓ Bring your own credentials | ✓ Hosted integration |
| Document template rendering | ✓ Included (8 public-domain P0) | ✓ 60+ attorney-reviewed |
| **Configuration & Data** | | |
| Self-hosted database (MariaDB, MySQL) | ✓ Full control | ✗ Cloud-hosted (AWS/GCP) |
| Local Ollama integration (optional LLM) | ✓ Free hermes3-mythos:70b | ✓ Claude API (paid) |
| Premium document templates (jurisdiction-specific) | ✗ 8 seed templates only | ✓ 60+ × 50 states (~3000 variants) |
| Compliance data feeds (IRS, state SOS, FinCEN) | ✗ Stubs only; DIY sources | ✓ Pre-configured + updated daily |
| Playbook auto-update on law changes | ✗ Manual code updates | ✓ Automatic via amendments engine |
| Multi-state law monitoring (50 states) | ✗ DIY sources only | ✓ Pre-wired 50-state monitor |
| **UI & Visualization** | | |
| Playbook results (CLI output) | ✓ Text-based, JSON export | ✗ Not included |
| Interactive entity map (org chart visualization) | ✗ Not included | ✓ Canvas 2D, click-through nodes |
| Tax leakage waterfall chart | ✗ Not included | ✓ Canvas 2D, interactive |
| Risk heat map (audit risk × impact) | ✗ Not included | ✓ Canvas 2D with playbook drill-down |
| Implementation roadmap timeline | ✗ Not included | ✓ Milestone-based critical path |
| Sankey cash flow diagram | ✗ Text only | ✓ Canvas 2D interactive |
| Aggression slider UI | ✗ Not included | ✓ 3-tier playbook gating UX |
| **Services & Support** | | |
| Attorney referral network | ✗ Not included | ✓ Vetted network in 10 states |
| Attorney-ready package generator | ✓ Stubs only | ✓ Full package (cover memo + docs + checklists) |
| Document assembly & DocuSign integration | ✗ Not included | ✓ One-click eSignature workflow |
| 1:1 onboarding consultation | ✗ Not included | ✓ $500–2000 depending on tier |
| Quarterly compliance review | ✗ Not included | ✓ Strategic review + playbook updates |
| Integration consulting (Plaid, payroll, tax software) | ✗ Not included | ✓ Included for $2k+ tier |
| **Compliance & Legal** | | |
| Law-change alert emails | ✗ Manual monitoring | ✓ Weekly digest + immediate for critical |
| Amendment auto-draft on law changes | ✓ Stubs only | ✓ Auto-generated + client notification |
| Amendment filing tracking | ✗ Not included | ✓ Audit trail + deadline reminders |
| UPL (Unauthorized Practice of Law) disclaimers | ✓ Built-in, required | ✓ Built-in + attorney-review gates |
| Audit trail (changes, approvals, filings) | ✓ Database logs | ✓ Comprehensive with versioning |
| **Scaling & Pricing** | | |
| Free tier | ✓ MIT license | ✓ Free (limited: 1 brand, basic playbooks) |
| Pay-per-use | ✗ N/A | ✗ Not available |
| Subscription tiers | ✗ N/A | ✓ Conservative ($99/mo), Growth ($499/mo), Aggressive ($2k+/mo) |
| Professional services packaging | ✗ Not included | ✓ $5–50k engagement for complex structures |
| Reseller / agency program | ✗ Not included | ✓ White-label + revenue share available |
| **Data & Privacy** | | |
| Data exported as JSON/CSV | ✓ Full control | ✓ Downloadable anytime |
| Delete all data anytime | ✓ Your database | ✓ 30-day hold, then purged |
| Third-party integrations (Slack, Zapier) | ✗ Not included | ✓ Webhooks + basic integrations |
| HIPAA compliance | ✗ Not applicable | ✓ Available (signed BAA) |
| SOC 2 Type II | ✗ Not applicable | ✓ Annual audit |
| GDPR Data Processing Agreement | ✗ Not applicable | ✓ Standard DPA included |

---

## When to Use Open-Source

**Best for:** Developers, agencies, in-house tax teams, DIY founders.

✓ You want full control over data (self-hosted database)
✓ You want to audit the evaluation logic (MIT source)
✓ You want to integrate into your own platform
✓ You have existing Plaid / Ollama / law-feed infrastructure
✓ You want to customize playbooks for your market
✓ You have an attorney on staff (no UPL risk from missing disclaimers)

### Typical Workflow (Open-Source)

1. Clone this repo
2. Stand up MariaDB + PHP 8.3
3. Load migrations (2 min)
4. Write intake data to `empire_brand_intake` table
5. Call `PlaybookRegistry::runAllSorted()` (deterministic, zero cost)
6. Get back JSON: `{playbook results}`
7. Your team manually renders docs, coordinates attorney, files

**Cost:** $0 (+ your time)
**Effort:** 40–80 hours for first setup; 10–20 hours per new brand

---

## When to Use Hosted SaaS

**Best for:** Businesses under 100 entities, founders <$10M portfolio, compliance-averse teams.

✓ You want visual UI (org charts, risk maps, timelines)
✓ You want premium templates (60+ attorney-reviewed across 50 states)
✓ You want law-change monitoring (FinCEN, IRS, 50 state SOS pre-wired)
✓ You want amendment auto-drafting on law changes
✓ You want attorney referral network + vetting
✓ You want compliance calendar alerts (email + SMS + Telegram)
✓ You want integration with Plaid + DocuSign + tax software
✓ You want quarterly strategic reviews
✓ You want full audit trail (changes, approvals, filings)

### Typical Workflow (Hosted SaaS)

1. Sign up at dstempire.com
2. Intake questionnaire (15–30 min per brand)
3. View playbook results + visualizations instantly
4. Select aggression tier (conservative / growth / aggressive)
5. Download attorney-ready package (7 docs pre-filled)
6. System schedules quarterly compliance review
7. System alerts on law changes affecting your structure
8. Auto-draft amendments, click "approve" → attorney notified
9. Attorney signs, documents filed

**Cost:** Free (1 brand) → $99/mo (Conservative) → $499/mo (Growth) → $2k+/mo (Aggressive custom)
**Effort:** 1–2 hours onboarding; system runs on its own

---

## Hybrid Approach (Recommended for Agencies)

**Use open-source internally + host a customer-facing SaaS on top.**

1. Fork this repo (or contribute back)
2. Customize playbooks for your vertical (healthcare, real estate, crypto, etc.)
3. Pre-wire your own compliance feeds (state bar updates, industry changes)
4. Deploy on your infrastructure (AWS, GCP, Azure, on-prem)
5. White-label for your clients OR integrate into your existing platform
6. Charge subscription or per-entity fees (higher margin than reselling dstempire.com)

**Example:** An EA (Enrolled Agent) firm deploying DST Empire on AWS, customizing playbooks for real estate investors, charging $199/month per client.

---

## Code Ownership & License

**Open-source (this repo):** MIT License
- You own the code
- You can modify it
- You can redistribute it (as long as you include license)
- You can use for commercial purposes
- No attribution required (but appreciated)

**Hosted SaaS:** Proprietary (dstempire.com)
- Templates, feeds, UI, attorney network are proprietary
- Data belongs to client (deletable anytime)
- API only (no source access)
- Service Level Agreement (99.5% uptime SLA)

---

## Roadmap Alignment

**Open-Source** (this repo):
- Phase A (done): 12 playbooks, synthesis, BOI, compliance calendar
- Phase B (queued): Doc templates (8 → 60+), attorney package builder
- Phase C (queued): Law monitor, amendment drafter, multi-state nexus
- Phase D (queued): UX (entity map, waterfall, heat map), aggression slider, pricing tiers
- Phase E (ongoing): Industry verticals, LLM fine-tuning, API hooks

**Hosted SaaS** (dstempire.com):
- Phases B–E are built + operational
- Always 1–2 phases ahead of open-source
- Automatic law-change updates (no client code deploy needed)
- UI polished, no CLI required
- Attorney network + vetting operational

---

## Support & Community

**Open-Source:**
- GitHub Issues for bug reports / feature requests
- Community PRs welcome
- 90-day security disclosure window
- No paid support (volunteer-driven)

**Hosted SaaS:**
- Email support (24-hour response target)
- Chat support for $2k+/mo tier
- Quarterly strategic reviews
- Priority updates for critical law changes

---

## Cost Examples

### Scenario: 10-entity holding company, owner age 48, 5-year exit timeline

**Open-Source Path:**
- Server: $5–20/mo (MariaDB hosting)
- Development time: 60 hours setup + 5 hours/entity = 110 hours
- Lawyer review: 20 hours @ $400/hr = $8,000
- **Total cost:** ~$10,000 + your labor + ongoing maintenance

**Hosted SaaS Path:**
- Tier: Growth ($499/mo) × 12 = $5,988/year
- Lawyer review: 10 hours @ $400/hr = $4,000 (less because package is pre-vetted)
- Quarterly reviews: 4 × 0.5 hr × $400 = $800
- Amendment auto-drafts save ~20 hours/year = $8,000
- **Total cost:** ~$19,000/year (but includes ongoing compliance + alerts)

**Net:** SaaS is ~$10k higher year 1, but saves labor + risk long-term.

---

## Quick Decision Tree

```
Do you have attorney on staff + DBA admin?
  → Yes: Open-source (control + audit log)
  → No: Hosted SaaS (risk-free, UI helps)

Budget for tool alone: <$2k/year?
  → Yes: Open-source or free tier SaaS
  → No: Growth/Aggressive tier SaaS (ROI >5x)

Need 50-state law monitoring?
  → Yes: Hosted SaaS (DIY = $500k+ for data feeds)
  → No: Open-source + free RSS feeds (IRS, some states)

Portfolio size: <10 entities?
  → Yes: Hosted SaaS (simpler, lower risk)
  → No: Open-source (scale locally, avoid SaaS pricing)

Time horizon: need structure TODAY vs. build over time?
  → Today: Hosted SaaS (instant UI + templates)
  → Over time: Open-source (learn + customize)
```

---

## References

- **Open-Source Repo:** [github.com/Mickeyp77/dst-empire](https://github.com/Mickeyp77/dst-empire)
- **Hosted SaaS:** [dstempire.com](https://dstempire.com)
- [docs/ARCHITECTURE.md](ARCHITECTURE.md) — System design
- [docs/SELF_HOSTING.md](SELF_HOSTING.md) — Self-host guide
- [CONTRIBUTING.md](../CONTRIBUTING.md) — Open-source development
