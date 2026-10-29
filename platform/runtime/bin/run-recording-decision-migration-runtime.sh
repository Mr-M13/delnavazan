#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
"$bin/reset-disposable.sh"
dzn_compose stop wordpress
dzn_wp eval '$installed = (string) get_option("dzn_platform_schema_version", ""); if ($installed !== (string) DZN_PLATFORM_SCHEMA_VERSION) { throw new RuntimeException("Installed schema differs from the package schema"); } $checker = new ReflectionMethod(Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::class, "verify_current_schema"); $checker->invoke(null); echo "current_schema_ok\n";'
dzn_wp_env DZN_RECORDING_DECISION_MIGRATION_TEST=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/recording-decision-migration-runtime.php" --user=1
echo 'Recording decision migration runtime: PASS'
