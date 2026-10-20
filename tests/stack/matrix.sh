#!/usr/bin/env bash
# Runs the whole integration pipeline (run.sh) once per database server: MariaDB 10.11 and MySQL 8.4. The servers share
# the Compose project, ports and networks but have their own volumes (VOLUME_SUFFIX); starting the next variant recreates
# the containers.
#
#   tests/stack/matrix.sh [phpunit arguments]
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

variants=("mariadb:10.11|" "mysql:8.4|_mysql")

for variant in "${variants[@]}"; do
    image="${variant%%|*}"
    suffix="${variant##*|}"

    log "===== Database server $image ====="
    DB_IMAGE="$image" VOLUME_SUFFIX="$suffix" "$STACK_DIR/run.sh" "$@"
done

log "All database servers passed"
