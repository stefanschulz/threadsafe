#!/usr/bin/env bash
# Spike for phase 6, work package 1 (docs/PHASE6.md section 8): a Joomla 5 and a Joomla 6 installed by the images' own installer, each
# with a database of its own on the `db` service of the Docker stack (tests/stack must have started it), so that the facts of section 3.1
# can be read from real installations. Throw-away: down.sh removes the containers and the two databases. The passwords are test values.
set -euo pipefail
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'

DB=threadsafe-dev-db-1
DBPASS="${DB_ROOT_PASSWORD:-prestashop}"
ADMINPASS='Spike-admin-0123456789'
NET="$(docker inspect "$DB" --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' | awk '{print $1}')"
[ -n "$NET" ] || { echo "the db container of the stack is not running" >&2; exit 1; }

start() { # start <name> <image> <port> <database>
    docker exec "$DB" mariadb -uroot -p"$DBPASS" -e "DROP DATABASE IF EXISTS $4; CREATE DATABASE $4 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    docker rm -f -v "$1" >/dev/null 2>&1 || true
    docker run -d --name "$1" --network "$NET" -p "$3:80" \
        -e JOOMLA_DB_HOST=db -e JOOMLA_DB_USER=root -e JOOMLA_DB_PASSWORD="$DBPASS" -e JOOMLA_DB_NAME="$4" \
        -e JOOMLA_SITE_NAME="Spike $4" -e JOOMLA_ADMIN_USER="Spike Admin" -e JOOMLA_ADMIN_USERNAME=spikeadmin \
        -e JOOMLA_ADMIN_PASSWORD="$ADMINPASS" -e JOOMLA_ADMIN_EMAIL=admin@example.com \
        "$2" >/dev/null
}

start ts-joomla-spike-5 joomla:5.4-php8.3-apache 8501 joomla5
start ts-joomla-spike-6 joomla:6-php8.4-apache 8502 joomla6

for c in ts-joomla-spike-5 ts-joomla-spike-6; do
    for i in $(seq 1 120); do
        if docker exec "$c" test -f /var/www/html/configuration.php && ! docker exec "$c" test -d /var/www/html/installation; then break; fi
        sleep 3
    done
    echo "$c: $(docker exec "$c" sh -c 'ls /var/www/html | head -30 | tr "\n" " "')"
done

# the probe that the experiments call inside the containers
for c in ts-joomla-spike-5 ts-joomla-spike-6; do
    docker cp "$(dirname "${BASH_SOURCE[0]}")/probe.php" "$c:/tmp/probe.php"
done
echo "ready: Joomla 5.4 on http://localhost:8501/ and Joomla 6 on http://localhost:8502/ (administrator: spikeadmin)"
echo "then: experiments.sh <container>, livesite.sh <container> <live_site>, dbtype.sh; down.sh removes everything"
