#!/usr/bin/env bash
# Clones the source shop (files + database) into the isolated target stack, the way a restore would leave it:
# files and database are in place, parameters.php and the domain are still the old ones. Wipes the target first.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

log "Starting the target stack"
dc up -d --wait target-db >/dev/null
dc up -d target-prestashop >/dev/null

# The clone must never be able to reach the source database: "db" must not resolve inside the target network.
if dc exec -T target-prestashop getent hosts db >/dev/null 2>&1; then
    die "Host 'db' resolves in the target network – the clone could use the source database. Check .docker/docker-compose.yml."
fi

log "Wiping the target"
dc exec -T target-prestashop sh -c 'find /var/www/html -mindepth 1 -delete'
sql target-db "" "DROP DATABASE IF EXISTS ${TARGET_DB_NAME}; CREATE DATABASE ${TARGET_DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

log "Copying files (without var/cache and cache/, like a backup would)"
dc exec -T prestashop tar -C /var/www/html --exclude=./var/cache --exclude=./cache/smarty -cf - . \
    | dc exec -T target-prestashop tar -C /var/www/html -xpf -

log "Copying the database"
dbdump db -uroot -p"$DB_ROOT_PASSWORD" --single-transaction --skip-comments "$SOURCE_DB_NAME" \
    | dbclient target-db -uroot -p"$DB_ROOT_PASSWORD" "$TARGET_DB_NAME"

tables_source="$(sql db "$SOURCE_DB_NAME" "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${SOURCE_DB_NAME}'")"
tables_target="$(sql target-db "$TARGET_DB_NAME" "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${TARGET_DB_NAME}'")"
[ "$tables_source" = "$tables_target" ] || die "Table count differs: source $tables_source, target $tables_target"

log "Target stack ready: $tables_target tables, shop files in place, still configured for the old domain (http://localhost:${TARGET_PORT} is expected to be broken)"
