#!/usr/bin/env bash
# Guarded one-shot: create/refresh the five legal-policy pages as drafts.
# This script never publishes a policy and never overwrites a published page.
set -Eeuo pipefail

readonly HOST="cp119.hosting-cloud.net"
readonly ROOT_REL="domains/delnavazan.com/public_html"
readonly SHA_RE='^[0-9a-f]{40}$'
readonly CONFIRM_VALUE='PROVISION_LEGAL_DRAFTS'
readonly PHP_FILE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/provision-production-legal-pages.php"

fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
[[ $# == 2 && "$1" =~ $SHA_RE && "$2" == "$CONFIRM_VALUE" ]] || fail "usage: provision-production-legal-pages.sh <full-current-main-sha> $CONFIRM_VALUE"
: "${PRODUCTION_SSH_USER:?Set the protected production SSH user}"
[[ "$PRODUCTION_SSH_USER" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'SSH username contains unsupported characters'
[[ -f "$PHP_FILE" ]] || fail 'provisioning PHP payload is missing'

code="$(tail -n +2 "$PHP_FILE" | base64 | tr -d '\n')"
[[ -n "$code" ]] || fail 'provisioning PHP payload is empty'

ssh -T "${PRODUCTION_SSH_USER}@${HOST}" bash -s -- "$ROOT_REL" "$code" "$1" <<'REMOTE'
set -Eeuo pipefail
root_rel="$1"
code="$2"
sha="$3"
[[ "$root_rel" == domains/delnavazan.com/public_html ]] || { echo 'FAIL invalid provisioning target' >&2; exit 1; }
[[ "$sha" =~ ^[0-9a-f]{40}$ ]] || { echo 'FAIL invalid source SHA' >&2; exit 1; }
root="$HOME/$root_rel"
[[ -f "$root/wp-load.php" ]] || { echo 'FAIL expected WordPress root is unavailable' >&2; exit 1; }
command -v wp >/dev/null || { echo 'FAIL WP-CLI is required' >&2; exit 1; }
build="$(wp --path="$root" eval 'echo defined("DZN_PLATFORM_BUILD_ID") ? DZN_PLATFORM_BUILD_ID : "";')"
[[ "$build" == "main-${sha:0:12}" ]] || { echo "FAIL deployed build identity ($build) does not match main-${sha:0:12}" >&2; exit 1; }
wp --path="$root" eval "eval(base64_decode('$code'));"
REMOTE
