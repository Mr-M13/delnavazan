#!/usr/bin/env bash
set -Eeuo pipefail
BASE_URL="${1:-https://delnavazan.com}"
EXPECT_NOINDEX="${2:-false}"
BASE_URL="${BASE_URL%/}"
if [[ "$EXPECT_NOINDEX" != "true" && "$EXPECT_NOINDEX" != "false" ]]; then
  echo "Second argument must be true or false (whether the target should be noindex)." >&2
  exit 2
fi
failures=0
pass(){ printf "PASS  %s\\n" "$1"; }
fail(){ printf "FAIL  %s\\n" "$1"; failures=$((failures+1)); }
headers="$(mktemp)"; body="$(mktemp)"; trap 'rm -f "$headers" "$body"' EXIT
code=""; final_url=""
fetch(){ local path="$1"; if ! result="$(curl --silent --show-error --location --max-redirs 5 --max-time 20 --proto "=https" --dump-header "$headers" --output "$body" --write-out "%{http_code}|%{url_effective}" "$BASE_URL$path")"; then fail "$path transfer failed"; return 1; fi; code="${result%%|*}"; final_url="${result#*|}"; if [[ "$final_url" != "$BASE_URL" && "$final_url" != "$BASE_URL/" && "$final_url" != "$BASE_URL/"* ]]; then fail "$path redirected off target origin to $final_url"; return 1; fi; return 0; }
check_html(){ local path="$1" expected="$2"; if ! fetch "$path"; then return; fi; if [[ "$code" =~ $expected ]]; then pass "$path $code"; else fail "$path expected $expected, got $code"; fi; if grep -Eqi "(PHP[[:space:]]+(Fatal error|Parse error|Warning|Notice):|<b>[[:space:]]*(Fatal error|Parse error|Warning|Notice)[[:space:]]*</b>)" "$body"; then fail "$path contains visible PHP error marker"; else pass "$path has no visible PHP error marker"; fi; }
check_portal_redirect(){ local path="$1"; local result location redirect_code expected; expected="$BASE_URL/login/?redirect_to=$(printf '%s' "$BASE_URL$path" | sed 's/:/%3A/g; s#/#%2F#g')"; if ! result="$(curl --silent --show-error --max-time 20 --proto "=https" --dump-header "$headers" --output /dev/null --write-out "%{http_code}" "$BASE_URL$path")"; then fail "$path transfer failed"; return; fi; redirect_code="$result"; location="$(grep -Ei "^location:" "$headers" | tail -1 | sed -E "s/^[Ll]ocation:[[:space:]]*//; s/\\r$//")"; if [[ "$redirect_code" =~ ^(301|302|303)$ ]] && [[ "$location" == "$BASE_URL/login/?redirect_to="* ]]; then pass "$path redirects signed-out user to /login/ with return target"; else fail "$path expected login redirect with return target, got $redirect_code ${location:-no-location}"; fi; }
check_login_required(){ local path="$1"; if ! fetch "$path"; then return; fi; if [[ "$code" == 200 ]] && grep -Eq "ورود لازم است" "$body"; then pass "$path controlled login-required page"; else fail "$path expected HTTP 200 login-required page, got $code"; fi; if grep -Eqi "(PHP[[:space:]]+(Fatal error|Parse error|Warning|Notice):|<b>[[:space:]]*(Fatal error|Parse error|Warning|Notice)[[:space:]]*</b>)" "$body"; then fail "$path contains visible PHP error marker"; else pass "$path has no visible PHP error marker"; fi; }
check_dashboard_redirect(){ local result location redirect_code; if ! result="$(curl --silent --show-error --max-time 20 --proto "=https" --dump-header "$headers" --output /dev/null --write-out "%{http_code}" "$BASE_URL/dashboard/")"; then fail "/dashboard/ transfer failed"; return; fi; redirect_code="$result"; location="$(grep -Ei "^location:" "$headers" | tail -1 | sed -E "s/^[Ll]ocation:[[:space:]]*//; s/\\r$//")"; if [[ "$redirect_code" =~ ^(301|302|303)$ ]] && { [[ "$location" == "/login/" ]] || [[ "$location" == "$BASE_URL/login/" ]]; }; then pass "/dashboard/ redirects to /login/"; else fail "/dashboard/ expected redirect to /login/, got $redirect_code ${location:-no-location}"; fi; }
check_sitemap(){ local path="$1"; if ! fetch "$path"; then return; fi; if [[ "$code" =~ ^(401|403|404)$ ]]; then pass "$path protected/unavailable ($code)"; elif [[ "$EXPECT_NOINDEX" == "false" && "$code" == 200 ]] && grep -Eiq '^content-type:[[:space:]]*(application|text)/[^;]*xml' "$headers"; then pass "$path public sitemap ($code)"; else fail "$path returned unexpected HTTP $code for noindex=$EXPECT_NOINDEX"; fi; }
check_platform_rest(){
  if ! fetch "/wp-json/"; then return; fi
  if [[ "$code" == 200 ]] && grep -Eq 'delnavazan-platform.{0,4}v1' "$body"; then pass 'WordPress REST index advertises Delnavazan Platform routes'; else fail "WordPress REST index missing Delnavazan Platform routes (HTTP $code)"; fi
  if ! fetch "/wp-json/delnavazan-platform/v1/booking-options"; then return; fi
  if [[ "$code" == 200 ]] && grep -Eiq '^content-type:[[:space:]]*application/(json|[^;[:space:]]+\+json)' "$headers"; then pass 'public booking-options REST route responds with JSON'; else fail "booking-options REST route expected JSON HTTP 200, got $code"; fi
}
check_html "/" "^(200)$"
if grep -Eqi "^x-robots-tag:.*noindex" "$headers"; then if [[ "$EXPECT_NOINDEX" == "true" ]]; then pass "X-Robots-Tag noindex"; else fail "Public target unexpectedly has X-Robots-Tag noindex"; fi; elif [[ "$EXPECT_NOINDEX" == "true" ]]; then fail "X-Robots-Tag noindex missing"; else pass "Public target is not marked noindex"; fi
robots_meta="$(grep -Eio '<meta[^>]+>' "$body" | grep -Ei 'name=(["'\''"]robots["'\''"]|robots)' | head -1 || true)"
if [[ -n "$robots_meta" ]] && grep -Eqi "noindex" <<<"$robots_meta"; then if [[ "$EXPECT_NOINDEX" == "true" ]]; then pass "robots meta noindex"; else fail "Public target unexpectedly has robots meta noindex"; fi; elif [[ "$EXPECT_NOINDEX" == "true" ]]; then fail "robots meta noindex missing"; else pass "Public target is not marked noindex in HTML"; fi
if result="$(curl --silent --show-error --max-time 20 --proto "=https" --dump-header "$headers" --output /dev/null --write-out "%{http_code}" "$BASE_URL/booking/?instrument=setar")"; then
  location="$(grep -Ei "^location:" "$headers" | tail -1 | sed -E "s/^[Ll]ocation:[[:space:]]*//; s/\\r$//" || true)"
  if [[ "$result" == 302 ]] && [[ "$location" == *"/enrol/"* ]] && [[ "$location" == *"instrument=setar"* ]]; then pass "/booking/ temporarily redirects to canonical /enrol/ with the instrument"; else fail "/booking/ expected 302 to /enrol/?instrument=setar, got $result ${location:-no-location}"; fi
else fail "/booking/ transfer failed"; fi
check_html "/enrol/" "^(200)$"
check_html "/login/" "^(200|301|302)$"
check_html "/this-route-must-not-exist-qa/" "^(404)$"
check_dashboard_redirect
for route in /student-portal/ /teacher-portal/; do check_portal_redirect "$route"; done
check_login_required "/admin-operations/"
check_platform_rest
check_sitemap "/wp-sitemap.xml"
check_sitemap "/sitemap_index.xml"
if [[ "$failures" == 0 ]]; then echo "RESULT: PASS"; exit 0; fi
echo "RESULT: FAIL"; exit 1
