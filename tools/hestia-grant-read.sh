#!/usr/bin/env bash
#
# Lets the system user of a ThreadSafe instance read the files of another site on a HestiaCP server (a user per domain), with a
# POSIX ACL: only that one user gets read access, the owner, the group and everybody else are left as they are.
#
#   hestia-grant-read.sh [options] TARGET_USER DOMAIN
#
# TARGET_USER  the Hestia user that owns the site to be backed up
# DOMAIN       its domain: the files are /home/TARGET_USER/web/DOMAIN/public_html
#
# Options
#   --as=USER      the user of the ThreadSafe instance (default: the-loom)
#   --also=DIR     one more directory of the domain, relative to web/DOMAIN (e.g. private); may be given more than once
#   --revoke       take the access away again (give the same --also directories); the way through the user's home stays open
#                  while another domain of that user is still granted
#   --dry-run      print what would be done, change nothing
#   -h, --help     this text
#
# Run it as root. It needs the package "acl" (apt install acl). Afterwards the PHP of the instance must also be allowed to look
# there: add /home/TARGET_USER/web/DOMAIN/public_html to the open_basedir of its PHP-FPM pool (a custom Hestia template, see
# docs/CONCEPT.md section 9). The access is read-only; a file that the site's application changes to mode 600 later is not
# readable for the instance until the script is run again.

set -euo pipefail

AS_USER='the-loom'
HOME_BASE="${HOME_BASE:-/home}"
REVOKE=0
DRY=0
ALSO=()
POSITIONAL=()

usage() {
    # the comment at the top of this file
    sed -n '2,/^set -euo/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'
}

die() {
    printf 'error: %s\n' "$*" >&2
    exit 1
}

for argument in "$@"; do
    case "$argument" in
        --as=*) AS_USER="${argument#--as=}" ;;
        --also=*) ALSO+=("${argument#--also=}") ;;
        --revoke) REVOKE=1 ;;
        --dry-run) DRY=1 ;;
        -h | --help) usage; exit 0 ;;
        -*) die "unknown option: $argument (try --help)" ;;
        *) POSITIONAL+=("$argument") ;;
    esac
done

[ "${#POSITIONAL[@]}" -eq 2 ] || die 'give the target user and the domain (try --help)'
TARGET_USER="${POSITIONAL[0]}"
DOMAIN="${POSITIONAL[1]}"

# names that cannot lead out of the directories they stand for
[[ "$TARGET_USER" =~ ^[a-z0-9][a-z0-9._-]*$ ]] || die "not a user name: $TARGET_USER"
[[ "$AS_USER" =~ ^[a-z0-9][a-z0-9._-]*$ ]] || die "not a user name: $AS_USER"
[[ "$DOMAIN" =~ ^[a-z0-9][a-z0-9.-]*$ ]] || die "not a domain: $DOMAIN"
for extra in "${ALSO[@]:-}"; do
    [ -z "$extra" ] || [[ "$extra" =~ ^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$ && "$extra" != *..* ]] || die "not a directory below the domain: $extra"
done

[ "$(id -u)" -eq 0 ] || die 'run this as root'
command -v setfacl >/dev/null || die 'setfacl is missing: apt install acl'
id "$AS_USER" >/dev/null 2>&1 || die "no such user: $AS_USER"
id "$TARGET_USER" >/dev/null 2>&1 || die "no such user: $TARGET_USER"
[ "$AS_USER" != "$TARGET_USER" ] || die 'the instance and the site are the same user: nothing to grant'

USER_HOME="$HOME_BASE/$TARGET_USER"
DOMAIN_DIR="$USER_HOME/web/$DOMAIN"
TREES=("$DOMAIN_DIR/public_html")
for extra in "${ALSO[@]:-}"; do
    [ -z "$extra" ] || TREES+=("$DOMAIN_DIR/$extra")
done
for tree in "${TREES[@]}"; do
    [ -d "$tree" ] || die "no such directory: $tree"
done

# runs a command, or only shows it
run() {
    if [ "$DRY" -eq 1 ]; then
        printf '  would run:'
        printf ' %q' "$@"
        printf '\n'
    else
        "$@"
    fi
}

# whether $AS_USER still has an entry on a public_html of another domain of the target user (the way to it must stay open then)
still_used() {
    local other
    for other in "$USER_HOME"/web/*/public_html; do
        [ -d "$other" ] || continue
        [ "$other" = "$DOMAIN_DIR/public_html" ] && continue
        if getfacl -p "$other" 2>/dev/null | grep -q "^user:$AS_USER:"; then
            return 0
        fi
    done
    return 1
}

if [ "$REVOKE" -eq 0 ]; then
    echo "Granting $AS_USER read access to $DOMAIN of $TARGET_USER ($DOMAIN_DIR)"
    # the way to the site: only "x", so that the directories can be passed through but not listed
    run setfacl -m "u:$AS_USER:x" "$USER_HOME" "$USER_HOME/web" "$DOMAIN_DIR"
    for tree in "${TREES[@]}"; do
        echo "  $tree"
        # the files and directories that are there ...
        run setfacl -R -m "u:$AS_USER:rX" "$tree"
        # ... and the ones that will be made later (a default entry belongs to directories only)
        run find "$tree" -type d -exec setfacl -d -m "u:$AS_USER:rX" {} +
    done
else
    echo "Taking the access of $AS_USER to $DOMAIN of $TARGET_USER away"
    for tree in "${TREES[@]}"; do
        echo "  $tree"
        run setfacl -R -x "u:$AS_USER" "$tree"
        run find "$tree" -type d -exec setfacl -d -x "u:$AS_USER" {} +
    done
    run setfacl -x "u:$AS_USER" "$DOMAIN_DIR"
    if [ "$DRY" -eq 1 ] || ! still_used; then
        run setfacl -x "u:$AS_USER" "$USER_HOME" "$USER_HOME/web"
    else
        echo "  $AS_USER keeps the way through $USER_HOME: another domain of $TARGET_USER is still granted"
    fi
fi

if [ "$DRY" -eq 1 ]; then
    echo 'Dry run: nothing was changed.'
    exit 0
fi

# the proof: what the instance's user sees now
if command -v runuser >/dev/null; then
    if runuser -u "$AS_USER" -- test -r "$DOMAIN_DIR/public_html" -a -x "$DOMAIN_DIR/public_html" 2>/dev/null; then
        if [ "$REVOKE" -eq 0 ]; then
            echo "OK: $AS_USER can read $DOMAIN_DIR/public_html"
        else
            echo "Warning: $AS_USER can still read $DOMAIN_DIR/public_html (another rule, or the permissions allow it anyway)" >&2
        fi
    elif [ "$REVOKE" -eq 0 ]; then
        echo "Warning: $AS_USER still cannot read $DOMAIN_DIR/public_html. Look at: getfacl $DOMAIN_DIR/public_html" >&2
        exit 1
    else
        echo "OK: $AS_USER can no longer read $DOMAIN_DIR/public_html"
    fi
fi

if [ "$REVOKE" -eq 0 ]; then
    echo "Next: add $DOMAIN_DIR/public_html to the open_basedir of the PHP-FPM pool of the instance."
fi
