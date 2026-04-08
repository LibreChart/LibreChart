# Quickstart: Librechart EMR

**Branch**: `001-emr-rebuild` | **Date**: 2026-03-15

---

## Prerequisites

- [DDEV](https://ddev.readthedocs.io/) installed (v1.23+)
- Docker Desktop or OrbStack running
- PHP 8.3+ (for local IDE tooling; DDEV provides its own PHP)
- Composer 2.x
- Git

---

## Local Development Setup

```bash
# 1. Clone the repository
git clone <repo-url> librechart
cd librechart

# 2. Start DDEV (creates containers, configures PHP 8.3, MySQL)
ddev start

# 3. Install Composer dependencies (Drupal CMS + all contrib modules)
ddev composer install

# 4. Install Drupal from existing configuration
ddev drush site:install --existing-config -y

# 5. Create a local admin account
ddev drush user:create admin --password=admin --mail=admin@librechart.local
ddev drush user:role:add administrator admin

# 6. Open the site
ddev launch
```

The site is now available at `https://librechart.ddev.site`.

---

## First-Time Setup After Install

### Download and commit Spanish translations (internet required — do once during development)

```bash
# Download Spanish PO files for core and all installed contrib modules
ddev drush locale:check
ddev drush locale:update --langcodes=es

# Translations are stored in the database; export them to files
ddev drush locale:export es --types=not-customized --file=translations/es.po

# Commit the translations directory to the repository
git add translations/
git commit -m "Add Spanish (es) translation files"
```

> **Important**: This step requires internet access and is done once by a developer. The committed `translations/` directory is imported automatically on production install, with no internet needed.

### Import legacy taxonomy content

Before running the migration, create `web/sites/default/settings.local.php` (not committed)
and add the legacy database connection:

```php
<?php
// Legacy OpenEMR database connection for taxonomy migration.
// Replace credentials with actual values for your environment.
$databases['migrate']['default'] = [
  'driver' => 'mysql',
  'database' => 'openemr',
  'username' => 'openemr_user',
  'password' => 'CHANGE_ME',
  'host' => '127.0.0.1',
  'port' => '3306',
  'prefix' => '',
  'collation' => 'utf8mb4_unicode_ci',
];
```

Then run the migration:

```bash
# Import all taxonomy vocabulary terms from legacy OpenEMR database
ddev drush migrate:import --group=librechart_taxonomy

# Verify all terms were imported
ddev drush migrate:status --group=librechart_taxonomy

# Roll back if needed
ddev drush migrate:rollback --group=librechart_taxonomy
```

### Start the Whisper.cpp dictation server (optional for development)

```bash
# On host machine — whisper.cpp must be compiled separately
# See docs/whisper-setup.md for compilation instructions
./whisper-server --model models/ggml-small.en.bin --host 127.0.0.1 --port 8080
```

If whisper.cpp is not running, the dictation button will show an error message. All other functionality is unaffected.

---

## Common Development Commands

```bash
# Export configuration after making changes in the UI
ddev drush config:export -y

# Import configuration from files (e.g., after a git pull)
ddev drush config:import -y

# Rebuild cache
ddev drush cache:rebuild

# Run PHPUnit tests
ddev exec phpunit -c web/core/phpunit.xml.dist --filter Test web/modules/custom

# Run PHP CodeSniffer (Drupal coding standards)
ddev exec phpcs --standard=Drupal web/modules/custom

# Run PHPStan static analysis
ddev exec phpstan analyse --level 6 web/modules/custom

# View watchdog logs
ddev drush watchdog:show --count=20

# Enable a new contrib module
ddev composer require drupal/module_name
ddev drush en module_name -y
ddev drush config:export -y
```

---

## Module Structure

```
web/modules/custom/
├── librechart_patient/     # Patient entity type and fields
├── librechart_visit/       # Visit entity, station fields, conditional visibility
├── librechart_lab/         # LabResult paragraph type
├── librechart_pharmacy/    # PrescriptionItem, DrugInventory, InventoryReceipt entities
├── librechart_dictation/   # Speech-to-text proxy endpoint + JS
├── librechart_reports/     # Views-based reporting
└── librechart_migrate/     # Migrate API plugins for legacy taxonomy import
```

---

## Onsite Server Deployment

The production system runs on a Linux server on the clinic's local network (no internet required).

```bash
# On the Linux server (non-DDEV production deployment)

# Install dependencies
composer install --no-dev --optimize-autoloader

# Run database updates after deploying new code
drush updatedb -y

# Import configuration
drush config:import -y

# Rebuild cache
drush cache:rebuild
```

**Web server**: Apache or Nginx with PHP 8.3 FPM. See `docs/server-setup.md` for full server configuration (virtual host, PHP settings, MySQL setup).

**Whisper.cpp**: Run as a systemd service. See `docs/whisper-setup.md`.

---

## Key Configuration

| Item | Location |
|---|---|
| Database credentials | `web/sites/default/settings.local.php` (not committed) |
| Drupal config sync | `config/sync/` |
| Custom module code | `web/modules/custom/` |
| Whisper server URL | Drupal admin → `/admin/config/librechart/dictation` |
| Dictation language | Drupal admin → `/admin/config/librechart/dictation` (set `es` for Spanish) |
| DDEV config | `.ddev/config.yaml` |
| Spanish translations | `translations/es.po` (committed; imported on install) |
