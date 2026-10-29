#!/usr/bin/env bash
set -Eeuo pipefail
readonly HOST="cp119.hosting-cloud.net"
readonly ROOT_REL="domains/delnavazan.com/public_html"
readonly SHA_RE='^[0-9a-f]{40}$'
readonly PHP_FILE="$(cd "$(dirname "$BASH_SOURCE")" && pwd)/production-calendar-projection-dispatch.php"
fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
[[ $# == 2 && "$1" =~ $SHA_RE && "$2" == 'DISPATCH' ]] || fail 'usage: production-calendar-projection-dispatch.sh <full-current-main-sha> DISPATCH'
: "${PRODUCTION_SSH_USER:?Set the protected production SSH user}"
[[ "$PRODUCTION_SSH_USER" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'SSH username contains unsupported characters'
[[ -f "$PHP_FILE" ]] || fail 'projection dispatch payload is missing'
code="$(tail -n +2 "$PHP_FILE" | base64 | tr -d '\n')"
[[ -n "$code" ]] || fail 'projection dispatch payload is empty'
ssh -T "${PRODUCTION_SSH_USER}@${HOST}" bash -s -- "$ROOT_REL" "$code" <<'REMOTE'
set -Eeuo pipefail
root_rel="$1"; code="$2"
[[ "$root_rel" == domains/delnavazan.com/public_html ]] || { echo 'FAIL invalid dispatch target' >&2; exit 1; }
root="$HOME/$root_rel"
[[ -f "$root/wp-load.php" ]] || { echo 'FAIL expected WordPress root is unavailable' >&2; exit 1; }
command -v wp >/dev/null || { echo 'FAIL WP-CLI is required' >&2; exit 1; }
set +e
state="$(wp --path="$root" eval "eval(base64_decode('$code'));" 2>&1)"
rc=$?
set -e
printf 'CALENDAR_PROJECTION_DISPATCH=%s\n' "$state"
exit $rc
REMOTE
