#!/usr/bin/env bash
# Backups across the two kinds of database server: the source stack runs on one kind, the target's database on the other
# (the group "cross-server": the seeded shop is backed up, restored with `threadsafe restore` onto the other kind and compared, and
# a MySQL 8 collation is refused on MariaDB). Both directions, MySQL 8.4 -> MariaDB 10.11 and MariaDB 10.11 -> MySQL 8.4.
#
# The source shop must be seeded on the source server's volumes (matrix.sh / run.sh do that; the first start installs PrestaShop and
# takes a few minutes). The target database has volumes of its own per kind (TARGET_DB_SUFFIX), because a data directory of one
# kind cannot be started by the other.
#
#   tests/stack/cross.sh [phpunit arguments]
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

# source image | source volume suffix | target image | target volume suffix
directions=("mysql:8.4|_mysql|mariadb:10.11|_cross_mariadb" "mariadb:10.11||mysql:8.4|_cross_mysql")

for direction in "${directions[@]}"; do
    IFS='|' read -r source_image source_suffix target_image target_suffix <<<"$direction"
    log "===== Source $source_image, target database $target_image ====="
    export DB_IMAGE="$source_image" VOLUME_SUFFIX="$source_suffix" TARGET_DB_IMAGE="$target_image" TARGET_DB_SUFFIX="$target_suffix"

    "$STACK_DIR/seed.sh"
    dc up -d --wait target-db >/dev/null
    dc up -d target-prestashop >/dev/null
    wait_http "http://localhost:${TARGET_PORT}/" "localhost:${TARGET_PORT}" 120

    dc run --rm -e THREADSAFE_STACK=1 tools vendor/bin/phpunit --testsuite integration --group cross-server "$@"
done

unset DB_IMAGE VOLUME_SUFFIX TARGET_DB_IMAGE TARGET_DB_SUFFIX
log "Both directions passed"
