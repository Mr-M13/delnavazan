#!/usr/bin/env bash
# Disposable WordPress/MariaDB proof for the Students administration workspace.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"

"$bin/run-fresh-install.sh"
"$bin/provision-post-intro-actor.sh"
dzn_wp_env DZN_PHASE_2A2J_RUNTIME_TEST=fixture -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/phase-2a2j-fixture.php" --user=1
dzn_wp_env DZN_STUDENT_WORKSPACE_RUNTIME=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/student-workspace-runtime.php" --user=1
echo 'Student workspace runtime: PASS'
