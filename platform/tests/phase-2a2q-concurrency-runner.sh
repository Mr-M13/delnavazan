#!/bin/sh
# Deterministic Phase 2A.2-Q continuation/slot-reservation race runner.
set -eu
: "${DZN_PHASE_2A2Q_WP_CLI:?}" "${DZN_PHASE_2A2Q_WP_PATH:?}" "${DZN_PHASE_2A2Q_WP_USER:?}" "${DZN_PHASE_2A2Q_GATE_DIR:?}"
[ "${DZN_PHASE_2A2Q_RUNTIME_TEST:-}" = concurrency ] || exit 1
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
gate=$DZN_PHASE_2A2Q_GATE_DIR
modes='continue_exact_replay command_key_changed_decision two_student_decisions continue_vs_teacher_exception competing_hold_same_slot unrelated_teachers hold_vs_lesson_schedule_q_first hold_vs_lesson_schedule_n_first expiry_vs_new_claim principal_decision_first principal_revocation_first guardian_decision_first guardian_revocation_first slot_vs_lesson_schedule slot_vs_teacher_unsuitable slot_vs_terminal_decision'
if [ -n "${DZN_PHASE_2A2Q_MODE:-}" ]; then modes=$DZN_PHASE_2A2Q_MODE; fi
wp(){ "$DZN_PHASE_2A2Q_WP_CLI" --path="$DZN_PHASE_2A2Q_WP_PATH" --user="$DZN_PHASE_2A2Q_WP_USER" eval-file "$1"; }
worker(){ env "DZN_PHASE_2A2Q_WORKER=$1" "$DZN_PHASE_2A2Q_WP_CLI" --path="$DZN_PHASE_2A2Q_WP_PATH" --user="$DZN_PHASE_2A2Q_WP_USER" eval-file "$root/tests/phase-2a2q-concurrency-worker.php"; }
fixture(){ env DZN_PHASE_2A2J_RUNTIME_TEST=fixture "$DZN_PHASE_2A2Q_WP_CLI" --path="$DZN_PHASE_2A2Q_WP_PATH" --user="$DZN_PHASE_2A2Q_WP_USER" eval-file "$root/tests/phase-2a2j-fixture.php"; }
waitfor(){ i=0; while [ ! -f "$gate/$1" ] && [ $i -lt 1200 ]; do i=$((i+1)); sleep .1; done; [ -f "$gate/$1" ]; }
oks(){ grep -c '"ok":true' "$1" 2>/dev/null || true; }
has(){ grep -q -- "$2" "$1"; }
rejected(){ has "$1" '"ok":false'; }

