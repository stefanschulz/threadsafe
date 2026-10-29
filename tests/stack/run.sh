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
#   6b. phase 4b: the web interface (group "web-ui", service `web`: Apache with the document root on public/, limits of 5 seconds and 64 MB): setup in the
#      browser, a backup in many steps, check, download with resume, a run that is continued, two requests at once, security, and the restore
#      kit from the page restoring the web backup onto the target through the wizard. It leaves the target restored, as phase 4 does.
#   6c. phase 4c: the trigger on real backups (group "trigger"): `threadsafe cron` makes the due backup in one snapshot and a second call does nothing, the address /cron/<secret> of the
#      service `web` makes it by ticks (5 s / 64 MB) or through a detached worker, a trigger killed in the middle is continued; retention on real backups (a pinned and a faulty one stay).
#   6d. phase 4d: encrypted backups (group "encryption"): the seeded shop backed up for a key pair, opened by the real `age` tool, restored by `threadsafe restore --identity-file`
#      and through the wizard under 5 s / 64 MB with the key typed in; a passphrase backup opens with `age`. It wipes the target stack, like phase 4.
#   7. phase five: disaster recovery (groups "disaster-prepare" and "disaster-restore"): the working target shop is backed up, the
#      target's containers and volumes are DESTROYED and made again empty on the same address, threadsafe-restore.php is uploaded into the
#      empty web directory and the wizard (served by the target's own Apache, with limits of 5 seconds and 64 MB) brings the shop
#      back; its pages, tables and files are compared with what they were.
#   8. phase six: Joomla (group "joomla", the services joomla5 and joomla6, seeded by seed-joomla.sh): both seeded sites are backed up through their
#      profiles; the backups are restored into the target by one command on another domain, by one command into a sub-directory (the
#      .htaccess follows, the web service API answers there), and through the threadsafe-restore.php wizard under 5 s / 64 MB; the restored sites
#      serve, their content points to the new domain and to nothing of the old one, and their configuration fits the host. It wipes the target.
#
#   9. phase seven: the installer and the update (group "fresh", the service `fresh`: Apache and PHP 8.4 with nothing on it, limits of 5 s / 64 MB; no database):
#      the releases are built by tools/build-release.php, threadsafe-install.php installs one through the browser interface (both layouts), the update
#      switches to the next one through the System page with a real health check, a version that cannot start is taken back, and what is not
#      right (checksum, file, host, a backup running) is refused. It needs no stack of the other phases and leaves them alone.
#
#  10. phase eight: WordPress (group "wordpress", the services wordpress7, wordpress6 and wordpress60, seeded by seed-wordpress.sh with WP-CLI before phase 4b, because the web
#      interface backs up one of them there): the seeded sites are backed up
#      through their profiles; the backups are restored by one command on another domain with another table prefix, by one command into a
#      sub-directory, and through the threadsafe-restore.php wizard under 5 s / 64 MB, onto the service target-wordpress (a web server with mysqli on the
#      files of the target volume); the restored sites serve, their users log in and have their rights, `wp search-replace --dry-run` finds nothing of the
#      old address, and their configuration fits the host. It wipes the target.
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
phpunit --exclude-group after-migration --exclude-group dev-migration --exclude-group backup-roundtrip --exclude-group disaster-prepare --exclude-group disaster-restore --exclude-group cross-server --exclude-group restore-variant --exclude-group web-ui --exclude-group trigger --exclude-group encryption --exclude-group joomla --exclude-group fresh --exclude-group wordpress "$@"

"$STACK_DIR/oracle.sh" target

log "Integration tests, phase 2: the migrated site"
phpunit --group after-migration "$@"

log "Integration tests, phase 3: dev mode on the migrated target"
phpunit --group dev-migration "$@"

log "Integration tests, phase 4a: restore the source shop into another database with another table prefix"
phpunit --group restore-variant "$@"

log "Integration tests, phase 4: back up the source shop and restore the backup into the target"
phpunit --group backup-roundtrip "$@"

log "Seeding the WordPress sites (the web interface backs one of them up in phase 4b; phase 8 restores all of them)"
"$STACK_DIR/seed-wordpress.sh"

log "Integration tests, phase 4b: the web interface of ThreadSafe (Apache, 5 s / 64 MB), a backup through it restored with its own kit"
dc up -d web >/dev/null
wait_http "http://localhost:${WEB_PORT:-8390}/" "localhost" 120
phpunit --group web-ui "$@"

log "Integration tests, phase 4c: the trigger (command, address by ticks and by a detached worker, a killed trigger) and retention on real backups"
phpunit --group trigger "$@"

log "Integration tests, phase 4d: encrypted backups opened by the real age tool, restored by the command and through the wizard (5 s / 64 MB)"
phpunit --group encryption "$@"

log "Integration tests, phase 5: disaster recovery"
phpunit --group disaster-prepare "$@"
log "Destroying the target stack: containers and volumes"
dc rm -sf target-prestashop target-wordpress target-db >/dev/null
docker volume rm "threadsafe-dev_target_ps_data${VOLUME_SUFFIX:-}" "threadsafe-dev_target_db_data${VOLUME_SUFFIX:-}" >/dev/null
log "Making the target stack again, empty, on the same address"
dc up -d --wait target-db >/dev/null
dc up -d target-prestashop >/dev/null
wait_http "http://localhost:${TARGET_PORT}/" "localhost:${TARGET_PORT}" 120
phpunit --group disaster-restore "$@"

log "Integration tests, phase 6: Joomla 5 and 6 (backup, restore on another domain, into a sub-directory, through the wizard under 5 s / 64 MB)"
"$STACK_DIR/seed-joomla.sh"
phpunit --group joomla "$@"

log "Integration tests, phase 7: the installer and the update on a host with nothing on it (Apache, 5 s / 64 MB)"
dc up -d fresh >/dev/null
wait_http "http://localhost:${FRESH_PORT:-8490}/" "localhost" 120
phpunit --group fresh "$@"

log "Integration tests, phase 8: WordPress 7.1, 6.9 and 6.0 (backup, restore on another domain with another prefix, into a sub-directory, through the wizard under 5 s / 64 MB)"
dc up -d --wait target-db >/dev/null
dc up -d target-wordpress >/dev/null
wait_http "http://localhost:${TARGET_WORDPRESS_PORT:-8291}/" "localhost:${TARGET_WORDPRESS_PORT:-8291}" 120
phpunit --group wordpress "$@"
