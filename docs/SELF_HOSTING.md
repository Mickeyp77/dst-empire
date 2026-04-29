# DST Empire — Self-Hosting Guide

**Production deployment for DST Empire OSS.**

---

## System Requirements

### Minimum

- **PHP 8.1+** (recommend 8.3 for performance)
- **MariaDB 10.6+** or **MySQL 8.0+**
- **Composer** for dependency management
- **4 GB RAM**, 20 GB storage
- **Linux** (Ubuntu 20.04+, Debian 11+, RHEL 8+) or macOS

### Recommended

- **PHP 8.3** with OPcache enabled
- **MariaDB 11.x** with Galera (for HA)
- **8+ GB RAM**, 100 GB SSD storage
- **Redis** for session/cache (optional but recommended)
- **Pandoc** for document rendering (.md → .docx/.pdf)
- **Ollama** locally for LLM-augmented advisor (optional)

---

## Installation

### 1. Clone Repository

```bash
git clone https://github.com/Mickeyp77/dst-empire.git
cd dst-empire
```

### 2. Install Dependencies

```bash
composer install
```

This installs:
- `Twig` for template rendering
- `PHPUnit` for testing
- `Symfony VarDumper` for debugging
- Additional dependencies per `composer.json`

### 3. Database Setup

#### Create Database

```bash
mysql -u root -p
```

```sql
CREATE DATABASE dst_empire CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dst_user'@'localhost' IDENTIFIED BY 'strong_password_here';
GRANT ALL PRIVILEGES ON dst_empire.* TO 'dst_user'@'localhost';
FLUSH PRIVILEGES;
```

#### Run Migrations

```bash
mysql -u dst_user -p dst_empire < migrations/072_dst_empire_brand_intake.sql
mysql -u dst_user -p dst_empire < migrations/077_dstempire_schema_expansion.sql
```

Verify:

```bash
mysql -u dst_user -p dst_empire -e "SHOW TABLES;"
```

Expected tables: `empire_states`, `empire_brand_intake`, `empire_advisor_log`, `empire_trust_thresholds`, `empire_portfolio_context`, `compliance_calendar`, `beneficial_owners`, `doc_templates`, `doc_renders`, `law_changes`, `amendments`, `plaid_transactions`, `industry_feeds`, `boi_audit_log`

### 4. Configuration

Create `.env` file in project root:

```bash
# Database
DB_HOST=localhost
DB_PORT=3306
DB_NAME=dst_empire
DB_USER=dst_user
DB_PASSWORD=strong_password_here
DB_CHARSET=utf8mb4

# PHP
PHP_ENV=production
TIMEZONE=America/Chicago

# Optional: External services
OLLAMA_HOST=http://localhost:11434
PLAID_CLIENT_ID=your_plaid_client_id
PLAID_SECRET=your_plaid_secret

# Optional: Document conversion
PANDOC_PATH=/usr/bin/pandoc

# Logging
LOG_LEVEL=warning
LOG_FILE=/var/log/dst-empire.log
```

Make sure `.env` is **not** committed to Git:

```bash
echo ".env" >> .gitignore
git rm --cached .env || true
```

### 5. Directory Permissions

```bash
# Documents & storage
mkdir -p storage/renders storage/boi storage/uploads
chmod 755 storage/
chmod 755 storage/renders storage/boi storage/uploads

# Logs
mkdir -p logs
chmod 755 logs
```

### 6. Test Installation

```bash
cd dst-empire
php examples/01_run_playbooks.php
```

Expected output: 12 playbooks with estimated Y1 savings + risk levels.

---

## Web Server Configuration

### Apache (.htaccess)

Create `.htaccess` in project root if using Apache:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Block direct access to sensitive files
    RewriteRule "^\.env$" - [F]
    RewriteRule "^\.git" - [F]
    RewriteRule "^migrations/" - [F]

    # Route public requests
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php?route=$1 [QSA,L]
</IfModule>

