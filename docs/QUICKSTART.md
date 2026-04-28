# Quickstart — 5-minute self-host

## Prerequisites

- PHP 8.1+
- MySQL 5.7+ or MariaDB 10.3+
- Composer
- (Optional) Pandoc — for .docx/.pdf document rendering
- (Optional) Ollama + hermes3-mythos:70b — for narrative intake parsing

## Steps

```bash
# 1. Clone
git clone https://github.com/Mickeyp77/dst-empire.git
cd dst-empire

# 2. Install
composer install

# 3. Database
mysql -u root -p -e "CREATE DATABASE dstempire CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p dstempire < migrations/001_brand_intake.sql
mysql -u root -p dstempire < migrations/002_chat_log.sql
mysql -u root -p dstempire < migrations/003_schema_expansion.sql

# 4. Configure
cp .env.example .env
# Edit .env with your DB creds

# 5. Run example
php examples/01_run_playbooks.php
```

## Expected output

```
SCorpElectionPlaybook: applies=true, savings_y1=$15,234
QSBS1202Playbook: applies=false (requires C-Corp + 5yr hold)
QBI199APlaybook: applies=true, savings_y1=$8,442
RDCredit41Playbook: applies=true, savings_y1=$5,200
...
Total Y1 savings: $51,237.71
```

## Next steps

- Read [ARCHITECTURE.md](ARCHITECTURE.md) for system overview
- Read [PLAYBOOKS.md](PLAYBOOKS.md) for the 12 included playbooks
- Read [SCHEMA.md](SCHEMA.md) for DB layout
- Read [SELF_HOSTING.md](SELF_HOSTING.md) for production deployment

## Want the curated templates + law monitor + attorney network?

Sign up at [dstempire.com](https://dstempire.com).
