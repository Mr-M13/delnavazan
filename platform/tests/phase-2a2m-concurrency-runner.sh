#!/bin/sh
# Deterministic Phase 2A.2-M process-level race runner.
#
# Coordinates setup, gated worker start, controlled release, worker completion and final
# database verification; consumes and asserts every worker artefact and fails non-zero when an
# expected race invariant is violated. Set DZN_PHASE_2A2M_MODE to certify a single mode.
set -eu
: "${DZN_PHASE_2A2M_WP_CLI:?}" "${DZN_PHASE_2A2M_WP_PATH:?}" "${DZN_PHASE_2A2M_WP_USER:?}" "${DZN_PHASE_2A2M_GATE_DIR:?}"
[ "${DZN_PHASE_2A2M_RUNTIME_TEST:-}" = concurrency ] || exit 1
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
gate=$DZN_PHASE_2A2M_GATE_DIR
modes='same_key standard_final replacement_same replacement_origin_same replacement_final lesson_pause pause_lesson lesson_close close_lesson lesson_term_close term_close_lesson lesson_term_cancel term_cancel_lesson lesson_replace replace_lesson unrelated'
if [ -n "${DZN_PHASE_2A2M_MODE:-}" ]; then modes=$DZN_PHASE_2A2M_MODE; fi

wp(){ "$DZN_PHASE_2A2M_WP_CLI" --path="$DZN_PHASE_2A2M_WP_PATH" --user="$DZN_PHASE_2A2M_WP_USER" eval-file "$1"; }
worker(){ env "DZN_PHASE_2A2M_WORKER=$1" "$DZN_PHASE_2A2M_WP_CLI" --path="$DZN_PHASE_2A2M_WP_PATH" --user="$DZN_PHASE_2A2M_WP_USER" eval-file "$root/tests/phase-2a2m-concurrency-worker.php"; }
fixture(){ env DZN_PHASE_2A2J_RUNTIME_TEST=fixture "$DZN_PHASE_2A2M_WP_CLI" --path="$DZN_PHASE_2A2M_WP_PATH" --user="$DZN_PHASE_2A2M_WP_USER" eval-file "$root/tests/phase-2a2j-fixture.php"; }
waitfor(){ i=0; while [ ! -f "$gate/$1" ] && [ $i -lt 1200 ]; do i=$((i+1)); sleep .1; done; [ -f "$gate/$1" ]; }
oks(){ grep -c '"ok":true' "$1" 2>/dev/null || true; }
has(){ grep -q -- "$2" "$1"; }
rejected(){ has "$1" '"ok":false'; }

# Every mode asserts the committed worker outcome artefacts, not just the final row counts.
expect(){
  mode=$1
  case $mode in
    same_key) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] && has "$gate/w2.result" '"idempotent":true' ;;
    standard_final)
      [ $(( $(oks "$gate/w1.result") + $(oks "$gate/w2.result") )) = 1 ] &&
      { has "$gate/w1.result" standard_allocation_exhausted || has "$gate/w2.result" standard_allocation_exhausted; } ;;
    replacement_same) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] && has "$gate/w2.result" '"idempotent":true' ;;
    replacement_origin_same|replacement_diff)
      [ $(( $(oks "$gate/w1.result") + $(oks "$gate/w2.result") )) = 1 ] &&
      { has "$gate/w1.result" replacement_origin_already_claimed || has "$gate/w2.result" replacement_origin_already_claimed; } ;;
    replacement_final)
      [ $(( $(oks "$gate/w1.result") + $(oks "$gate/w2.result") )) = 1 ] &&
      { has "$gate/w1.result" replacement_allocation_exhausted || has "$gate/w2.result" replacement_allocation_exhausted; } ;;
    lesson_pause) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    pause_lesson) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" enrolment_not_current ;;
    # A current Term is itself a closure blocker in this ordering, so closure is refused by the
    # applicable-Term guard before the stranded canonical Lesson guard is reached.
    lesson_close) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" &&
      { has "$gate/w2.result" applicable_term_exists || has "$gate/w2.result" authorised_canonical_lesson_exists; } ;;
    close_lesson) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" enrolment_not_current ;;
    lesson_term_close|lesson_term_cancel) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" authorised_canonical_lesson_exists ;;
    term_close_lesson|term_cancel_lesson) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" term_not_current ;;
    lesson_replace) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    replace_lesson) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" stale_teacher_assignment ;;
    unrelated) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    *) return 1 ;;
  esac
}

