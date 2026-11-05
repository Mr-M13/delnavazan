#!/usr/bin/env bash
# Disposable MariaDB proof that provider failure never reverses Teacher acceptance.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
"$bin/run-fresh-install.sh"
plugin_root="$(dzn_plugin_source_from_worktree "$DZN_PLUGIN_WORKTREE")"
dzn_wp_env DZN_BOOKING_PROVISIONING_RUNTIME=runtime -- eval-file "$plugin_root/tests/booking-provisioning-recovery-runtime.php" --user=1
echo 'Booking provisioning recovery runtime: PASS'
