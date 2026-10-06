#!/usr/bin/env bash
set -Eeuo pipefail

readonly HOST="cp119.hosting-cloud.net"
readonly ROOT_REL="domains/delnavazan.com/public_html"
readonly THEME_REL="wp-content/themes/delnavazan-theme"
readonly SHA_RE='^[0-9a-f]{40}$'

fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
[[ $# == 1 && "$1" =~ $SHA_RE ]] || fail 'usage: production-static-serving-diagnostic.sh <full-current-main-sha>'
: "${PRODUCTION_SSH_USER:?Set the protected production SSH user}"
[[ "$PRODUCTION_SSH_USER" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'SSH username contains unsupported characters'

ssh -T "${PRODUCTION_SSH_USER}@${HOST}" bash -s -- "$ROOT_REL" "$THEME_REL" <<'REMOTE'
set -Eeuo pipefail
root_rel="$1"; theme_rel="$2"
[[ "$root_rel" == domains/delnavazan.com/public_html && "$theme_rel" == wp-content/themes/delnavazan-theme ]] || { echo 'FAIL invalid diagnostic target' >&2; exit 1; }
root="$HOME/$root_rel"
[[ -f "$root/wp-load.php" ]] || { echo 'FAIL expected WordPress root is unavailable' >&2; exit 1; }
command -v wp >/dev/null && command -v realpath >/dev/null || { echo 'FAIL WP-CLI and realpath are required' >&2; exit 1; }

# Emit only URL/path identity and file metadata. Skip plugins and themes so
# diagnostic bootstrap cannot run application/plugin callbacks.
state="$(wp --path="$root" --skip-plugins --skip-themes eval '
$theme = get_stylesheet();
$paths = [
    "ABSPATH" => ABSPATH,
    "WP_CONTENT_DIR" => defined("WP_CONTENT_DIR") ? WP_CONTENT_DIR : ABSPATH . "wp-content",
    "theme_root" => get_theme_root($theme),
    "stylesheet_dir" => get_stylesheet_directory(),
    "template_dir" => get_template_directory(),
];
foreach ($paths as $key => $path) {
    $paths[$key] = ["configured" => $path, "realpath" => realpath($path) ?: null, "is_link" => is_link($path)];
}
echo json_encode([
    "home" => get_option("home"),
    "siteurl" => get_option("siteurl"),
    "content_url" => content_url(),
    "theme_root_uri" => get_theme_root_uri($theme),
    "stylesheet" => $theme,
    "stylesheet_uri" => get_stylesheet_directory_uri(),
    "template" => get_template(),
    "template_uri" => get_template_directory_uri(),
    "wp_cli_document_root" => $_SERVER["DOCUMENT_ROOT"] ?? null,
    "paths" => $paths,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
')"
printf 'WORDPRESS_PATH_STATE=%s\n' "$state"

root_real="$(realpath "$root")"
theme="$root/$theme_rel"
printf 'DEPLOY_ROOT=%s\nDEPLOY_ROOT_REALPATH=%s\n' "$root" "$root_real"
for rel in wp-content wp-content/themes "$THEME_REL"; do
  path="$root/$rel"
  if [[ -e "$path" || -L "$path" ]]; then
    printf 'PATH rel=%s real=%s mode=%s symlink=%s\n' "$rel" "$(realpath -m "$path")" "$(stat -c '%a:%F' "$path")" "$(if [[ -L "$path" ]]; then readlink "$path"; else printf no; fi)"
  else
    printf 'PATH rel=%s missing=yes\n' "$rel"
  fi
done
for rel in style.css assets/css/theme.css assets/css/booking.css assets/js/navigation.js assets/js/booking.js; do
  path="$theme/$rel"
  if [[ -f "$path" ]]; then
    printf 'THEME_FILE rel=%s real=%s mode=%s bytes=%s sha256=%s\n' "$rel" "$(realpath "$path")" "$(stat -c '%a:%F' "$path")" "$(stat -c '%s' "$path")" "$(sha256sum "$path" | awk '{print $1}')"
  else
    printf 'THEME_FILE rel=%s missing=yes\n' "$rel"
  fi
done

# Show only rewrite/LiteSpeed directives, never arbitrary config or credentials.
for file in "$root/.htaccess" "$root/wp-content/.htaccess" "$root/wp-content/themes/.htaccess" "$theme/.htaccess"; do
  if [[ -f "$file" ]]; then
    printf 'HTACCESS path=%s real=%s mode=%s\n' "$file" "$(realpath "$file")" "$(stat -c '%a:%F' "$file")"
    awk 'BEGIN{IGNORECASE=1} /^[[:space:]]*(RewriteEngine|RewriteBase|RewriteCond|RewriteRule|<IfModule|<Files|Require|Deny|Allow|Options|</ {print}' "$file" | sed "s#^#HTACCESS_RULE path=$file rule=#"
  fi
done

# Server configuration is optional and may be unreadable to the restricted
# account. Report only DocumentRoot directives from the account/domain file.
for file in \
  "$HOME/domains/delnavazan.com.conf" \
  "$HOME/.config/apache/domains/delnavazan.com.conf" \
  "/usr/local/directadmin/data/users/$USER/domains/delnavazan.com.conf"; do
  if [[ -r "$file" ]]; then
    awk 'BEGIN{IGNORECASE=1} /^[[:space:]]*(DocumentRoot|documentroot)[[:space:]=]/ {value=$2; sub(/^[[:space:]]*/, "", value); print "VHOST_DOCUMENT_ROOT=" value}' "$file"
  fi
done
REMOTE

# Public HTTP checks complement the server-side paths above and never submit
# forms or follow redirects to a different host.
for path in \
  /wp-content/themes/delnavazan-theme/style.css \
  /wp-content/themes/delnavazan-theme/assets/css/theme.css \
  /wp-content/themes/delnavazan-theme/assets/css/booking.css \
  /wp-content/themes/delnavazan-theme/assets/js/navigation.js \
  /wp-content/themes/delnavazan-theme/assets/js/booking.js; do
  result="$(curl --silent --show-error --max-time 20 --proto '=https' --max-redirs 0 --output /dev/null --write-out '%{http_code}|%{content_type}' "https://delnavazan.com$path")" || fail "public request failed: $path"
  printf 'PUBLIC_ASSET path=%s status_content_type=%s\n' "$path" "$result"
done
