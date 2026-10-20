#!/usr/bin/env bash
# oracle.sh <source|target>
# Saves the current .htaccess and the one PrestaShop itself would generate for the current database state into
# .cache/oracle/ so that the integration tests (which cannot run docker) can compare them.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

stack="${1:-}"
case "$stack" in
    source) service=prestashop ;;
    target) service=target-prestashop ;;
    *) die "usage: oracle.sh <source|target>" ;;
esac

out="$REPO_ROOT/.cache/oracle"
mkdir -p "$out"

dc exec -T "$service" cat /var/www/html/.htaccess > "$out/$stack.current.htaccess"
dc exec -T -u www-data "$service" php < "$STACK_DIR/oracle/htaccess-oracle.php" > "$out/$stack.regenerated.htaccess"
log "Oracle files written to .cache/oracle/ ($stack)"
