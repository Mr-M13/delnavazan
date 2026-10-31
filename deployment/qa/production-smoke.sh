#!/usr/bin/env bash
set -Eeuo pipefail
MODE="full"
if [[ "${1:-}" == "--confirm" ]]; then MODE="confirm"; shift; fi
BASE_URL="${1:-https://delnavazan.com}"
BASE_URL="${BASE_URL%/}"
[[ "$BASE_URL" == "https://delnavazan.com" ]] || { echo 'FAIL production smoke target must be https://delnavazan.com' >&2; exit 1; }
if [[ "$MODE" == 'confirm' ]]; then
  export SMOKE_MAX_ATTEMPTS="${SMOKE_CONFIRM_MAX_ATTEMPTS:-2}"
  export SMOKE_RETRY_DELAYS_STRING="${SMOKE_CONFIRM_RETRY_DELAYS:-1}"
  export SMOKE_MAX_TOTAL_SECONDS="${SMOKE_CONFIRM_MAX_TOTAL_SECONDS:-30}"
fi
# shellcheck source=production-smoke-lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/production-smoke-lib.sh"
ENROL_BODY=""
smoke_cleanup_all(){ [[ -n "$ENROL_BODY" ]] && rm -f "$ENROL_BODY"; smoke_cleanup; }
trap smoke_cleanup_all EXIT

failures=0
pass(){ printf 'PASS  %s\n' "$1"; }
fail(){ printf 'FAIL  %s\n' "$1"; failures=$((failures+1)); }

fetch(){
  local path="$1"
  if ! smoke_request "$path" "$BASE_URL$path"; then
    fail "$path retry budget exhausted or transport failed ($SMOKE_CLASSIFICATION)"
    return 1
  fi
  if [[ "$SMOKE_FINAL_URL" != "$BASE_URL" && "$SMOKE_FINAL_URL" != "$BASE_URL/" && "$SMOKE_FINAL_URL" != "$BASE_URL/"* ]]; then
    fail "$path redirected off apex production to $SMOKE_FINAL_URL"
    return 1
  fi
  return 0
}

header_value(){
  local name="$1"
  grep -Ei "^${name}:" "$SMOKE_HEADERS" | tail -1 | sed -E 's/^[^:]+:[[:space:]]*//; s/\r$//' || true
}

check_html(){
  local path="$1"
  if ! fetch "$path"; then return; fi
  if [[ "$SMOKE_CODE" == 200 ]]; then pass "$path HTTP 200"; else fail "$path expected HTTP 200, got $SMOKE_CODE"; fi
  if grep -Eqi '(PHP[[:space:]]+(Fatal error|Parse error|Warning|Notice):|<b>[[:space:]]*(Fatal error|Parse error|Warning|Notice)[[:space:]]*</b>)' "$SMOKE_BODY"; then
    fail "$path contains visible PHP error marker"
  else
    pass "$path has no visible PHP error marker"
  fi
  if grep -Eqi '^x-robots-tag:.*noindex' "$SMOKE_HEADERS" || grep -Eiq '<meta[^>]+name=["'"'"']?robots["'"'"']?[^>]+content=["'"'"'][^"'"'"']*noindex' "$SMOKE_BODY"; then
    fail "$path is marked noindex"
  else
    pass "$path is indexable"
  fi
}

check_legal(){
  local path="$1"
  if ! fetch "$path"; then return; fi
  if [[ "$SMOKE_CODE" == 200 ]]; then pass "$path legal page HTTP 200"; else fail "$path expected HTTP 200, got $SMOKE_CODE"; fi
  if grep -Fq 'site-footer__policies' "$SMOKE_BODY"; then
    pass "$path renders the footer policy section"
  else
    fail "$path does not render the footer policy section"
  fi
}

