#!/usr/bin/env bash
# Disposable WordPress/MariaDB proof for owner-bound Student promotion application.
# Synthetic local data only; no live Stripe or other provider call is made.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"

"$bin/run-fresh-install.sh"
dzn_wp_env DZN_PHASE_2A2J_RUNTIME_TEST=fixture -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/phase-2a2j-fixture.php" --user=1
dzn_wp_env DZN_PROMOTION_APPLICATION_RUNTIME_TEST=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/promotion-application-runtime.php" --user=1
echo 'Promotion application runtime: PASS'
