#!/usr/bin/env bash
# Starts the three WordPress sites of the stack (WordPress 7.1 on port 8197, WordPress 6.9 on port 8198, WordPress 6.0 on port 8199) and installs and seeds them with WP-CLI
# (idempotent): content with the site's own address in every form WordPress stores it, a foreign domain, a user with a role, serialized and JSON
# values, a transient, a plugin, the drop-ins and the files that a backup leaves out, pretty permalinks. See seed-wordpress-inside.sh and
# docs/PHASE9.md 4.2.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

WORDPRESS7_PORT="${WORDPRESS7_PORT:-8197}"
WORDPRESS6_PORT="${WORDPRESS6_PORT:-8198}"
WORDPRESS60_PORT="${WORDPRESS60_PORT:-8199}"

log "Starting the database and the three WordPress sites"
dc up -d --wait db >/dev/null
# a database as a hosting panel makes it: the character set, not a collation (MySQL 8.4 then defaults to utf8mb4_0900_ai_ci for the database)
sql db "" "CREATE DATABASE IF NOT EXISTS wp7 CHARACTER SET utf8mb4"
sql db "" "CREATE DATABASE IF NOT EXISTS wp6 CHARACTER SET utf8mb4"
sql db "" "CREATE DATABASE IF NOT EXISTS wp60 CHARACTER SET utf8mb4"
dc up -d wordpress7 wordpress6 wordpress60 >/dev/null

# seed_site <service> <volume directory in the tools container> <database> <port> <title>
seed_site() {
    local service="$1" directory="$2" database="$3" port="$4" title="$5" domain="localhost:$4" i=0

    until dc exec -T "$service" test -f /var/www/html/wp-includes/version.php >/dev/null 2>&1; do
        i=$((i + 1))
        [ "$i" -ge 120 ] && die "$service did not get its files within 120 s"
        sleep 1
    done
    # the entrypoint copies the files as root and then hands them over to www-data; wait for that
    until [ "$(dc exec -T "$service" stat -c %U /var/www/html/index.php 2>/dev/null || true)" = "www-data" ]; do
        i=$((i + 1))
        [ "$i" -ge 120 ] && die "$service did not hand its files to www-data within 120 s"
        sleep 1
    done

    if dc exec -T "$service" test -f /var/www/html/.threadsafe-seeded >/dev/null 2>&1; then
        log "$service is already seeded ($domain)"
        return
    fi

    # a clean start: a half-seeded database from an earlier try would make "core install" refuse
    sql db "" "DROP DATABASE IF EXISTS $database"
    sql db "" "CREATE DATABASE $database CHARACTER SET utf8mb4"

    log "Installing and seeding $title in $database ($domain)"
    dc run --rm -T --no-deps --user 33:33 -e DB_ROOT_PASSWORD="$DB_ROOT_PASSWORD" tools \
        bash /app/tests/stack/seed-wordpress-inside.sh "$directory" "$port" "$title" "$database"
    wait_http "http://localhost:$port/" "$domain" 120
    log "Seeded $service at $domain"
}

seed_site wordpress7 /wordpress7 wp7 "$WORDPRESS7_PORT" "Seeded WordPress 7"
seed_site wordpress6 /wordpress6 wp6 "$WORDPRESS6_PORT" "Seeded WordPress 6"
seed_site wordpress60 /wordpress60 wp60 "$WORDPRESS60_PORT" "Seeded WordPress 60"