check_css_asset(){
  local asset="$1" url=""
  url="$(grep -E "/assets/css/${asset}\.css(\?|$)" <<<"$css_urls" | head -1 || true)"
  if [[ -z "$url" ]]; then fail "/enrol/ does not reference current $asset.css from delnavazan-theme"; return; fi
  if ! fetch "$url"; then return; fi
  if [[ "$SMOKE_CODE" == 200 ]]; then pass "$url HTTP 200"; else fail "$url expected HTTP 200, got $SMOKE_CODE"; fi
  if grep -Eiq '^content-type:[[:space:]]*text/css([;[:space:]]|$)' "$SMOKE_HEADERS"; then
    pass "$url has CSS content type"
  else
    fail "$url expected CSS content type, got $(header_value content-type || echo 'missing Content-Type')"
  fi
}

check_theme_asset(){
  local path="$1" expected_type="$2"
  if ! fetch "$path"; then return; fi
  if [[ "$SMOKE_CODE" == 200 ]]; then pass "$path HTTP 200"; else fail "$path expected HTTP 200, got $SMOKE_CODE"; fi
  if grep -Eiq "^content-type:[[:space:]]*(${expected_type})([;/[:space:]]|$)" "$SMOKE_HEADERS"; then
    pass "$path has $expected_type content type"
  else
    fail "$path expected $expected_type content type, got $(header_value content-type || echo 'missing Content-Type')"
  fi
}

check_redirect(){
  local path="$1" expected="$2" required_location="$3"
  if ! smoke_request "$path" "$BASE_URL$path" --no-location; then
    fail "$path retry budget exhausted or transport failed ($SMOKE_CLASSIFICATION)"
    return
  fi
  local location; location="$(header_value location)"
  if [[ "$SMOKE_CODE" =~ $expected && "$location" == *"$required_location"* ]]; then
    pass "$path redirects to $required_location"
  else
    fail "$path expected $expected to $required_location, got $SMOKE_CODE ${location:-no-location}"
  fi
}

if [[ "$MODE" == 'confirm' ]]; then
  check_html '/'
  check_html '/enrol/'
  if ! fetch '/login/'; then :; elif [[ "$SMOKE_CODE" == 200 ]]; then pass '/login/ HTTP 200'; else fail "/login/ expected HTTP 200, got $SMOKE_CODE"; fi
  if ! fetch '/wp-json/'; then :; elif [[ "$SMOKE_CODE" == 200 ]] && grep -Eq 'delnavazan-platform.{0,4}v1' "$SMOKE_BODY"; then
    pass 'WordPress REST index advertises Platform routes'
  else
    fail "WordPress REST index missing Platform routes (HTTP $SMOKE_CODE)"
  fi
  if [[ "$failures" == 0 ]]; then echo 'RESULT: CONFIRM PASS'; exit 0; fi
  echo 'RESULT: CONFIRM FAIL'; exit 1
fi

check_html '/'
check_html '/enrol/'
ENROL_BODY="$(mktemp)"
cp "$SMOKE_BODY" "$ENROL_BODY"
for legal_path in /privacy-policy/ /terms-and-conditions/ /cancellation-and-refunds/ /recording-and-ai-policy/ /cookie-policy/; do
  check_legal "$legal_path"
done
css_urls="$(grep -Eo '/wp-content/themes/delnavazan-theme/assets/css/(theme|booking)\.css(\?[^"[:space:]<>]*)?' "$ENROL_BODY" | sed "s/'$//" | sort -u || true)"
if grep -Eq 'data-instrument-choice="[0-9]+"' "$ENROL_BODY"; then pass '/enrol/ renders course choices'; else fail '/enrol/ does not render any course choices'; fi
check_css_asset theme
check_css_asset booking
check_theme_asset '/wp-content/themes/delnavazan-theme/style.css' 'text/css'
check_theme_asset '/wp-content/themes/delnavazan-theme/assets/js/navigation.js' 'application/javascript|text/javascript'
check_theme_asset '/wp-content/themes/delnavazan-theme/assets/js/booking.js' 'application/javascript|text/javascript'
if ! fetch '/wp-json/delnavazan-platform/v1/booking-options'; then :; elif [[ "$SMOKE_CODE" == 200 ]] && grep -Eiq '^content-type:[[:space:]]*application/json' "$SMOKE_HEADERS" && ! grep -Eq '"instruments"[[:space:]]*:[[:space:]]*\[\]' "$SMOKE_BODY"; then
  pass 'booking options API returns a non-empty catalogue'
