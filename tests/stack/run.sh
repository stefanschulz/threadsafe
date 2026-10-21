#!/usr/bin/env bash
# Runs the whole integration pipeline:
#   1. seed the source stack and clone it into the target stack,
#   2. phase one of the PHPUnit "integration" suite: baseline, steps against scratch shops, and the migration of the
#      clone (group "after-migration" excluded),
#   3. ask PrestaShop itself what the migrated target's .htaccess should look like (oracle),
#   4. phase two: the tests that need the migrated site (group "after-migration"),
#   5. phase three: the dev mode run on the migrated target (group "dev-migration"); it changes files and settings the
#      earlier phases compare with PrestaShop's own output.
#   6. phase four: the backup round trip (group "backup-roundtrip"): back up the source shop, unzip it and import the SQL
#      into the wiped target with the plain tools, compare, migrate; it overwrites the target. A second test restores the same
#      backup with `threadsafe restore` (one command). Before it, phase 4a restores into another database with another table prefix (group
#      "restore-variant"); that leaves the target shop on the other database, which is why the plain restore, and the disaster
#      recovery after it, come later.
#   7. phase five: disaster recovery (groups "disaster-prepare" and "disaster-restore"): the working target shop is backed up, the
#      target's containers and volumes are DESTROYED and made again empty on the same address, restore.php is uploaded into the
#      empty web directory and the wizard (served by the target's own Apache, with limits of 5 seconds and 64 MB) brings the shop
#      back; its pages, tables and files are compared with what they were.
#
#   tests/stack/run.sh [phpunit arguments]
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

"$STACK_DIR/seed.sh"
"$STACK_DIR/clone.sh"
"$STACK_DIR/oracle.sh" source

phpunit() {
    dc run --rm -e THREADSAFE_STACK=1 tools vendor/bin/phpunit --testsuite integration "$@"
}

log "Integration tests, phase 1: baseline, steps, migration of the clone"
phpunit --exclude-group after-migration --exclude-group dev-migration --exclude-group backup-roundtrip --exclude-group disaster-prepare --exclude-group disaster-restore --exclude-group cross-server --exclude-group restore-variant "$@"

"$STACK_DIR/oracle.sh" target

log "Integration tests, phase 2: the migrated site"
phpunit --group after-migration "$@"

log "Integration tests, phase 3: dev mode on the migrated target"
phpunit --group dev-migration "$@"

log "Integration tests, phase 4a: restore the source shop into another database with another table prefix"
phpunit --group restore-variant "$@"

log "Integration tests, phase 4: back up the source shop and restore the backup into the target"
phpunit --group backup-roundtrip "$@"

log "Integration tests, phase 5: disaster recovery"
phpunit --group disaster-prepare "$@"
log "Destroying the target stack: containers and volumes"
dc rm -sf target-prestashop target-db >/dev/null
docker volume rm "threadsafe-dev_target_ps_data${VOLUME_SUFFIX:-}" "threadsafe-dev_target_db_data${VOLUME_SUFFIX:-}" >/dev/null
log "Making the target stack again, empty, on the same address"
dc up -d --wait target-db >/dev/null
dc up -d target-prestashop >/dev/null
wait_http "http://localhost:${TARGET_PORT}/" "localhost:${TARGET_PORT}" 120
phpunit --group disaster-restore "$@"
