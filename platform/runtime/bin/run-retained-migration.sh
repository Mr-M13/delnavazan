#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
echo '=== Retained Schema-33 verification / repeat migration ==='
"$(dirname "$BASH_SOURCE")/run-fresh-install.sh"
before="$(dzn_wp eval 'echo get_option("dzn_platform_schema_version")."|".count((array)get_option("dzn_platform_completed_migrations",array()));')"
dzn_wp eval '\Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();'
"$(dirname "$BASH_SOURCE")/verify-schema33.sh"
after="$(dzn_wp eval 'echo get_option("dzn_platform_schema_version")."|".count((array)get_option("dzn_platform_completed_migrations",array()));')"
[ "$before" = "$after" ] || { echo 'retained migration changed the ledger' >&2; exit 1; }
[ "$after" = '33|33' ] || { echo "the retained ledger must hold all 33 recorded migrations at Schema 33 (got $after)" >&2; exit 1; }
echo 'Retained/repeat migration: PASS'