<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript application/json
</IfModule>
```

Enable mod_rewrite:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### Nginx

Create `/etc/nginx/sites-available/dst-empire.conf`:

```nginx
server {
    listen 80;
    server_name dst-empire.local;

    root /var/www/dst-empire;
    index index.php;

    # Block sensitive files
    location ~ /(\.|env|git|migrations/) {
        deny all;
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Static files
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
    }

    # Deny access to hidden files
    location ~ /\. {
        deny all;
    }
}
```

Enable:

```bash
sudo ln -s /etc/nginx/sites-available/dst-empire.conf /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

---

## Cron Jobs

### Daily Law-Change Monitor

```bash
# /etc/cron.d/dst-empire-monitor
0 1 * * * www-data php /var/www/dst-empire/scripts/law_monitor_cron.php >> /var/log/dst-empire-monitor.log 2>&1
```

Script location: `scripts/law_monitor_cron.php`

```php
<?php
require __DIR__ . '/../vendor/autoload.php';

$container = new \Mnmsos\Empire\Container();
$monitor = $container->get('LawMonitor');
$monitor->runDaily();
```

### Compliance Calendar Alerts

```bash
# /etc/cron.d/dst-empire-compliance
0 8 * * * www-data php /var/www/dst-empire/scripts/compliance_cron.php >> /var/log/dst-empire-compliance.log 2>&1
```

Script location: `scripts/compliance_cron.php`

```php
<?php
require __DIR__ . '/../vendor/autoload.php';

$container = new \Mnmsos\Empire\Container();
$alerts = $container->get('AlertDispatcher');
$alerts->sendDailyDigest();
```

### Plaid Transaction Sync (if using Plaid)

```bash
# /etc/cron.d/dst-empire-plaid
*/30 * * * * www-data php /var/www/dst-empire/scripts/plaid_cron.php >> /var/log/dst-empire-plaid.log 2>&1
```

Script location: `scripts/plaid_cron.php`

```php
<?php
require __DIR__ . '/../vendor/autoload.php';

$container = new \Mnmsos\Empire\Container();
$fetcher = $container->get('Plaid\TransactionFetcher');
$fetcher->syncAllAccounts();
```

### Email Digest (Weekly)

```bash
# /etc/cron.d/dst-empire-digest
0 9 * * 1 www-data php /var/www/dst-empire/scripts/digest_cron.php >> /var/log/dst-empire-digest.log 2>&1
```

---

## Backup Strategy

### Daily mysqldump

```bash
#!/bin/bash
# /usr/local/bin/backup-dst-empire.sh

BACKUP_DIR="/backups/dst-empire"
BACKUP_DATE=$(date +%Y%m%d-%H%M%S)
DB_NAME="dst_empire"
DB_USER="dst_user"
DB_PASS="strong_password_here"

mkdir -p $BACKUP_DIR

# Database dump (compressed)
mysqldump -u $DB_USER -p$DB_PASS \
    --single-transaction \
    --quick \
    --lock-tables=false \
    $DB_NAME | gzip > $BACKUP_DIR/dst-empire-$BACKUP_DATE.sql.gz

# Rotate backups (keep last 30 days)
find $BACKUP_DIR -name "dst-empire-*.sql.gz" -mtime +30 -delete

echo "Backup completed: $BACKUP_DIR/dst-empire-$BACKUP_DATE.sql.gz"
```

Make executable:

```bash
chmod +x /usr/local/bin/backup-dst-empire.sh
```

Schedule daily at 2 AM:

```bash
# /etc/cron.d/dst-empire-backup
0 2 * * * root /usr/local/bin/backup-dst-empire.sh >> /var/log/dst-empire-backup.log 2>&1
```

### Filesystem Backup

Back up `storage/` directory (rendered documents, BOI files):

```bash
tar -czf /backups/dst-empire-storage-$(date +%Y%m%d).tar.gz \
    /var/www/dst-empire/storage/
```

---

## Upgrade Path

