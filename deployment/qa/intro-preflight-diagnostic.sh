#!/usr/bin/env bash
# Read-only diagnostic for the admin "Create intro" pre-flight.
#
# Prints the exact refusal of each precondition. It never writes, never takes a
# backup and never requires a confirm token. Only the workflow-dispatch-only
# diagnose-intro-preflight.yml may run it.
set -Eeuo pipefail

readonly HOST="cp119.hosting-cloud.net"
readonly ROOT_REL="domains/delnavazan.com/public_html"
readonly SHA_RE='^[0-9a-f]{40}$'
readonly STUDENT_RE='^DZN-STU-[A-Z0-9]+$'
readonly QA_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }

usage() {
  cat >&2 <<EOF
usage:
  bash deployment/qa/intro-preflight-diagnostic.sh <full-current-main-sha> <student-reference> <teacher-id> <course-id> <local-date> <local-time> <timezone>
EOF
  exit 64
}

[[ $# == 7 ]] || usage
sha="$1"; student_ref="$2"; teacher_id="$3"; course_id="$4"; local_date="$5"; local_time="$6"; timezone="$7"

[[ -f "$QA_DIR/diagnose-intro-preflight.php" ]] || fail 'diagnostic payload is missing'
[[ "$sha" =~ $SHA_RE ]] || fail 'first argument must be the full 40-character current main SHA'
[[ "$student_ref" =~ $STUDENT_RE ]] || fail 'student reference must look like DZN-STU-...'
[[ "$teacher_id" =~ ^[0-9]+$ ]] || fail 'teacher id must be numeric'
[[ "$course_id" =~ ^[0-9]+$ ]] || fail 'course id must be numeric'
[[ "$local_date" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || fail 'local date must be YYYY-MM-DD'
[[ "$local_time" =~ ^[0-9]{2}:[0-9]{2}$ ]] || fail 'local time must be HH:MM'
: "${PRODUCTION_SSH_USER:?Set the protected production SSH user}"
[[ "$PRODUCTION_SSH_USER" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'SSH username contains unsupported characters'

bundle="$(tar -czf - -C "$QA_DIR" diagnose-intro-preflight.php | base64 | tr -d '\n')"
[[ -n "$bundle" ]] || fail 'payload bundle is empty'

ssh -T "${PRODUCTION_SSH_USER}@${HOST}" bash -s -- \
  "$ROOT_REL" "$bundle" "$sha" "$student_ref" "$teacher_id" "$course_id" "$local_date" "$local_time" "$timezone" <<'REMOTE'
set -Eeuo pipefail
root_rel="$1"; bundle="$2"; sha="$3"; student_ref="$4"; teacher_id="$5"; course_id="$6"; local_date="$7"; local_time="$8"; timezone="$9"

fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }

[[ "$root_rel" == domains/delnavazan.com/public_html ]] || fail 'invalid diagnostic target'
[[ "$sha" =~ ^[0-9a-f]{40}$ ]] || fail 'invalid source SHA'
root="$HOME/$root_rel"
[[ -f "$root/wp-load.php" && -f "$root/wp-config.php" ]] || fail 'expected WordPress root is unavailable'
command -v wp >/dev/null || fail 'WP-CLI is required'

build="$(wp --path="$root" eval 'echo defined("DZN_PLATFORM_BUILD_ID") ? DZN_PLATFORM_BUILD_ID : "";')"
[[ "$build" == "main-${sha:0:12}" ]] || fail "deployed build identity ($build) does not match main-${sha:0:12}"

tmp="$(mktemp -d)"
cleanup(){ rm -rf "$tmp"; }
trap cleanup EXIT
printf '%s' "$bundle" | base64 -d | tar -xzf - -C "$tmp"
[[ -f "$tmp/diagnose-intro-preflight.php" ]] || fail 'payload extraction failed'

wp --path="$root" eval-file "$tmp/diagnose-intro-preflight.php" "$student_ref" "$teacher_id" "$course_id" "$local_date" "$local_time" "$timezone"
REMOTE
