# Librechart

Librechart is a free, open-source Electronic Medical Record (EMR) system built with Drupal CMS, designed for medical mission clinics operating in resource-limited environments. It runs entirely on a local area network (LAN) with no internet dependency — all assets are self-hosted.

## What it does

- **Patient records** — searchable patient identity records with demographic information
- **Visit tracking** — per-visit medical records linked to patient identities
- **Role-based access control** — permissions tied to clinic roles and stations
- **Offline-capable** — runs on a local server; no internet connection required during clinic operations


## Requirements

- [DDEV](https://ddev.com) (recommended for local development)
- PHP 8.3+
- MySQL / MariaDB
- Composer

## Quick start (with DDEV)

```shell
# 1. Install DDEV: https://ddev.com/get-started/

# 2. Clone and enter the project
git clone <repo-url> librechart
cd librechart

# 3. Start DDEV
ddev start

# 4. Install dependencies
ddev composer install

# 5. Install Drupal from existing config
ddev drush site:install --existing-config -y

# 6. Open in browser
ddev launch
```

Default admin credentials are set during installation. Log in at `/user/login`.

## Development commands

| Task | Command |
|------|---------|
| Install dependencies | `ddev composer install` |
| Install Drupal | `ddev drush site:install --existing-config` |
| Clear cache | `ddev drush cache:rebuild` |
| Import config | `ddev drush config:import -y` |
| Export config | `ddev drush config:export -y` |
| Run linter | `ddev exec phpcs` |
| Run static analysis | `ddev exec phpstan` |
| Run tests | `ddev exec phpunit --filter Test path/to/test` |
| View logs | `ddev drush watchdog:show --count=20` |

## Project structure

```
web/
  modules/
    custom/
      librechart_patient/   # Patient entity type and related config
      librechart_visit/     # Visit entity type and related config
  themes/
    custom/                 # Custom theme (if any)
config/
  sync/                     # Drupal configuration (YAML, checked into git)
specs/                      # Feature specifications and implementation plans
```

## Configuration management

All site configuration is stored as YAML in `config/sync/` and checked into version control. To apply configuration changes:

```shell
ddev drush config:import -y
```

To capture changes made through the admin UI:

```shell
ddev drush config:export -y
```

## Production deployment

Librechart is designed to run on a Linux web server (Apache or Nginx) on a local network. There is no dependency on external services, CDNs, or cloud APIs. All assets are self-hosted.

Deployment steps:

1. Copy the codebase to the server
2. Run `composer install --no-dev`
3. Configure `web/sites/default/settings.php` with database credentials
4. Run `drush site:install --existing-config -y`
5. Configure your web server to serve from `web/`

## License

Librechart is licensed under the [GNU General Public License, version 2 or later](http://www.gnu.org/licenses/old-licenses/gpl-2.0.html).
