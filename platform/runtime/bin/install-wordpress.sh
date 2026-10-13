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
plugin="$DZN_WP_DIR/wp-content/plugins/delnavazan-platform"
mkdir -p "$(dirname "$plugin")"
[ ! -e "$plugin" ] || rm -f "$plugin"
# Candidate worktrees may be monorepos; the WordPress plugin lives in platform/.
plugin_source="$DZN_PLUGIN_WORKTREE"
if [ -f "$plugin_source/platform/delnavazan-platform.php" ]; then plugin_source="$plugin_source/platform"; fi
ln -s "$plugin_source" "$plugin"
dzn_wp plugin activate delnavazan-platform
