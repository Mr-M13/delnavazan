#!/usr/bin/env bash
# Disposable acceptance proof for the public booking CSRF bootstrap.
# Assumes the disposable runtime is running (make smoke or make reset).
set -euo pipefail
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd -P)"
source "$bin/common.sh"

base="$DZN_ACCEPTANCE_WP_URL"
tmp="$(mktemp -d "${TMPDIR:-/tmp}/dzn-booking-csrf.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT
jar="$tmp/cookies"
headers="$tmp/headers"
page="$tmp/page"
fail() { echo "FAIL  $*" >&2; exit 1; }

curl -sS -D "$headers" -c "$jar" -b "$jar" "$base/enrol/" -o "$page"
grep -qi '^Set-Cookie: dzn_booking_csrf=' "$headers" || fail 'first visit did not set the booking CSRF cookie'
grep -qi '^Set-Cookie: dzn_booking_csrf=.*HttpOnly' "$headers" || fail 'booking CSRF cookie is not HttpOnly'
grep -qi '^Set-Cookie: dzn_booking_csrf=.*SameSite=Lax' "$headers" || fail 'booking CSRF cookie is not SameSite=Lax'
grep -qi '^Cache-Control:.*no-cache' "$headers" || fail 'booking page is not no-cache'
grep -qi '^Expires: .*1984' "$headers" || fail 'booking page does not carry a past Expires header'

token="$(grep -o '"csrfToken":"[^"]*"' "$page" | head -1 | sed 's/.*"csrfToken":"//;s/"$//')"
cookie="$(awk '/dzn_booking_csrf/{print $7}' "$jar")"
[ -n "$token" ] || fail 'localized booking CSRF token is empty'
[ "$token" = "$cookie" ] || fail 'localized CSRF token and cookie differ'
[ "$(awk -F. '{print NF}' <<<"$token")" = 3 ] || fail 'booking CSRF token is not a 3-part signed token'

expired="$(dzn_acceptance_wp eval '$n=bin2hex(random_bytes(32));$e=(string)(time()-10);echo $n.".".$e.".".hash_hmac("sha256",$n.".".$e,wp_salt("dzn_booking_csrf"));')"
code="$(curl -sS -o "$tmp/expired.body" -w '%{http_code}' -X POST "$base/?rest_route=/delnavazan-platform/v1/booking-requests" \
  -H 'Content-Type: application/json' \
  -H "Idempotency-Key: $(uuidgen | tr 'A-Z' 'a-z')" \
  -H "Cookie: dzn_booking_csrf=$expired" \
  -H "X-DZN-Booking-CSRF: $expired" \
  --data '{}')"
[ "$code" = 403 ] || fail "expired CSRF token was not rejected (HTTP $code)"
grep -q 'dzn_booking_csrf_invalid' "$tmp/expired.body" || fail 'expired CSRF rejection did not return the controlled code'

echo 'PASS  first visit sets a signed HttpOnly SameSite=Lax CSRF cookie'
echo 'PASS  booking page is no-cache with a past Expires header'
echo 'PASS  localized token matches the cookie and has three parts'
echo 'PASS  expired CSRF token is rejected with HTTP 403'
echo 'Booking CSRF acceptance: PASS'
