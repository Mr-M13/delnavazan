#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
dzn_candidate
core_ready=false
for _ in $(seq 1 60); do
  if [ -f "$DZN_WP_DIR/wp-config.php" ] && dzn_wp core version >/dev/null 2>&1; then core_ready=true; break; fi
  sleep 1
done
[ "$core_ready" = true ] || { echo 'error: WordPress core did not become ready for WP-CLI.' >&2; exit 1; }
if ! dzn_wp core is-installed >/dev/null 2>&1; then
  dzn_wp core install --url="$DZN_WP_URL" --title='Disposable Schema-33 runtime' --admin_user=admin --admin_password=local-only-not-a-secret --admin_email=admin@example.invalid --skip-email
fi
# Candidate worktrees may be monorepos; the WordPress plugin lives in platform/.
plugin_source="$DZN_PLUGIN_WORKTREE"
if [ -f "$plugin_source/platform/delnavazan-platform.php" ]; then plugin_source="$plugin_source/platform"; fi
# On Linux the WordPress container owns wp-content, so the host user cannot create
# the plugin link. Create it as root inside the CLI container, which mounts the
# candidate source at the same absolute path. This keeps the harness portable.
dzn_wp_env "DZN_PLUGIN_TARGET=$plugin_source" -- eval '
$target = (string) getenv("DZN_PLUGIN_TARGET");
$plugin = WP_PLUGIN_DIR . "/delnavazan-platform";
if (is_link($plugin) || file_exists($plugin)) {
    if (is_dir($plugin) && !is_link($plugin)) { throw new RuntimeException("Unexpected plugin directory: " . $plugin); }
    if (!unlink($plugin)) { throw new RuntimeException("Cannot replace plugin link: " . $plugin); }
}
if (!is_dir($target)) { throw new RuntimeException("Plugin source missing: " . $target); }
if (!symlink($target, $plugin)) { throw new RuntimeException("Cannot create plugin link: " . $plugin); }
echo "plugin_linked\n";
'
dzn_wp plugin activate delnavazan-platform