failures=0
for mode in $modes; do
  export DZN_PHASE_2A2M_MODE=$mode
  mkdir -p "$gate"; rm -f "$gate"/*
  if ! wp "$root/tests/phase-2a2m-concurrency-setup.php" >"$gate/setup.out" 2>&1; then
    if grep -q no_available_source "$gate/setup.out"; then
      fixture >>"$gate/setup.out" 2>&1
      wp "$root/tests/phase-2a2m-concurrency-setup.php" >>"$gate/setup.out" 2>&1 || { echo "race=$mode setup=FAIL"; failures=$((failures+1)); continue; }
    else
      echo "race=$mode setup=FAIL"; failures=$((failures+1)); continue
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
  if [ "$mode" = unrelated ]; then
    # Independence: worker 2 completes while worker 1 deliberately stays gated.
    if ! waitfor w2.result || [ ! -f "$gate/w1.locked" ] || [ -f "$gate/release" ]; then
      echo "race=$mode independence=FAIL worker 2 did not complete while worker 1 remained gated"
      : >"$gate/release"; wait "$p1" 2>/dev/null || true; wait "$p2" 2>/dev/null || true; failures=$((failures+1)); continue
    fi
  else
    # A failed attribution run still releases the gate so no holder is left waiting.
    if ! wp "$root/tests/phase-2a2m-concurrency-wait.php" >"$gate/wait.out" 2>&1 || ! waitfor w2.blocked || [ -f "$gate/w2.result" ]; then
      echo "race=$mode contention=FAIL contender was not blocked before release"
      : >"$gate/release"; wait "$p1" 2>/dev/null || true; wait "$p2" 2>/dev/null || true; failures=$((failures+1)); continue
    fi
  fi
  : >"$gate/release"
  wait "$p1" 2>/dev/null || true
  wait "$p2" 2>/dev/null || true
  if grep -Eiq 'deadlock|lock wait timeout|WordPress database error' "$gate/w1.out" "$gate/w2.out"; then
    echo "race=$mode stability=FAIL database contention error"; failures=$((failures+1)); continue
  fi
  [ -f "$gate/w1.result" ] && [ -f "$gate/w2.result" ] || { echo "race=$mode artefacts=FAIL"; failures=$((failures+1)); continue; }
  if ! wp "$root/tests/phase-2a2m-concurrency-verify.php" >"$gate/verify.out" 2>&1; then
    echo "race=$mode verify=FAIL"; cat "$gate/verify.out"; failures=$((failures+1)); continue
  fi
  if ! expect "$mode"; then
    echo "race=$mode outcome=FAIL"; printf 'w1=%s\nw2=%s\n' "$(cat "$gate/w1.result")" "$(cat "$gate/w2.result")"; failures=$((failures+1)); continue
  fi
  blocked=none
  if [ -f "$gate/w2.blocked" ]; then blocked=$(tr -d '\n' <"$gate/w2.blocked"); fi
  printf 'race=%s holder=%s contender=%s attribution=%s verify=%s\n' "$mode" "$(tr -d '\n' <"$gate/w1.result")" "$(tr -d '\n' <"$gate/w2.result")" "$blocked" "$(tr -d '\n' <"$gate/verify.out")"
done
if [ "$failures" -ne 0 ]; then echo "phase-2a2m concurrency: $failures mode(s) failed"; exit 1; fi
echo "Phase 2A.2-M concurrency runner passed"
