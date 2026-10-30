#!/usr/bin/env bash
# Installs and seeds one of the two WordPress sites with the plugins of real sites. Runs INSIDE the tools container as www-data
# (tests/stack/seed-wordpress-plugins.sh starts it):
#
#   seed-wordpress-plugins-inside.sh <directory of the site> <port> <title> <database> <shop|cache>
#
#   shop   WooCommerce (products, a variable product, customers, orders, coupons, webhooks, payment settings, a cart session), Yoast SEO (indexables
#          with the address of the posts) and Redis Object Cache on the service `redis` (its drop-in object-cache.php needs that server).
#   cache  W3 Total Cache (page, object and database cache: the drop-ins advanced-cache.php, object-cache.php and db.php, its configuration files,
#          rules in .htaccess) and Elementor (a page whose data names the own address, and the CSS files that Elementor generates from it).
#
# The plugins are downloaded from wordpress.org by WP-CLI (this needs the internet). Steps that belong to one plugin are tried one by one: a step
# that fails prints "SEED STEP FAILED: ..." and the seed goes on, so that one plugin that does not install does not spoil the rest.
set -uo pipefail

directory="$1"
port="$2"
title="$3"
database="$4"
kind="$5"
domain="localhost:$port"
password="${WORDPRESS_ADMIN_PASSWORD:-Seed-admin-0123456789}"
fixtures="/app/tests/fixtures/wordpress"

export HOME=/tmp WP_CLI_CACHE_DIR=/tmp/wp-cli-cache
wp() { command wp --path="$directory" "$@"; }
# try <description> <command...>: a step that may fail without ending the seed
try() {
    local what="$1"
    shift
    if ! "$@"; then
        echo "SEED STEP FAILED: $what" >&2
    fi
}

cd "$directory"

wp config create --dbname="$database" --dbuser=root --dbpass="${DB_ROOT_PASSWORD:-prestashop}" --dbhost=db --force --skip-check || exit 1
wp core install --url="http://$domain" --title="$title" --admin_user=seedadmin --admin_password="$password" --admin_email=admin@example.com --skip-email || exit 1
wp user create editor editor@example.com --role=editor --user_pass="$password" --user_url="http://$domain/author/editor/"
wp option update blogdescription "Seeded with the plugins of a real site"
wp rewrite structure '/%postname%/'
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
mkdir -p wp-content/uploads/seed
printf 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' | base64 -d > wp-content/uploads/seed/photo.png

# an attachment for the images (no image library here: made by hand, like in the other seed)
attachment() {   # attachment <title> <file in uploads>: the post, and the record of its file (without it WordPress takes the guid for the address)
    local id
    id=$(wp post create --post_type=attachment --post_status=inherit --post_title="$1" --post_mime_type=image/png \
        --guid="http://$domain/wp-content/uploads/$2" --porcelain | head -n 1)
    wp post meta update "$id" _wp_attached_file "$2" >/dev/null
    echo "$id"
}

# a request as a visitor (the Host header is the site's, the address is the service's)
visit() {   # visit <path>
    curl -s -o /dev/null -w "visit $1: %{http_code}\n" -H "Host: $domain" "http://$SERVICE$1"
}
SERVICE="${SERVICE:-}"

