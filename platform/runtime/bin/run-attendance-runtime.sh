#!/usr/bin/env bash
# Disposable WordPress/MariaDB proof for the interim attendance and completion policy.
# Synthetic local data only; no provider or production contact.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"

"$bin/run-fresh-install.sh"
"$bin/provision-post-intro-actor.sh"
php "$DZN_PLUGIN_SOURCE/platform/tests/livekit-attendance-threshold-unit.php"
dzn_wp_env DZN_PHASE_2A2J_RUNTIME_TEST=fixture -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/phase-2a2j-fixture.php" --user=1
dzn_wp_env DZN_INTERIM_ATTENDANCE_RUNTIME=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/interim-attendance-completion-runtime.php" --user=1
dzn_wp_env DZN_LIVEKIT_RUNTIME=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/livekit-classroom-runtime.php" --user=1
dzn_wp_env DZN_LESSON_REPLACEMENT_RUNTIME=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/lesson-replacement-runtime.php" --user=1
# Existing post-intro enrolment, twelve-Lesson scheduling and instalment funding must stay intact.
dzn_wp_env DZN_POST_INTRO_RUNTIME_TEST=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/post-intro-enrolment-automation-runtime.php" --user=1
# The LiveKit canonical-attendance suite needs one fresh canonical chain per synthetic scenario.
# Seed two additional disposable Phase-J fixtures after the existing suites so those suites keep
# their original source allocation and this adapter proof remains deterministic.
dzn_wp_env DZN_PHASE_2A2J_RUNTIME_TEST=fixture -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/phase-2a2j-fixture.php" --user=1
dzn_wp_env DZN_PHASE_2A2J_RUNTIME_TEST=fixture -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/phase-2a2j-fixture.php" --user=1
dzn_wp_env DZN_LIVEKIT_ATTENDANCE_RUNTIME=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/livekit-attendance-runtime.php" --user=1
# Two independent reconciliation workers race on the one durable pending occurrence left by the
# runtime proof. The verifier asserts one outcome, one completion event and one settlement decision.
concurrency_a="$DZN_RUNTIME_STATE_DIR/livekit-concurrency-a.log"
concurrency_b="$DZN_RUNTIME_STATE_DIR/livekit-concurrency-b.log"
dzn_wp_env DZN_LIVEKIT_ATTENDANCE_CONCURRENCY=worker -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/livekit-attendance-concurrency-worker.php" --user=1 >"$concurrency_a" 2>&1 &
concurrency_pid_a=$!
dzn_wp_env DZN_LIVEKIT_ATTENDANCE_CONCURRENCY=worker -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/livekit-attendance-concurrency-worker.php" --user=1 >"$concurrency_b" 2>&1 &
concurrency_pid_b=$!
if ! wait "$concurrency_pid_a"; then cat "$concurrency_a"; exit 1; fi
if ! wait "$concurrency_pid_b"; then cat "$concurrency_b"; exit 1; fi
dzn_wp_env DZN_LIVEKIT_ATTENDANCE_CONCURRENCY=verify -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/livekit-attendance-concurrency-verify.php" --user=1
echo 'Interim attendance runtime: PASS'
