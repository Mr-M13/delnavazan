#!/usr/bin/env bash
# Disposable proof for monthly draft-only statement scheduling and history.
set -euo pipefail
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
source "$bin/common.sh"
dzn_require_cached_images
"$bin/run-fresh-install.sh"
dzn_candidate
DZN_PLUGIN_SOURCE="$DZN_PLUGIN_WORKTREE/platform"
export DZN_PLUGIN_SOURCE
dzn_assert_runtime_paths
dzn_wp_env DZN_FINANCE_SCHEDULE_TEST=authority -- eval-file "$DZN_PLUGIN_SOURCE/tests/finance-schedule-runtime.php" --user=1
echo 'Finance schedule runtime: PASS'
