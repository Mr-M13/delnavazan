#!/usr/bin/env bash
set -euo pipefail
# Disposable recording/archive runtime: own project, port and state directory.
export DZN_COMPOSE_PROJECT="${DZN_RECORDING_ARCHIVE_PROJECT:-dzn-recording-archive}"
export DZN_RUNTIME_STATE_DIR="${DZN_RECORDING_ARCHIVE_STATE:-${TMPDIR:-/tmp}/dzn-recording-archive}"
export DZN_WP_PORT="${DZN_RECORDING_ARCHIVE_PORT:-18088}"
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
if [ -e "$DZN_PLUGIN_WORKTREE" ]; then
  git -C "$DZN_SHARED_CHECKOUT" worktree remove --force "$DZN_PLUGIN_WORKTREE" >/dev/null 2>&1 || true
  [ ! -e "$DZN_PLUGIN_WORKTREE" ] || dzn_remove_disposable_path "$DZN_PLUGIN_WORKTREE"
fi
"$bin/reset-disposable.sh"
docker rm -f "${DZN_COMPOSE_PROJECT}-wordpress-1" || true
dzn_wp eval '$installed = (string) get_option("dzn_platform_schema_version", ""); if ($installed !== (string) DZN_PLATFORM_SCHEMA_VERSION) { throw new RuntimeException("Installed schema differs from the package schema"); } $checker = new ReflectionMethod(Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::class, "verify_current_schema"); $checker->invoke(null); echo "current_schema_ok\n";'
dzn_wp_env DZN_RECORDING_ARCHIVE_MIGRATION_TEST=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/recording-archive-migration-runtime.php" --user=1
dzn_wp_env DZN_RECORDING_ARCHIVE_BEHAVIOR_TEST=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/recording-archive-behavior-runtime.php" --user=1
echo 'Recording archive runtime: PASS'
