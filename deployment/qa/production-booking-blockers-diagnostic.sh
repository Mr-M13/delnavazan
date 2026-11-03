#!/usr/bin/env bash
# Read-only production booking/notification blocker diagnostic. Never mutates state.
set -Eeuo pipefail
readonly HOST="cp119.hosting-cloud.net"
readonly ROOT_REL="domains/delnavazan.com/public_html"
readonly PHP_FILE="$(cd "$(dirname "$BASH_SOURCE")" && pwd)/production-booking-blockers-diagnostic.php"
fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
: "${PRODUCTION_SSH_USER:?Set the protected production SSH user}"
[[ "$PRODUCTION_SSH_USER" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'SSH username contains unsupported characters'
[[ -f "$PHP_FILE" ]] || fail 'diagnostic PHP payload is missing'
code="$(tail -n +2 "$PHP_FILE" | base64 | tr -d '\n')"
[[ -n "$code" ]] || fail 'diagnostic PHP payload is empty'
ssh -T "${PRODUCTION_SSH_USER}@${HOST}" bash -s -- "$ROOT_REL" "$code" <<'REMOTE'
set -Eeuo pipefail
root_rel="$1"; code="$2"
[[ "$root_rel" == domains/delnavazan.com/public_html ]] || { echo 'FAIL invalid diagnostic target' >&2; exit 1; }
root="$HOME/$root_rel"
[[ -f "$root/wp-load.php" ]] || { echo 'FAIL expected WordPress root is unavailable' >&2; exit 1; }
command -v wp >/dev/null || { echo 'FAIL WP-CLI is required' >&2; exit 1; }
printf 'MAINTENANCE_MODE=%s\n' "$(wp --path="$root" --skip-plugins --skip-themes maintenance-mode is-active >/dev/null 2>&1 && echo active || echo inactive)"
state="$(wp --path="$root" --skip-themes eval "eval(base64_decode('$code'));")"
printf 'BOOKING_BLOCKERS=%s\n' "$state"
REMOTE
