#!/bin/sh
# Deterministic Phase 2A.2-R1 commercial capacity/evidence race runner.
set -eu
: "${DZN_PHASE_2A2R1_REPO:?}" "${DZN_PHASE_2A2R1_WP_DIR:?}" "${DZN_PHASE_2A2R1_NET:?}"
mode=${1:?usage: phase-2a2r1-concurrency-runner.sh MODE}
repo=$DZN_PHASE_2A2R1_REPO
wpdir=$DZN_PHASE_2A2R1_WP_DIR
net=$DZN_PHASE_2A2R1_NET
# The gate directory must be visible to the worker containers at the same absolute path, so it
# lives inside the mounted candidate repository and is removed again when the run finishes.
gate=$(mktemp -d "$repo/.dzn-2a2r1-gate.XXXXXX")
cleanup(){ case "$gate" in "$repo"/.dzn-2a2r1-gate.*) rm -rf "$gate";; esac; }
trap cleanup EXIT INT TERM
wprun(){
  gateArg=$1; workerArg=$2; file=$3
  set -- -e "DZN_PHASE_2A2R1_RUNTIME_TEST=concurrency" -e "DZN_PHASE_2A2R1_MODE=$mode"
  [ "$workerArg" != "-" ] && set -- "$@" -e "DZN_PHASE_2A2R1_WORKER=$workerArg"
  [ "$gateArg" != "-" ] && set -- "$@" -e "DZN_PHASE_2A2R1_GATE_DIR=$gateArg"
  docker run --rm --network "$net" -u 0 -v "$wpdir:/var/www/html" -v "$repo:$repo" "$@" \
    wordpress:cli-php8.3 wp eval-file "$file" --path=/var/www/html --user=1 --allow-root
}
waitfor(){
  i=0
  while [ ! -f "$1" ] && [ "$i" -lt 900 ]; do i=$((i+1)); sleep 0.1; done
  [ -f "$1" ]
}
wprun - - "$repo/tests/phase-2a2r1-concurrency-setup.php" >"$gate/setup.out" 2>&1
wprun "$gate" w1 "$repo/tests/phase-2a2r1-concurrency-worker.php" >"$gate/w1.out" 2>&1 &
w1=$!
waitfor "$gate/w1.started" || { echo "holder worker never gated"; cat "$gate/w1.out"; exit 1; }
wprun "$gate" w2 "$repo/tests/phase-2a2r1-concurrency-worker.php" >"$gate/w2.out" 2>&1 &
w2=$!
case $mode in
  settlement_vs_lesson_seven)
    # The contender cannot block on the holder's locks here, so its action must complete while the
    # holder's settlement is still uncommitted; only then is the holder released.
    waitfor "$gate/w2.finished" || true
    : >"$gate/release"
    ;;
  *)
    sleep 1
    : >"$gate/release"
    ;;
esac
wait "$w1" || true
wait "$w2" || true
wprun "$gate" - "$repo/tests/phase-2a2r1-concurrency-verify.php"
