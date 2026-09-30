#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
dzn_candidate
for _ in $(seq 1 30); do [ -f "$DZN_WP_DIR/wp-load.php" ] && break; sleep 1; done
[ -f "$DZN_WP_DIR/wp-load.php" ] || { echo 'error: WordPress core was not supplied by the cached image.' >&2; exit 1; }
if ! dzn_wp core is-installed >/dev/null 2>&1; then
  dzn_wp core install --url="$DZN_WP_URL" --title='Disposable Schema-33 runtime' --admin_user=admin --admin_password=local-only-not-a-secret --admin_email=admin@example.invalid --skip-email
fi
plugin="$DZN_WP_DIR/wp-content/plugins/delnavazan-platform"
mkdir -p "$(dirname "$plugin")"
[ ! -e "$plugin" ] || rm -f "$plugin"
ln -s "$DZN_PLUGIN_WORKTREE" "$plugin"
dzn_wp plugin activate delnavazan-platform
