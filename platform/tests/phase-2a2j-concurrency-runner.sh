#!/bin/sh
set -eu
: "${DZN_PHASE_2A2J_WP_CLI:?}" "${DZN_PHASE_2A2J_WP_PATH:?}" "${DZN_PHASE_2A2J_WP_USER:?}" "${DZN_PHASE_2A2J_GATE_DIR:?}"
[ "${DZN_PHASE_2A2J_RUNTIME_TEST:-}" = concurrency ] || exit 1
case "${DZN_PHASE_2A2J_MODE:-}" in a_same_teacher_keys|c_replace_end|d1_replace_diff|d2_replace_same|e_initial_archive|e_replace_archive|e_archive_initial|e_archive_replace|f_initial_close|f_replace_close|f_close_initial|f_close_replace|o1_initial_offboard|o2_offboard_initial|o3_staff_replace_offboard|o4_offboard_staff_replace|o5_authenticated_replace_offboard|o6_offboard_authenticated_replace|u1_unrelated_roots|u2_shared_teacher);; *) exit 1;; esac
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd); gate=$DZN_PHASE_2A2J_GATE_DIR
[ -d "$gate" ] && [ -z "$(find "$gate" -mindepth 1 -maxdepth 1 -print -quit)" ] || exit 1
wp(){ "$DZN_PHASE_2A2J_WP_CLI" --path="$DZN_PHASE_2A2J_WP_PATH" --user="$DZN_PHASE_2A2J_WP_USER" eval-file "$1"; }
wait_gate(){ i=0; while [ ! -f "$gate/$1" ] && [ "$i" -lt 600 ]; do i=$((i+1)); sleep 0.1; done; [ -f "$gate/$1" ] || exit 1; }
wp "$root/tests/phase-2a2j-concurrency-setup.php"
env DZN_PHASE_2A2J_WORKER=w1 "$DZN_PHASE_2A2J_WP_CLI" --path="$DZN_PHASE_2A2J_WP_PATH" --user="$DZN_PHASE_2A2J_WP_USER" eval-file "$root/tests/phase-2a2j-concurrency-worker.php" >"$gate/w1.out" 2>&1 & p1=$!
wait_gate w1.locked; wait_gate w1.connection
env DZN_PHASE_2A2J_WORKER=w2 "$DZN_PHASE_2A2J_WP_CLI" --path="$DZN_PHASE_2A2J_WP_PATH" --user="$DZN_PHASE_2A2J_WP_USER" eval-file "$root/tests/phase-2a2j-concurrency-worker.php" >"$gate/w2.out" 2>&1 & p2=$!
wait_gate w2.started; wait_gate w2.connection
if [ "$DZN_PHASE_2A2J_MODE" = u1_unrelated_roots ] || [ "$DZN_PHASE_2A2J_MODE" = u2_shared_teacher ]; then wait_gate w2.finished; overlap=independent_completed_before_release; else wp "$root/tests/phase-2a2j-concurrency-wait.php" >"$gate/wait.out"; wait_gate w2.blocked; overlap=attributed_block_before_release; fi
: >"$gate/release"; wait "$p1"; wait "$p2"
! grep -Eiq 'deadlock|lock wait timeout|WordPress database error|mysqli_sql_exception|Duplicate entry' "$gate/w1.out" "$gate/w2.out"
case "$DZN_PHASE_2A2J_MODE" in
  a_same_teacher_keys) grep -q 'outcome=created' "$gate/w1.out"; grep -q 'outcome=already_applied' "$gate/w2.out";;
  c_replace_end|d1_replace_diff) grep -q 'outcome=created' "$gate/w1.out"; grep -q 'outcome=assignment_changed' "$gate/w2.out";;
  d2_replace_same) grep -q 'outcome=created' "$gate/w1.out"; grep -q 'outcome=already_applied' "$gate/w2.out";;
  e_initial_archive|e_replace_archive) grep -q 'outcome=created' "$gate/w1.out"; grep -q 'applicable Teacher Assignment exists' "$gate/w2.out";;
  e_archive_initial|e_archive_replace) grep -q 'outcome=archived' "$gate/w1.out"; grep -q 'outcome=teacher_not_current' "$gate/w2.out";;
  f_initial_close|f_replace_close) grep -q 'outcome=created' "$gate/w1.out"; grep -q 'outcome=closed' "$gate/w2.out";;
  f_close_initial|f_close_replace) grep -q 'outcome=closed' "$gate/w1.out"; grep -q 'outcome=enrolment_not_applicable' "$gate/w2.out";;
  o1_initial_offboard|o3_staff_replace_offboard|o5_authenticated_replace_offboard) grep -q 'outcome=created' "$gate/w1.out"; grep -q 'outcome=offboarded' "$gate/w2.out";;
  o2_offboard_initial|o4_offboard_staff_replace) grep -q 'outcome=offboarded' "$gate/w1.out"; grep -q 'outcome=created' "$gate/w2.out";;
  o6_offboard_authenticated_replace) grep -q 'outcome=offboarded' "$gate/w1.out"; grep -q 'outcome=Unauthorized' "$gate/w2.out";;
  u1_unrelated_roots) grep -q 'outcome=root_locked' "$gate/w1.out"; grep -q 'outcome=root_locked' "$gate/w2.out";;
  u2_shared_teacher) grep -q 'outcome=created' "$gate/w1.out"; grep -q 'outcome=created' "$gate/w2.out";;
esac
wp "$root/tests/phase-2a2j-concurrency-verify.php"
printf 'race=%s overlap=%s attributed_wait=%s holder="%s" contender="%s"\n' "$DZN_PHASE_2A2J_MODE" "$overlap" "$([ -f "$gate/w2.blocked" ] && tr '\n' ' ' <"$gate/w2.blocked" || printf 'not_applicable')" "$(tr '\n' ' ' <"$gate/w1.out")" "$(tr '\n' ' ' <"$gate/w2.out")"
rm -f "$gate"/*