expect(){
  mode=$1
  case $mode in
    continue_exact_replay) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    command_key_changed_decision) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" 'Idempotency conflict' ;;
    two_student_decisions) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    continue_vs_teacher_exception) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    competing_hold_same_slot) [ $(( $(oks "$gate/w1.result") + $(oks "$gate/w2.result") )) -eq 1 ] && { rejected "$gate/w1.result" || rejected "$gate/w2.result"; } ;;
    unrelated_teachers) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    hold_vs_lesson_schedule_q_first) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" 'teacher_slot_conflict' ;;
    hold_vs_lesson_schedule_n_first) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" 'teacher_slot_conflict' ;;
    expiry_vs_new_claim) rejected "$gate/w1.result" && has "$gate/w1.result" 'teacher_slot_conflict' && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    principal_decision_first) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    principal_revocation_first) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" 'Unauthorized' ;;
    guardian_decision_first) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    guardian_revocation_first) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" 'Unauthorized' ;;
    # Worker 1 is gated while holding the Teacher scheduling root, so the delayed slot converges first
    # and the conflicting Phase-N schedule loses; the verifier separately accepts either winner.
    slot_vs_lesson_schedule) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" 'teacher_slot_conflict' ;;
    slot_vs_teacher_unsuitable) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    slot_vs_terminal_decision) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    *) return 1 ;;
  esac
}
failures=0
for mode in $modes; do
  export DZN_PHASE_2A2Q_MODE=$mode
  mkdir -p "$gate"; rm -f "$gate"/*
  # Each race starts from a freshly prepared production-authoritative fixture so the matrix is
  # deterministic and independent of the order in which modes run.
  fixture >"$gate/fixture.out" 2>&1 || { echo "race=$mode fixture=FAIL"; tail -3 "$gate/fixture.out"; failures=$((failures+1)); continue; }
  if ! wp "$root/tests/phase-2a2q-concurrency-setup.php" >"$gate/setup.out" 2>&1; then
    echo "race=$mode setup=FAIL"; cat "$gate/setup.out"; failures=$((failures+1)); continue
  fi
  if [ "$mode" = expiry_vs_new_claim ]; then
    # A genuinely time-crossing claim: the first attempt must lose to the still-effective hold, the
    # second must succeed once the frozen expiry has passed.
    worker w1 >"$gate/w1.out" 2>&1 || true
    sleep 3
    worker w2 >"$gate/w2.out" 2>&1 || true
    if wp "$root/tests/phase-2a2q-concurrency-verify.php" >"$gate/verify.out" 2>&1 && expect "$mode"; then
      printf 'race=%s outcome=pass verify=%s\n' "$mode" "$(tr -d '\n' <"$gate/verify.out")"
    else
      echo "race=$mode outcome=FAIL"; printf 'w1=%s\nw2=%s\n' "$(cat "$gate/w1.result")" "$(cat "$gate/w2.result")"; failures=$((failures+1))
    fi
    continue
  fi
  worker w1 >"$gate/w1.out" 2>&1 & p1=$!
  if ! waitfor w1.locked; then
    echo "race=$mode gate=FAIL holder did not hold its locks"; kill "$p1" 2>/dev/null || true; failures=$((failures+1)); continue
  fi
  worker w2 >"$gate/w2.out" 2>&1 & p2=$!
  if ! waitfor w2.started; then
    echo "race=$mode contender=FAIL"; kill "$p1" "$p2" 2>/dev/null || true; failures=$((failures+1)); continue
  fi
  if ! wp "$root/tests/phase-2a2q-concurrency-wait.php" >"$gate/wait.out" 2>&1 || ! waitfor w2.blocked; then
    echo "race=$mode contention=FAIL contender was not observed as blocked"; cat "$gate/wait.out" 2>/dev/null
    : >"$gate/release"; wait "$p1" 2>/dev/null || true; wait "$p2" 2>/dev/null || true; failures=$((failures+1)); continue
  fi
  : >"$gate/release"
  wait "$p1" 2>/dev/null || true
  wait "$p2" 2>/dev/null || true
  if grep -Eiq 'deadlock|lock wait timeout|WordPress database error' "$gate/w1.out" "$gate/w2.out"; then
    echo "race=$mode stability=FAIL database contention error"; failures=$((failures+1)); continue
  fi
  [ -f "$gate/w1.result" ] && [ -f "$gate/w2.result" ] || { echo "race=$mode artefacts=FAIL"; failures=$((failures+1)); continue; }
  if ! wp "$root/tests/phase-2a2q-concurrency-verify.php" >"$gate/verify.out" 2>&1; then
    echo "race=$mode verify=FAIL"; tail -3 "$gate/verify.out"; failures=$((failures+1)); continue
  fi
  if ! expect "$mode"; then
    echo "race=$mode outcome=FAIL"; printf 'w1=%s\nw2=%s\n' "$(cat "$gate/w1.result")" "$(cat "$gate/w2.result")"; failures=$((failures+1)); continue
  fi
  printf 'race=%s outcome=pass verify=%s\n' "$mode" "$(tr -d '\n' <"$gate/verify.out")"
done
if [ "$failures" -ne 0 ]; then echo "phase-2a2q concurrency: $failures mode(s) failed"; exit 1; fi
echo "Phase 2A.2-Q concurrency runner passed"
