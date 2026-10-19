#!/usr/bin/env bash
set -Eeuo pipefail
BASE_URL="${1:-https://delnavazan.com}"
BASE_URL="${BASE_URL%/}"
[[ "$BASE_URL" == "https://delnavazan.com" ]] || { echo 'FAIL production smoke target must be https://delnavazan.com' >&2; exit 1; }
failures=0
pass(){ printf 'PASS  %s\n' "$1"; }
fail(){ printf 'FAIL  %s\n' "$1"; failures=$((failures+1)); }
headers="$(mktemp)"; body="$(mktemp)"; trap 'rm -f "$headers" "$body"' EXIT
fetch(){ local path="$1" result; if ! result="$(curl --silent --show-error --location --max-redirs 5 --max-time 20 --proto '=https' --dump-header "$headers" --output "$body" --write-out '%{http_code}|%{url_effective}' "$BASE_URL$path")"; then fail "$path transfer failed"; return 1; fi; code="${result%%|*}"; final_url="${result#*|}"; if [[ "$final_url" != "$BASE_URL" && "$final_url" != "$BASE_URL/" && "$final_url" != "$BASE_URL/"* ]]; then fail "$path redirected off apex production to $final_url"; return 1; fi; return 0; }
check_html(){ local path="$1"; if ! fetch "$path"; then return; fi; if [[ "$code" == 200 ]]; then pass "$path HTTP 200"; else fail "$path expected HTTP 200, got $code"; fi; if grep -Eqi '(PHP[[:space:]]+(Fatal error|Parse error|Warning|Notice):|<b>[[:space:]]*(Fatal error|Parse error|Warning|Notice)[[:space:]]*</b>)' "$body"; then fail "$path contains visible PHP error marker"; else pass "$path has no visible PHP error marker"; fi; if grep -Eqi '^x-robots-tag:.*noindex' "$headers" || grep -Eiq '<meta[^>]+name=["'"'"']?robots["'"'"']?[^>]+content=["'"'"'][^"'"'"']*noindex' "$body"; then fail "$path is marked noindex"; else pass "$path is indexable"; fi; }
check_html '/'
check_html '/enrol/'
if grep -Eq 'data-instrument-choice="[0-9]+"' "$body"; then pass '/enrol/ renders course choices'; else fail '/enrol/ does not render any course choices'; fi
css_urls="$(grep -Eo '/wp-content/themes/delnavazan-theme/assets/css/(theme|booking)\.css(\?[^"[:space:]<>]*)?' "$body" | sort -u || true)"
check_css_asset(){
  local asset="$1" url=""
  url="$(grep -E "/assets/css/${asset}\.css(\?|$)" <<<"$css_urls" | head -1 || true)"
  if [[ -z "$url" ]]; then fail "/enrol/ does not reference current $asset.css from delnavazan-theme"; return; fi
  if ! fetch "$url"; then return; fi
  if [[ "$code" == 200 ]]; then pass "$url HTTP 200"; else fail "$url expected HTTP 200, got $code"; fi
  if grep -Eiq '^content-type:[[:space:]]*text/css([;[:space:]]|$)' "$headers"; then pass "$url has CSS content type"; else fail "$url expected CSS content type, got $(grep -Ei '^content-type:' "$headers" | tail -1 | sed -E 's/\r$//' || echo 'missing Content-Type')"; fi
}
check_css_asset theme
check_css_asset booking
check_theme_asset(){
  local path="$1" expected_type="$2"
  if ! fetch "$path"; then return; fi
  if [[ "$code" == 200 ]]; then pass "$path HTTP 200"; else fail "$path expected HTTP 200, got $code"; fi
  if grep -Eiq "^content-type:[[:space:]]*(${expected_type})([;/[:space:]]|$)" "$headers"; then pass "$path has $expected_type content type"; else fail "$path expected $expected_type content type, got $(grep -Ei '^content-type:' "$headers" | tail -1 | sed -E 's/\r$//' || echo 'missing Content-Type')"; fi
}
check_theme_asset '/wp-content/themes/delnavazan-theme/style.css' 'text/css'
check_theme_asset '/wp-content/themes/delnavazan-theme/assets/js/navigation.js' 'application/javascript|text/javascript'
check_theme_asset '/wp-content/themes/delnavazan-theme/assets/js/booking.js' 'application/javascript|text/javascript'
if ! fetch '/wp-json/delnavazan-platform/v1/booking-options'; then :; elif [[ "$code" == 200 ]] && grep -Eiq '^content-type:[[:space:]]*application/json' "$headers" && ! grep -Eq '"instruments"[[:space:]]*:[[:space:]]*\[\]' "$body"; then pass 'booking options API returns a non-empty catalogue'; else fail "booking options API is empty or invalid (HTTP $code)"; fi
if result="$(curl --silent --show-error --max-time 20 --proto '=https' --dump-header "$headers" --output /dev/null --write-out '%{http_code}' "$BASE_URL/booking/")"; then location="$(grep -Ei '^location:' "$headers" | tail -1 | sed -E 's/^[Ll]ocation:[[:space:]]*//; s/\r$//' || true)"; if [[ "$result" == 301 ]] && { [[ "$location" == '/enrol/' ]] || [[ "$location" == "$BASE_URL/enrol/" ]]; }; then pass '/booking/ redirects permanently to /enrol/'; else fail "/booking/ expected 301 to /enrol/, got $result ${location:-no-location}"; fi; else fail '/booking/ transfer failed'; fi
for path in /login/ /wp-login.php; do if ! fetch "$path"; then continue; fi; if [[ "$code" == 200 ]]; then pass "$path HTTP 200"; else fail "$path expected HTTP 200, got $code"; fi; done
if ! fetch '/admin-operations/'; then :; elif [[ "$code" == 200 ]]; then pass '/admin-operations/ HTTP 200'; else fail "/admin-operations/ expected HTTP 200, got $code"; fi
if result="$(curl --silent --show-error --max-time 20 --proto '=https' --dump-header "$headers" --output /dev/null --write-out '%{http_code}' "$BASE_URL/wp-admin/")"; then location="$(grep -Ei '^location:' "$headers" | tail -1 | sed -E 's/^[Ll]ocation:[[:space:]]*//; s/\r$//' || true)"; if [[ "$result" == 302 && ( "$location" == *wp-login.php* || "$location" == "$BASE_URL/login/"* ) ]]; then pass '/wp-admin/ redirects signed-out visitor to login'; else fail "/wp-admin/ expected login redirect, got $result ${location:-no-location}"; fi; else fail '/wp-admin/ transfer failed'; fi
for path in /student-portal/ /teacher-portal/; do if result="$(curl --silent --show-error --max-time 20 --proto '=https' --dump-header "$headers" --output /dev/null --write-out '%{http_code}' "$BASE_URL$path")"; then location="$(grep -Ei '^location:' "$headers" | tail -1 | sed -E 's/^[Ll]ocation:[[:space:]]*//; s/\r$//' || true)"; if [[ "$result" =~ ^(301|302|303)$ && "$location" == "$BASE_URL/login/"* ]]; then pass "$path redirects signed-out visitor to login"; else fail "$path expected login redirect, got $result ${location:-no-location}"; fi; else fail "$path transfer failed"; fi; done
if ! fetch '/wp-json/'; then :; elif [[ "$code" == 200 ]] && grep -Eq 'delnavazan-platform.{0,4}v1' "$body"; then pass 'WordPress REST index advertises Platform routes'; else fail "WordPress REST index missing Platform routes (HTTP $code)"; fi
if ! fetch '/wp-sitemap.xml'; then :; elif [[ "$code" == 200 ]]; then pass 'WordPress sitemap is available'; else fail "WordPress sitemap expected HTTP 200, got $code"; fi
if result="$(curl --silent --show-error --max-time 20 --proto '=https' --dump-header "$headers" --output /dev/null --write-out '%{http_code}' 'https://www.delnavazan.com/')"; then location="$(grep -Ei '^location:' "$headers" | tail -1 | sed -E 's/^[Ll]ocation:[[:space:]]*//; s/\r$//' || true)"; if [[ "$result" =~ ^(301|302)$ && "$location" == 'https://delnavazan.com/'* ]]; then pass 'www redirects to HTTPS apex'; else fail "www expected HTTPS apex redirect, got $result ${location:-no-location}"; fi; else fail 'www HTTPS transfer failed'; fi
if [[ "$failures" == 0 ]]; then echo 'RESULT: PASS'; exit 0; fi
echo 'RESULT: FAIL'; exit 1
