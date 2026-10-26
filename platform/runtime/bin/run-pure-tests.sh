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
tests=(tests/static.php tests/finance-pricing-unit.php tests/finance-statement-template-unit.php tests/intro-payment-unit.php tests/finance-administration-contract.php tests/interim-attendance-completion-contract.php tests/post-intro-fulfilment-unit.php tests/post-payment-fulfilment-contract.php tests/portal-calendar-export-contract.php tests/portal-calendar-export-unit.php tests/portal-calendar-export-controller-unit.php tests/google-oauth-browser-flow-contract.php tests/google-oauth-transport-unit.php tests/booking-calendar-projection-unit.php tests/teacher-portal-presentation-contract.php tests/phase-2a3-iran-quiet-hours.php tests/phase-2a3-day-availability-contract.php tests/booking-availability-timezone-dst-contract.php tests/phase-2a2w-contract.php tests/phase-opreadiness-core-dataset-contract.php tests/phase-opreadiness-core-operator-entrypoint-contract.php tests/phase-opreadiness-core-operator-entrypoint-unit.php tests/student-operations-journey-contract.php)
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
