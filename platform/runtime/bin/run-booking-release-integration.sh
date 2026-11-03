#!/usr/bin/env bash
# Disposable cross-feature proof: cancellation, stale invitation refusal and slot reuse.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
"$bin/run-fresh-install.sh"
plugin_root="$(dzn_plugin_source_from_worktree "$DZN_PLUGIN_WORKTREE")"
dzn_wp_env DZN_BOOKING_RELEASE_INTEGRATION=authority -- eval-file "$plugin_root/tests/booking-release-integration-runtime.php" --user=1
echo 'Booking release integration runtime: PASS'