### Minor Version (1.1 → 1.2)

```bash
cd /var/www/dst-empire

# Backup
mysqldump -u dst_user -p dst_empire | gzip > dst-empire-pre-upgrade.sql.gz

# Fetch updates
git fetch origin
git checkout v1.2.0

# Install new dependencies
composer install

# No new migrations required (minor version)
# Test
php examples/01_run_playbooks.php

# Clear any caches
rm -rf storage/cache/*

# Done
```

### Major Version (1.x → 2.0)

```bash
cd /var/www/dst-empire

# Backup
mysqldump -u dst_user -p dst_empire | gzip > dst-empire-pre-2.0.sql.gz

# Fetch updates
git fetch origin
git checkout v2.0.0

# Install
composer install

# Apply NEW migrations (check migrations/ directory)
# Example: if 078_*.sql exists
mysql -u dst_user -p dst_empire < migrations/078_dst_empire_v2_expansion.sql

# Update config (if breaking changes)
# Check CHANGELOG.md for migration guide

# Test
php examples/01_run_playbooks.php

# Restart PHP-FPM
sudo systemctl restart php8.3-fpm

# Clear cache
rm -rf storage/cache/*
```

---

## Document Rendering (Pandoc)

### Install Pandoc

```bash
# Ubuntu/Debian
sudo apt-get install pandoc

# macOS
brew install pandoc

# Verify
pandoc --version
```

### Configuration

In `.env`:

```env
PANDOC_PATH=/usr/bin/pandoc
PANDOC_PDF_ENGINE=xelatex  # or pdflatex
```

### Usage in Code

```php
use Mnmsos\Empire\Docs\PandocConverter;

$converter = new PandocConverter();
$pdf = $converter->markdownToPdf(
    markdown: $rendered_md,
    output_path: '/var/www/dst-empire/storage/renders/123.pdf'
);
```

---

## LLM Integration (Optional Ollama)

### Install Ollama

```bash
# macOS
brew install ollama

# Linux (https://ollama.ai)
curl https://ollama.ai/install.sh | sh
```

### Run hermes3-mythos locally

```bash
ollama pull hermes3-mythos:70b
ollama serve  # starts on http://localhost:11434
```

### Configuration

In `.env`:

```env
OLLAMA_HOST=http://localhost:11434
OLLAMA_MODEL=hermes3-mythos:70b
```

### Usage in Code

```php
use Mnmsos\Empire\Advisor;

$advisor = new Advisor();
$narrative = $advisor->narrativeExplain(
    intake: $intake,
    playbook_results: $results,
    model: 'hermes3-mythos:70b'
);
```

**Note:** Advisor usage is optional. Engine works 100% without LLM.

---

## Plaid Integration (Optional)

### Get Credentials

