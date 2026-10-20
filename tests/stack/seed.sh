#!/usr/bin/env bash
# Adds the problem cases to the source stack (idempotent): fixture module, seed SQL, host-bound files.
# See tests/fixtures/ and docs/PHASE1.md (test strategy).
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

# The live connections of a real shop (mail account, PayPal, PrestaShop Account …) for the dev mode tests; separate from
# the first seeding so that an already seeded source stack gets them too.
seed_live_links() {
    local prefix="$1"
    if [ "$(sql db "$SOURCE_DB_NAME" "SELECT COUNT(*) FROM ${prefix}configuration WHERE name='THREADSAFE_SEEDED_LIVE_LINKS'")" != "0" ]; then
        return
    fi
    log "Adding the live connections (mail account, PayPal, PrestaShop Account) for the dev mode tests"
    sed -e "s/{{prefix}}/${prefix}/g" "$REPO_ROOT/tests/fixtures/seed-live-links.sql"         | dc exec -T db mariadb -uroot -p"$DB_ROOT_PASSWORD" "$SOURCE_DB_NAME"
}

log "Starting the source stack"
dc up -d --wait db prestashop mailpit >/dev/null

prefix="$(dc exec -T prestashop php -r '$p = include "/var/www/html/app/config/parameters.php"; echo $p["parameters"]["database_prefix"];')"
domain="$(sql db "$SOURCE_DB_NAME" "SELECT value FROM ${prefix}configuration WHERE name='PS_SHOP_DOMAIN'")"
[ -n "$domain" ] || die "Cannot read PS_SHOP_DOMAIN from the source database"

if [ "$(sql db "$SOURCE_DB_NAME" "SELECT COUNT(*) FROM ${prefix}configuration WHERE name='THREADSAFE_SEEDED'")" != "0" ]; then
    log "Source stack is already seeded ($domain)"
    seed_live_links "$prefix"
    exit 0
fi

log "Installing the fixture module"
dc cp tests/fixtures/modules/threadsafe_fixture prestashop:/var/www/html/modules/ >/dev/null
dc exec -T prestashop chown -R www-data:www-data /var/www/html/modules/threadsafe_fixture
# Earlier commands run as root may have left root-owned files in var/; the console runs as www-data.
dc exec -T prestashop chown -R www-data:www-data /var/www/html/var
dc exec -T -u www-data prestashop php /var/www/html/bin/console --env=prod prestashop:module install threadsafe_fixture

log "Loading the seed SQL (200,000 log rows, CMS content, cache entry)"
encoded="${domain//:/%3A}"
sed -e "s/{{prefix}}/${prefix}/g" -e "s/{{domain_encoded}}/${encoded}/g" -e "s/{{domain}}/${domain}/g" \
    "$REPO_ROOT/tests/fixtures/seed-problem-cases.sql" \
    | dc exec -T db mariadb -uroot -p"$DB_ROOT_PASSWORD" "$SOURCE_DB_NAME"

log "Writing host-bound files (php.ini, sitemap)"
dc exec -T prestashop sh -c 'cat > /var/www/html/php.ini' <<'EOF'
error_reporting = E_ALL & ~E_DEPRECATED;
log_errors = On;
display_errors = Off;
error_log = /kunden/homepages/22/d000/htdocs/shoptest/var/logs/php-errors.log;
memory_limit = 512M
EOF
dc exec -T prestashop sh -c 'cat > /var/www/html/1_index_sitemap.xml' <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <sitemap><loc>http://${domain}/1_de_0_sitemap.xml</loc></sitemap>
</sitemapindex>
EOF
dc exec -T prestashop chown www-data:www-data /var/www/html/php.ini /var/www/html/1_index_sitemap.xml

seed_live_links "$prefix"

log "Seeded the source stack at $domain"