# --------------------------------------------------------------------------------------------------------- the shop
if [ "$kind" = shop ]; then
    SERVICE=wordpressshop
    try "install the plugins" wp plugin install woocommerce wordpress-seo redis-cache --activate

    wp option update woocommerce_currency EUR
    wp option update woocommerce_default_country DE:BE
    wp option update woocommerce_store_address "Teststrasse 1"
    wp option update woocommerce_store_city "Berlin"
    wp option update woocommerce_store_postcode "10115"
    # the pages of the shop (WooCommerce makes them when it is installed; make sure)
    try "install the pages" wp wc tool run install_pages --user=seedadmin

    # --- products: simple ones with the own address in the text, one variable product, categories
    wp term create product_cat "Seed clothing" --porcelain >/dev/null
    wp term create product_cat "Seed books" --porcelain >/dev/null
    image=$(attachment "Product photo" seed/photo.png)
    p1=$(wp wc product create --user=seedadmin --name="Seed T-Shirt" --type=simple --regular_price=19.90 --sku=SEED-TSHIRT \
        --description="<p>A shirt. See the <a href=\"http://$domain/shop/\">shop</a> and <img src=\"http://$domain/wp-content/uploads/seed/photo.png\"> and the partner <a href=\"https://partner.example/offer\">offer</a>.</p>" \
        --short_description="Soft cotton" --manage_stock=true --stock_quantity=50 --porcelain)
    p2=$(wp wc product create --user=seedadmin --name="Seed Book" --type=simple --regular_price=12.50 --sale_price=9.90 --sku=SEED-BOOK \
        --description="A book about backups: http://$domain/shop/" --short_description="Read it" --manage_stock=true --stock_quantity=20 --porcelain)
    p3=$(wp wc product create --user=seedadmin --name="Seed Mug" --type=simple --regular_price=7.00 --sku=SEED-MUG --porcelain)
    p4=$(wp wc product create --user=seedadmin --name="Seed Download" --type=simple --virtual=true --downloadable=true --regular_price=5.00 --sku=SEED-DL \
        --downloads="[{\"name\":\"Manual\",\"file\":\"http://$domain/wp-content/uploads/seed/photo.png\"}]" --porcelain)
    echo "products: $p1 $p2 $p3 $p4"
    # a variable product with two variations
    pv=$(wp wc product create --user=seedadmin --name="Seed Hoodie" --type=variable --sku=SEED-HOODIE \
        --attributes='[{"name":"Size","visible":true,"variation":true,"options":["S","M"]}]' --porcelain)
    wp wc product_variation create "$pv" --user=seedadmin --regular_price=39.00 --attributes='[{"name":"Size","option":"S"}]' --porcelain >/dev/null
    wp wc product_variation create "$pv" --user=seedadmin --regular_price=41.00 --attributes='[{"name":"Size","option":"M"}]' --porcelain >/dev/null
    wp post meta update "$p1" _thumbnail_id "$image"

    # --- customers and orders (custom order tables, the default of a new WooCommerce)
    c1=$(wp wc customer create --user=seedadmin --email=anna@example.com --first_name=Anna --last_name=Seed --username=anna --password="$password" \
        --billing='{"first_name":"Anna","last_name":"Seed","address_1":"Teststrasse 1","city":"Berlin","postcode":"10115","country":"DE","email":"anna@example.com","phone":"+49 30 1234"}' --porcelain)
    c2=$(wp wc customer create --user=seedadmin --email=ben@example.com --first_name=Ben --last_name=Seed --username=ben --password="$password" --porcelain)
    for i in 1 2 3 4 5; do
        o=$(wp wc shop_order create --user=seedadmin --customer_id="$c1" --status=processing --payment_method=cod --payment_method_title="Cash on delivery" \
            --billing='{"first_name":"Anna","last_name":"Seed","address_1":"Teststrasse 1","city":"Berlin","postcode":"10115","country":"DE","email":"anna@example.com"}' \
            --line_items="[{\"product_id\":$p1,\"quantity\":$i},{\"product_id\":$p2,\"quantity\":1}]" --customer_note="Please ring (order $i)" --porcelain)
        wp wc order_note create "$o" --user=seedadmin --note="Note with the own address: http://$domain/my-account/orders/ and a foreign one https://carrier.example/track/$i" --porcelain >/dev/null
        last_order=$o
    done
    wp wc shop_order create --user=seedadmin --customer_id="$c2" --status=completed --payment_method=bacs --payment_method_title="Bank transfer" \
        --line_items="[{\"product_id\":$p3,\"quantity\":2}]" --porcelain >/dev/null
    try "a refund" wp wc shop_order_refund create "$last_order" --user=seedadmin --amount=5.00 --reason="Seed refund" --api_refund=false --porcelain

    # --- coupons, webhooks, gateways
    wp wc shop_coupon create --user=seedadmin --code=WELCOME10 --amount=10 --discount_type=percent --description="Welcome, see http://$domain/shop/" --porcelain >/dev/null
    wp wc shop_coupon create --user=seedadmin --code=EURO5 --amount=5 --discount_type=fixed_cart --porcelain >/dev/null
    try "a webhook to the own site" wp wc webhook create --user=seedadmin --name="Order created (own site)" --topic=order.created --delivery_url="http://$domain/wp-json/seed/hook" --secret=seed-webhook-secret --status=active --porcelain
    try "a webhook to another service" wp wc webhook create --user=seedadmin --name="Order created (CRM)" --topic=order.created --delivery_url="https://crm.example.net/woo/hook" --secret=seed-webhook-secret-2 --status=active --porcelain
    wp wc payment_gateway update cod --user=seedadmin --enabled=true
    wp wc payment_gateway update bacs --user=seedadmin --enabled=true
    # payment settings of a live shop, with made-up values (a dev copy would still hold them)
    wp option update woocommerce_paypal_settings '{"enabled":"yes","title":"PayPal","email":"shop@example.com","testmode":"no","api_username":"seed_api_user","api_password":"seed-not-a-real-password","api_signature":"seed-not-a-real-signature"}' --format=json
    wp option update woocommerce_stripe_settings '{"enabled":"yes","testmode":"no","publishable_key":"pk_live_seed_not_real","secret_key":"sk_live_seed_not_real","webhook_secret":"whsec_seed_not_real"}' --format=json
    wp option update woocommerce_email_from_address "shop@example.com"
    wp option update woocommerce_myaccount_page_url "http://$domain/my-account/"

    # --- Yoast SEO: the indexables hold the address of every post
    try "Yoast SEO index" wp yoast index --skip-confirmation
    wp option update wpseo_social '{"facebook_site":"https://www.facebook.com/seed.example"}' --format=json

    # --- Redis Object Cache on the service `redis` (Predis comes with the plugin; the drop-in needs the server)
    wp config set WP_REDIS_HOST redis --type=constant
    wp config set WP_REDIS_PORT 6379 --raw --type=constant
    wp config set WP_REDIS_CLIENT predis --type=constant
    wp config set WP_REDIS_DATABASE 0 --raw --type=constant
    try "enable the Redis object cache" wp redis enable

    # --- a visitor puts a product into the cart (a row in woocommerce_sessions, transients)
    visit "/"
    visit "/?add-to-cart=$p1"
    visit "/shop/"
    visit "/cart/"
    visit "/checkout/"
    mkdir -p wp-content/uploads/wc-logs
    printf '2026-10-30T10:00:00+00:00 INFO Seed log line of WooCommerce\n' > wp-content/uploads/wc-logs/seed-log.log
