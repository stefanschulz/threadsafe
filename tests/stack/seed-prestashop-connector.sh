#!/usr/bin/env bash
# Starts the PrestaShop of the module threadsafeconnector (service prestashopconnector, port 8590, its own database psconn; docs/PHASE15.md 5) and waits until the
# installer of the image has made the shop (idempotent). The image installs on the first start only if the database exists, so it is made first; a volume that
# holds a half-installed shop from a failed first start is thrown away. The shop is a shop of its own: the source shop (ps_data) is the baseline of the migration
# and restore tests, and a module in it would change what they compare.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

PSCONNECTOR_PORT="${PSCONNECTOR_PORT:-8590}"

log "Starting the database and the PrestaShop of the module"
dc up -d --wait db >/dev/null
sql db "" "CREATE DATABASE IF NOT EXISTS psconn CHARACTER SET utf8mb4"
dc up -d prestashopconnector >/dev/null

i=0
until [ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://localhost:$PSCONNECTOR_PORT/" || true)" = "200" ]; do
    i=$((i + 1))
    if [ "$i" -ge 300 ]; then
        die "The shop of the module did not answer 200 within 300 s (docker compose logs prestashopconnector)"
    fi
    sleep 1
done
log "The shop of the module answers at localhost:$PSCONNECTOR_PORT"
