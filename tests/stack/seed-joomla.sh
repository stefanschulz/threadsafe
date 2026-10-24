#!/usr/bin/env bash
# Starts the two Joomla sites of the stack (Joomla 5.4 on port 8195, Joomla 6 on port 8196) and gives them the problem cases that the
# Joomla tests need (idempotent): content with absolute URLs of the site's own domain in every form Joomla stores them, a foreign
# domain and an e-mail address that must not be replaced, a third-party extension, sessions, the files that a backup leaves out, and
# the search-engine friendly addresses with their .htaccess. See tests/fixtures/seed-joomla.sql and docs/PHASE6.md 4.2.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

JOOMLA5_PORT="${JOOMLA5_PORT:-8195}"
JOOMLA6_PORT="${JOOMLA6_PORT:-8196}"

log "Starting the database and the two Joomla sites (the very first start installs them and takes a minute or two)"
dc up -d --wait db >/dev/null
sql db "" "CREATE DATABASE IF NOT EXISTS joomla5 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
sql db "" "CREATE DATABASE IF NOT EXISTS joomla6 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
dc up -d joomla5 joomla6 >/dev/null

# seed_site <service> <database> <port>
seed_site() {
    local service="$1" database="$2" port="$3" domain="localhost:$3" i=0

    until dc exec -T "$service" sh -c 'test -f /var/www/html/configuration.php && ! test -d /var/www/html/installation' >/dev/null 2>&1; do
        i=$((i + 1))
        [ "$i" -ge 300 ] && die "$service was not installed within 300 s"
        sleep 1
    done
    wait_http "http://localhost:$port/" "$domain" 120

    if dc exec -T "$service" test -f /var/www/html/.threadsafe-seeded >/dev/null 2>&1; then
        log "$service is already seeded ($domain)"
        return
    fi

    local prefix
    prefix="$(dc exec -T "$service" php -r 'include "/var/www/html/configuration.php"; echo (new JConfig)->dbprefix;')"
    [ -n "$prefix" ] || die "Cannot read the table prefix of $service"

    log "Loading the seed SQL into $database ($domain, prefix $prefix)"
    local escaped="${domain//\//\\\\/}" encoded="${domain//:/%3A}"
    sed -e "s/{{prefix}}/${prefix}/g" -e "s/{{domain}}/${domain}/g" -e "s/{{domain_json}}/${domain}/g" -e "s/{{domain_encoded}}/${encoded}/g" \
        "$REPO_ROOT/tests/fixtures/seed-joomla.sql" | dbclient db -uroot -p"$DB_ROOT_PASSWORD" "$database"

    log "Writing the files of the seed (media, files that a backup leaves out, .htaccess, search-engine friendly addresses)"
    dc exec -T "$service" sh -c '
        set -e
        cd /var/www/html
        mkdir -p images/seed
        head -c 20000 /dev/urandom > images/seed/photo.bin
        printf "seed file in the cache\n" > cache/seed.cache
        printf "seed file in the administrator cache\n" > administrator/cache/seed.cache
        printf "seed temporary file\n" > tmp/seed.tmp
        printf "seed log line\n" > administrator/logs/seed.log.php
        mkdir -p administrator/components/com_akeebabackup/backup
        printf "an archive of another backup extension\n" > administrator/components/com_akeebabackup/backup/seed.jpa
        cp htaccess.txt .htaccess
        sed -i "s/public \$sef_rewrite = false;/public \$sef_rewrite = true;/" configuration.php
        chown -R www-data:www-data images/seed cache administrator/cache tmp administrator/logs administrator/components/com_akeebabackup .htaccess
        touch .threadsafe-seeded
    '
    log "Seeded $service at $domain"
}

seed_site joomla5 joomla5 "$JOOMLA5_PORT"
seed_site joomla6 joomla6 "$JOOMLA6_PORT"
