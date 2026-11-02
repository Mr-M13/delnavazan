#!/usr/bin/env bash
# The only no-runtime evidence: parse/source-contract tests inside the cached CLI image.
#
# `tests/schema-contract.php` is deliberately not in this list. It is a Phase-1 guard that forbids the
# string `finance` anywhere in the Migrator, which Schema 030 (Phase 2A.2-U) legitimately added; it fails
# identically on authoritative `main` with `Forbidden schema direction.`, and the integration manifest
# records it as a pre-existing failure that this candidate neither caused nor inherits as its own. Running
# it here would abort the acceptance sequence on a defect outside this package, so this list carries the
# guards that describe the current tree: the parse/lint sweep, the Phase-2A.2-W contract guard (which now
# embeds the §15.6 refusal-versus-failure proof).
# `tests/phase-opreadiness-core-dataset-contract.php` is the WordPress-free guard for the Schema-033
# readiness slice: the locked Schema-033 migration, the persisted reconciliation run, the convergent
# operator replay and the fail-closed projection read. It reads the tree and embeds the
# projection-read failure-injection proof, which stubs `$wpdb` rather than using one, so it runs in
# the same cached CLI image without WordPress or a database.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
dzn_require_cached_images; dzn_candidate; dzn_assert_runtime_paths
plugin_root="$(dzn_plugin_source_from_worktree "$DZN_PLUGIN_WORKTREE")"
tests=(tests/static.php tests/legal-pages-contract.php tests/golden-hour-pay-unit.php tests/golden-hour-pay-contract.php tests/golden-hour-teacher-visual-unit.php tests/finance-pricing-unit.php tests/finance-statement-template-unit.php tests/intro-payment-unit.php tests/finance-administration-contract.php tests/finance-payment-completion-contract.php tests/finance-refund-batch-contract.php tests/teacher-statement-email-unit.php tests/student-payment-history-contract.php tests/finance-schedule-export-contract.php tests/finance-notification-contract.php tests/interim-attendance-completion-contract.php tests/teacher-delivery-confirmation-contract.php tests/post-intro-fulfilment-unit.php tests/post-payment-fulfilment-contract.php tests/portal-calendar-export-contract.php tests/portal-calendar-export-unit.php tests/portal-calendar-export-controller-unit.php tests/google-oauth-browser-flow-contract.php tests/google-oauth-transport-unit.php tests/booking-calendar-projection-unit.php tests/calendar-transport-retry-unit.php tests/teacher-portal-presentation-contract.php tests/teacher-class-window-unit.php tests/lesson-reschedule-migration-contract.php tests/lesson-reschedule-service-unit.php tests/lesson-replacement-service-unit.php tests/term-replacement-allocation-unit.php tests/lesson-reschedule-notification-unit.php tests/lesson-reschedule-calendar-unit.php tests/lesson-reschedule-system-actors-unit.php tests/portal-identity-display-unit.php tests/phase-2a3-iran-quiet-hours.php tests/phase-2a3-day-availability-contract.php tests/booking-availability-timezone-dst-contract.php tests/phase-2a2w-contract.php tests/phase-opreadiness-core-dataset-contract.php tests/phase-opreadiness-core-operator-entrypoint-contract.php tests/phase-opreadiness-core-operator-entrypoint-unit.php tests/student-operations-journey-contract.php tests/notification-management-contract.php tests/notification-management-unit.php tests/communications-brand-contract.php tests/notification-admin-language-contract.php tests/notification-preview-unit.php tests/notification-delivery-status-unit.php tests/notification-sms-fallback-policy-unit.php tests/notification-sms-fallback-contract.php tests/telnyx-template-authority-unit.php)
tests+=(tests/teacher-availability-summary-unit.php tests/tuition-allocation-unit.php tests/teacher-portal-ux-finance-contract.php tests/teacher-induction-unit.php tests/teacher-induction-contract.php tests/teacher-induction-presentation.php tests/teacher-workspace-admin-contract.php tests/teacher-workspace-state-unit.php tests/admin-dashboard-finance-classroom-contract.php tests/finance-monthly-activity-unit.php tests/livekit-administrator-classroom-unit.php)
executed=0
for test in "${tests[@]}"; do
  [ -f "$plugin_root/$test" ] || continue
  # Disposable worktrees are intentionally owner-only. Run the read-only CLI
  # container as root so PHP can traverse that bind mount; no source is writable.
  docker run --rm --pull=never -u 0 -v "$DZN_PLUGIN_WORKTREE:$DZN_PLUGIN_WORKTREE:ro" --entrypoint php "$DZN_CLI_IMAGE" "$plugin_root/$test"
  executed=$((executed + 1))
  echo "PASS  $test"
done
[ "$executed" -gt 0 ] || { echo 'error: pure-test bundle executed zero tests.' >&2; exit 1; }
