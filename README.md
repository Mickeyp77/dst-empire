# DST Empire — Portfolio Analysis Engine

**Free and open-source engine for entity formation, tax structuring, and continuous compliance.**

The hosted version — with curated premium templates, live law monitoring feeds, and an attorney referral network — is at **[dstempire.com](https://dstempire.com)**.

---

## Why This Exists

Tax strategy for growing businesses is expensive, opaque, and gatekept. A solo founder or small holding company often can't afford the $15k–40k advisory engagement needed to model S-Corp elections, §199A QBI deductions, DAPT asset protection, cost segregation, IP holding structures, and dynasty trust integrations across a multi-brand portfolio — let alone keep all of it continuously compliant.

DST Empire is the open-core engine that powers `dstempire.com`. The rule-based evaluation logic is MIT-licensed and auditable by anyone. The moat is curated: premium document templates, vetted compliance data feeds, and the attorney network stay proprietary — that is how we fund development and keep the open engine excellent.

---

## Quick Example

```php
<?php
require 'vendor/autoload.php';

use Mnmsos\Empire\Playbooks\PlaybookRegistry;

$registry = PlaybookRegistry::getInstance();

// Minimal intake record — see docs/EXAMPLES.md for full shape
$intake = [
    'brand_slug'        => 'acme_co',
    'entity_type'       => 'sole_prop',
    'annual_revenue'    => 480000,
    'ebitda'            => 120000,
    'industry_vertical' => 'professional_services',
    'employees'         => 3,
    'aggression_tier'   => 'growth',
    'w2_wages_paid'     => 55000,
    'equipment_value_usd' => 25000,
];

$portfolioCtx = [
    'aggression_tier'  => 'growth',
    'owner_age'        => 42,
    'owner_domicile_state' => 'TX',
];

$results = $registry->runAllSorted($intake, $portfolioCtx);

foreach ($results as $pb) {
    if ($pb['applies']) {
        printf(
            "%-35s  Y1 savings: $%s  risk: %s\n",
            $pb['name'],
            number_format($pb['estimated_savings_y1_usd']),
            $pb['risk_level']
        );
    }
}
```

---

## Features

### Open-source engine (this repo, MIT)

| Feature | Description |
|---|---|
| **12 tax/structuring playbooks** | §199A QBI, S-Corp election, §1202 QSBS, R&D §41 credit, IP-Co separation, captive insurance §831(b), mgmt fee transfer pricing, charging-order protection, FLP valuation discount, DAPT domestic asset protection, cost segregation, Solo 401(k) |
| **Portfolio Synthesizer** | Aggregates all playbook results into org chart + cash flow waterfall + 5-year tax projection |
| **State Matrix** | WY / DE / NV / SD / TX formation cost + franchise tax + charging-order strength comparison |
| **BOI Filing Helper** | FinCEN Corporate Transparency Act beneficial owner tracker |
| **Compliance Calendar** | Recurring deadline engine (annual reports, BOI updates, §83(b) deadlines) |
| **LawMonitor stubs** | Source polling + classification + per-client impact framework (bring your own feeds) |
| **Intake Parser** | Narrative → structured intake; archetype matcher; competitive benchmark stubs |
| **Document Rendering** | Template renderer + Pandoc converter + cover memo generator + attorney package builder stubs |
| **Plaid Client stub** | Account linking framework (bring your own Plaid credentials) |
| **Deterministic only** | Zero LLM calls in the engine. Advisor.php wraps results for optional LLM narrative. |

### Hosted SaaS only — [dstempire.com](https://dstempire.com)

| Feature | Why it's not here |
|---|---|
| Premium document templates | Attorney-reviewed, jurisdiction-specific — curated asset |
| Compliance data feeds | Source URLs + API credentials are licensed / proprietary |
| Attorney referral network | Vetting + relationships are the service |
| LLM-augmented advisor narrative | Anthropic/Claude API costs money |
| Multi-tenant hosted UI | VoltOps SaaS platform (separate codebase) |

---

## Install

```bash
composer require mnmsllc/dst-empire
```

Or clone and install locally:

```bash
git clone https://github.com/mnmsllc/dst-empire.git
cd dst-empire
composer install
```

---

## Self-Host

See [docs/SELF_HOSTING.md](docs/SELF_HOSTING.md) for full production deployment instructions.

Quick version:

1. PHP 8.1+, MariaDB 10.6+ (or MySQL 8+)
2. `composer install`
3. Run migrations: `mysql -u root your_db < migrations/001_empire_brand_intake.sql` (repeat for 002–004)
4. Configure your PDO connection
5. Instantiate `PlaybookRegistry`, pass an intake array, call `runAll()`

Full 5-minute quickstart: [docs/QUICKSTART.md](docs/QUICKSTART.md)

---

## Documentation

| Doc | Contents |
|---|---|
| [docs/QUICKSTART.md](docs/QUICKSTART.md) | 5-minute self-host guide |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | System design and component map |
| [docs/PLAYBOOKS.md](docs/PLAYBOOKS.md) | All 12 playbooks with IRC citations |
| [docs/SCHEMA.md](docs/SCHEMA.md) | Database schema reference |
| [docs/SELF_HOSTING.md](docs/SELF_HOSTING.md) | Production deployment |
| [docs/HOSTED_VS_OSS.md](docs/HOSTED_VS_OSS.md) | Open-source vs hosted feature comparison |
| [docs/EXAMPLES.md](docs/EXAMPLES.md) | Code examples |

---

## License

MIT — see [LICENSE](LICENSE).

Commercial use, redistribution, and modification are explicitly permitted. Attribution appreciated but not required.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Bug reports and new playbook implementations are especially welcome.
