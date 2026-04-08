# Production Server Setup Guide

This document describes the prerequisites and deployment steps for the Librechart EMR
system on an onsite LAN-hosted Linux server.

## Server Requirements

- OS: Ubuntu 22.04 LTS or Debian 12 (recommended)
- RAM: 2 GB minimum (4 GB recommended for whisper.cpp)
- Disk: 20 GB minimum
- PHP 8.3 + extensions
- MySQL 8.0 or MariaDB 10.11
- Apache 2.4 or Nginx 1.24+

## PHP 8.3 Installation

```bash
sudo apt-get install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php
sudo apt-get update
sudo apt-get install -y php8.3 php8.3-fpm php8.3-mysql php8.3-gd \
  php8.3-xml php8.3-mbstring php8.3-curl php8.3-zip php8.3-intl \
  php8.3-opcache php8.3-cli
```

### PHP FPM Settings (`/etc/php/8.3/fpm/php.ini`)

```ini
memory_limit = 512M
upload_max_filesize = 32M
post_max_size = 32M
max_execution_time = 300
max_input_vars = 3000
```

## MySQL / MariaDB Setup

```bash
sudo apt-get install -y mariadb-server
sudo mysql_secure_installation

# Create database and user
sudo mysql -u root -p <<SQL
CREATE DATABASE librechart CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'librechart'@'localhost' IDENTIFIED BY 'CHANGE_THIS_PASSWORD';
GRANT ALL PRIVILEGES ON librechart.* TO 'librechart'@'localhost';
FLUSH PRIVILEGES;
SQL
```

## Apache Virtual Host

```apache
<VirtualHost *:80>
    ServerName librechart.local
    DocumentRoot /var/www/librechart/web

    <Directory /var/www/librechart/web>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/librechart_error.log
    CustomLog ${APACHE_LOG_DIR}/librechart_access.log combined
</VirtualHost>
```

Enable the site:

```bash
sudo a2enmod rewrite
sudo a2ensite librechart
sudo systemctl reload apache2
```

## File Permissions

```bash
sudo chown -R www-data:www-data /var/www/librechart/web/sites/default/files
sudo chmod -R 755 /var/www/librechart/web/sites/default/files
sudo chmod 444 /var/www/librechart/web/sites/default/settings.php
```

## Deployment Steps

```bash
# Clone the repository
cd /var/www
git clone <repository-url> librechart
cd librechart

# Install production dependencies (no dev packages)
composer install --no-dev --optimize-autoloader

# Configure settings.php (copy and edit the template)
cp web/sites/default/default.settings.php web/sites/default/settings.php
# Edit settings.php: set database credentials, config_sync_directory, etc.

# Import configuration and run updates
vendor/bin/drush updatedb -y
vendor/bin/drush config:import -y
vendor/bin/drush cache:rebuild

# Create admin user (first deployment only)
vendor/bin/drush user:create admin --mail="admin@example.com" --password="CHANGE_ME"
vendor/bin/drush role:add administrator admin
```

## Ongoing Deployments

```bash
cd /var/www/librechart
git pull
composer install --no-dev --optimize-autoloader
vendor/bin/drush updatedb -y
vendor/bin/drush config:import -y
vendor/bin/drush cache:rebuild
```

## Cron

Add to crontab for cache rebuilds and system cron:

```cron
*/15 * * * * www-data /var/www/librechart/vendor/bin/drush --root=/var/www/librechart/web cron
```
