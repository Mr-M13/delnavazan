#!/bin/sh
# Deterministic Phase 2A.2-N scheduling/capacity race runner.
#
# Coordinates setup, gated worker start, controlled release, worker completion and final database
# verification; consumes and asserts every worker artefact and fails non-zero on any violated
# race invariant. Set DZN_PHASE_2A2N_MODE to certify a single mode.
set -eu
: "${DZN_PHASE_2A2N_WP_CLI:?}" "${DZN_PHASE_2A2N_WP_PATH:?}" "${DZN_PHASE_2A2N_WP_USER:?}" "${DZN_PHASE_2A2N_GATE_DIR:?}"
[ "${DZN_PHASE_2A2N_RUNTIME_TEST:-}" = concurrency ] || exit 1
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
gate=$DZN_PHASE_2A2N_GATE_DIR
modes='capacity_first capacity_prepared same_key different_keys revise_stale revise_release buffer_adjacency unrelated_teachers availability_race override_race schedule_pause pause_schedule schedule_close close_schedule schedule_term_close term_close_schedule schedule_term_cancel term_cancel_schedule schedule_complete complete_schedule schedule_cancel cancel_schedule schedule_replace replace_schedule schedule_archive archive_schedule'
if [ -n "${DZN_PHASE_2A2N_MODE:-}" ]; then modes=$DZN_PHASE_2A2N_MODE; fi
wp(){ "$DZN_PHASE_2A2N_WP_CLI" --path="$DZN_PHASE_2A2N_WP_PATH" --user="$DZN_PHASE_2A2N_WP_USER" eval-file "$1"; }
worker(){ env "DZN_PHASE_2A2N_WORKER=$1" "$DZN_PHASE_2A2N_WP_CLI" --path="$DZN_PHASE_2A2N_WP_PATH" --user="$DZN_PHASE_2A2N_WP_USER" eval-file "$root/tests/phase-2a2n-concurrency-worker.php"; }
fixture(){ env DZN_PHASE_2A2J_RUNTIME_TEST=fixture "$DZN_PHASE_2A2N_WP_CLI" --path="$DZN_PHASE_2A2N_WP_PATH" --user="$DZN_PHASE_2A2N_WP_USER" eval-file "$root/tests/phase-2a2j-fixture.php"; }
waitfor(){ i=0; while [ ! -f "$gate/$1" ] && [ $i -lt 1200 ]; do i=$((i+1)); sleep .1; done; [ -f "$gate/$1" ]; }
oks(){ grep -c '"ok":true' "$1" 2>/dev/null || true; }
has(){ grep -q -- "$2" "$1"; }
hasAny(){ has "$1" "$2" || has "$1" "$3"; }
rejected(){ has "$1" '"ok":false'; }

# Every mode asserts the committed worker outcome artefacts, not only final row counts.
expect(){
  mode=$1
  case $mode in
    capacity_first|capacity_prepared)
      [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" teacher_slot_conflict ;;
    same_key) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] && has "$gate/w2.result" '"idempotent":true' ;;
    buffer_adjacency|unrelated_teachers) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    different_keys) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" schedule_already_exists ;;
    revise_stale|revise_release) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" stale_schedule_version ;;
    schedule_pause) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    pause_schedule) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" enrolment_not_schedulable ;;
    schedule_close) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" applicable_term_exists ;;
    close_schedule) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" lesson_not_schedulable ;;
    schedule_term_close|schedule_term_cancel) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" authorised_canonical_lesson_exists ;;
    term_close_schedule|term_cancel_schedule)
      # A Term holding an authorised canonical Lesson cannot be terminalised at all, so the
      # pre-existing L guard rejects the holder while the contended scheduling proceeds.
      [ "$(oks "$gate/w1.result")" = 0 ] && rejected "$gate/w1.result" && has "$gate/w1.result" authorised_canonical_lesson_exists && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    schedule_complete|schedule_cancel) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" active_future_schedule_exists ;;
    complete_schedule|cancel_schedule) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" lesson_not_schedulable ;;
    schedule_replace) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" active_future_schedule_exists ;;
    replace_schedule) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" stale_teacher_assignment ;;
    archive_schedule) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && hasAny "$gate/w2.result" teacher_not_available stale_teacher_assignment ;;
    schedule_archive) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" ;;
    availability_race) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" teacher_unavailable ;;
    override_race) [ "$(oks "$gate/w1.result")" = 0 ] && rejected "$gate/w1.result" && has "$gate/w1.result" teacher_unavailable && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    *) return 1 ;;
  esac
}
failures=0
for mode in $modes; do
  export DZN_PHASE_2A2N_MODE=$mode
  mkdir -p "$gate"; rm -f "$gate"/*
  if ! wp "$root/tests/phase-2a2n-concurrency-setup.php" >"$gate/setup.out" 2>&1; then
    if grep -q no_available_source "$gate/setup.out"; then
      fixture >>"$gate/setup.out" 2>&1
      wp "$root/tests/phase-2a2n-concurrency-setup.php" >>"$gate/setup.out" 2>&1 || { echo "race=$mode setup=FAIL"; failures=$((failures+1)); continue; }
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
    unrelated_teachers)
      # Independence: worker 2 completes while worker 1 deliberately stays gated.
      if ! waitfor w2.result || [ ! -f "$gate/w1.locked" ] || [ -f "$gate/release" ]; then
        echo "race=$mode independence=FAIL worker 2 did not complete while worker 1 remained gated"
        : >"$gate/release"; wait "$p1" 2>/dev/null || true; wait "$p2" 2>/dev/null || true; failures=$((failures+1)); continue
      fi ;;
    *)
      if ! wp "$root/tests/phase-2a2n-concurrency-wait.php" >"$gate/wait.out" 2>&1 || ! waitfor w2.blocked || [ -f "$gate/w2.result" ]; then
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
  if ! wp "$root/tests/phase-2a2n-concurrency-verify.php" >"$gate/verify.out" 2>&1; then
    echo "race=$mode verify=FAIL"; cat "$gate/verify.out"; failures=$((failures+1)); continue
  fi
  if ! expect "$mode"; then
    echo "race=$mode outcome=FAIL"; printf 'w1=%s\nw2=%s\n' "$(cat "$gate/w1.result")" "$(cat "$gate/w2.result")"; failures=$((failures+1)); continue
  fi
  blocked=none
  if [ -f "$gate/w2.blocked" ]; then blocked=$(tr -d '\n' <"$gate/w2.blocked"); fi
  printf 'race=%s w1=%s w2=%s attribution=%s verify=%s\n' "$mode" "$(tr -d '\n' <"$gate/w1.result")" "$(tr -d '\n' <"$gate/w2.result")" "$blocked" "$(tr -d '\n' <"$gate/verify.out")"
done
if [ "$failures" -ne 0 ]; then echo "phase-2a2n concurrency: $failures mode(s) failed"; exit 1; fi
echo "Phase 2A.2-N concurrency runner passed"
