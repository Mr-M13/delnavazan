#!/usr/bin/env bash
# Exercises the HTTP login flow and resulting role-aware navigation. It does
# not inspect PHP source or submit any commercial/provider action.
set -euo pipefail
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd -P)"
source "$bin/common.sh"

if [ "${DZN_ACCEPTANCE_SKIP_RESET:-0}" != 1 ]; then
  "$bin/reset.sh"
  "$bin/seed-identities.sh"
fi
mkdir -p "$DZN_ACCEPTANCE_STATE_DIR/cookies" "$DZN_ACCEPTANCE_STATE_DIR/http"

password='dzn-acceptance-local-only'

login_and_expect() {
	local identity="$1" credential="$2" password_value="$3" expected_path="$4" post_path="${5:-/login/}" requested_path="${6:-/dashboard/}"
	local jar="$DZN_ACCEPTANCE_STATE_DIR/cookies/$identity.txt" login_page="$DZN_ACCEPTANCE_STATE_DIR/http/$identity-login.html" response="$DZN_ACCEPTANCE_STATE_DIR/http/$identity-response.html" nonce meta status effective primary_nav
	rm -f "$jar" "$login_page" "$response"
	curl --fail --silent --show-error --cookie-jar "$jar" "$DZN_ACCEPTANCE_WP_URL/login/" >"$login_page"
	nonce="$(sed -n 's/.*name="dzn_frontend_login_nonce" value="\([^"]*\)".*/\1/p' "$login_page" | head -n 1)"
	[ -n "$nonce" ] || { echo "error: no frontend-login nonce was served for $identity" >&2; exit 1; }
	! grep -q 'wordpress_test_cookie' "$jar" || { echo "error: $identity did not begin with a fresh browser cookie jar" >&2; exit 1; }
	grep -Fq "action=\"$DZN_ACCEPTANCE_WP_URL/login/\"" "$login_page" || { echo "error: $identity form does not use the unified login endpoint" >&2; exit 1; }
	primary_nav="$(sed -n '/<nav id="primary-navigation"/,/<\/nav>/p' "$login_page")"
	[ -n "$primary_nav" ] || { echo "error: primary navigation was not rendered" >&2; exit 1; }
	[ "$(grep -Fo '>ورود</a>' <<<"$primary_nav" | wc -l | tr -d ' ')" = 1 ] || { echo "error: primary navigation must expose exactly one login CTA labelled ورود" >&2; exit 1; }
	grep -Fq 'class="dzn-nav-portal dzn-nav-action" href="' <<<"$primary_nav" || { echo "error: primary navigation login CTA must keep the original enrolment button styling" >&2; exit 1; }
	[ "$(grep -o '<a [^>]*>[^<]*</a>' <<<"$primary_nav" | tail -1 | grep -Fc '>ورود</a>')" = 1 ] || { echo "error: the login CTA must be the last primary navigation item" >&2; exit 1; }
	! grep -Fq '/enrol/' <<<"$primary_nav" || { echo "error: primary navigation must not duplicate the homepage enrolment action" >&2; exit 1; }
	! grep -Eq 'ورود هنرجویان|ورود اساتید|ورود به پرتال مدرس' "$login_page" || { echo "error: a competing role-specific login entry is still rendered" >&2; exit 1; }
	meta="$(curl --fail --silent --show-error --location --max-redirs 10 --cookie "@$jar" --cookie-jar "$jar" --output "$response" --write-out '%{http_code} %{url_effective}' \
		--data-urlencode "log=$credential" --data-urlencode "pwd=$password_value" --data-urlencode 'dzn_frontend_login=1' --data-urlencode "dzn_frontend_login_nonce=$nonce" --data-urlencode "redirect_to=$DZN_ACCEPTANCE_WP_URL$requested_path" \
		"$DZN_ACCEPTANCE_WP_URL$post_path")"
	status="${meta%% *}"; effective="${meta#* }"
	[ "$status" = 200 ] || { echo "error: $identity finished login with HTTP $status" >&2; exit 1; }
	[ "$effective" = "$DZN_ACCEPTANCE_WP_URL$expected_path" ] || { echo "error: $identity routed to $effective; expected $DZN_ACCEPTANCE_WP_URL$expected_path" >&2; exit 1; }
	echo "PASS  $identity -> $expected_path"
}

login_failure_expect() {
	local identity="$1" credential="$2" password_value="$3" nonce_mode="$4"
	local jar="$DZN_ACCEPTANCE_STATE_DIR/cookies/$identity.txt" login_page="$DZN_ACCEPTANCE_STATE_DIR/http/$identity-login.html" response="$DZN_ACCEPTANCE_STATE_DIR/http/$identity-response.html" nonce meta effective
	rm -f "$jar" "$login_page" "$response"
	curl --fail --silent --show-error --cookie-jar "$jar" "$DZN_ACCEPTANCE_WP_URL/login/" >"$login_page"
	nonce="$(sed -n 's/.*name="dzn_frontend_login_nonce" value="\([^"]*\)".*/\1/p' "$login_page" | head -n 1)"
	[ "$nonce_mode" = valid ] || nonce='invalid-nonce'
	meta="$(curl --fail --silent --show-error --location --max-redirs 10 --cookie "@$jar" --cookie-jar "$jar" --output "$response" --write-out '%{http_code} %{url_effective}' \
		--data-urlencode "log=$credential" --data-urlencode "pwd=$password_value" --data-urlencode 'dzn_frontend_login=1' --data-urlencode "dzn_frontend_login_nonce=$nonce" --data-urlencode "redirect_to=$DZN_ACCEPTANCE_WP_URL/dashboard/" \
		"$DZN_ACCEPTANCE_WP_URL/login/")"
	effective="${meta#* }"
	[ "$effective" = "$DZN_ACCEPTANCE_WP_URL/login/?auth=failed" ] || { echo "error: $identity failure escaped the branded route: $effective" >&2; exit 1; }
	! grep -q 'wordpress_logged_in_' "$jar" || { echo "error: $identity failure established an authenticated session" >&2; exit 1; }
	grep -q 'ورود انجام نشد' "$response" || { echo "error: $identity did not receive the generic failure state" >&2; exit 1; }
	echo "PASS  $identity rejected on branded route"
}

login_and_expect student 'dzn-acceptance-student' "$password" '/student-portal/'
login_and_expect teacher-email 'dzn-acceptance-teacher@example.invalid' "$password" '/teacher-portal/'
login_and_expect dual 'dzn-acceptance-dual' "$password" '/dashboard/'
login_and_expect administrator admin 'local-only-not-a-secret' '/admin-operations/'
login_and_expect student-cross-role 'dzn-acceptance-student' "$password" '/student-portal/' '/login/' '/teacher-portal/'
# A cached copy of the former form may still post to wp-login.php. It must be
# intercepted before core's missing-test-cookie branch and succeed first try.
login_and_expect stale-form 'dzn-acceptance-student' "$password" '/student-portal/' '/wp-login.php'
login_failure_expect wrong-password 'dzn-acceptance-student' 'incorrect-password' valid
login_failure_expect invalid-nonce 'dzn-acceptance-student' "$password" invalid
echo 'Role-aware login routing smoke: PASS'
