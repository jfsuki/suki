# SUKI — Installation Guide

**Scope**: Fresh install on Laragon (local) or VPS (production).  
**Requirements**: PHP 8.1+, MySQL 8+, Apache/Nginx, Composer

---

## Local (Laragon)

### 1. Clone and configure

```bash
git clone <repo> C:/laragon/www/suki
cd C:/laragon/www/suki
composer install
cp project/.env.example project/.env
```

### 2. Edit `project/.env`

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=suki_saas
DB_USER=root
DB_PASS=

# At least one LLM provider (system has 6 with failover)
OPENROUTER_API_KEY=your_openrouter_key_here
GEMINI_API_KEY=your_gemini_key_here

# Qdrant (optional — falls back to rules without it)
QDRANT_URL=http://localhost:6333
QDRANT_COLLECTION=suki_intents
SEMANTIC_MEMORY_ENABLED=1   # requires GEMINI_API_KEY for embeddings

# Security
SUKI_MASTER_KEY=change-this-in-production
APP_ENV=local
```

### 3. Initialize database

```bash
php framework/scripts/apply_schema_migrations.php
php framework/tests/db_health.php   # verify DB accessible
```

### 4. Seed initial data

```bash
php framework/scripts/codex_self_check.php --strict   # pre-flight
php framework/scripts/seed_base.php                   # base tenant data
```

### 5. Seed Qdrant vectors (if SEMANTIC_MEMORY_ENABLED=1 and GEMINI_API_KEY set)

```bash
php framework/scripts/seed_erp_intents.php
```

### 6. Verify

```bash
php framework/tests/run.php       # unit tests (121/121 expected)
php framework/tests/db_health.php # DB health
```

Open: `http://localhost/suki/` — you should see the Marketplace.

---

## VPS (Production)

### Prerequisites

- PHP 8.1+ with extensions: pdo_mysql, pdo_sqlite, mbstring, fileinfo, json, curl, zip
- MySQL 8.0+
- Apache with `mod_rewrite` enabled

### Deploy steps

```bash
# 1. Upload files (rsync or git pull)
rsync -avz --exclude='project/.env' ./ user@server:/var/www/suki/

# 2. Set permissions
chown -R www-data:www-data /var/www/suki
find /var/www/suki -type f -name "*.php" -exec chmod 644 {} \;
find /var/www/suki -type d -exec chmod 755 {} \;
chmod 700 /var/www/suki/project/storage

# 3. Configure .env on server (never commit .env)
cp project/.env.example project/.env
nano project/.env   # fill production values

# 4. Install dependencies
composer install --no-dev --optimize-autoloader

# 5. Initialize DB
php framework/scripts/apply_schema_migrations.php

# 6. Seed base data
php framework/scripts/seed_base.php

# 7. Seed vectors (optional — requires GEMINI_API_KEY)
php framework/scripts/seed_erp_intents.php

# 8. Verify
php framework/tests/run.php
php framework/tests/db_health.php
```

### Apache VirtualHost

```apache
<VirtualHost *:443>
    ServerName suki.yourdomain.com
    DocumentRoot /var/www/suki

    <Directory /var/www/suki>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Three entry points:
- `/` → `framework/public/index.php` (Marketplace / Builder)
- `/apps/` → `project/public/index.php` (Tenant Apps)
- `/torre/` → `tower/public/index.php` (Control Tower — master key only)

---

## Troubleshooting

See `docs/troubleshooting/FAILURE_MAP.md` for known failure modes.

Common issues:
- **Chat history empty**: Keys mismatch fixed 2026-05-16. Update if on older version.
- **Accounting roles missing**: `seedDefaultRolesForTenant` now called automatically.
- **LLM smoke test fails**: Set at least one provider key in `project/.env`.
- **Torre Training tab crashes**: Run `php framework/scripts/seed_knowledge_catalog.php`.
- **PUC Colombia empty**: Run `php framework/scripts/puc_seeder.php` if `puc_nacional` table is empty after migration.
- **Qdrant empty after deploy**: Run `php framework/scripts/seed_erp_intents.php`. Requires `GEMINI_API_KEY` and running Qdrant.
