#!/usr/bin/env bash
# Disposable WordPress/MariaDB proof for the teacher statement read seam and the real
# admin-post.php download handler (owner success, cross-teacher denial, invalid nonce,
# unauthenticated). The real statement is drafted, issued and settled through the canonical
# finance authority from recorded snapshot/payability/golden-hour facts.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
plugin_tests="$DZN_PLUGIN_SOURCE/platform/tests"

"$bin/run-fresh-install.sh"

# The WordPress web container mounts only DZN_WP_DIR, while the installer links the plugin to the
# external candidate source (mounted only into the CLI container). Replace the symlink with a real
# copy of the plugin and theme inside the mounted webroot (as root, because the container owns
# wp-content), so the real admin-post.php HTTP handler and the real teacher portal can load them.
docker run --rm --pull=never -u 0 \
  -v "$DZN_WP_DIR:/var/www/html" \
  -v "$DZN_PLUGIN_SOURCE:$DZN_PLUGIN_SOURCE:ro" \
  --entrypoint sh "$DZN_CLI_IMAGE" -c '
    set -e
    rm -rf /var/www/html/wp-content/plugins/delnavazan-platform
    cp -a "$1/platform" /var/www/html/wp-content/plugins/delnavazan-platform
    rm -rf /var/www/html/wp-content/themes/delnavazan-theme
    cp -a "$1/theme" /var/www/html/wp-content/themes/delnavazan-theme
    chmod -R a+rX /var/www/html/wp-content/plugins/delnavazan-platform /var/www/html/wp-content/themes/delnavazan-theme
  ' sh "$DZN_PLUGIN_SOURCE"

dzn_wp_env DZN_PHASE_2A2J_RUNTIME_TEST=fixture -- eval-file "$plugin_tests/phase-2a2j-fixture.php" --user=1

out="$(dzn_wp_env DZN_TEACHER_STATEMENT_RUNTIME_TEST=authority -- eval-file "$plugin_tests/teacher-statement-runtime.php" --user=1)"
printf '%s\n' "$out"

json="$(printf '%s\n' "$out" | sed -n 's/^DZN_TEACHER_STATEMENT_HTTP=//p' | tail -1)"
[ -n "$json" ] || { echo 'error: runtime did not emit the HTTP handshake' >&2; exit 1; }

tmp="$(mktemp -d)"
cleanup(){ rm -rf "$tmp"; }
trap cleanup EXIT
printf '%s' "$json" > "$tmp/handshake.json"

json_get(){
  php -r '$d=json_decode(file_get_contents($argv[1]),true); $k=$argv[2]; if(!is_array($d)||!array_key_exists($k,$d)){fwrite(STDERR,"missing handshake key: $k\n");exit(2);} echo is_scalar($d[$k])?$d[$k]:json_encode($d[$k]);' "$tmp/handshake.json" "$1"
}

url="$(json_get url)"
sid="$(json_get statement_id)"
a_cookie="$(json_get a_cookie)"
a_nonce="$(json_get a_nonce)"
b_cookie="$(json_get b_cookie)"
b_nonce="$(json_get b_nonce)"
ta_ref="$(json_get teacher_a_reference)"
tb_ref="$(json_get teacher_b_reference)"
expected_total="$(json_get expected_total_text)"

pass=0
fail=0
check(){ if [ "$1" = "$2" ]; then pass=$((pass+1)); else fail=$((fail+1)); echo "FAIL: $3 (expected '$2', got '$1')" >&2; fi; }
check_absent(){ if grep -q -- "$1" "$2"; then fail=$((fail+1)); echo "FAIL: $3" >&2; else pass=$((pass+1)); fi; }
check_present(){ if grep -q -- "$1" "$2"; then pass=$((pass+1)); else fail=$((fail+1)); echo "FAIL: $3" >&2; fi; }

post(){
  local nonce="$1" cookie="$2" body="$3"
  local args=(-sS -D "${body}.headers" -o "$body" -w '%{http_code}' -X POST)
  if [ -n "$cookie" ]; then args+=(-H "Cookie: $cookie"); fi
  curl "${args[@]}" \
    --data-urlencode "action=dzn_teacher_statement_download" \
    --data-urlencode "statement_id=$sid" \
    --data-urlencode "_wpnonce=$nonce" \
    "$url"
}

# 1. Owner with a valid nonce downloads the statement.
code="$(post "$a_nonce" "$a_cookie" "$tmp/success.html")"
check "$code" "200" "owner download succeeds"
check_present "$ta_ref" "$tmp/success.html" "owner download carries the teacher reference"
check_present "$expected_total" "$tmp/success.html" "owner download carries the recorded payable total"
check_present "Content-Disposition: attachment" "$tmp/success.html.headers" "owner download is served as an attachment"

# 2. Another authenticated Teacher is denied.
code="$(post "$b_nonce" "$b_cookie" "$tmp/cross.html")"
check "$code" "403" "cross-teacher download is denied"
check_absent "$ta_ref" "$tmp/cross.html" "cross-teacher denial does not leak the owner statement"

# 3. An invalid nonce is rejected.
code="$(post "invalidnonce" "$a_cookie" "$tmp/nonce.html")"
check "$code" "403" "invalid nonce is rejected"

# 4. An unauthenticated request is denied.
code="$(post "$a_nonce" "" "$tmp/anon.html")"
check "$code" "403" "unauthenticated download is denied"

# 5. The real teacher finance portal renders the recorded lesson lines and download action.
# This is a server-rendered theme verification (not a real browser).
dzn_wp theme activate delnavazan-theme >/dev/null
portal_code="$(curl -sS -L -o "$tmp/portal.html" -w '%{http_code}' -H "Cookie: $a_cookie" "$DZN_WP_URL/teacher-portal/?teacher-view=finance")"
check "$portal_code" "200" "teacher finance portal renders"
check_present 'dzn-tp-statement-lines' "$tmp/portal.html" "portal renders the lesson-lines table"
check_present 'Synthetic J' "$tmp/portal.html" "portal renders a recorded lesson course"
check_present 'دانلود صورت‌حساب' "$tmp/portal.html" "portal renders the download action"
check_present 'پرداخت شده' "$tmp/portal.html" "portal renders the paid status"
check_present "$expected_total" "$tmp/portal.html" "portal renders the recorded payable total"

if [ "$fail" -ne 0 ]; then
  echo "teacher-statement-runtime: FAIL ($fail failures, $pass passing checks)" >&2
  exit 1
fi
echo "teacher-statement-runtime: PASS ($pass checks)"
