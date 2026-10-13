#!/usr/bin/env bash
# Runs the synthetic student claim issuance, recipient-authority, acceptance, expiry, and replay path.
set -euo pipefail
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd -P)"
source "$bin/common.sh"

cleanup() { "$bin/destroy.sh" YES; }
trap cleanup EXIT

"$bin/reset.sh"
dzn_acceptance_wp eval-file /workspace/acceptance/fixtures/student-account-claim.php --user=1
