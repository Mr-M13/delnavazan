#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"

for _ in $(seq 1 30); do [ -f "$DZN_ACCEPTANCE_WP_DIR/wp-load.php" ] && break; sleep 1; done
[ -f "$DZN_ACCEPTANCE_WP_DIR/wp-load.php" ] || { echo 'error: WordPress core was not supplied by the cached image.' >&2; exit 1; }
if ! dzn_acceptance_wp core is-installed >/dev/null 2>&1; then
  dzn_acceptance_wp core install --url="$DZN_ACCEPTANCE_WP_URL" --title='Disposable Delnavazan acceptance' --admin_user=admin --admin_password=local-only-not-a-secret --admin_email=admin@example.invalid --skip-email
fi

link_source() {
  local link="$1" target="$2"
  if [ -e "$link" ] || [ -L "$link" ]; then
    [ -L "$link" ] || { echo "error: refusing to replace non-symlink integration asset: $link" >&2; exit 1; }
    [ "$(readlink "$link")" = "$target" ] || { echo "error: refusing unexpected integration symlink: $link" >&2; exit 1; }
  else
    ln -s "$target" "$link"
  fi
}

mkdir -p "$DZN_ACCEPTANCE_WP_DIR/wp-content/plugins" "$DZN_ACCEPTANCE_WP_DIR/wp-content/themes"
link_source "$DZN_ACCEPTANCE_WP_DIR/wp-content/plugins/delnavazan-platform" /workspace/platform
link_source "$DZN_ACCEPTANCE_WP_DIR/wp-content/themes/delnavazan" /workspace/theme
dzn_acceptance_wp plugin activate delnavazan-platform
dzn_acceptance_wp theme activate delnavazan
