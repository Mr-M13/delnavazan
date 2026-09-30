#!/usr/bin/env bash
# The full chain on one existing database: Schema 30 (immutable 86d5760) is upgraded in place by the
# candidate tree under test, which must add exactly the six Schema-031 portal tables, the eighteen
# Schema-032 notification tables and the five Schema-033 readiness tables, and change nothing else.
set -euo pipefail
"$(dirname "$BASH_SOURCE")/schema-upgrade-rehearsal.sh" 30 86d57606cabcddba15d076edfe14fb4e7257e60f \
  wp_dzn_portal_access_denials wp_dzn_portal_lesson_capability_roots wp_dzn_portal_public_action_events \
  wp_dzn_portal_public_capabilities wp_dzn_portal_public_capability_commands wp_dzn_portal_public_capability_events \
  wp_dzn_notification_workflows wp_dzn_notification_workflow_versions wp_dzn_notification_workflow_rules \
  wp_dzn_notification_workflow_commands wp_dzn_notification_templates wp_dzn_notification_template_versions \
  wp_dzn_notification_template_commands wp_dzn_notification_rendered_snapshots wp_dzn_notifications \
  wp_dzn_notification_events wp_dzn_notification_commands wp_dzn_notification_attempts \
  wp_dzn_notification_attempt_events wp_dzn_notification_deliveries wp_dzn_notification_suppressions \
  wp_dzn_notification_suppression_events wp_dzn_notification_suppression_commands wp_dzn_notification_privacy_tombstones \
  wp_dzn_core_dataset_provenance wp_dzn_core_dataset_reconciliation_runs wp_dzn_core_dataset_reconciliation_findings wp_dzn_core_dataset_operator_commands wp_dzn_core_dataset_corrections
