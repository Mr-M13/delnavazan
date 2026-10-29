#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
"$bin/run-fresh-install.sh"
dzn_wp_env DZN_RECORDING_DECISION_MIGRATION_TEST=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/recording-decision-migration-runtime.php" --user=1
echo 'Recording decision migration runtime: PASS'
