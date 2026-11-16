#!/usr/bin/env bash
# Guarded one-shot go-live purge of pre-launch Student/Teacher/booking test data.
#
#   report  : read-only inventory + pre-state fingerprint. Changes nothing.
#   execute : takes a verified full database export, requires the exact report
#             fingerprint, then deletes the classified test data.
#
# There is no unattended or scheduled path. Only an authorised operator on the
# protected production SSH account may run it, inside an approved window.
set -Eeuo pipefail

readonly HOST="cp119.hosting-cloud.net"
readonly ROOT_REL="domains/delnavazan.com/public_html"
readonly SHA_RE='^[0-9a-f]{40}$'
readonly FINGERPRINT_RE='^[0-9a-f]{64}$'
readonly CONFIRM_VALUE='PURGE_ALL_TEST_DATA'
readonly QA_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }

usage() {
  cat >&2 <<EOF
usage:
  bash deployment/qa/purge-test-data.sh report  <full-current-main-sha>
  bash deployment/qa/purge-test-data.sh execute <full-current-main-sha> <prestate-fingerprint> $CONFIRM_VALUE
EOF
  exit 64
}

[[ -f "$QA_DIR/purge-test-data.php" ]] || fail 'purge payload is missing'
[[ -f "$QA_DIR/purge-test-data-tables.php" ]] || fail 'purge table classification is missing'

case "${1:-}" in
  report)
    [[ $# == 2 ]] || usage
    mode='report'; sha="$2"; fingerprint=''; confirm=''
    ;;
  execute)
    [[ $# == 4 ]] || usage
    mode='execute'; sha="$2"; fingerprint="$3"; confirm="$4"
    [[ "$fingerprint" =~ $FINGERPRINT_RE ]] || fail 'pre-state fingerprint must be a 64-character hex digest from report mode'
    [[ "$confirm" == "$CONFIRM_VALUE" ]] || fail "execute requires the exact confirm token $CONFIRM_VALUE"
    : "${DZN_PURGE_ATTESTATION:?Set the protected workflow attestation token; direct execute is refused}"
    ;;
  *) usage ;;
esac

[[ "$sha" =~ $SHA_RE ]] || fail 'first argument must be the full 40-character current main SHA'
: "${PRODUCTION_SSH_USER:?Set the protected production SSH user}"
[[ "$PRODUCTION_SSH_USER" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'SSH username contains unsupported characters'

# OpenSSH drops empty trailing arguments, so send explicit placeholders.
[[ -n "$fingerprint" ]] || fingerprint='none'
[[ -n "$confirm" ]] || confirm='none'

bundle="$(tar -czf - -C "$QA_DIR" purge-test-data.php purge-test-data-tables.php | base64 | tr -d '\n')"
[[ -n "$bundle" ]] || fail 'payload bundle is empty'

attestation="${DZN_PURGE_ATTESTATION:-none}"

ssh -T "${PRODUCTION_SSH_USER}@${HOST}" bash -s -- \
  "$ROOT_REL" "$bundle" "$mode" "$sha" "$fingerprint" "$confirm" "$attestation" <<'REMOTE'
set -Eeuo pipefail
root_rel="$1"; bundle="$2"; mode="$3"; sha="$4"; fingerprint="$5"; confirm="$6"; attestation="$7"

fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }

[[ "$root_rel" == domains/delnavazan.com/public_html ]] || fail 'invalid purge target'
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
[[ -f "$tmp/purge-test-data.php" && -f "$tmp/purge-test-data-tables.php" ]] || fail 'payload extraction failed'

if [[ "$mode" == 'report' ]]; then
  wp --path="$root" eval-file "$tmp/purge-test-data.php" report none none none none "$tmp/purge-test-data-tables.php" none
  exit 0
fi

backup_dir="$HOME/backups/go-live-purge"
mkdir -p "$backup_dir"
chmod 700 "$backup_dir"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
raw="$backup_dir/pre-purge-$stamp.sql"
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

wp --path="$root" eval-file "$tmp/purge-test-data.php" execute "$confirm" "$fingerprint" "$backup" "$backup_sha" "$tmp/purge-test-data-tables.php" "$attestation"
REMOTE
