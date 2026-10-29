#!/usr/bin/env bash
# Bounded, evidence-driven HTTP probes for the read-only production smoke gate.
# This file is sourced by production-smoke.sh and exercised by production-smoke-retry-unit.sh.
# It never sends credentials, cookies or mutation requests.

SMOKE_MAX_ATTEMPTS="${SMOKE_MAX_ATTEMPTS:-3}"
SMOKE_RETRY_DELAYS_STRING="${SMOKE_RETRY_DELAYS_STRING:-1 3}"
SMOKE_MAX_TOTAL_SECONDS="${SMOKE_MAX_TOTAL_SECONDS:-50}"
SMOKE_CONNECT_TIMEOUT="${SMOKE_CONNECT_TIMEOUT:-8}"
SMOKE_REQUEST_TIMEOUT="${SMOKE_REQUEST_TIMEOUT:-20}"
SMOKE_UA="${SMOKE_UA:-Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 DelnavazanProductionSmoke/1.0}"
IFS=' ' read -r -a SMOKE_RETRY_DELAYS <<<"$SMOKE_RETRY_DELAYS_STRING"
SMOKE_HEADERS="$(mktemp)"
SMOKE_BODY="$(mktemp)"
SMOKE_RESULT="$(mktemp)"

smoke_now_ms(){ perl -MTime::HiRes=time -e 'printf "%.0f\n", time*1000' 2>/dev/null || echo $(( $(date +%s) * 1000 )); }

smoke_cleanup(){ rm -f "$SMOKE_HEADERS" "$SMOKE_BODY" "$SMOKE_RESULT"; }

smoke_safe_headers(){
  [[ -s "$SMOKE_HEADERS" ]] || return 0
  grep -Ei '^(server|cf-ray|cf-mitigated|cf-cache-status|content-type|retry-after|x-turbo-charged-by|x-powered-by|location):' "$SMOKE_HEADERS" \
    | sed -E 's/\r$//' | head -12 | sed 's/^/  header /' || true
}

smoke_body_marker(){
  [[ -s "$SMOKE_BODY" ]] || return 0
  local marker
  marker="$(grep -Eio 'cloudflare|attention required|you have been blocked|access denied|forbidden|just a moment' "$SMOKE_BODY" | head -1 || true)"
  [[ -n "$marker" ]] && printf '  body_marker: %s\n' "$marker"
}

smoke_log_attempt(){
  local label="$1" attempt="$2" status="$3" classification="$4" elapsed="$5"
  printf 'PROBE %s attempt=%d/%d status=%s classification=%s elapsed_ms=%s\n' \
    "$label" "$attempt" "$SMOKE_MAX_ATTEMPTS" "$status" "$classification" "$elapsed"
  if [[ "$classification" == transient_* || ! "$status" =~ ^(200|301|302|303)$ ]]; then
    smoke_safe_headers
    smoke_body_marker
  fi
}

smoke_rc_transient(){
  case "$1" in
    6|7|28|35|52|56) return 0 ;;
    *) return 1 ;;
  esac
}

# Returns 0 when the response is explicitly transient and may be retried.
smoke_classify_transient(){
  local code="$1" mitigated=""
  case "$code" in
    429|503|504|520|521|522|523|524|525|526)
      printf 'transient_http_%s' "$code"; return 0 ;;
    403)
      mitigated="$(grep -Ei '^cf-mitigated:' "$SMOKE_HEADERS" | tail -1 | sed -E 's/^[^:]+:[[:space:]]*//; s/\r$//' | tr '[:upper:]' '[:lower:]' || true)"
      if [[ "$mitigated" == 'challenge' || "$mitigated" == 'managed_challenge' ]]; then
        if grep -Eqi 'cloudflare|just a moment|attention required|checking your browser' "$SMOKE_BODY"; then
          printf 'transient_cloudflare_%s' "$mitigated"; return 0
        fi
      fi
      printf 'permanent_http_403%s' "${mitigated:+_$mitigated}"; return 1 ;;
    200|301|302|303) printf 'stable_http_%s' "$code"; return 1 ;;
    *) printf 'permanent_http_%s' "$code"; return 1 ;;
  esac
}

