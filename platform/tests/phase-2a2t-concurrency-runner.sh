#!/bin/sh
# Deterministic Phase 2A.2-T payment-execution race runner.
set -eu
: "${DZN_PHASE_2A2T_REPO:?}" "${DZN_PHASE_2A2T_WP_DIR:?}" "${DZN_PHASE_2A2T_NET:?}"
mode=${1:?usage: phase-2a2t-concurrency-runner.sh MODE}
repo=$DZN_PHASE_2A2T_REPO
wpdir=$DZN_PHASE_2A2T_WP_DIR
net=$DZN_PHASE_2A2T_NET
case $mode in
  duplicate_webhook|out_of_order_event|submit_vs_cancel|settlement_vs_attempt|mapping_change_vs_intake|\
  secret_rotation_vs_intake|unrelated_students|duplicate_command_replay|settlement_vs_r2_consequence|\
  submit_vs_cancel_in_flight|redrive_after_crash|concurrent_expired_lease|takeover_reissue_fenced|\
  fenced_settlement_lost|initial_dispatch_descriptor_failure|post_preflight_capability_failure|\
  conflicting_duplicate_webhook|pending_decision_retry|undecided_event_recovery|\
  stale_owner_after_lease_expiry|stale_owner_inside_r1_unit|stale_owner_inside_r2_unit|\
  stale_owner_at_decision_append|append_blocked_on_claim_row) ;;
  *) echo "Unknown Phase T concurrency mode: $mode" >&2; exit 2;;
esac
# The gate directory must be visible to the worker containers at the same absolute path, so it lives
# inside the mounted candidate repository and is removed again when the run finishes.
gate=$(mktemp -d "$repo/.dzn-2a2t-gate.XXXXXX")
cleanup(){ case "$gate" in "$repo"/.dzn-2a2t-gate.*) rm -rf "$gate";; esac; }
trap cleanup EXIT INT TERM
wprun(){
  gateArg=$1; workerArg=$2; file=$3
  set -- -e "DZN_PHASE_2A2T_RUNTIME_TEST=concurrency" -e "DZN_PHASE_2A2T_MODE=$mode"
  [ "$workerArg" != "-" ] && set -- "$@" -e "DZN_PHASE_2A2T_WORKER=$workerArg"
  [ "$gateArg" != "-" ] && set -- "$@" -e "DZN_PHASE_2A2T_GATE_DIR=$gateArg"
  docker run --rm --network "$net" -u 0 -v "$wpdir:/var/www/html" -v "$repo:$repo" "$@" \
    wordpress:cli-php8.3 wp eval-file "$file" --path=/var/www/html --user=1 --allow-root
}
waitfor(){
  i=0
  while [ ! -f "$1" ] && [ "$i" -lt 1200 ]; do i=$((i+1)); sleep 0.1; done
  [ -f "$1" ]
}
wprun - - "$repo/tests/phase-2a2t-concurrency-setup.php" >"$gate/setup.out" 2>&1
wprun "$gate" w1 "$repo/tests/phase-2a2t-concurrency-worker.php" >"$gate/w1.out" 2>&1 &
w1=$!
waitfor "$gate/w1.started" || { echo "holder worker never gated"; cat "$gate/w1.out"; exit 1; }
wprun "$gate" w2 "$repo/tests/phase-2a2t-concurrency-worker.php" >"$gate/w2.out" 2>&1 &
w2=$!
case $mode in
  duplicate_webhook|conflicting_duplicate_webhook|pending_decision_retry|undecided_event_recovery|stale_owner_after_lease_expiry|stale_owner_inside_r1_unit|stale_owner_inside_r2_unit|stale_owner_at_decision_append|append_blocked_on_claim_row)
    # [C8-3] Both deliveries must be in flight together, so neither worker holds a lock: each announces
    # itself in the gate directory and waits for its sibling before submitting the same provider event
    # identity, and the unique `provider_event` index arbitrates the race. [C9-1] The decision-claim
    # races use the same gate: both deliveries reach the one owed decision together, and the unique
    # `event_claim` index arbitrates which of them may complete it.
    # [C10-2] `stale_owner_after_lease_expiry` uses the same gate in the other direction: the first
    # worker announces itself from inside the decision it owns, ages its own lease past expiry and then
    # waits for the second worker's record, so the successor takes the claim over *before* the first
    # workers resumes its decision work.
    # [C11-1] `stale_owner_inside_r1_unit` / `stale_owner_inside_r2_unit` reuse the gate inside a work
    # unit: the first worker stalls *inside* an R1/R2 mutation with its own window aged, the second worker
    # delivers while it is stalled (it can own nothing and works nowhere), and once the fence has rolled the
    # stalled unit back and released the claim, the second worker completes the event's decision.
    # [C12-1] `stale_owner_at_decision_append` uses the gate in the other direction again: the first worker
    # lets its own window lapse *after* its last R1/R2 work unit — the append seam of the decision operation —
    # with no successor having taken its claim over, and the second worker delivers only once the first has
    # returned, so the append fence (never a take-over) is what refuses the stale generation and the next
    # delivery is what completes the event. The claim row is never written for this mode: the window lapses in
    # real elapsed time, so this one mode runs for the structural 120-second decision-claim lease before the
    # second worker is released.
    # [C14-1] `append_blocked_on_claim_row` also runs for a real window, but the window is held open by the
    # *second* worker: it takes its own transaction on the owner's claim row and holds that row until the
    # owner's live window has lapsed, while the owner's append — whose first statement is the transition's
    # own fenced locking read, never the conditional update — is queued behind that lock. The queued append is
    # refused the moment the lock is released, so the release, not a take-over, is what lets it through; the
    # second worker then delivers and completes the event.
    waitfor "$gate/w2.started" || { echo "webhook contender never started"; cat "$gate/w2.out"; exit 1; }
    ;;
  unrelated_students)
    # The contender owns a different Student's account root, so it must complete while the holder's
    # transaction is still open; only then is the holder released.
    if waitfor "$gate/w2.finished"; then : >"$gate/w2.independent"; fi
    : >"$gate/release"
    ;;
  *)
    # The contender serialises on the same account root, claim row or subject, so it blocks until release.
    sleep 1
    : >"$gate/release"
    ;;
esac
wait "$w1" || true
wait "$w2" || true
wprun "$gate" - "$repo/tests/phase-2a2t-concurrency-verify.php"
