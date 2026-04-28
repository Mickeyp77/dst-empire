# Playbook Catalog (OSS Tier)

The OSS engine ships with 12 deterministic rule-based playbooks. Each evaluates a single tax/structuring strategy against an intake row + portfolio context. All playbooks return:

- `applies` (bool)
- `applicability_score` (0-100)
- `estimated_savings_y1_usd` / `_5y_usd`
- `estimated_setup_cost_usd` / `_ongoing_cost_usd`
- `risk_level` (low/medium/high)
- `audit_visibility` (low/medium/high)
- `prerequisites_md` / `rationale_md` / `gotchas_md`
- `citations` (array of IRC sections + caselaw)
- `docs_required` (array of template slugs)
- `next_actions` (array of human-readable steps)

## Playbooks

| # | Playbook | IRC Section | Tier | Category |
|---|---|---|---|---|
| 1 | SCorpElectionPlaybook | §1361/§1362 | Conservative | Tax |
| 2 | QSBS1202Playbook | §1202 | Aggressive | Tax |
| 3 | QBI199APlaybook | §199A | Conservative | Tax |
| 4 | RDCredit41Playbook | §41 + §174 | Growth | Tax |
| 5 | IPCoSeparationPlaybook | §482 + asset protection | Aggressive | Liability |
| 6 | CaptiveInsurance831bPlaybook | §831(b) | Aggressive | Liability |
| 7 | MgmtFeeTransferPricingPlaybook | §482 cost-plus | Growth | Intercompany |
| 8 | ChargingOrderProtectionPlaybook | state law (WY/NV/SD) | Growth | Liability |
| 9 | FLPValuationDiscountPlaybook | §2031 + Tax Court precedent | Aggressive | Estate |
| 10 | DAPTDomesticAssetPlaybook | state DAPT statutes | Aggressive | Liability |
| 11 | CostSegregationPlaybook | §168 + ATGs | Growth | Tax |
| 12 | Solo401kMaxPlaybook | §401(k) + §415 | Conservative | Tax |

## Adding a new playbook

1. Extend `Mnmsos\Empire\Playbooks\AbstractPlaybook`
2. Implement: `getId()`, `getName()`, `getCodeSection()`, `getAggressionTier()`, `getCategory()`, `applies()`, `evaluate()`
3. Register in `PlaybookRegistry`
4. Add tests in `tests/Playbooks/`
5. Submit PR with citations + risk assessment

## What's NOT in OSS (paid tier features)

- 25+ additional playbooks (industry-specific, niche tax strategies)
- Captive insurance program design (§831(b) detailed structuring)
- Bridge Trust offshore decant playbook
- §1031 like-kind exchange tracking
- Opportunity Zone reinvestment playbook
- §1202 stacking via grantor trust laddering (vs simple §1202 in OSS)
- F-Reorg pre-sale conversion playbook
- Up-C structure for PE-track companies
- §199A wage limit optimization across multi-entity portfolios
- PTET annual election state-by-state optimizer

These are at [dstempire.com](https://dstempire.com).
