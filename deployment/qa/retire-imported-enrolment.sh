#!/usr/bin/env bash
# Guarded retirement of one imported legacy Enrolment.
#
#   report  : read-only inventory + pre-state fingerprint. Changes nothing.
#   execute : takes a verified full database export, requires the exact report
#             fingerprint, then archives the imported lessons, Term and
#             Enrolment named by the reference. It never deletes rows.
#
# Only the protected, workflow-dispatch-only retire-imported-enrolment.yml may
# run execute mode.
set -Eeuo pipefail

readonly HOST="cp119.hosting-cloud.net"
readonly ROOT_REL="domains/delnavazan.com/public_html"
readonly SHA_RE='^[0-9a-f]{40}$'
readonly HASH_RE='^[0-9a-f]{64}$'
readonly CONFIRM_VALUE='RETIRE_IMPORTED_ENROLMENT'
readonly ENROLMENT_RE='^ENR-[A-Z0-9]+$'
readonly STUDENT_RE='^CC[A-Z0-9]+$'
readonly QA_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }

usage() {
  cat >&2 <<EOF
usage:
  bash deployment/qa/retire-imported-enrolment.sh report  <full-current-main-sha> <enrolment-reference> <student-reference>
  bash deployment/qa/retire-imported-enrolment.sh execute <full-current-main-sha> <enrolment-reference> <student-reference> <prestate-fingerprint> $CONFIRM_VALUE
EOF
  exit 64
}

[[ -f "$QA_DIR/retire-imported-enrolment.php" ]] || fail 'retirement payload is missing'
[[ -f "$QA_DIR/purge-test-data-tables.php" ]] || fail 'catalogue classification is missing'

case "${1:-}" in
  report)
    [[ $# == 4 ]] || usage
    mode='report'; sha="$2"; enrolment_ref="$3"; student_ref="$4"; fingerprint=''; confirm=''
    ;;
  execute)
    [[ $# == 6 ]] || usage
    mode='execute'; sha="$2"; enrolment_ref="$3"; student_ref="$4"; fingerprint="$5"; confirm="$6"
    [[ "$fingerprint" =~ $HASH_RE ]] || fail 'pre-state fingerprint must be a 64-character hex digest from report mode'
    [[ "$confirm" == "$CONFIRM_VALUE" ]] || fail "execute requires the exact confirm token $CONFIRM_VALUE"
    : "${DZN_RETIRE_ATTESTATION:?Set the protected workflow attestation token; direct execute is refused}"
    ;;
  *) usage ;;
esac

[[ "$sha" =~ $SHA_RE ]] || fail 'first argument must be the full 40-character current main SHA'
[[ "$enrolment_ref" =~ $ENROLMENT_RE ]] || fail 'enrolment reference must look like ENR-...'
[[ "$student_ref" =~ $STUDENT_RE ]] || fail 'student reference must look like CC...'
: "${PRODUCTION_SSH_USER:?Set the protected production SSH user}"
[[ "$PRODUCTION_SSH_USER" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'SSH username contains unsupported characters'

# OpenSSH drops empty trailing arguments, so send explicit placeholders.
[[ -n "$fingerprint" ]] || fingerprint='none'
[[ -n "$confirm" ]] || confirm='none'

bundle="$(tar -czf - -C "$QA_DIR" retire-imported-enrolment.php purge-test-data-tables.php | base64 | tr -d '\n')"
[[ -n "$bundle" ]] || fail 'payload bundle is empty'

attestation="${DZN_RETIRE_ATTESTATION:-none}"

ssh -T "${PRODUCTION_SSH_USER}@${HOST}" bash -s -- \
  "$ROOT_REL" "$bundle" "$mode" "$sha" "$enrolment_ref" "$student_ref" "$fingerprint" "$confirm" "$attestation" <<'REMOTE'
set -Eeuo pipefail
root_rel="$1"; bundle="$2"; mode="$3"; sha="$4"; enrolment_ref="$5"; student_ref="$6"; fingerprint="$7"; confirm="$8"; attestation="$9"

fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }

[[ "$root_rel" == domains/delnavazan.com/public_html ]] || fail 'invalid retirement target'
[[ "$sha" =~ ^[0-9a-f]{40}$ ]] || fail 'invalid source SHA'
[[ "$enrolment_ref" =~ ^ENR-[A-Z0-9]+$ ]] || fail 'invalid enrolment reference'
[[ "$student_ref" =~ ^CC[A-Z0-9]+$ ]] || fail 'invalid student reference'
root="$HOME/$root_rel"
[[ -f "$root/wp-load.php" && -f "$root/wp-config.php" ]] || fail 'expected WordPress root is unavailable'
command -v wp >/dev/null || fail 'WP-CLI is required'

build="$(wp --path="$root" eval 'echo defined("DZN_PLATFORM_BUILD_ID") ? DZN_PLATFORM_BUILD_ID : "";')"
[[ "$build" == "main-${sha:0:12}" ]] || fail "deployed build identity ($build) does not match main-${sha:0:12}"

tmp="$(mktemp -d)"
cleanup(){ rm -rf "$tmp"; }
trap cleanup EXIT
printf '%s' "$bundle" | base64 -d | tar -xzf - -C "$tmp"
[[ -f "$tmp/retire-imported-enrolment.php" && -f "$tmp/purge-test-data-tables.php" ]] || fail 'payload extraction failed'

if [[ "$mode" == 'report' ]]; then
  wp --path="$root" eval-file "$tmp/retire-imported-enrolment.php" report "$enrolment_ref" "$student_ref" none none none none none "$tmp/purge-test-data-tables.php"
  exit 0
fi

backup_dir="$HOME/backups/retire-imported-enrolment"
mkdir -p "$backup_dir"
chmod 700 "$backup_dir"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
raw="$backup_dir/pre-retirement-$stamp.sql"
[[ ! -e "$raw" && ! -e "$raw.gz" ]] || fail 'refusing to overwrite an existing backup'

wp --path="$root" --skip-plugins --skip-themes db export "$raw" --add-drop-table >/dev/null
[[ -s "$raw" ]] || fail 'database export is empty'
gzip -9 "$raw"
backup="$raw.gz"
gzip -t "$backup" || fail 'database export failed gzip integrity'
backup_sha="$(sha256sum "$backup" | awk '{print $1}')"
printf '%s  %s\n' "$backup_sha" "$backup" > "$backup.sha256"
chmod 600 "$backup" "$backup.sha256"
bytes="$(wc -c < "$backup" | tr -d ' ')"
(( bytes > 65536 )) || fail "database export is implausibly small ($bytes bytes); stop and inspect"
printf 'BACKUP ok  %s  sha256=%s  bytes=%s\n' "$backup" "$backup_sha" "$bytes" >&2

wp --path="$root" eval-file "$tmp/retire-imported-enrolment.php" execute "$enrolment_ref" "$student_ref" "$confirm" "$fingerprint" "$backup" "$backup_sha" "$attestation" "$tmp/purge-test-data-tables.php"
REMOTE
