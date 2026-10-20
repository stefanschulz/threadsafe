#!/usr/bin/env bash
# Shared helpers for the Docker test stacks (sourced by the other scripts in this directory).
#
# Source stack: PrestaShop 9 + MariaDB, shop domain localhost:8190   (compose services db, prestashop)
# Target stack: empty Apache/PHP + MariaDB in an isolated network    (compose services target-db, target-prestashop)

set -euo pipefail

# Git Bash on Windows would rewrite paths such as /var/www/html in docker arguments.
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

STACK_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$STACK_DIR/../.." && pwd)"
# A relative path works for Linux, macOS and Git Bash on Windows alike (an MSYS path would not).
cd "$REPO_ROOT"
COMPOSE_FILE=".docker/docker-compose.yml"

DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-prestashop}"
SOURCE_DB_NAME="${SOURCE_DB_NAME:-prestashop}"
TARGET_DB_NAME="${TARGET_DB_NAME:-prestashop_target}"
SOURCE_PORT="${PS_PORT:-8190}"
TARGET_PORT="${TARGET_PORT:-8290}"

# dc <args>: docker compose with all profiles of the test environment
dc() {
    docker compose -f "$COMPOSE_FILE" --profile target --profile tools "$@"
}

log() {
    printf '\033[1;34m==>\033[0m %s\n' "$*" >&2
}

die() {
    printf '\033[1;31merror:\033[0m %s\n' "$*" >&2
    exit 1
}

# dbclient <service> <client arguments>: the command line client of the database server in that container. It is called
# "mariadb" in MariaDB images (newer ones no longer have "mysql") and "mysql" in MySQL images.
dbclient() {
    local service="$1"
    shift
    dc exec -T "$service" sh -c 'if command -v mariadb >/dev/null 2>&1; then exec mariadb "$@"; else exec mysql "$@"; fi' sh "$@"
}

# dbdump <service> <dump arguments>: mariadb-dump or mysqldump, whichever the container has
dbdump() {
    local service="$1"
    shift
    dc exec -T "$service" sh -c 'if command -v mariadb-dump >/dev/null 2>&1; then exec mariadb-dump "$@"; else exec mysqldump "$@"; fi' sh "$@"
}

# sql <service> <database> <statement>: run one statement as root and print the raw result (database may be empty)
sql() {
    local service="$1" database="$2" statement="$3"
    local args=(-uroot -p"$DB_ROOT_PASSWORD" -N -B)
    [ -n "$database" ] && args+=("$database")
    dbclient "$service" "${args[@]}" -e "$statement"
}

# wait_http <url> <host-header> <seconds>: wait until the URL answers with any HTTP status
wait_http() {
    local url="$1" host="$2" seconds="${3:-120}" i=0
    until [ "$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $host" --max-time 10 "$url" || true)" != "000" ]; do
        i=$((i + 1))
        [ "$i" -ge "$seconds" ] && die "$url did not answer within ${seconds}s"
        sleep 1
    done
}
