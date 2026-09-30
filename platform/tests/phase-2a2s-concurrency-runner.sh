#!/bin/sh
# Deterministic Phase 2A.2-S notification race runner.
#
# The gate directory must be visible to the worker containers at the same absolute path, so it lives inside
# the mounted candidate repository and is removed again when the run finishes.
set -eu
: "${DZN_PHASE_2A2S_REPO:?}" "${DZN_PHASE_2A2S_WP_DIR:?}" "${DZN_PHASE_2A2S_NET:?}"
mode=${1:?usage: phase-2a2s-concurrency-runner.sh MODE}
repo=$DZN_PHASE_2A2S_REPO
wpdir=$DZN_PHASE_2A2S_WP_DIR
net=$DZN_PHASE_2A2S_NET
# The complete §15 matrix, in the contract's own order: all fourteen modes run through the same four files,
# and the static contract suite asserts this exact list against the contract text.
MODES='dispatch_vs_retry lease_expiry_vs_handoff retry_exhaustion_vs_recovery subject_transition_after_enqueue_vs_dispatch policy_change_after_publication_vs_dispatch deferral_vs_claim activation_vs_dispatch competing_activation_same_intent rule_attach_vs_activation suppress_vs_enqueue cancel_vs_dispatch delivery_vs_attempt_close erase_vs_dispatch unrelated_notifications'
case " $MODES " in
  *" $mode "*) ;;
  *) echo "unsupported Phase 2A.2-S concurrency mode: $mode" >&2; exit 2 ;;
esac
gate=$(mktemp -d "$repo/.dzn-2a2s-gate.XXXXXX")
cleanup(){ case "$gate" in "$repo"/.dzn-2a2s-gate.*) rm -rf "$gate";; esac; }
trap cleanup EXIT INT TERM
wprun(){
  gateArg=$1; workerArg=$2; file=$3
  set -- -e "DZN_PHASE_2A2S_RUNTIME_TEST=concurrency" -e "DZN_PHASE_2A2S_MODE=$mode"
  [ "$workerArg" != "-" ] && set -- "$@" -e "DZN_PHASE_2A2S_WORKER=$workerArg"
  [ "$gateArg" != "-" ] && set -- "$@" -e "DZN_PHASE_2A2S_GATE_DIR=$gateArg"
  docker run --rm --network "$net" -u 0 -v "$wpdir:/var/www/html" -v "$repo:$repo" "$@" \
    wordpress:cli-php8.3 wp eval-file "$file" --path=/var/www/html --user=1 --allow-root
}
waitfor(){ i=0; while [ ! -f "$1" ] && [ "$i" -lt 1200 ]; do i=$((i+1)); sleep 0.1; done; [ -f "$1" ]; }
wprun - - "$repo/tests/phase-2a2s-concurrency-setup.php" >"$gate/setup.out" 2>&1 || { cat "$gate/setup.out"; exit 1; }
wprun "$gate" w1 "$repo/tests/phase-2a2s-concurrency-worker.php" >"$gate/w1.out" 2>&1 &
w1=$!
waitfor "$gate/w1.started" || { echo "holder worker never gated"; cat "$gate/w1.out"; exit 1; }
wprun "$gate" w2 "$repo/tests/phase-2a2s-concurrency-worker.php" >"$gate/w2.out" 2>&1 &
w2=$!
waitfor "$gate/w2.started" || true
if [ "$mode" = "unrelated_notifications" ]; then
  # The contender owns a different serialisation root, so it must complete while the holder is still gated;
  # only then is the holder released.
  waitfor "$gate/w2.finished" || true
else
  sleep 1
fi
: >"$gate/release"
wait "$w1" || true
wait "$w2" || true
wprun "$gate" - "$repo/tests/phase-2a2s-concurrency-verify.php"
