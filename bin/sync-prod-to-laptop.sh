#!/usr/bin/env bash
#
# Sync the production EMR database down to this laptop's DDEV instance.
# Used for the laughh2.emr brownout-failover cutover. Run this ONLY after
# everyone has saved and logged out of prod, so the snapshot is complete.
#
# Steps: dump on prod -> copy down -> import into DDEV -> rebuild cache ->
# verify row counts match prod. Exits non-zero (and prints FAIL) on any
# problem or count mismatch, so the operator never assumes a bad sync worked.
#
# Usage: bin/sync-prod-to-laptop.sh

set -euo pipefail

PROD_SSH="aaron@192.168.0.66"
PROD_DIR="/var/www/html/laughh"
PROD_DRUSH="vendor/bin/drush"
REPO_DIR="$HOME/Sites/librechart"
BACKUP_DIR="$REPO_DIR/backups"
TS="$(date +%Y%m%d-%H%M%S)"
FILE="laughh-prod-${TS}.sql.gz"
VERIFY_TABLES=(visit patient users_field_data)

cd "$REPO_DIR"
mkdir -p "$BACKUP_DIR"

say() { printf '\n=== %s ===\n' "$1"; }
fail() { printf '\n*** FAIL: %s ***\n' "$1" >&2; exit 1; }

say "1/5  Checking prod is reachable"
ssh -o ConnectTimeout=10 "$PROD_SSH" 'true' 2>/dev/null \
  || fail "cannot SSH to $PROD_SSH (prod down? brownout?). Aborting — no changes made locally."

say "2/5  Dumping prod database"
ssh -o ConnectTimeout=15 "$PROD_SSH" \
  "cd $PROD_DIR && $PROD_DRUSH sql:dump --gzip --result-file=/tmp/${FILE%.gz}" \
  || fail "prod sql:dump failed."
ssh "$PROD_SSH" "test -s /tmp/${FILE}" || fail "dump file /tmp/${FILE} missing or empty on prod."

say "3/5  Copying dump to $BACKUP_DIR and clearing remote temp"
scp -o ConnectTimeout=15 "$PROD_SSH:/tmp/${FILE}" "$BACKUP_DIR/" || fail "scp of dump failed."
ssh "$PROD_SSH" "rm -f /tmp/${FILE}" || true
test -s "$BACKUP_DIR/${FILE}" || fail "local dump $BACKUP_DIR/${FILE} missing or empty."
printf '   saved %s (%s)\n' "$BACKUP_DIR/${FILE}" "$(du -h "$BACKUP_DIR/${FILE}" | cut -f1)"

say "4/5  Importing into DDEV and rebuilding cache"
ddev import-db --file="$BACKUP_DIR/${FILE}" || fail "ddev import-db failed (local DB may be partial!)."
ddev drush cache:rebuild || fail "cache:rebuild failed."

say "5/5  Verifying row counts (prod vs laptop)"
MISMATCH=0
for T in "${VERIFY_TABLES[@]}"; do
  P="$(ssh -o ConnectTimeout=10 "$PROD_SSH" "cd $PROD_DIR && $PROD_DRUSH sql:query 'SELECT COUNT(*) FROM $T;'" 2>/dev/null | tr -d '[:space:]')"
  L="$(ddev drush sql:query "SELECT COUNT(*) FROM $T;" 2>/dev/null | tr -d '[:space:]')"
  if [ -n "$P" ] && [ "$P" = "$L" ]; then
    printf '   %-18s prod=%-7s laptop=%-7s match\n' "$T" "$P" "$L"
  else
    printf '   %-18s prod=%-7s laptop=%-7s MISMATCH\n' "$T" "${P:-?}" "${L:-?}"
    MISMATCH=1
  fi
done
[ "$MISMATCH" -eq 0 ] || fail "row counts do not match — do NOT cut over; investigate."

printf '\nSYNC OK — laptop now mirrors prod (%s). Safe to change DNS to 192.168.0.99.\n' "$TS"
