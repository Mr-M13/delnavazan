#!/usr/bin/env bash
# The Schema-032 slice alone on one existing database: the integrated Schema-31 base (immutable 2ab0c71,
# the W candidate tip the Schema-032 re-land was built on) is upgraded in place by the candidate tree under
# test, which must add exactly the eighteen Schema-032 notification tables and five Schema-033 readiness tables, leave the six Schema-031 portal
# tables untouched and change nothing else.
set -euo pipefail
"$(dirname "$BASH_SOURCE")/schema-upgrade-rehearsal.sh" 31 2ab0c71f5cc53ca8aa4241db0b2f7d100de65997 \
  wp_dzn_notification_workflows wp_dzn_notification_workflow_versions wp_dzn_notification_workflow_rules \
  wp_dzn_notification_workflow_commands wp_dzn_notification_templates wp_dzn_notification_template_versions \
  wp_dzn_notification_template_commands wp_dzn_notification_rendered_snapshots wp_dzn_notifications \
  wp_dzn_notification_events wp_dzn_notification_commands wp_dzn_notification_attempts \
  wp_dzn_notification_attempt_events wp_dzn_notification_deliveries wp_dzn_notification_suppressions \
  wp_dzn_notification_suppression_events wp_dzn_notification_suppression_commands wp_dzn_notification_privacy_tombstones \
  wp_dzn_core_dataset_provenance wp_dzn_core_dataset_reconciliation_runs wp_dzn_core_dataset_reconciliation_findings wp_dzn_core_dataset_operator_commands wp_dzn_core_dataset_corrections
