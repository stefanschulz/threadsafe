#!/usr/bin/env bash
# The passkeys in a real browser (docs/PHASE14.md 4.2; phase 4h of run.sh): Chrome with a virtual authenticator drives the web interface behind the
# real Apache of the service "web" (5 seconds, 64 MB), through its own pages. The installation of the service is made empty and given an administrator
# first, so this can run on its own: tests/stack/passkeys.sh. It needs node and Chrome (or Edge) on the machine that runs it, not in a container;
# without them it says so and stops with a status of 0, because the other phases do not depend on it.
#
# What it leaves behind: no passkey and no second factor, an administrator with the password below. The next run of the web phase starts from
# empty volumes anyway.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

PASSWORD="passkey stack password"
PORT="${WEB_PORT:-8390}"

if ! command -v node >/dev/null 2>&1; then
    log "Passkeys in a real browser: node is not installed, skipped"
    exit 0
fi

dc up -d --wait db >/dev/null
dc up -d web >/dev/null
wait_http "http://localhost:$PORT/" "localhost" 120

log "Making the installation of the web service empty and giving it an administrator"
dc run --rm tools sh -c '
    find /web-data /web-storage -mindepth 1 -delete &&
    printf "%s\n" "$0" > /tmp/pw &&
    THREADSAFE_DATA=/web-data php bin/threadsafe admin password --password-file=/tmp/pw >/dev/null &&
    THREADSAFE_DATA=/web-data php bin/threadsafe admin enable >/dev/null &&
    chown -R www-data:www-data /web-data /web-storage
' "$PASSWORD"

log "Passkeys: a real Chrome with a virtual authenticator against http://localhost:$PORT"
status=0
node tests/stack/passkey-browser.mjs --url="http://localhost:$PORT" --password="$PASSWORD" || status=$?
if [ "$status" -eq 2 ]; then
    log "Passkeys in a real browser: no Chrome or Edge found on this machine, skipped"
    exit 0
fi
exit "$status"
