#!/usr/bin/env bash
# Disposable WordPress/MariaDB proof for confirmed introductory booking cancellation.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
"$bin/run-fresh-install.sh"
plugin_root="$(dzn_plugin_source_from_worktree "$DZN_PLUGIN_WORKTREE")"
dzn_wp_env DZN_CONFIRMED_CANCELLATION_RUNTIME=authority -- eval-file "$plugin_root/tests/confirmed-intro-cancellation-runtime.php" --user=1
echo 'Confirmed introductory cancellation runtime: PASS'