1. Sign up at [plaid.com/en-US/signin](https://plaid.com/en-US/signin)
2. Get **Client ID** + **Secret** from dashboard
3. Start with **Development** environment (free tier)

### Configuration

In `.env`:

```env
PLAID_CLIENT_ID=client_id_here
PLAID_SECRET=secret_here
PLAID_ENVIRONMENT=development  # or production
```

### Usage in Code

```php
use Mnmsos\Empire\Plaid\PlaidClient;

$plaid = new PlaidClient($_ENV['PLAID_CLIENT_ID'], $_ENV['PLAID_SECRET']);

// Get Plaid Link token
$link_token = $plaid->createLinkToken(
    user_id: 'user_123',
    client_name: 'DST Empire',
    language: 'en'
);

// Client opens Plaid Link, returns public_token
// Exchange for access_token
$access_token = $plaid->exchangePublicToken($public_token);

// Fetch transactions
$transactions = $plaid->getTransactions(
    access_token: $access_token,
    start_date: '2024-01-01',
    end_date: '2024-12-31'
);
```

---

## Security Hardening

### 1. File Permissions

```bash
# Restrict web server to read-only for most files
sudo chown -R www-data:www-data /var/www/dst-empire
sudo find /var/www/dst-empire -type f -exec chmod 644 {} \;
sudo find /var/www/dst-empire -type d -exec chmod 755 {} \;

# Writable directories only
chmod 755 /var/www/dst-empire/storage
chmod 755 /var/www/dst-empire/logs
```

### 2. Disable Direct File Access

Ensure `.env`, `.git`, `migrations/` are blocked:

```apache
# Apache .htaccess
<FilesMatch "^\.(env|git|htaccess)$">
    Order allow,deny
    Deny from all
</FilesMatch>

<DirectoryMatch "^/migrations/">
    Order allow,deny
    Deny from all
</DirectoryMatch>
```

### 3. PHP Security

In `php.ini`:

```ini
display_errors = off
log_errors = on
error_log = /var/log/php-error.log

; Prevent direct file access
open_basedir = /var/www/dst-empire:/tmp

; Disable dangerous functions
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,curl_multi_exec,parse_ini_file,show_source

; Session security
session.cookie_secure = on
session.cookie_httponly = on
session.cookie_samesite = Strict
```

### 4. Database Hardening

```sql
-- Create read-only user for reports
CREATE USER 'dst_readonly'@'localhost' IDENTIFIED BY 'readonly_password';
GRANT SELECT ON dst_empire.* TO 'dst_readonly'@'localhost';
FLUSH PRIVILEGES;

-- Limit root login
DELETE FROM mysql.user WHERE User='root' AND Host!='localhost';
FLUSH PRIVILEGES;
```

### 5. HTTPS / TLS

```bash
# Let's Encrypt (free)
sudo apt-get install certbot python3-certbot-nginx
sudo certbot certonly --nginx -d dst-empire.example.com

# Auto-renew
sudo systemctl enable certbot.timer
```

Update Nginx:

```nginx
server {
    listen 443 ssl http2;
    ssl_certificate /etc/letsencrypt/live/dst-empire.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/dst-empire.example.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
}

server {
    listen 80;
    return 301 https://$host$request_uri;
}
```

---

## Monitoring & Logging

### Application Logs

```bash
tail -f /var/log/dst-empire.log
tail -f /var/log/dst-empire-monitor.log
tail -f /var/log/dst-empire-compliance.log
```

### PHP-FPM Logs

```bash
tail -f /var/log/php8.3-fpm.log
```

### MySQL Logs

```bash
tail -f /var/log/mysql/error.log
tail -f /var/log/mysql/slow.log
```

### System Health

```bash
# Check disk usage
df -h /var/www/dst-empire
df -h /backups/

# Check database size
mysql -u dst_user -p dst_empire -e "
    SELECT
        table_name,
        ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb
    FROM information_schema.TABLES
    WHERE table_schema = 'dst_empire'
    ORDER BY size_mb DESC;
"

# Check running processes
ps aux | grep php
ps aux | grep mysql
```

---

## Troubleshooting

### Database Connection Error

```
Error: SQLSTATE[HY000] [2002] Can't connect to MySQL server
```

Check:

```bash
mysql -u dst_user -p dst_empire -e "SELECT 1;"
# If fails, verify DB_HOST, DB_PORT, DB_USER, DB_PASSWORD in .env
```

### Composer Autoload Error

```bash
composer dumpautoload
composer install --no-dev --optimize-autoloader
```

### Permission Denied on storage/

```bash
chmod 755 storage/
chown www-data:www-data storage/
```

### Cron Jobs Not Running

```bash
# Check crontab
sudo crontab -l -u www-data

# Check system mail for errors
sudo mail

# Test script directly
php /var/www/dst-empire/scripts/law_monitor_cron.php
```

---

## References

- [docs/ARCHITECTURE.md](ARCHITECTURE.md) — System design
- [docs/EXAMPLES.md](EXAMPLES.md) — Code recipes
- [README.md](../README.md) — Overview
- [CONTRIBUTING.md](../CONTRIBUTING.md) — Development guide
