#!/usr/bin/env bash
# Verify the installed candidate using the production migrator's own complete
# schema and migration-ledger checks, rather than frozen Schema 33 assumptions.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
dzn_wp eval '
$installed = (string) get_option("dzn_platform_schema_version", "");
if (!defined("DZN_PLATFORM_SCHEMA_VERSION") || $installed !== (string) DZN_PLATFORM_SCHEMA_VERSION) {
    throw new RuntimeException("Installed schema differs from the package schema");
}
$checker = new ReflectionMethod(\Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::class, "verify_current_schema");
$checker->invoke(null);
echo "current_schema_ok\n";
'
