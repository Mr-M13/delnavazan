#!/usr/bin/env bash
# Prepare one historical suite on its own immutable source tree and empty database.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
phase="${1:?usage: prepare-historical-suite.sh s|r2|v|t|u}"
case "$phase" in
  s) ref=63f6b5b2eeaeebc194b07103d4a622d3fecf52ca;;
  r2) ref=559b1736621c9ed32e41dd2b785dd0f040dcb647;;
  v) ref=1cb9d16b0beb5bec293b41a065b63ffa5f1318f6;;
  t) ref=b36561dc6bb6e87fd142a28ae67fbc4f2fdc9279;;
  u) ref=773e13e2bf148f6e9ff250b58c7bd6ee4b135f48;;
  *) echo "unknown historical suite: $phase" >&2; exit 2;;
esac
dzn_require_cached_images
historical="$DZN_RUNTIME_STATE_DIR/historical-$phase"
dzn_prepare_worktree "$historical" "$ref"
DZN_PLUGIN_SOURCE="$historical"; export DZN_PLUGIN_SOURCE
dzn_assert_runtime_paths
dzn_compose down --volumes --remove-orphans || true
dzn_remove_disposable_path "$DZN_DB_DIR"; dzn_remove_disposable_path "$DZN_WP_DIR"; mkdir -p "$DZN_DB_DIR" "$DZN_WP_DIR"
dzn_compose up --pull never -d --wait db
dzn_compose up --pull never -d wordpress
for _ in $(seq 1 30); do [ -f "$DZN_WP_DIR/wp-load.php" ] && break; sleep 1; done
[ -f "$DZN_WP_DIR/wp-load.php" ] || { echo 'error: WordPress core was not supplied by the cached image.' >&2; exit 1; }
dzn_wp core install --url="$DZN_WP_URL" --title="Historical $phase" --admin_user=admin --admin_password=local-only-not-a-secret --admin_email=admin@example.invalid --skip-email
plugin="$DZN_WP_DIR/wp-content/plugins/delnavazan-platform"; mkdir -p "$(dirname "$plugin")"; ln -s "$historical" "$plugin"
dzn_wp plugin activate delnavazan-platform
printf '%s\n' "$historical" >"$DZN_RUNTIME_STATE_DIR/historical-source"
echo "prepared $phase at immutable $ref"
