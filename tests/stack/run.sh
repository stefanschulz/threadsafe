#!/usr/bin/env bash
# Runs the whole integration pipeline:
#   1. seed the source stack and clone it into the target stack,
#   2. phase one of the PHPUnit "integration" suite: baseline, steps against scratch shops, and the migration of the
#      clone (group "after-migration" excluded),
#   3. ask PrestaShop itself what the migrated target's .htaccess should look like (oracle),
#   4. phase two: the tests that need the migrated site (group "after-migration").
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
phpunit --exclude-group after-migration "$@"

"$STACK_DIR/oracle.sh" target

log "Integration tests, phase 2: the migrated site"
phpunit --group after-migration "$@"
