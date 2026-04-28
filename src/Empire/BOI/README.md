# FinCEN BOI Filing Module

## The Rule

Corporate Transparency Act (CTA) — effective 2024-01-01.

- **New entities** (formed on/after 2024-01-01): file BOIR within **30 days** of formation.
- **Existing entities** (formed before 2024-01-01): deadline was **2025-01-01** — if unfiled, overdue now.
- **Any change** to beneficial owner info (new owner, address change, doc expiry): re-file within **30 days**.
- **Penalty**: $500/day per missed or late filing + up to $10,000 criminal fine + 2 years imprisonment.

## FinCEN BOIR Schema

FinCEN publishes the official XML schema + sample JSON at:

- Schema/XSD: `https://www.fincen.gov/beneficial-ownership-information-reporting-xml-schema`
- E-filing portal: `https://boiefiling.fincen.gov/`
- FAQs: `https://www.fincen.gov/boi-faqs`

This module generates JSON payloads that mirror the schema structure:
`filingInfo` → `reportingCompany` → `beneficialOwners[]` → `companyApplicants[]` (post-2024 only).

## Who is a Beneficial Owner?

A person qualifies as a beneficial owner if they meet **either** test:

1. **25% ownership** — directly or indirectly owns or controls 25%+ of the entity's ownership interests.
2. **Substantial control** — exercises substantial control over the entity (e.g., senior officer, director with authority, anyone who directs/decides major decisions).

Every reporting company must identify **all** people who meet either test. There is no cap on the number.

## Company Applicant

A company applicant is the person who **directly filed** the formation documents (e.g., the person who filed the Articles of Organization with the SOS).

- **Post-2024 entities**: company applicant info REQUIRED in the BOIR.
- **Pre-2024 entities**: company applicant info NOT required — grandfather rule. `Filer.php` skips the `companyApplicants` block automatically for pre-2024 entities.

There can be up to 2 company applicants: the direct filer and the person who directed the filing.

## Exempt Entities (23 categories — no BOIR required)

Key exemptions relevant to MNMS:

1. **Large operating company** — 20+ full-time US employees, $5M+ in US gross receipts/sales (prior year tax return), physical office in the US.
2. **Publicly traded company** — listed on a US national securities exchange.
3. **SEC-reporting company** — registered under Securities Exchange Act §12 or files reports under §15(d).
4. **Bank / credit union / insurance company** — federally regulated.
5. **Inactive entity** — formed before 2020-01-01, not actively doing business, no assets, no ownership changes in prior 12 months, not foreign-owned. (Very narrow — confirm with attorney.)
6. **Subsidiaries of exempt entities** — owned or controlled entirely by one or more exempt entities.

Full list of all 23 at: `https://www.fincen.gov/boi-faqs#C_1`

For MNMS Phase A (24 brands forming in 2025-2026): none are expected to qualify for exemption — all are new small LLCs/corps below $5M revenue. File for all of them.

## Workflow

```
1. Form entity (SOS filing)
       ↓
2. DST Empire: open /empire/boi.php → select entity → add beneficial owners
       ↓
3. Click "Download BOIR JSON" — review payload
       ↓
4. File manually at https://boiefiling.fincen.gov/
   (Upload the JSON or use the web form — both accepted)
       ↓
5. Copy FinCEN confirmation number
       ↓
6. DST Empire → "Mark Filed" → enter confirmation number → save
       ↓
7. Done. Track future owner changes — each triggers a new 30-day clock.
```

## Re-filing Triggers

Must re-file (update report) within 30 days of:
- Any new beneficial owner gaining 25%+ or substantial control
- Any beneficial owner's name, DOB, address, or ID doc changing
- Any beneficial owner losing qualifying interest
- The entity's own legal name or address changing
- The entity changing its EIN

## UI

`/empire/boi.php` — see it at `https://voltops.net/empire/boi.php`

## Code

- Generator: `src/Empire/BOI/Filer.php` — namespace `Mnmsos\Empire\BOI\Filer`
- DB table: `beneficial_owners` — created in migration 076
- Audit log: `boi_audit_log` — created in migration 076 (same migration)
- `Filer::prepareReport()` — validates + builds JSON payload, never throws
- `Filer::markFiled()` — records FinCEN confirmation hash + date
- `Filer::daysUntilDue()` — negative = overdue
- `Filer::listOverdue()` / `listPending()` / `listAll()` — dashboard queries

## Decisions Made in This Module

| Decision | Reasoning |
|---|---|
| No auto-submit | FinCEN free portal is sufficient; paid batch API is $X/filing; manual review prevents errors |
| Pre-2024 grandfather | FinCEN explicitly exempts pre-2024 entities from company applicant requirement |
| Address as free text | `residential_address_md` is a single text field; Filer parses "Street, City, ST ZIP" pattern. Mig 077 should split this into structured columns for cleaner FinCEN compliance |
| EIN required | FinCEN requires a tax ID; SSN allowed for sole props but EIN preferred for LLCs |
| fincen_id shortcut | If an owner already has a FinCEN ID (from a prior filing), most personal info fields are optional per FinCEN rules — Filer sends a short block |
