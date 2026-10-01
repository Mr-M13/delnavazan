#!/usr/bin/env bash
set -Eeuo pipefail
BASE_URL="${1:-https://staging.delnavazan.com}"
BASE_URL="${BASE_URL%/}"
CURL=(curl --fail --silent --show-error --location --max-time 20 --proto '=https')
failures=0
pass(){ printf 'PASS  %s\n' "$1"; }
fail(){ printf 'FAIL  %s\n' "$1"; failures=$((failures+1)); }
check(){ local path="$1" expected="$2" code; code="$("${CURL[@]}" --output /dev/null --write-out '%{http_code}' "$BASE_URL$path" 2>/dev/null || true)"; if [[ "$code" =~ $expected ]]; then pass "$path $code"; else fail "$path ${code:-no-response}"; fi; }
check_login_required(){ local path="$1" body code; body="$(mktemp)"; code="$("${CURL[@]}" --output "$body" --write-out '%{http_code}' "$BASE_URL$path" 2>/dev/null || true)"; if [[ "$code" == 200 ]] && rg -q 'ورود لازم است' "$body"; then pass "$path controlled login-required page"; else fail "$path expected HTTP 200 login-required page, got ${code:-no-response}"; fi; rm -f "$body"; }
check_dashboard_redirect(){ local headers code location; headers="$(mktemp)"; code="$(curl --silent --show-error --max-time 20 --proto '=https' --output /dev/null --dump-header "$headers" --write-out '%{http_code}' "$BASE_URL/dashboard/" 2>/dev/null || true)"; location="$(rg -i '^location:' "$headers" | tail -1 || true)"; if [[ "$code" =~ ^(301|302|303)$ ]] && [[ "$location" =~ /login/? ]]; then pass "/dashboard/ redirects to /login/"; else fail "/dashboard/ expected redirect to /login/, got ${code:-no-response} ${location:-}"; fi; rm -f "$headers"; }
headers="$(mktemp)"; html="$(mktemp)"; trap 'rm -f "$headers" "$html"' EXIT
code="$("${CURL[@]}" --dump-header "$headers" --output "$html" --write-out '%{http_code}' "$BASE_URL/" 2>/dev/null || true)"
[[ "$code" == 200 ]] && pass "homepage HTTPS 200" || fail "homepage ${code:-no-response}"
rg -qi '^x-robots-tag:.*noindex' "$headers" && pass "X-Robots-Tag noindex" || fail "X-Robots-Tag noindex missing"
rg -qi 'fatal error|parse error|warning:|notice:' "$html" && fail "visible PHP error marker" || pass "no visible PHP error marker"
check /booking/ '^(200)$'
check /enrol/ '^(200|301|302)$'
check /login/ '^(200|301|302)$'
check /this-route-must-not-exist-qa/ '^(404)$'
check_dashboard_redirect
for route in /student-portal/ /teacher-portal/ /admin-operations/; do check_login_required "$route"; done
sitemap="$("${CURL[@]}" --output /dev/null --write-out '%{http_code}' "$BASE_URL/wp-sitemap.xml" 2>/dev/null || true)"
[[ "$sitemap" != 200 ]] && pass "protected sitemap unavailable ($sitemap)" || fail "protected sitemap public"
[[ "$failures" == 0 ]] && { echo 'RESULT: PASS'; exit 0; } || { echo 'RESULT: FAIL'; exit 1; }
