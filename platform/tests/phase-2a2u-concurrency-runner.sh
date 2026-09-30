#!/bin/sh
# Deterministic Phase 2A.2-U finance race runner.
#
# Two disposable WP-CLI workers race one declared Finance operation in a gated order; the verifier asserts
# the contract's invariant for that mode (one snapshot, one applicable evaluation, one live rate row, one
# live statement, one coherent policy state, one recorded transition, or two independent runs/teachers).
set -eu
: "${DZN_PHASE_2A2U_REPO:?}" "${DZN_PHASE_2A2U_WP_DIR:?}" "${DZN_PHASE_2A2U_NET:?}"
mode=${1:?usage: phase-2a2u-concurrency-runner.sh MODE}
repo=$DZN_PHASE_2A2U_REPO
wpdir=$DZN_PHASE_2A2U_WP_DIR
net=$DZN_PHASE_2A2U_NET
case $mode in
  duplicate_snapshot_capture|rate_close_vs_rate_record|rate_change_vs_snapshot_capture|\
  successor_record_vs_delayed_historical_capture|payability_override_vs_evaluation|\
  concurrent_statement_draft|concurrent_statement_issue|concurrent_policy_record|\
  statement_issue_vs_snapshot_correction|concurrent_reconciliation_run|duplicate_command_replay|\
  unrelated_teachers|policy_change_vs_statement_draft|policy_record_vs_snapshot_capture|\
  concurrent_exception_resolution|concurrent_exception_resolution_teacherless|\
  refusal_evidence_convergence|period_wide_run_vs_draft) ;;
  *) echo "Unknown Phase U concurrency mode: $mode" >&2; exit 2;;
esac
# The gate directory must be visible inside the worker containers at the same absolute path.
gate=$(mktemp -d "$repo/.dzn-2a2u-gate.XXXXXX")
cleanup(){ case "$gate" in "$repo"/.dzn-2a2u-gate.*) rm -rf "$gate";; esac; }
trap cleanup EXIT INT TERM
wprun(){
  gateArg=$1; workerArg=$2; file=$3
  set -- -e "DZN_PHASE_2A2U_RUNTIME_TEST=concurrency" -e "DZN_PHASE_2A2U_MODE=$mode"
  [ "$workerArg" != "-" ] && set -- "$@" -e "DZN_PHASE_2A2U_WORKER=$workerArg"
  [ "$gateArg" != "-" ] && set -- "$@" -e "DZN_PHASE_2A2U_GATE_DIR=$gateArg"
  docker run --rm --network "$net" -u 0 -v "$wpdir:/var/www/html" -v "$repo:$repo" "$@" \
    wordpress:cli-php8.3 wp eval-file "$file" --path=/var/www/html --user=1 --allow-root
}
waitfor(){
  i=0
  while [ ! -f "$1" ] && [ "$i" -lt 1200 ]; do i=$((i+1)); sleep 0.1; done
  [ -f "$1" ]
}
wprun - - "$repo/tests/phase-2a2u-concurrency-setup.php" >"$gate/setup.out" 2>&1
wprun "$gate" w1 "$repo/tests/phase-2a2u-concurrency-worker.php" >"$gate/w1.out" 2>&1 &
w1=$!
waitfor "$gate/w1.started" || { echo "first worker never gated"; cat "$gate/w1.out"; exit 1; }
wprun "$gate" w2 "$repo/tests/phase-2a2u-concurrency-worker.php" >"$gate/w2.out" 2>&1 &
w2=$!
case $mode in
  unrelated_teachers|concurrent_reconciliation_run|period_wide_run_vs_draft|concurrent_exception_resolution_teacherless)
    # The contender owns a different Teacher, period or teacher-less scope, so it must complete while the
    # holder's transaction is still open; only then is the holder released.
    if waitfor "$gate/w2.finished"; then : >"$gate/w2.independent"; fi
    : >"$gate/release"
    ;;
  *)
    # Both must be in flight together so neither holds a lock before the other contends, or the contender
    # serialises on the same root; either way the holder is released after the sibling has started.
    waitfor "$gate/w2.started" || { echo "contender never started"; cat "$gate/w2.out"; exit 1; }
    sleep 1
    : >"$gate/release"
    ;;
esac
wait "$w1" || true
wait "$w2" || true
wprun "$gate" - "$repo/tests/phase-2a2u-concurrency-verify.php"
