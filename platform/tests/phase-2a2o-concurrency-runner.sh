#!/bin/sh
# Deterministic Phase 2A.2-O delivery/attendance race runner.
#
# Coordinates setup, gated worker start, controlled release, worker completion and final database
# verification; consumes and asserts every worker artefact and fails non-zero on any violated
# race invariant. Set DZN_PHASE_2A2O_MODE to certify a single mode.
set -eu
: "${DZN_PHASE_2A2O_WP_CLI:?}" "${DZN_PHASE_2A2O_WP_PATH:?}" "${DZN_PHASE_2A2O_WP_USER:?}" "${DZN_PHASE_2A2O_GATE_DIR:?}"
[ "${DZN_PHASE_2A2O_RUNTIME_TEST:-}" = concurrency ] || exit 1
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
gate=$DZN_PHASE_2A2O_GATE_DIR
modes='outcome_first complete_first outcome_cancel outcome_release outcome_revise outcome_close outcome_term_close correction_correction duplicate_assertion unrelated_lessons'
if [ -n "${DZN_PHASE_2A2O_MODE:-}" ]; then modes=$DZN_PHASE_2A2O_MODE; fi
wp(){ "$DZN_PHASE_2A2O_WP_CLI" --path="$DZN_PHASE_2A2O_WP_PATH" --user="$DZN_PHASE_2A2O_WP_USER" eval-file "$1"; }
worker(){ env "DZN_PHASE_2A2O_WORKER=$1" "$DZN_PHASE_2A2O_WP_CLI" --path="$DZN_PHASE_2A2O_WP_PATH" --user="$DZN_PHASE_2A2O_WP_USER" eval-file "$root/tests/phase-2a2o-concurrency-worker.php"; }
fixture(){ env DZN_PHASE_2A2J_RUNTIME_TEST=fixture "$DZN_PHASE_2A2O_WP_CLI" --path="$DZN_PHASE_2A2O_WP_PATH" --user="$DZN_PHASE_2A2O_WP_USER" eval-file "$root/tests/phase-2a2j-fixture.php"; }
waitfor(){ i=0; while [ ! -f "$gate/$1" ] && [ $i -lt 1200 ]; do i=$((i+1)); sleep .1; done; [ -f "$gate/$1" ]; }
oks(){ grep -c '"ok":true' "$1" 2>/dev/null || true; }
has(){ grep -q -- "$2" "$1"; }
hasAny(){ has "$1" "$2" || has "$1" "$3"; }
rejected(){ has "$1" '"ok":false'; }

# Every mode asserts the committed worker outcome artefacts, not only final row counts.
expect(){
  mode=$1
  case $mode in
    outcome_first) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" lesson_not_delivered ;;
    # The contender either observes the committed completion (stale expected state) or reaches the
    # O-D1/O-D8 guard itself (lesson_completed_reconciliation_required); both must fail closed.
    complete_first) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && hasAny "$gate/w2.result" lesson_completed_reconciliation_required stale_lesson_state ;;
    outcome_cancel|outcome_release) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    outcome_revise) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" delivery_outcome_exists ;;
    outcome_close) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" applicable_term_exists ;;
    outcome_term_close) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" authorised_canonical_lesson_exists ;;
    correction_correction)
      [ $(( $(oks "$gate/w1.result") + $(oks "$gate/w2.result") )) = 1 ] && { has "$gate/w1.result" stale_delivery_outcome || has "$gate/w2.result" stale_delivery_outcome; } ;;
    duplicate_assertion) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] && has "$gate/w2.result" '"idempotent":true' ;;
    unrelated_lessons) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    *) return 1 ;;
  esac
}
failures=0
for mode in $modes; do
  export DZN_PHASE_2A2O_MODE=$mode
  mkdir -p "$gate"; rm -f "$gate"/*
  if ! wp "$root/tests/phase-2a2o-concurrency-setup.php" >"$gate/setup.out" 2>&1; then
    if grep -q no_available_source "$gate/setup.out"; then
      fixture >>"$gate/setup.out" 2>&1
      wp "$root/tests/phase-2a2o-concurrency-setup.php" >>"$gate/setup.out" 2>&1 || { echo "race=$mode setup=FAIL"; cat "$gate/setup.out"; failures=$((failures+1)); continue; }
    else
      echo "race=$mode setup=FAIL"; cat "$gate/setup.out"; failures=$((failures+1)); continue
    fi
  fi
  worker w1 >"$gate/w1.out" 2>&1 & p1=$!
  if ! waitfor w1.locked; then
    echo "race=$mode gate=FAIL holder did not hold its locks"; kill "$p1" 2>/dev/null || true; failures=$((failures+1)); continue
  fi
  worker w2 >"$gate/w2.out" 2>&1 & p2=$!
  if ! waitfor w2.started; then
    echo "race=$mode contender=FAIL"; kill "$p1" "$p2" 2>/dev/null || true; failures=$((failures+1)); continue
  fi
  case $mode in
    unrelated_lessons)
      # Independence: worker 2 completes while worker 1 deliberately stays gated.
      if ! waitfor w2.result || [ ! -f "$gate/w1.locked" ] || [ -f "$gate/release" ]; then
        echo "race=$mode independence=FAIL unrelated Lesson did not proceed while the other root was gated"
        : >"$gate/release"; wait "$p1" 2>/dev/null || true; wait "$p2" 2>/dev/null || true; failures=$((failures+1)); continue
      fi ;;
    *)
      if ! wp "$root/tests/phase-2a2o-concurrency-wait.php" >"$gate/wait.out" 2>&1 || ! waitfor w2.blocked || [ -f "$gate/w2.result" ]; then
        echo "race=$mode contention=FAIL contender was not blocked before release"
        : >"$gate/release"; wait "$p1" 2>/dev/null || true; wait "$p2" 2>/dev/null || true; failures=$((failures+1)); continue
      fi ;;
  esac
  : >"$gate/release"
  wait "$p1" 2>/dev/null || true
  wait "$p2" 2>/dev/null || true
  if grep -Eiq 'deadlock|lock wait timeout|WordPress database error' "$gate/w1.out" "$gate/w2.out"; then
    echo "race=$mode stability=FAIL database contention error"; failures=$((failures+1)); continue
  fi
  [ -f "$gate/w1.result" ] && [ -f "$gate/w2.result" ] || { echo "race=$mode artefacts=FAIL"; failures=$((failures+1)); continue; }
  if ! wp "$root/tests/phase-2a2o-concurrency-verify.php" >"$gate/verify.out" 2>&1; then
    echo "race=$mode verify=FAIL"; cat "$gate/verify.out"; failures=$((failures+1)); continue
  fi
  if ! expect "$mode"; then
    echo "race=$mode outcome=FAIL"; printf 'w1=%s\nw2=%s\n' "$(cat "$gate/w1.result")" "$(cat "$gate/w2.result")"; failures=$((failures+1)); continue
  fi
  blocked=none
  if [ -f "$gate/w2.blocked" ]; then blocked=$(tr -d '\n' <"$gate/w2.blocked"); fi
  printf 'race=%s w1=%s w2=%s attribution=%s verify=%s\n' "$mode" "$(tr -d '\n' <"$gate/w1.result")" "$(tr -d '\n' <"$gate/w2.result")" "$blocked" "$(tr -d '\n' <"$gate/verify.out")"
done
if [ "$failures" -ne 0 ]; then echo "phase-2a2o concurrency: $failures mode(s) failed"; exit 1; fi
echo "Phase 2A.2-O concurrency runner passed"
