#!/usr/bin/env bash
# Runs the whole integration pipeline: seed the source stack, clone it into the target stack, write the
# .htaccess oracle files and run the PHPUnit "integration" suite inside the tools container.
#
#   tests/stack/run.sh [phpunit arguments]
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

"$STACK_DIR/seed.sh"
"$STACK_DIR/clone.sh"
"$STACK_DIR/oracle.sh" source

log "Running the integration tests"
dc run --rm -e THREADSAFE_STACK=1 tools vendor/bin/phpunit --testsuite integration "$@"