fi

# --------------------------------------------------------------------------------------------------------- the cache site
if [ "$kind" = cache ]; then
    SERVICE=wordpresscache
    # Elementor first and the page with it: once W3 Total Cache is active, its database cache (db.php) stands between WP-CLI and the rows it just wrote
    try "install Elementor" wp plugin install elementor --activate

    # --- Elementor: a page whose data names the own address (image, link), and the CSS that Elementor generates from it
    cat > /tmp/elementor-data.json <<EOF
[{"id":"a1b2c3d","elType":"section","settings":{"background_background":"classic","background_image":{"url":"http://$domain/wp-content/uploads/seed/photo.png","id":0}},"elements":[{"id":"e4f5a6b","elType":"column","settings":{"_column_size":100},"elements":[{"id":"c7d8e9f","elType":"widget","widgetType":"heading","settings":{"title":"Built with Elementor","link":{"url":"http:\/\/$domain\/about-elementor\/","is_external":""}},"elements":[]},{"id":"1a2b3c4","elType":"widget","widgetType":"button","settings":{"text":"Partner","link":{"url":"https:\/\/partner.example\/offer"}},"elements":[]}]}]}]
EOF
    page=$(wp post create --post_type=page --post_status=publish --post_title="About Elementor" --post_name=about-elementor --post_content="" --porcelain | head -n 1)
    wp post meta update "$page" _elementor_edit_mode builder
    wp post meta update "$page" _elementor_template_type wp-page
    wp post meta update "$page" _elementor_version "$(wp plugin get elementor --field=version | head -n 1)"
    wp post meta update "$page" _elementor_data "$(cat /tmp/elementor-data.json)"
    try "Elementor kit" wp elementor update db
    wp option update elementor_disable_color_schemes yes
    wp option update elementor_css_print_method external

    try "install W3 Total Cache" wp plugin install w3-total-cache --activate

    # --- W3 Total Cache: page cache on disk with rules in .htaccess, object cache and database cache (their drop-ins), minify
    try "W3TC page cache" wp w3tc option set pgcache.enabled true --type=boolean
    try "W3TC page cache engine" wp w3tc option set pgcache.engine file_generic
    try "W3TC object cache" wp w3tc option set objectcache.enabled true --type=boolean
    try "W3TC object cache engine" wp w3tc option set objectcache.engine file
    try "W3TC database cache" wp w3tc option set dbcache.enabled true --type=boolean
    try "W3TC database cache engine" wp w3tc option set dbcache.engine file
    try "W3TC browser cache" wp w3tc option set browsercache.enabled true --type=boolean
    try "W3TC the environment (drop-ins, rules)" wp w3tc fix_environment all
    wp config set WP_CACHE true --raw --type=constant

    # the CSS is generated when the page is seen, and written to wp-content/uploads/elementor/css
    visit "/"
    visit "/about-elementor/"
    visit "/about-elementor/"
    # a page of the page cache
    visit "/hello-world/"
    visit "/hello-world/"
fi

# a site that is seeded
touch .threadsafe-seeded
echo "Seeded $title ($kind)"