else
  fail "booking options API is empty or invalid (HTTP $SMOKE_CODE)"
fi
if ! smoke_request '/booking/?instrument=setar' "$BASE_URL/booking/?instrument=setar" --no-location; then
  fail "/booking/ retry budget exhausted or transport failed ($SMOKE_CLASSIFICATION)"
else
  location="$(header_value location)"
  if [[ "$SMOKE_CODE" == 302 && "$location" == *'/enrol/'* && "$location" == *'instrument=setar'* ]]; then
    pass '/booking/ temporarily redirects to /enrol/ and keeps the instrument'
  else
    fail "/booking/ expected 302 to /enrol/?instrument=setar, got $SMOKE_CODE ${location:-no-location}"
  fi
fi
if ! fetch '/login/'; then :; elif [[ "$SMOKE_CODE" == 200 ]]; then pass '/login/ HTTP 200'; else fail "/login/ expected HTTP 200, got $SMOKE_CODE"; fi
if ! fetch '/wp-login.php'; then :; elif [[ "$SMOKE_CODE" == 200 ]]; then pass '/wp-login.php HTTP 200'; else fail "/wp-login.php expected HTTP 200, got $SMOKE_CODE"; fi
if ! fetch '/admin-operations/'; then :; elif [[ "$SMOKE_CODE" == 200 ]]; then pass '/admin-operations/ HTTP 200'; else fail "/admin-operations/ expected HTTP 200, got $SMOKE_CODE"; fi
for path in /wp-admin/ /student-portal/ /teacher-portal/; do
  if ! smoke_request "$path" "$BASE_URL$path" --no-location; then
    fail "$path retry budget exhausted or transport failed ($SMOKE_CLASSIFICATION)"
    continue
  fi
  location="$(header_value location)"
  if [[ "$SMOKE_CODE" =~ ^(301|302|303)$ && ( "$location" == *wp-login.php* || "$location" == "$BASE_URL/login/"* ) ]]; then
    pass "$path redirects signed-out visitor to login"
  else
    fail "$path expected login redirect, got $SMOKE_CODE ${location:-no-location}"
  fi
done
if ! fetch '/wp-json/'; then :; elif [[ "$SMOKE_CODE" == 200 ]] && grep -Eq 'delnavazan-platform.{0,4}v1' "$SMOKE_BODY"; then
  pass 'WordPress REST index advertises Platform routes'
else
  fail "WordPress REST index missing Platform routes (HTTP $SMOKE_CODE)"
fi
if ! fetch '/wp-sitemap.xml'; then :; elif [[ "$SMOKE_CODE" == 200 ]]; then pass 'WordPress sitemap is available'; else fail "WordPress sitemap expected HTTP 200, got $SMOKE_CODE"; fi
if ! smoke_request 'www' 'https://www.delnavazan.com/' --no-location; then
  fail "www retry budget exhausted or transport failed ($SMOKE_CLASSIFICATION)"
else
  location="$(header_value location)"
  if [[ "$SMOKE_CODE" =~ ^(301|302)$ && "$location" == 'https://delnavazan.com/'* ]]; then
    pass 'www redirects to HTTPS apex'
  else
    fail "www expected HTTPS apex redirect, got $SMOKE_CODE ${location:-no-location}"
  fi
fi

if [[ "$failures" == 0 ]]; then echo 'RESULT: PASS'; exit 0; fi
echo 'RESULT: FAIL'; exit 1
