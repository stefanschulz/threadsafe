#!/usr/bin/env bash
# Starts the two WordPress sites with plugins (the shop on port 8200 with WooCommerce, Yoast SEO and Redis Object Cache; the cache site on port 8201 with
# W3 Total Cache and Elementor) and installs and seeds them with WP-CLI (idempotent). The plugins are downloaded from wordpress.org: this needs the
# internet. See seed-wordpress-plugins-inside.sh and docs/PHASE9.md (field test, plugins).
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

WORDPRESSSHOP_PORT="${WORDPRESSSHOP_PORT:-8200}"
WORDPRESSCACHE_PORT="${WORDPRESSCACHE_PORT:-8201}"

log "Starting the database, Redis and the two WordPress sites with plugins"
dc up -d --wait db >/dev/null
sql db "" "CREATE DATABASE IF NOT EXISTS wpshop CHARACTER SET utf8mb4"
sql db "" "CREATE DATABASE IF NOT EXISTS wpcache CHARACTER SET utf8mb4"
dc up -d redis wordpressshop wordpresscache >/dev/null

# seed_site <service> <volume directory in the tools container> <database> <port> <title> <kind>
seed_site() {
    local service="$1" directory="$2" database="$3" port="$4" title="$5" kind="$6" domain="localhost:$4" i=0

    until dc exec -T "$service" test -f /var/www/html/wp-includes/version.php >/dev/null 2>&1; do
        i=$((i + 1))
        [ "$i" -ge 120 ] && die "$service did not get its files within 120 s"
        sleep 1
    done
    until [ "$(dc exec -T "$service" stat -c %U /var/www/html/index.php 2>/dev/null || true)" = "www-data" ]; do
        i=$((i + 1))
        [ "$i" -ge 120 ] && die "$service did not hand its files to www-data within 120 s"
        sleep 1
    done

    if dc exec -T "$service" test -f /var/www/html/.threadsafe-seeded >/dev/null 2>&1; then
        log "$service is already seeded ($domain)"
        return
    fi

    sql db "" "DROP DATABASE IF EXISTS $database"
    sql db "" "CREATE DATABASE $database CHARACTER SET utf8mb4"

    log "Installing and seeding $title in $database ($domain)"
    dc run --rm -T --no-deps --user 33:33 -e DB_ROOT_PASSWORD="$DB_ROOT_PASSWORD" -e SERVICE="$service" tools \
        bash /app/tests/stack/seed-wordpress-plugins-inside.sh "$directory" "$port" "$title" "$database" "$kind"
    wait_http "http://localhost:$port/" "$domain" 180
    log "Seeded $service at $domain"
}

seed_site wordpressshop /wordpressshop wpshop "$WORDPRESSSHOP_PORT" "Seeded Shop" shop
seed_site wordpresscache /wordpresscache wpcache "$WORDPRESSCACHE_PORT" "Seeded Cache Site" cache
