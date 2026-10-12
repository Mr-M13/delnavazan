#!/usr/bin/env bash
# Exercises the HTTP login flow and resulting role-aware navigation. It does
# not inspect PHP source or submit any commercial/provider action.
set -euo pipefail
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd -P)"
source "$bin/common.sh"

"$bin/reset.sh"
"$bin/seed-identities.sh"
mkdir -p "$DZN_ACCEPTANCE_STATE_DIR/cookies" "$DZN_ACCEPTANCE_STATE_DIR/http"

password='dzn-acceptance-local-only'
test_cookie='wordpress_test_cookie=WP Cookie check'

login_and_expect() {
  local identity="$1" expected_path="$2" jar="$DZN_ACCEPTANCE_STATE_DIR/cookies/$identity.txt" login_page="$DZN_ACCEPTANCE_STATE_DIR/http/$identity-login.html" response="$DZN_ACCEPTANCE_STATE_DIR/http/$identity-response.html" nonce meta status effective
  rm -f "$jar" "$login_page" "$response"
  curl --fail --silent --show-error --cookie "$test_cookie" --cookie-jar "$jar" "$DZN_ACCEPTANCE_WP_URL/login/" >"$login_page"
  nonce="$(sed -n 's/.*name="dzn_frontend_login_nonce" value="\([^"]*\)".*/\1/p' "$login_page" | head -n 1)"
  [ -n "$nonce" ] || { echo "error: no frontend-login nonce was served for $identity" >&2; exit 1; }
  meta="$(curl --fail --silent --show-error --location --max-redirs 10 --cookie "$test_cookie" --cookie "@$jar" --cookie-jar "$jar" --output "$response" --write-out '%{http_code} %{url_effective}' \
    --data-urlencode "log=dzn-acceptance-$identity" --data-urlencode "pwd=$password" --data-urlencode 'dzn_frontend_login=1' --data-urlencode "dzn_frontend_login_nonce=$nonce" --data-urlencode "redirect_to=$DZN_ACCEPTANCE_WP_URL/dashboard/" --data-urlencode 'testcookie=1' \
    "$DZN_ACCEPTANCE_WP_URL/wp-login.php")"
  status="${meta%% *}"; effective="${meta#* }"
  [ "$status" = 200 ] || { echo "error: $identity finished login with HTTP $status" >&2; exit 1; }
  [ "$effective" = "$DZN_ACCEPTANCE_WP_URL$expected_path" ] || { echo "error: $identity routed to $effective; expected $DZN_ACCEPTANCE_WP_URL$expected_path" >&2; exit 1; }
  echo "PASS  $identity -> $expected_path"
}

login_and_expect student '/student-portal/'
login_and_expect teacher '/teacher-portal/'
login_and_expect dual '/dashboard/'
echo 'Role-aware login routing smoke: PASS'
