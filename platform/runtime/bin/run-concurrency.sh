#!/usr/bin/env bash
# Runs unmodified historical runner scripts only after cache preflight. Their image
# names remain historical; preflight guarantees docker run cannot need a pull.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
phases="${*:-r2 v t u}"
for phase in $phases; do
  "$(dirname "$BASH_SOURCE")/prepare-historical-suite.sh" "$phase"
  source_dir="$(cat "$DZN_RUNTIME_STATE_DIR/historical-source")"
  DZN_PLUGIN_SOURCE="$source_dir"; export DZN_PLUGIN_SOURCE
  dzn_assert_disposable_path "$source_dir"; dzn_assert_runtime_paths
  case "$phase" in
    r2) modes="renewal_vs_schedule guarantee_vs_close recovery_vs_satisfaction release_vs_succession mode_change_vs_cycle refund_vs_settlement unrelated_recurring_enrolments"; key=R2;;
    v) modes="connect_vs_revoke authorization_replay projection_vs_release projection_vs_completion duplicate_vs_conflicting_event cross_lesson_event_key_race provider_event_sequence_race ingest_vs_canonical_authority mapping_revoke_vs_ingest teacher_archival_vs_connection unrelated_teacher"; key=V;;
    t) modes="duplicate_webhook out_of_order_event submit_vs_cancel settlement_vs_attempt mapping_change_vs_intake secret_rotation_vs_intake unrelated_students duplicate_command_replay settlement_vs_r2_consequence submit_vs_cancel_in_flight redrive_after_crash concurrent_expired_lease takeover_reissue_fenced fenced_settlement_lost initial_dispatch_descriptor_failure post_preflight_capability_failure conflicting_duplicate_webhook pending_decision_retry undecided_event_recovery stale_owner_after_lease_expiry stale_owner_inside_r1_unit stale_owner_inside_r2_unit stale_owner_at_decision_append append_blocked_on_claim_row"; key=T;;
    u) modes="duplicate_snapshot_capture rate_close_vs_rate_record rate_change_vs_snapshot_capture successor_record_vs_delayed_historical_capture payability_override_vs_evaluation concurrent_statement_draft concurrent_statement_issue concurrent_policy_record statement_issue_vs_snapshot_correction concurrent_reconciliation_run duplicate_command_replay unrelated_teachers policy_change_vs_statement_draft policy_record_vs_snapshot_capture concurrent_exception_resolution concurrent_exception_resolution_teacherless refusal_evidence_convergence period_wide_run_vs_draft"; key=U;;
    *) echo "unknown historical suite: $phase" >&2; exit 2;;
  esac
  if [ -f "$source_dir/tests/phase-2a2j-fixture.php" ]; then
    dzn_wp_env DZN_PHASE_2A2J_RUNTIME_TEST=fixture -- eval-file "$source_dir/tests/phase-2a2j-fixture.php" --user=1
  fi
  for mode in $modes; do
    echo "--- $phase concurrency: $mode"
    export "DZN_PHASE_2A2$key"_REPO="$source_dir" "DZN_PHASE_2A2$key"_WP_DIR="$DZN_WP_DIR" "DZN_PHASE_2A2$key"_NET="$DZN_NETWORK"
    dzn_historical_runner "$source_dir/tests/phase-2a2${phase}-concurrency-runner.sh" "$mode"
  done
done
echo 'Historical concurrency hooks: PASS'
