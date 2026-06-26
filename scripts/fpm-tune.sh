#!/bin/bash
# Raise the PHP-FPM worker cap so the laptop failover can serve the whole
# clinic, then gracefully reload (USR2) without dropping in-flight requests.
# Safe to run repeatedly — used both manually and as a DDEV post-start hook.
# Runs INSIDE the web container.

MASTERLINE=$(pgrep -af 'php-fpm: master' | head -1)
[ -n "$MASTERLINE" ] || { echo "fpm-tune: no FPM master found, skipping"; exit 0; }

PHPV=$(echo "$MASTERLINE" | grep -oE '[0-9]+\.[0-9]+' | head -1)
POOL="/etc/php/${PHPV}/fpm/pool.d/www.conf"
[ -f "$POOL" ] || { echo "fpm-tune: $POOL not found, skipping"; exit 0; }

sed -i \
  -e 's/^pm.max_children = .*/pm.max_children = 40/' \
  -e 's/^pm.start_servers = .*/pm.start_servers = 12/' \
  -e 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 8/' \
  -e 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 24/' \
  "$POOL"

if "/usr/sbin/php-fpm${PHPV}" -t 2>/dev/null; then
  kill -USR2 "$(echo "$MASTERLINE" | awk '{print $1}')"
  echo "fpm-tune: ${POOL} set to max_children=40, FPM gracefully reloaded"
else
  echo "fpm-tune: config test FAILED — not reloading"; exit 1
fi
