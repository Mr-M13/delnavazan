#!/usr/bin/env bash
set -Eeuo pipefail
here="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
export SMOKE_MAX_ATTEMPTS=3
export SMOKE_RETRY_DELAYS_STRING="0 0"
export SMOKE_MAX_TOTAL_SECONDS=50
# shellcheck source=production-smoke-lib.sh
source "$here/production-smoke-lib.sh"

CALLS=0
FAKE_CODES=(); FAKE_HEADERS=(); FAKE_BODIES=(); FAKE_RCS=(); FAKE_ARGS=()
curl(){
  CALLS=$((CALLS+1)); local headers="" body="" arg
  FAKE_ARGS+=("$*")
  while (( $# )); do
    case "$1" in
      -D) headers="$2"; shift 2 ;;
      -o) body="$2"; shift 2 ;;
      -w) shift 2 ;;
      *) shift ;;
    esac
  done
  local i=$((CALLS-1))
  [[ -n "$headers" ]] && printf '%s' "${FAKE_HEADERS[$i]:-}" > "$headers"
  [[ -n "$body" ]] && printf '%s' "${FAKE_BODIES[$i]:-}" > "$body"
  local rc="${FAKE_RCS[$i]:-0}"
  (( rc == 0 )) || return "$rc"
  printf '%s' "${FAKE_CODES[$i]:-200}|https://delnavazan.com/"
}
sleep(){ :; }

fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
reset_fake(){ CALLS=0; FAKE_CODES=(); FAKE_HEADERS=(); FAKE_BODIES=(); FAKE_RCS=(); FAKE_ARGS=(); }
run_probe(){ local rc=0; smoke_request 'test' 'https://delnavazan.com/' || rc=$?; }

reset_fake
FAKE_CODES=(200); run_probe
[[ "$SMOKE_ATTEMPTS" == 1 && "$SMOKE_CODE" == 200 && "$SMOKE_CLASSIFICATION" == stable_http_200 ]] || fail 'immediate success'
(( ${#FAKE_ARGS[@]} == 1 )) || fail 'immediate success must make one request'
[[ "${FAKE_ARGS[0]}" == *'--user-agent'* && "${FAKE_ARGS[0]}" == *'Accept-Language'* && "${FAKE_ARGS[0]}" == *'--location'* ]] || fail 'browser-like headers and redirect following missing'

reset_fake
FAKE_CODES=(503 200); run_probe
[[ "$SMOKE_ATTEMPTS" == 2 && "$SMOKE_CODE" == 200 ]] || fail 'transient 503 followed by success'

reset_fake
FAKE_CODES=(403); FAKE_HEADERS=('server: cloudflare'); FAKE_BODIES=('Forbidden'); run_probe
[[ "$SMOKE_ATTEMPTS" == 1 && "$SMOKE_CODE" == 403 && "$SMOKE_CLASSIFICATION" == permanent_http_403 ]] || fail 'plain 403 must fail immediately and strictly'

reset_fake
FAKE_CODES=(403 200); FAKE_HEADERS=('cf-mitigated: challenge' 'server: cloudflare'); FAKE_BODIES=('Just a moment... Cloudflare' ''); run_probe
[[ "$SMOKE_ATTEMPTS" == 2 && "$SMOKE_CODE" == 200 ]] || fail 'explicit Cloudflare challenge is transient'

reset_fake
FAKE_CODES=(403); FAKE_HEADERS=('cf-mitigated: block'); FAKE_BODIES=('Sorry, you have been blocked'); run_probe
[[ "$SMOKE_ATTEMPTS" == 1 && "$SMOKE_CLASSIFICATION" == permanent_http_403_block ]] || fail 'Cloudflare block is permanent'

reset_fake
FAKE_RCS=(28 0); FAKE_CODES=('' 200); run_probe
[[ "$SMOKE_ATTEMPTS" == 2 && "$SMOKE_CODE" == 200 ]] || fail 'timeout followed by success'

reset_fake
FAKE_CODES=(404); run_probe
[[ "$SMOKE_ATTEMPTS" == 1 && "$SMOKE_CLASSIFICATION" == permanent_http_404 ]] || fail '404 must fail immediately'

reset_fake
FAKE_CODES=(500); run_probe
[[ "$SMOKE_ATTEMPTS" == 1 && "$SMOKE_CLASSIFICATION" == permanent_http_500 ]] || fail '500 must fail immediately'

reset_fake
FAKE_CODES=(503 503 503); local_rc=0; smoke_request 'test' 'https://delnavazan.com/' || local_rc=$?
[[ "$local_rc" == 1 && "$SMOKE_ATTEMPTS" == 3 ]] || fail 'maximum retry exhaustion must fail after three attempts'

reset_fake
FAKE_CODES=(429 200); FAKE_HEADERS=('retry-after: 1' 'server: cloudflare'); run_probe
[[ "$SMOKE_ATTEMPTS" == 2 && "$SMOKE_CODE" == 200 ]] || fail '429 rate limit is transient'

reset_fake
FAKE_CODES=(403 403 403); local_rc=0; smoke_request 'test' 'https://delnavazan.com/' --no-location || local_rc=$?
[[ "$local_rc" == 0 && "$SMOKE_ATTEMPTS" == 1 ]] || fail 'no-location must not change classification'
[[ "${FAKE_ARGS[0]}" != *'--location'* ]] || fail 'no-location probe must not follow redirects'

smoke_cleanup
echo 'production smoke retry unit: PASS'
