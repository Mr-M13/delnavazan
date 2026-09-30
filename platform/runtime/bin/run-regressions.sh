#!/usr/bin/env bash
# Hooks only existing historical runtime tests; no source is copied or retargeted.
# The S migration runtime is deliberately pinned to immutable Schema 32; it is
# never routed through the current Schema-33 candidate worktree.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
phases="${*:-r2 v t u s}"
for phase in $phases; do
  "$(dirname "$BASH_SOURCE")/prepare-historical-suite.sh" "$phase"
  source_dir="$(cat "$DZN_RUNTIME_STATE_DIR/historical-source")"; DZN_PLUGIN_SOURCE="$source_dir"; export DZN_PLUGIN_SOURCE
  dzn_assert_disposable_path "$source_dir"; dzn_assert_runtime_paths
  if [ -f "$source_dir/tests/phase-2a2j-fixture.php" ]; then dzn_wp_env DZN_PHASE_2A2J_RUNTIME_TEST=fixture -- eval-file "$source_dir/tests/phase-2a2j-fixture.php" --user=1; fi
  case "$phase" in
    s) tests="DZN_PHASE_2A2S_RUNTIME_TEST=migration:phase-2a2s-migration-runtime.php";;
    r2) tests="DZN_PHASE_2A2R2_RUNTIME_TEST=authority:phase-2a2r2-runtime.php DZN_PHASE_2A2R2_RUNTIME_TEST=migration:phase-2a2r2-migration-runtime.php DZN_PHASE_2A2R2_RUNTIME_TEST=failure:phase-2a2r2-failure-runtime.php DZN_PHASE_2A2R2_RUNTIME_TEST=corruption:phase-2a2r2-corruption-runtime.php";;
    v) tests="DZN_PHASE_2A2V_RUNTIME_TEST=authority:phase-2a2v-runtime.php DZN_PHASE_2A2V_RUNTIME_TEST=migration:phase-2a2v-migration-runtime.php DZN_PHASE_2A2V_RUNTIME_TEST=failure:phase-2a2v-failure-runtime.php DZN_PHASE_2A2V_RUNTIME_TEST=corruption:phase-2a2v-corruption-runtime.php -:phase-2a2v-isolated-runtime.php";;
    t) tests="DZN_PHASE_2A2T_RUNTIME_TEST=authority:phase-2a2t-runtime.php DZN_PHASE_2A2T_MIGRATION_TEST=authority:phase-2a2t-migration-runtime.php DZN_PHASE_2A2T_FAILURE_TEST=authority:phase-2a2t-failure-runtime.php DZN_PHASE_2A2T_CORRUPTION_TEST=authority:phase-2a2t-corruption-runtime.php DZN_PHASE_2A2T_SECRET_TEST=authority:phase-2a2t-secret-runtime.php DZN_PHASE_2A2T_WEBHOOK_TEST=authority:phase-2a2t-webhook-runtime.php";;
    u) tests="DZN_PHASE_2A2U_RUNTIME_TEST=authority:phase-2a2u-runtime.php DZN_PHASE_2A2U_MIGRATION_TEST=authority:phase-2a2u-migration-runtime.php DZN_PHASE_2A2U_FAILURE_TEST=authority:phase-2a2u-failure-runtime.php DZN_PHASE_2A2U_CORRUPTION_TEST=authority:phase-2a2u-corruption-runtime.php DZN_PHASE_2A2U_RECONCILIATION_TEST=authority:phase-2a2u-reconciliation-runtime.php DZN_PHASE_2A2U_STATEMENT_TEST=authority:phase-2a2u-statement-runtime.php -:phase-2a2u-replay-unit.php";;
  esac
  for item in $tests; do env="${item%%:*}"; file="${item#*:}"; echo "--- $phase $file"; if [ "$env" = - ]; then docker run --rm --pull=never -v "$source_dir:$source_dir:ro" --entrypoint php "$DZN_CLI_IMAGE" "$source_dir/tests/$file"; else dzn_wp_env "$env" -- eval-file "$source_dir/tests/$file" --user=1; fi; done
done
echo 'Historical regression hooks: PASS'
