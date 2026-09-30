#!/bin/sh
# Phase 2A.2-W concurrency runner.  The source preflight always runs; the races themselves need the
# disposable WordPress/MariaDB runtime and the Performance Schema that attributes the wait to the
# Lesson root row.  Every mode asserts that no second delegation, second claim or second terminal
# outcome can exist for one confirmation (§15.1–15.5, W-D18/W-D19).
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
rg -q 'portal_lesson_capability_roots' "$root/src/Portals/PortalCapabilityService.php" && rg -q 'SELECT id FROM.*FOR UPDATE' "$root/src/Portals/PortalCapabilityService.php" || { echo 'Phase-W concurrency preflight missing Lesson-root locking.' >&2; exit 1; }
rg -q 'UNIQUE KEY lesson_purpose_generation|UNIQUE KEY lesson_purpose_active' "$root/src/Core/Infrastructure/Migration/Migrator.php" || { echo 'Phase-W concurrency preflight missing generation/active uniqueness.' >&2; exit 1; }
rg -q 'private function underLessonRoot' "$root/src/Portals/PortalPublicActionService.php" && rg -q "action_state'=>'delegating'" "$root/src/Portals/PortalPublicActionService.php" && rg -q 'DELEGATION_LEASE_SECONDS' "$root/src/Portals/PortalRule.php" || { echo 'Phase-W concurrency preflight missing the root-locked delegation lease.' >&2; exit 1; }
rg -q 'portal_lesson_capability_roots' "$root/tests/phase-2a2w-concurrency-wait.php" || { echo 'Phase-W concurrency preflight missing the Lesson-root wait attribution.' >&2; exit 1; }
if [ "${DZN_PHASE_2A2W_RUNTIME_TEST:-}" != concurrency ]; then
  echo 'Phase-W concurrency source preflight passed'
  echo 'Phase-W concurrency runtime requires the disposable harness; not executed locally.' >&2
  exit 2
fi
: "${DZN_PHASE_2A2W_WP_CLI:?}" "${DZN_PHASE_2A2W_WP_PATH:?}" "${DZN_PHASE_2A2W_WP_USER:?}" "${DZN_PHASE_2A2W_GATE_DIR:?}"
mode=${DZN_PHASE_2A2W_MODE:-}
case "$mode" in
  mint_vs_mint|rotate_vs_redeem|revoke_vs_redeem|two_redemptions_one_confirmation|replay_during_delegation|replay_after_crash|refusal_vs_redeem|outcome_vs_rotation|stale_schedule_vs_rotate|two_lessons_disjoint) ;;
  *) echo "Phase-W concurrency: unknown mode '${mode}'" >&2; exit 1;;
esac
gate=$DZN_PHASE_2A2W_GATE_DIR
[ -d "$gate" ] && [ -z "$(find "$gate" -mindepth 1 -maxdepth 1 -print -quit)" ] || { echo 'Phase-W concurrency: gate directory must be empty' >&2; exit 1; }
wp(){ "$DZN_PHASE_2A2W_WP_CLI" --path="$DZN_PHASE_2A2W_WP_PATH" --user="$DZN_PHASE_2A2W_WP_USER" eval-file "$1"; }
worker(){ env DZN_PHASE_2A2W_WORKER="$1" "$DZN_PHASE_2A2W_WP_CLI" --path="$DZN_PHASE_2A2W_WP_PATH" --user="$DZN_PHASE_2A2W_WP_USER" eval-file "$root/tests/phase-2a2w-concurrency-worker.php"; }
wait_gate(){ i=0; while [ ! -f "$gate/$1" ] && [ "$i" -lt 900 ]; do i=$((i+1)); sleep 0.1; done; [ -f "$gate/$1" ] || { echo "Phase-W concurrency: gate timeout ${1}" >&2; exit 1; }; }
wp "$root/tests/phase-2a2w-concurrency-setup.php"
worker w1 >"$gate/w1.out" 2>&1 & p1=$!
case "$mode" in
  replay_during_delegation|replay_after_crash) wait_gate w1.delegating;;
  *) wait_gate w1.root_locked;;
