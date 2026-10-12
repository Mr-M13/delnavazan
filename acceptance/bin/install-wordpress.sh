#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"

# The Apache image copies WordPress into the shared bind mount asynchronously.
# Seeing wp-load.php alone does not mean the full core is ready for WP-CLI.
core_ready=false
for _ in $(seq 1 60); do
  if [ -f "$DZN_ACCEPTANCE_WP_DIR/wp-config.php" ] && dzn_acceptance_wp core version >/dev/null 2>&1; then
    core_ready=true
    break
  fi
  sleep 1
done
[ "$core_ready" = true ] || { echo 'error: WordPress core did not become ready for WP-CLI within 60 seconds.' >&2; exit 1; }
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
