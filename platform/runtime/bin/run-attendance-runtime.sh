#!/usr/bin/env bash
# Disposable WordPress/MariaDB proof for the interim attendance and completion policy.
# Synthetic local data only; no provider or production contact.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"

"$bin/run-fresh-install.sh"
"$bin/provision-post-intro-actor.sh"
dzn_wp_env DZN_PHASE_2A2J_RUNTIME_TEST=fixture -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/phase-2a2j-fixture.php" --user=1
dzn_wp_env DZN_INTERIM_ATTENDANCE_RUNTIME=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/interim-attendance-completion-runtime.php" --user=1
# Existing post-intro enrolment, twelve-Lesson scheduling and instalment funding must stay intact.
dzn_wp_env DZN_POST_INTRO_RUNTIME_TEST=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/post-intro-enrolment-automation-runtime.php" --user=1
echo 'Interim attendance runtime: PASS'
