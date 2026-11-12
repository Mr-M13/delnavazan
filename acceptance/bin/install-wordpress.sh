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
  local relative="$1" target="$2"
  dzn_acceptance_wp eval "\$link=ABSPATH . '$relative'; \$target='$target'; if (is_link(\$link)) { if (readlink(\$link) !== \$target) { throw new RuntimeException('Unexpected integration symlink: ' . \$link); } } elseif (file_exists(\$link)) { throw new RuntimeException('Refusing to replace non-symlink integration asset: ' . \$link); } elseif (!symlink(\$target, \$link)) { throw new RuntimeException('Could not create integration symlink: ' . \$link); }"
}

link_source 'wp-content/plugins/delnavazan-platform' /workspace/platform
link_source 'wp-content/themes/delnavazan' /workspace/theme
dzn_acceptance_wp plugin activate delnavazan-platform
dzn_acceptance_wp theme activate delnavazan
