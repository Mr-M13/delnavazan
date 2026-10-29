#!/usr/bin/env bash
# Authorised recovery of one failed calendar hand-off. No other record is touched.
set -Eeuo pipefail
readonly HOST="cp119.hosting-cloud.net"
readonly ROOT_REL="domains/delnavazan.com/public_html"
readonly SHA_RE='^[0-9a-f]{40}$'
readonly PHP_FILE="$(cd "$(dirname "$BASH_SOURCE")" && pwd)/production-calendar-job-recovery.php"
fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
[[ $# == 3 && "$1" =~ $SHA_RE && "$2" =~ ^[1-9][0-9]*$ && "$3" == 'RECOVER' ]] || fail 'usage: production-calendar-job-recovery.sh <full-current-main-sha> <job-id> RECOVER'
: "${PRODUCTION_SSH_USER:?Set the protected production SSH user}"
[[ "$PRODUCTION_SSH_USER" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'SSH username contains unsupported characters'
[[ -f "$PHP_FILE" ]] || fail 'recovery PHP payload is missing'
code="$(tail -n +2 "$PHP_FILE" | base64 | tr -d '\n')"
[[ -n "$code" ]] || fail 'recovery PHP payload is empty'
ssh -T "${PRODUCTION_SSH_USER}@${HOST}" bash -s -- "$ROOT_REL" "$code" "$2" <<'REMOTE'
set -Eeuo pipefail
root_rel="$1"; code="$2"; job_id="$3"
[[ "$root_rel" == domains/delnavazan.com/public_html ]] || { echo 'FAIL invalid recovery target' >&2; exit 1; }
root="$HOME/$root_rel"
[[ -f "$root/wp-load.php" ]] || { echo 'FAIL expected WordPress root is unavailable' >&2; exit 1; }
command -v wp >/dev/null || { echo 'FAIL WP-CLI is required' >&2; exit 1; }
set +e
state="$(DZN_CALENDAR_JOB_ID="$job_id" wp --path="$root" eval "eval(base64_decode('$code'));" 2>&1)"
rc=$?
set -e
printf 'CALENDAR_JOB_RECOVERY=%s\n' "$state"
exit $rc
REMOTE
