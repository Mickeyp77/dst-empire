# Contributing to DST Empire

Thank you for contributing. DST Empire is MIT-licensed and community contributions are welcome.

## Code of Conduct

This project follows the [Contributor Covenant 2.1](CODE_OF_CONDUCT.md). By participating you agree to abide by its terms.

## What We Welcome

- Bug fixes in playbook math or logic
- New playbook implementations (see AbstractPlaybook contract)
- Schema migrations for new compliance features
- Documentation improvements
- Test coverage
- Translations of docs

## What Belongs in the Hosted Tier, Not Here

- Premium document templates (Word/PDF with jurisdiction-specific legal language)
- Compliance feed source URLs or API credentials
- Attorney network data
- LLM prompt chains

If you are unsure whether a contribution belongs in OSS vs hosted, open an issue first.

## Contribution Flow

1. Fork the repo
2. Create a feature branch: `git checkout -b feat/my-playbook`
3. Make changes; run `composer lint` and `composer test` locally
4. Commit with DCO sign-off (see below)
5. Open a pull request against `main`
6. A maintainer will review within 7 days

## Implementing a New Playbook

1. Create `src/Empire/Playbooks/MyNewPlaybook.php`
2. Extend `AbstractPlaybook`
3. Implement all abstract methods: `getId()`, `getName()`, `getCodeSection()`, `getAggressionTier()`, `getCategory()`, `applies()`, `evaluate()`
4. The `evaluate()` return array must include all 14 keys defined in `AbstractPlaybook` docblock
5. No LLM calls inside playbooks — deterministic rule logic only
6. Add a smoke test in `tests/PlaybookSmokeTest.php`
7. Register the playbook in `PlaybookRegistry::__construct()`
8. Add an entry to `docs/PLAYBOOKS.md`

## Coding Style

- PHP 8.1+ features welcome (`readonly`, enums, first-class callables)
- PSR-4 autoloading, namespace `Mnmsos\Empire\`
- Strict types (`declare(strict_types=1);`) on new files
- No external dependencies in the engine core — use only PHP stdlib + PDO
- Prepared statements for all DB queries
- All dollar amounts in USD as `float`; round to 2dp at output boundaries

## Testing

```bash
composer install
composer test
```

Tests live in `tests/`. PHPUnit 10+.

## DCO — Developer Certificate of Origin

By contributing you certify the [Developer Certificate of Origin](https://developercertificate.org/) (version 1.1). Add a sign-off line to every commit:

```
git commit -s -m "feat: add §1031 exchange playbook"
```

This adds `Signed-off-by: Your Name <your@email.com>` to the commit message.

## Security Vulnerabilities

See [SECURITY.md](SECURITY.md). Do not open public issues for security bugs.