esac
if [ "$mode" = replay_after_crash ]; then
  # The delegator is killed between the two declared phases: its claim and its lease stay durable and
  # no terminal outcome exists, which is exactly the crash W-D19 promises an exact replay converges.
  kill -9 "$p1"
  wait "$p1" 2>/dev/null || true
  overlap=crash_between_phases
else
  overlap=lock_wait_before_release
fi
worker w2 >"$gate/w2.out" 2>&1 & p2=$!
wait_gate w2.connection
case "$mode" in
  replay_during_delegation)
    # The replay must not need the root while another delegator is inside the owner; it polls its own
    # confirmation and refuses the declared pending reason.
    wait_gate w2.finished
    : >"$gate/release"
    overlap=replay_while_delegating
    ;;
  replay_after_crash)
    wait_gate w2.finished
    overlap=replay_after_crash
    ;;
  two_lessons_disjoint)
    wait_gate w2.root_locked
    : >"$gate/release"
    overlap=independent_roots_held_together
    ;;
  *)
    wp "$root/tests/phase-2a2w-concurrency-wait.php" >"$gate/wait.out"
    wait_gate w2.blocked
    : >"$gate/release"
    overlap=attributed_root_block_before_release
    ;;
esac
[ "$mode" = replay_after_crash ] || wait "$p1"
wait "$p2"
! grep -Eiq 'deadlock|lock wait timeout|mysqli_sql_exception' "$gate/w1.out" "$gate/w2.out"
if [ "$mode" = mint_vs_mint ]; then
  # The declared outcome of two mints for one (lesson,purpose) is one winner: the unique active-slot
  # index refuses the second insert, so that single duplicate-entry is the contract, not a defect.
  if grep -Ei 'WordPress database error' "$gate/w1.out" "$gate/w2.out" | grep -Eiv 'Duplicate entry' | grep -q .; then
    echo 'Phase-W concurrency: unexpected database error output' >&2; exit 1
  fi
elif grep -Eiq 'WordPress database error' "$gate/w1.out" "$gate/w2.out"; then
  echo 'Phase-W concurrency: unexpected database error output' >&2; exit 1
fi
case "$mode" in
  mint_vs_mint) grep -q 'outcome=minted' "$gate/w1.out"; grep -q 'outcome=portal_capability_persistence_failed' "$gate/w2.out";;
  rotate_vs_redeem|revoke_vs_redeem) grep -qE 'outcome=(rotated|revoked)' "$gate/w1.out"; grep -q 'outcome=code=portal_action_unavailable status=404' "$gate/w2.out";;
  refusal_vs_redeem) grep -q 'outcome=refusal_recorded reason=portal_capability_expired' "$gate/w1.out"; grep -q 'outcome=state=submitted ' "$gate/w2.out";;
  outcome_vs_rotation) grep -q 'outcome=state=submitted ' "$gate/w1.out"; grep -q 'outcome=rotated' "$gate/w2.out";;
  stale_schedule_vs_rotate) grep -q 'outcome=code=portal_action_unavailable status=404' "$gate/w1.out"; grep -q 'outcome=rotated' "$gate/w2.out";;
  two_redemptions_one_confirmation) grep -q 'outcome=state=submitted ' "$gate/w1.out"; grep -qE 'outcome=(state=submitted replayed=1|code=portal_action_unavailable)' "$gate/w2.out";;
  replay_during_delegation) grep -q 'outcome=state=submitted replayed=0' "$gate/w1.out"; grep -q 'outcome=code=portal_action_unavailable status=404' "$gate/w2.out";;
  replay_after_crash) grep -q 'outcome=state=submitted ' "$gate/w2.out";;
  two_lessons_disjoint) grep -q 'outcome=state=submitted ' "$gate/w1.out"; grep -q 'outcome=state=submitted ' "$gate/w2.out";;
esac
wp "$root/tests/phase-2a2w-concurrency-verify.php"
printf 'race=%s overlap=%s attributed_wait=%s holder="%s" contender="%s"\n' "$mode" "$overlap" "$([ -f "$gate/w2.blocked" ] && tr '\n' ' ' <"$gate/w2.blocked" || printf 'not_applicable')" "$(tr '\n' ' ' <"$gate/w1.out")" "$(tr '\n' ' ' <"$gate/w2.out")"
rm -f "$gate"/*
