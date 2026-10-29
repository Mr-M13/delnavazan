#!/usr/bin/env bash
set -euo pipefail
# Dedicated state, project and port for the recording/research UI runtime.
export DZN_COMPOSE_PROJECT="${DZN_RECORDING_UI_PROJECT:-dzn-recording-choices-ui}"
export DZN_RUNTIME_STATE_DIR="${DZN_RECORDING_UI_STATE:-${TMPDIR:-/tmp}/dzn-recording-choices-ui}"
export DZN_WP_PORT="${DZN_RECORDING_UI_PORT:-18083}"
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
if [ -e "$DZN_PLUGIN_WORKTREE" ]; then
  git -C "$DZN_SHARED_CHECKOUT" worktree remove --force "$DZN_PLUGIN_WORKTREE" >/dev/null 2>&1 || true
  [ ! -e "$DZN_PLUGIN_WORKTREE" ] || dzn_remove_disposable_path "$DZN_PLUGIN_WORKTREE"
fi
"$bin/reset-disposable.sh"
docker rm -f "${DZN_COMPOSE_PROJECT}-wordpress-1" || true
dzn_wp_env "DZN_THEME_TARGET=$DZN_PLUGIN_WORKTREE/theme" -- eval '
$target = (string) getenv("DZN_THEME_TARGET");
$link = WP_CONTENT_DIR . "/themes/delnavazan-theme";
if (is_link($link) || file_exists($link)) {
    if (is_dir($link) && !is_link($link)) { throw new RuntimeException("Unexpected theme directory: " . $link); }
    if (!unlink($link)) { throw new RuntimeException("Cannot replace theme link: " . $link); }
}
if (!is_dir($target)) { throw new RuntimeException("Theme source missing: " . $target); }
if (!symlink($target, $link)) { throw new RuntimeException("Cannot create theme link: " . $link); }
echo "theme_linked\n";
'
dzn_wp theme activate delnavazan-theme
dzn_wp eval '$installed = (string) get_option("dzn_platform_schema_version", ""); if ($installed !== (string) DZN_PLATFORM_SCHEMA_VERSION) { throw new RuntimeException("Installed schema differs from the package schema"); } echo "current_schema_ok\n";'
dzn_wp_env DZN_RECORDING_UI_TEST=choices -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/recording-choices-ui-runtime.php" --user=1
echo 'Recording choices UI runtime: PASS'
