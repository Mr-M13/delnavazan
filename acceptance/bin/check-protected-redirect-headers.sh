#!/usr/bin/env bash
# Runtime assertion for protected 302 responses and rendered auth headers.
# Requires the disposable runtime to be up (run `make smoke` first).
set -euo pipefail
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd -P)"
source "$bin/common.sh"

BASE="$DZN_ACCEPTANCE_WP_URL"
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

check_headers() {
	local label="$1" path="$2" expected_status="$3"
	local status
	status="$(curl --silent --show-error --output /dev/null --dump-header "$tmp" --write-out '%{http_code}' "$BASE$path")"
	[ "$status" = "$expected_status" ] || { echo "error: $label returned $status, expected $expected_status" >&2; exit 1; }
	grep -qi '^X-Robots-Tag: noindex, nofollow' "$tmp" || { echo "error: $label is missing X-Robots-Tag" >&2; exit 1; }
	grep -qi '^Cache-Control: .*no-store' "$tmp" || { echo "error: $label is missing no-store" >&2; exit 1; }
	grep -qi '^Cache-Control: .*private' "$tmp" || { echo "error: $label is missing private" >&2; exit 1; }
	echo "PASS  $label ($status, noindex + no-store/private)"
}

check_headers 'signed-out student redirect' '/student-portal/' 302
check_headers 'signed-out teacher redirect' '/teacher-portal/' 302
check_headers 'signed-out dashboard redirect' '/dashboard/' 302
check_headers 'login screen' '/login/' 200

# The public legacy redirect must remain public and keep the instrument.
location="$(curl --silent --show-error --output /dev/null --write-out '%{redirect_url}' "$BASE/booking/?instrument=setar")"
case "$location" in
	*'/enrol/'*'instrument=setar'*) echo "PASS  legacy booking keeps the instrument" ;;
	*) echo "error: legacy booking location was $location" >&2; exit 1 ;;
esac

echo 'Protected redirect/header runtime assertions: PASS'
