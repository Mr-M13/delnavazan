#!/usr/bin/env bash
# Disposable WordPress/MariaDB proof for durable statement-email intents and attempts.
set -euo pipefail
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
source "$bin/common.sh"

dzn_require_cached_images
"$bin/run-fresh-install.sh"
dzn_candidate
DZN_PLUGIN_SOURCE="$DZN_PLUGIN_WORKTREE/platform"
export DZN_PLUGIN_SOURCE
dzn_assert_runtime_paths
dzn_wp_env DZN_PHASE_2A2J_RUNTIME_TEST=fixture -- eval-file "$DZN_PLUGIN_SOURCE/tests/phase-2a2j-fixture.php" --user=1
dzn_wp_env DZN_PHASE_2A2U_STATEMENT_TEST=authority -- eval-file "$DZN_PLUGIN_SOURCE/tests/phase-2a2u-statement-runtime.php" --user=1
echo 'Finance statement email runtime: PASS'
