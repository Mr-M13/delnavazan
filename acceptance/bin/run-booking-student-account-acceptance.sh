#!/usr/bin/env bash
set -euo pipefail
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd -P)"
source "$bin/common.sh"
cleanup() { "$bin/destroy.sh" YES; }
trap cleanup EXIT
"$bin/reset.sh"
dzn_acceptance_wp eval-file /workspace/acceptance/fixtures/booking-student-account.php --user=1
