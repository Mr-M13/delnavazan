#!/bin/sh
# Deterministic Phase 2A.2-V provider-integration race runner.
set -eu
: "${DZN_PHASE_2A2V_REPO:?}" "${DZN_PHASE_2A2V_WP_DIR:?}" "${DZN_PHASE_2A2V_NET:?}"
mode=${1:?usage: phase-2a2v-concurrency-runner.sh MODE}
repo=$DZN_PHASE_2A2V_REPO
wpdir=$DZN_PHASE_2A2V_WP_DIR
net=$DZN_PHASE_2A2V_NET
case $mode in
  connect_vs_revoke|authorization_replay|projection_vs_release|projection_vs_completion|duplicate_vs_conflicting_event|cross_lesson_event_key_race|provider_event_sequence_race|ingest_vs_canonical_authority|mapping_revoke_vs_ingest|teacher_archival_vs_connection|unrelated_teacher) ;;
  *) echo "Unknown Phase V concurrency mode: $mode" >&2; exit 2;;
esac
# The gate directory must be visible to the worker containers at the same absolute path, so it lives
# inside the mounted candidate repository and is removed again when the run finishes.
gate=$(mktemp -d "$repo/.dzn-2a2v-gate.XXXXXX")
cleanup(){ case "$gate" in "$repo"/.dzn-2a2v-gate.*) rm -rf "$gate";; esac; }
trap cleanup EXIT INT TERM
wprun(){
  gateArg=$1; workerArg=$2; file=$3
  set -- -e "DZN_PHASE_2A2V_RUNTIME_TEST=concurrency" -e "DZN_PHASE_2A2V_MODE=$mode"
  [ "$workerArg" != "-" ] && set -- "$@" -e "DZN_PHASE_2A2V_WORKER=$workerArg"
  [ "$gateArg" != "-" ] && set -- "$@" -e "DZN_PHASE_2A2V_GATE_DIR=$gateArg"
  docker run --rm --network "$net" -u 0 -v "$wpdir:/var/www/html" -v "$repo:$repo" "$@" \
    wordpress:cli-php8.3 wp eval-file "$file" --path=/var/www/html --user=1 --allow-root
}
waitfor(){
  i=0
  while [ ! -f "$1" ] && [ "$i" -lt 1200 ]; do i=$((i+1)); sleep 0.1; done
  [ -f "$1" ]
}
wprun - - "$repo/tests/phase-2a2v-concurrency-setup.php" >"$gate/setup.out" 2>&1
wprun "$gate" w1 "$repo/tests/phase-2a2v-concurrency-worker.php" >"$gate/w1.out" 2>&1 &
w1=$!
waitfor "$gate/w1.started" || { echo "holder worker never gated"; cat "$gate/w1.out"; exit 1; }
wprun "$gate" w2 "$repo/tests/phase-2a2v-concurrency-worker.php" >"$gate/w2.out" 2>&1 &
w2=$!
case $mode in
  unrelated_teacher)
    # The contender owns a different Teacher's connection row, so it must complete while the holder's
    # transaction is still open; only then is the holder released.
    if waitfor "$gate/w2.finished"; then : >"$gate/w2.independent"; fi
    : >"$gate/release"
    ;;
  *)
    # The contender serialises on the same connection/occurrence row, so it blocks until release.
    sleep 1
    : >"$gate/release"
    ;;
esac
wait "$w1" || true
wait "$w2" || true
wprun "$gate" - "$repo/tests/phase-2a2v-concurrency-verify.php"
