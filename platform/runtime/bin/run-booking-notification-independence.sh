#!/usr/bin/env bash
# Disposable proof that notification failure cannot unassign or hide a matched booking.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
"$bin/run-fresh-install.sh"
plugin_root="$(dzn_plugin_source_from_worktree "$DZN_PLUGIN_WORKTREE")"
dzn_wp_env DZN_BOOKING_NOTIFICATION_INDEPENDENCE=authority -- eval-file "$plugin_root/tests/booking-notification-independence-runtime.php" --user=1
echo 'Booking notification independence runtime: PASS'
