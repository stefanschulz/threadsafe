#!/usr/bin/env bash
# Installs and seeds one WordPress site with WP-CLI. Runs INSIDE the tools container as www-data (tests/stack/seed-wordpress.sh starts it):
#
#   seed-wordpress-inside.sh <directory of the site> <port> <title> <database>
#
# What it makes is described in docs/PHASE9.md 4.2: content with the site's own address in every form WordPress stores it, a foreign domain,
# a sub-domain and an e-mail address that must not be replaced, serialized and JSON values, a user with a role, a transient, a nav menu, a
# plugin, the drop-ins and the files that a backup leaves out, pretty permalinks and their .htaccess.
set -euo pipefail

directory="$1"
port="$2"
title="$3"
database="$4"
domain="localhost:$port"
fixtures="/app/tests/fixtures/wordpress"
password="${WORDPRESS_ADMIN_PASSWORD:-Seed-admin-0123456789}"

export HOME=/tmp WP_CLI_CACHE_DIR=/tmp/wp-cli-cache
wp() { command wp --path="$directory" "$@"; }

# fill <template>: the template with the placeholders of this site
fill() {
    sed -e "s/{{domain}}/$domain/g" -e "s/{{domain_encoded}}/${domain//:/%3A}/g" "$1"
}

cd "$directory"

# a literal wp-config.php, as a person makes it on a host (the image's own computes its values from the environment)
wp config create --dbname="$database" --dbuser=root --dbpass="${DB_ROOT_PASSWORD:-prestashop}" --dbhost=db --force --skip-check
wp core install --url="http://$domain" --title="$title" --admin_user=seedadmin --admin_password="$password" --admin_email=admin@example.com --skip-email

wp user create editor editor@example.com --role=editor --user_pass="$password" --user_url="http://$domain/author/editor/"
wp user create reader reader@example.com --role=subscriber --user_pass="$password"
wp option update blogdescription "Seeded for the tests of ThreadSafe"
wp rewrite structure '/%postname%/'
# the block that WordPress writes for pretty permalinks (WP-CLI cannot write it without Apache's modules)
cat > .htaccess <<'HTACCESS'
# BEGIN WordPress

<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>

# END WordPress
HTACCESS

# files: media, and what a backup leaves out
mkdir -p wp-content/uploads/seed wp-content/cache wp-content/upgrade wp-content/updraft
head -c 20000 /dev/urandom > wp-content/uploads/seed/photo.bin
printf 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' | base64 -d > wp-content/uploads/seed/photo.png
printf 'seed file in the cache\n' > wp-content/cache/seed.cache
printf 'seed file of an update\n' > wp-content/upgrade/seed.tmp
printf 'an archive of another backup plugin\n' > wp-content/updraft/seed.zip
printf '[seed] a line of the debug log\n' > wp-content/debug.log
# the tools container has no image library, so the attachment is made by hand: its guid carries the address
attachment="$(wp post create --post_type=attachment --post_mime_type=image/png --post_status=inherit --post_title="Seeded image" --post_name=seeded-image --guid="http://$domain/wp-content/uploads/seed/photo.png" --porcelain)"

# content
about="$(fill "$fixtures/seed-post-about.html" | wp post create - --post_type=page --post_status=publish --post_name=about-us --post_title="About the seeded site" --porcelain)"
wp post create --post_status=publish --post_name=hello-seed --post_title="A seeded post" --post_content="Grüße 🌍 and a link to <a href=\"http://$domain/about-us/\">the page</a>." --porcelain >/dev/null
wp post meta add "$about" _builder_data "$(fill "$fixtures/seed-page-builder.json")"
wp post meta add "$about" _seed_serialized "$(fill "$fixtures/seed-options.json")" --format=json
wp comment create --comment_post_ID="$about" --comment_content="See http://$domain/about-us/ and https://foreign.example/page" --comment_author=Visitor --comment_author_email=visitor@example.com --comment_author_url="http://$domain/visitor/" --comment_approved=1 --porcelain >/dev/null

# options: serialized, plain and JSON, a widget, a transient
wp option add threadsafe_seed_serialized "$(fill "$fixtures/seed-options.json")" --format=json
wp option add threadsafe_seed_plain "http://$domain/seed/"
wp option add threadsafe_seed_json "{\"logo\":\"http:\\/\\/$domain\\/wp-content\\/uploads\\/seed\\/photo.png\",\"foreign\":\"https:\\/\\/foreign.example\\/page\"}"
wp option update widget_custom_html "{\"2\":{\"title\":\"Seeded widget\",\"content\":\"<a href=\\\"http://$domain/about-us/\\\">About</a>\"},\"_multiwidget\":1}" --format=json
wp transient set threadsafe_seed "http://$domain/transient/" 36000
wp transient set threadsafe_seed_site "http://$domain/site-transient/" 36000 --network

# a menu with the site's own address in it
wp menu create "Seeded menu" >/dev/null
wp menu item add-custom "Seeded menu" "Home" "http://$domain/" >/dev/null
wp menu item add-custom "Seeded menu" "Elsewhere" "https://foreign.example/" >/dev/null

# a plugin of a third party that writes an absolute path into wp-config.php, as caching plugins do
mkdir -p wp-content/plugins/threadsafe-fixture
cat > wp-content/plugins/threadsafe-fixture/threadsafe-fixture.php <<'PLUGIN'
<?php
/**
 * Plugin Name: ThreadSafe Fixture
 * Version: 1.2.3
 * Description: Seeded for the tests of ThreadSafe.
 */
PLUGIN
wp plugin activate threadsafe-fixture
wp config set WPCACHEHOME "/var/www/html/wp-content/plugins/threadsafe-fixture/" --type=constant
wp config set WP_CACHE true --raw --type=constant

# drop-ins: an advanced cache (harmless) and an object cache that works for this host only
printf '<?php\n// the advanced-cache.php of the fixture; WP_CACHE is on, so WordPress loads it\n' > wp-content/advanced-cache.php
sed "s/__SEEDED_HOST__/$domain/g" "$fixtures/object-cache-bound-to-host.php" > wp-content/object-cache.php

# a table that holds nothing but sessions: a backup keeps its structure only
wp eval '
global $wpdb;
$wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}woocommerce_sessions (session_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, session_key CHAR(32) NOT NULL, session_value LONGTEXT NOT NULL, session_expiry BIGINT UNSIGNED NOT NULL) DEFAULT CHARSET=utf8mb4");
$wpdb->query("INSERT INTO {$wpdb->prefix}woocommerce_sessions (session_key, session_value, session_expiry) VALUES (\"a\", \"x\", 1), (\"b\", \"y\", 2), (\"c\", \"z\", 3)");
'

touch .threadsafe-seeded
echo "Seeded $title at $domain (page $about, attachment $attachment)"
