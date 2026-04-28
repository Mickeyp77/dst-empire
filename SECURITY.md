# Security Policy

## Supported versions

The latest tagged release is supported. Older tags are best-effort only.

## Reporting a vulnerability

If you discover a security vulnerability in DST Empire, please report it privately:

- **Email**: security@dstempire.com (preferred — see DNS for verification)
- **Or**: open a private vulnerability advisory on GitHub (Security tab → Report a vulnerability)

Please do NOT open a public issue for security reports.

### What to include

- Description of the vulnerability
- Steps to reproduce
- Impact assessment
- Suggested fix (if you have one)
- Your name + how to credit you (or "anonymous")

### Response timeline

- **Acknowledgment**: within 72 hours
- **Initial assessment**: within 7 days
- **Fix or mitigation**: within 90 days for high/critical severity
- **Public disclosure**: coordinated with reporter, typically after fix is available

### Scope

In scope:
- The DST Empire engine (this repo)
- Documented integrations (Plaid client, Ollama LLM client, FinCEN BOIR generator)
- Migration files (SQL injection, privilege escalation)

Out of scope:
- Vulnerabilities in dependencies (report to those projects)
- Denial-of-service via expensive playbook computation (these are user-controlled)
- Issues requiring physical access to a self-hosted server
- Issues in the hosted SaaS at dstempire.com (separate scope)

### Recognition

We maintain a SECURITY-HALL-OF-FAME.md crediting reporters of valid issues (with permission).
