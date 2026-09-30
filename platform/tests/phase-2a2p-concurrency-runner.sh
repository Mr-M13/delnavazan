#!/bin/sh
# Deterministic Phase 2A.2-P attendance intake race runner.
set -eu
: "${DZN_PHASE_2A2P_WP_CLI:?}" "${DZN_PHASE_2A2P_WP_PATH:?}" "${DZN_PHASE_2A2P_WP_USER:?}" "${DZN_PHASE_2A2P_GATE_DIR:?}"
[ "${DZN_PHASE_2A2P_RUNTIME_TEST:-}" = concurrency ] || exit 1
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
gate=$DZN_PHASE_2A2P_GATE_DIR
modes='same_event_same_payload same_event_changed_payload claim_vs_adjudication adjudication_vs_adjudication command_key_cross_lesson command_key_exact command_key_changed_payload command_key_changed_event command_key_changed_account command_key_changed_interval command_key_changed_observed command_key_changed_provenance unrelated_lessons'
if [ -n "${DZN_PHASE_2A2P_MODE:-}" ]; then modes=$DZN_PHASE_2A2P_MODE; fi
wp(){ "$DZN_PHASE_2A2P_WP_CLI" --path="$DZN_PHASE_2A2P_WP_PATH" --user="$DZN_PHASE_2A2P_WP_USER" eval-file "$1"; }
worker(){ env "DZN_PHASE_2A2P_WORKER=$1" "$DZN_PHASE_2A2P_WP_CLI" --path="$DZN_PHASE_2A2P_WP_PATH" --user="$DZN_PHASE_2A2P_WP_USER" eval-file "$root/tests/phase-2a2p-concurrency-worker.php"; }
fixture(){ env DZN_PHASE_2A2J_RUNTIME_TEST=fixture "$DZN_PHASE_2A2P_WP_CLI" --path="$DZN_PHASE_2A2P_WP_PATH" --user="$DZN_PHASE_2A2P_WP_USER" eval-file "$root/tests/phase-2a2j-fixture.php"; }
waitfor(){ i=0; while [ ! -f "$gate/$1" ] && [ $i -lt 1200 ]; do i=$((i+1)); sleep .1; done; [ -f "$gate/$1" ]; }
oks(){ grep -c '"ok":true' "$1" 2>/dev/null || true; }
has(){ grep -q -- "$2" "$1"; }
rejected(){ has "$1" '"ok":false'; }

expect(){
  mode=$1
  case $mode in
    same_event_same_payload) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    same_event_changed_payload) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" 'Idempotency conflict' ;;
    # A claim that lands first makes a competing adjudication stale: exactly one canonical winner.
    claim_vs_adjudication) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" 'stale_case_version' ;;
    adjudication_vs_adjudication) { [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" 'stale_case_version'; } || { [ "$(oks "$gate/w2.result")" = 1 ] && rejected "$gate/w1.result" && has "$gate/w1.result" 'stale_case_version'; } ;;
    # Worker 1 is gated before it writes, so worker 2 wins: exactly one Lesson may hold the command.
    command_key_cross_lesson) [ "$(oks "$gate/w2.result")" = 1 ] && rejected "$gate/w1.result" && has "$gate/w1.result" 'Idempotency conflict' ;;
    command_key_exact) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    command_key_changed_*) [ "$(oks "$gate/w1.result")" = 1 ] && rejected "$gate/w2.result" && has "$gate/w2.result" 'Idempotency conflict' ;;
    unrelated_lessons) [ "$(oks "$gate/w1.result")" = 1 ] && [ "$(oks "$gate/w2.result")" = 1 ] ;;
    *) return 1 ;;
  esac
}
failures=0
for mode in $modes; do
  export DZN_PHASE_2A2P_MODE=$mode
  mkdir -p "$gate"; rm -f "$gate"/*
  if ! wp "$root/tests/phase-2a2p-concurrency-setup.php" >"$gate/setup.out" 2>&1; then
    if grep -q no_available_source "$gate/setup.out"; then
      fixture >>"$gate/setup.out" 2>&1
      wp "$root/tests/phase-2a2p-concurrency-setup.php" >>"$gate/setup.out" 2>&1 || { echo "race=$mode setup=FAIL"; cat "$gate/setup.out"; failures=$((failures+1)); continue; }
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
      if ! waitfor w2.result || [ ! -f "$gate/w1.locked" ] || [ -f "$gate/release" ]; then
        echo "race=$mode independence=FAIL unrelated Lesson did not proceed while the other root was gated"
        : >"$gate/release"; wait "$p1" 2>/dev/null || true; wait "$p2" 2>/dev/null || true; failures=$((failures+1)); continue
      fi ;;
    *)
      if ! wp "$root/tests/phase-2a2p-concurrency-wait.php" >"$gate/wait.out" 2>&1 || ! waitfor w2.blocked; then
        echo "race=$mode contention=FAIL contender was not observed as blocked"; cat "$gate/wait.out" 2>/dev/null
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
  if ! wp "$root/tests/phase-2a2p-concurrency-verify.php" >"$gate/verify.out" 2>&1; then
    echo "race=$mode verify=FAIL"; cat "$gate/verify.out"; failures=$((failures+1)); continue
  fi
  if ! expect "$mode"; then
    echo "race=$mode outcome=FAIL"; printf 'w1=%s\nw2=%s\n' "$(cat "$gate/w1.result")" "$(cat "$gate/w2.result")"; failures=$((failures+1)); continue
  fi
  printf 'race=%s w1=%s w2=%s verify=%s\n' "$mode" "$(tr -d '\n' <"$gate/w1.result")" "$(tr -d '\n' <"$gate/w2.result")" "$(tr -d '\n' <"$gate/verify.out")"
done
if [ "$failures" -ne 0 ]; then echo "phase-2a2p concurrency: $failures mode(s) failed"; exit 1; fi
echo "Phase 2A.2-P concurrency runner passed"