# Perform one bounded probe. Sets SMOKE_CODE, SMOKE_FINAL_URL, SMOKE_ATTEMPTS,
# SMOKE_CLASSIFICATION and SMOKE_ELAPSED_MS. Returns 0 when an HTTP response was
# obtained (even a permanent 4xx/5xx), 1 only when no usable response was obtained.
smoke_request(){
  local label="$1" url="$2" follow=1
  shift 2
  if [[ "${1:-}" == '--no-location' ]]; then follow=0; shift; fi
  local attempt=1 started attempt_started rc result delay elapsed
  started="$(smoke_now_ms)"
  while (( attempt <= SMOKE_MAX_ATTEMPTS )); do
    attempt_started="$(smoke_now_ms)"
    : > "$SMOKE_HEADERS"; : > "$SMOKE_BODY"
    local curl_args=(--silent --show-error --connect-timeout "$SMOKE_CONNECT_TIMEOUT" --max-time "$SMOKE_REQUEST_TIMEOUT" --proto '=https'
      --user-agent "$SMOKE_UA"
      -H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
      -H 'Accept-Language: en-US,en;q=0.9'
      -D "$SMOKE_HEADERS" -o "$SMOKE_BODY" -w '%{http_code}|%{url_effective}')
    (( follow )) && curl_args+=(--location --max-redirs 5)
    if curl "${curl_args[@]}" "$@" "$url" >"$SMOKE_RESULT"; then
      result="$(cat "$SMOKE_RESULT")"
      SMOKE_CODE="${result%%|*}"; SMOKE_FINAL_URL="${result#*|}"
      SMOKE_ELAPSED_MS=$(( $(smoke_now_ms) - attempt_started ))
      if smoke_classification="$(smoke_classify_transient "$SMOKE_CODE")"; then
        SMOKE_CLASSIFICATION="$smoke_classification"; SMOKE_ATTEMPTS="$attempt"
        smoke_log_attempt "$label" "$attempt" "$SMOKE_CODE" "$SMOKE_CLASSIFICATION" "$SMOKE_ELAPSED_MS"
      else
        SMOKE_CLASSIFICATION="$smoke_classification"; SMOKE_ATTEMPTS="$attempt"
        smoke_log_attempt "$label" "$attempt" "$SMOKE_CODE" "$SMOKE_CLASSIFICATION" "$SMOKE_ELAPSED_MS"
        return 0
      fi
    else
      rc=$?
      SMOKE_ELAPSED_MS=$(( $(smoke_now_ms) - attempt_started ))
      if smoke_rc_transient "$rc"; then SMOKE_CLASSIFICATION="transient_curl_$rc"; else SMOKE_CLASSIFICATION="permanent_curl_$rc"; fi
      SMOKE_ATTEMPTS="$attempt"
      smoke_log_attempt "$label" "$attempt" "curl_error_$rc" "$SMOKE_CLASSIFICATION" "$SMOKE_ELAPSED_MS"
      if [[ "$SMOKE_CLASSIFICATION" == permanent_* ]]; then return 1; fi
    fi
    elapsed=$(( $(smoke_now_ms) - started ))
    if (( attempt >= SMOKE_MAX_ATTEMPTS || elapsed >= SMOKE_MAX_TOTAL_SECONDS * 1000 )); then
      return 1
    fi
    delay="${SMOKE_RETRY_DELAYS[$((attempt-1))]:-${SMOKE_RETRY_DELAYS[${#SMOKE_RETRY_DELAYS[@]}-1]:-1}}"
    sleep "$delay"
    attempt=$((attempt+1))
  done
  return 1
}
