#!/usr/bin/env bash
set -Eeuo pipefail
BASE_URL="${1:-https://staging.delnavazan.com}"
BASE_URL="${BASE_URL%/}"
CURL=(curl --fail --silent --show-error --location --max-time 20 --proto '=https')
failures=0
pass(){ printf 'PASS  %s\n' "$1"; }
fail(){ printf 'FAIL  %s\n' "$1"; failures=$((failures+1)); }
check(){ local path="$1" expected="$2" code; code="$("${CURL[@]}" --output /dev/null --write-out '%{http_code}' "$BASE_URL$path" 2>/dev/null || true)"; if [[ "$code" =~ $expected ]]; then pass "$path $code"; else fail "$path ${code:-no-response}"; fi; }
headers="$(mktemp)"; html="$(mktemp)"; trap 'rm -f "$headers" "$html"' EXIT
code="$("${CURL[@]}" --dump-header "$headers" --output "$html" --write-out '%{http_code}' "$BASE_URL/" 2>/dev/null || true)"
[[ "$code" == 200 ]] && pass "homepage HTTPS 200" || fail "homepage ${code:-no-response}"
rg -qi '^x-robots-tag:.*noindex' "$headers" && pass "X-Robots-Tag noindex" || fail "X-Robots-Tag noindex missing"
rg -qi 'fatal error|parse error|warning:|notice:' "$html" && fail "visible PHP error marker" || pass "no visible PHP error marker"
check /booking/ '^(200)$'
check /enrol/ '^(200|301|302)$'
check /login/ '^(200|301|302)$'
check /this-route-must-not-exist-qa/ '^(404)$'
for route in /account/ /student/ /teacher/ /admin-operations/; do check "$route" '^(200|302|303)$'; done
sitemap="$("${CURL[@]}" --output /dev/null --write-out '%{http_code}' "$BASE_URL/wp-sitemap.xml" 2>/dev/null || true)"
[[ "$sitemap" != 200 ]] && pass "protected sitemap unavailable ($sitemap)" || fail "protected sitemap public"
[[ "$failures" == 0 ]] && { echo 'RESULT: PASS'; exit 0; } || { echo 'RESULT: FAIL'; exit 1; }
