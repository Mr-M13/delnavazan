#!/usr/bin/env bash
set -Eeuo pipefail

# DirectAdmin SSH must be enabled for the restricted production account before
# this workflow can run. The destination and WordPress paths are fixed here.
readonly PRODUCTION_HOST="cp119.hosting-cloud.net"
readonly PRODUCTION_ROOT="domains/delnavazan.com/public_html"
readonly SHA_RE='^[0-9a-f]{40}$'

fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
[[ $# == 2 ]] || fail 'usage: deployment/production-deploy.sh production <full-40-character-main-sha>'
[[ $1 == production ]] || fail 'target must be exactly production'
[[ $2 =~ $SHA_RE ]] || fail 'source must be one full Git commit SHA'
: "${PRODUCTION_SSH_USER:?Set the restricted DirectAdmin production SSH user}"
: "${PRODUCTION_BACKUP_DIR:?Set an absolute private backup directory outside public_html}"
[[ "$PRODUCTION_SSH_USER" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'SSH username contains unsupported characters'
[[ "$PRODUCTION_BACKUP_DIR" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail 'backup directory must be an absolute safe path'

repo="$(git rev-parse --show-toplevel)"
cd "$repo"
git fetch --no-tags origin main
main_sha="$(git rev-parse origin/main)"
[[ "$2" == "$main_sha" ]] || fail "requested SHA is not current origin/main ($main_sha)"
git cat-file -e "$2^{commit}" 2>/dev/null || fail 'requested commit is unavailable locally'
[[ -z "$(git status --porcelain)" ]] || fail 'source checkout must be clean'

plugin_file="$(git show "$2:platform/delnavazan-platform.php")"
schema="$(sed -n "s/.*DZN_PLATFORM_SCHEMA_VERSION', '\([0-9][0-9]*\)'.*/\1/p" <<<"$plugin_file")"
version="$(sed -n "s/.*DZN_PLATFORM_VERSION', '\([^']*\)'.*/\1/p" <<<"$plugin_file")"
declared_build="$(sed -n "s/.*DZN_PLATFORM_BUILD_ID', '\([^']*\)'.*/\1/p" <<<"$plugin_file")"
build="main-${2:0:12}"
[[ -n "$schema" && -n "$version" && -n "$declared_build" ]] || fail 'Platform schema, version, or build identity is missing'
[[ "$version" =~ ^[0-9A-Za-z._-]+$ && "$build" =~ ^[0-9A-Za-z._-]+$ && "$declared_build" =~ ^[0-9A-Za-z._-]+$ ]] || fail 'Platform version/build identity contains unsupported characters'

scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT
mkdir -p "$scratch/wp-content/plugins/delnavazan-platform" "$scratch/wp-content/themes/delnavazan-theme"
git archive "$2" platform | tar -x -C "$scratch/wp-content/plugins/delnavazan-platform" --strip-components=1
git archive "$2" theme | tar -x -C "$scratch/wp-content/themes/delnavazan-theme" --strip-components=1
[[ -f "$scratch/wp-content/plugins/delnavazan-platform/delnavazan-platform.php" && -f "$scratch/wp-content/themes/delnavazan-theme/style.css" ]] || fail 'Platform/theme package is incomplete'
for asset in theme.css booking.css; do
  [[ -f "$scratch/wp-content/themes/delnavazan-theme/assets/css/$asset" ]] || fail "theme package is missing required assets/css/$asset"
done
for asset in navigation.js booking.js; do
  [[ -f "$scratch/wp-content/themes/delnavazan-theme/assets/js/$asset" ]] || fail "theme package is missing required assets/js/$asset"
done
python3 - "$scratch/wp-content/plugins/delnavazan-platform/delnavazan-platform.php" "$build" <<'PY'
import pathlib, re, sys
path = pathlib.Path(sys.argv[1])
source = path.read_text()
stamped, count = re.subn(r"define\(\s*'DZN_PLATFORM_BUILD_ID',\s*'[^']*'\s*\);", f"define( 'DZN_PLATFORM_BUILD_ID', '{sys.argv[2]}' );", source)
if count != 1:
    raise SystemExit('Expected exactly one Platform build identity to stamp')
path.write_text(stamped)
PY
tar -C "$scratch" -czf "$scratch/deploy.tar.gz" wp-content
archive_sha="$(sha256sum "$scratch/deploy.tar.gz" | awk '{print $1}')"
theme_sha="$(sha256sum "$scratch/wp-content/themes/delnavazan-theme/style.css" | awk '{print $1}')"
theme_css_sha="$(sha256sum "$scratch/wp-content/themes/delnavazan-theme/assets/css/theme.css" | awk '{print $1}')"
booking_css_sha="$(sha256sum "$scratch/wp-content/themes/delnavazan-theme/assets/css/booking.css" | awk '{print $1}')"
navigation_js_sha="$(sha256sum "$scratch/wp-content/themes/delnavazan-theme/assets/js/navigation.js" | awk '{print $1}')"
booking_js_sha="$(sha256sum "$scratch/wp-content/themes/delnavazan-theme/assets/js/booking.js" | awk '{print $1}')"
git fetch --no-tags origin main
[[ "$(git rev-parse origin/main)" == "$2" ]] || fail 'origin/main moved while the deployment package was being prepared'

readonly ssh_target="${PRODUCTION_SSH_USER}@${PRODUCTION_HOST}"
readonly upload=".dzn-production-deploy-${2}.tar.gz"
scp "$scratch/deploy.tar.gz" "$ssh_target:$upload"
ssh -T "$ssh_target" bash -s -- "$2" "$version" "$build" "$schema" "$PRODUCTION_ROOT" "$PRODUCTION_BACKUP_DIR" "$upload" "$archive_sha" "$declared_build" "$theme_sha" "$theme_css_sha" "$booking_css_sha" "$navigation_js_sha" "$booking_js_sha" <<'REMOTE'
set -Eeuo pipefail
sha="$1"; version="$2"; build="$3"; expected_schema="$4"; root_rel="$5"; backup_root="$6"; upload="$7"; expected_archive_sha="$8"; declared_build="$9"; expected_theme_sha="${10}"; expected_theme_css_sha="${11}"; expected_booking_css_sha="${12}"; expected_navigation_js_sha="${13}"; expected_booking_js_sha="${14}"
[[ "$sha" =~ ^[0-9a-f]{40}$ && "$root_rel" == 'domains/delnavazan.com/public_html' && "$expected_archive_sha" =~ ^[0-9a-f]{64}$ && "$expected_theme_sha" =~ ^[0-9a-f]{64}$ && "$expected_theme_css_sha" =~ ^[0-9a-f]{64}$ && "$expected_booking_css_sha" =~ ^[0-9a-f]{64}$ && "$expected_navigation_js_sha" =~ ^[0-9a-f]{64}$ && "$expected_booking_js_sha" =~ ^[0-9a-f]{64}$ ]] || { echo 'FAIL invalid deployment identity or target' >&2; exit 1; }
[[ "$backup_root" == /* && "$backup_root" =~ ^/[A-Za-z0-9._/-]+$ ]] || { echo 'FAIL backup path must be absolute and contain only safe path characters' >&2; exit 1; }
root="$HOME/$root_rel"; plugin="$root/wp-content/plugins/delnavazan-platform"; theme="$root/wp-content/themes/delnavazan-theme"
[[ -f "$root/wp-load.php" && -f "$plugin/delnavazan-platform.php" && -f "$theme/style.css" ]] || { echo 'FAIL production WordPress/plugin/theme preflight failed' >&2; exit 1; }
command -v wp >/dev/null && command -v tar >/dev/null && command -v realpath >/dev/null || { echo 'FAIL WP-CLI, tar, and realpath are required' >&2; exit 1; }
root_real="$(realpath -m "$root")"; backup_real="$(realpath -m "$backup_root")"
[[ "$backup_real" != "$root_real" && "$backup_real" != "$root_real"/* ]] || { echo 'FAIL backup path resolves inside production public_html' >&2; exit 1; }
wp --path="$root" --skip-plugins --skip-themes core is-installed >/dev/null
site_state="$(wp --path="$root" --skip-plugins --skip-themes eval 'echo json_encode(["home" => get_option("home"), "siteurl" => get_option("siteurl"), "blog_public" => (string) get_option("blog_public")]);')"
if ! php -r '$s=json_decode($argv[1],true); exit(($s["home"]??"")==="https://delnavazan.com"&&($s["siteurl"]??"")==="https://delnavazan.com"&&($s["blog_public"]??"")==="1"?0:1);' "$site_state"; then
  echo "FAIL production URL/indexability preflight failed: $site_state" >&2
  exit 1
fi
live_plugin="$(cat "$plugin/delnavazan-platform.php")"
live_version="$(sed -n "s/.*DZN_PLATFORM_VERSION', '\([^']*\)'.*/\1/p" <<<"$live_plugin")"
live_build="$(sed -n "s/.*DZN_PLATFORM_BUILD_ID', '\([^']*\)'.*/\1/p" <<<"$live_plugin")"
[[ -n "$live_version" && -n "$live_build" ]] || { echo 'FAIL current Platform identity is unavailable' >&2; exit 1; }
before="$(wp --path="$root" --skip-plugins --skip-themes eval 'echo json_encode(["schema" => (string) get_option("dzn_platform_schema_version", "not-installed"), "plugin_active" => in_array("delnavazan-platform/delnavazan-platform.php", (array) get_option("active_plugins", []), true), "stylesheet" => get_option("stylesheet")]);')"
[[ "$(php -r '$s=json_decode($argv[1],true); echo ($s["plugin_active"]??false)?"active":"inactive";' "$before")" == active ]] || { echo 'FAIL Platform plugin is not active' >&2; exit 1; }
[[ "$(php -r '$s=json_decode($argv[1],true); echo $s["stylesheet"]??"";' "$before")" == delnavazan-theme ]] || { echo 'FAIL active theme is not delnavazan-theme' >&2; exit 1; }
[[ -f "$upload" && "$(sha256sum "$upload" | awk '{print $1}')" == "$expected_archive_sha" ]] || { echo 'FAIL uploaded package checksum mismatch' >&2; exit 1; }
staged="$HOME/.dzn-production-preflight-${sha}-$$"
install -d -m 700 "$staged"
if ! tar -xzf "$upload" -C "$staged" || ! find "$staged" -type f -name '*.php' -print0 | xargs -0 -n 1 php -l >/dev/null; then
  rm -rf "$staged"
  echo 'FAIL uploaded package does not extract or contains PHP syntax errors on the production runtime' >&2
  exit 1
fi
staged_plugin="$staged/wp-content/plugins/delnavazan-platform"
staged_theme="$staged/wp-content/themes/delnavazan-theme"
[[ -f "$staged_plugin/delnavazan-platform.php" && -f "$staged_theme/style.css" && -f "$staged_theme/assets/css/theme.css" && -f "$staged_theme/assets/css/booking.css" && -f "$staged_theme/assets/js/navigation.js" && -f "$staged_theme/assets/js/booking.js" ]] || { rm -rf "$staged"; echo 'FAIL staged Platform/theme package is missing required files' >&2; exit 1; }
[[ "$(sha256sum "$staged_theme/assets/css/theme.css" | awk '{print $1}')" == "$expected_theme_css_sha" && "$(sha256sum "$staged_theme/assets/css/booking.css" | awk '{print $1}')" == "$expected_booking_css_sha" && "$(sha256sum "$staged_theme/assets/js/navigation.js" | awk '{print $1}')" == "$expected_navigation_js_sha" && "$(sha256sum "$staged_theme/assets/js/booking.js" | awk '{print $1}')" == "$expected_booking_js_sha" ]] || { rm -rf "$staged"; echo 'FAIL staged theme asset checksum mismatch' >&2; exit 1; }
plugin_device="$(stat -c '%d' "$(dirname "$plugin")")"
theme_device="$(stat -c '%d' "$(dirname "$theme")")"
stage_device="$(stat -c '%d' "$staged")"
[[ "$plugin_device" == "$theme_device" && "$plugin_device" == "$stage_device" ]] || { rm -rf "$staged"; echo 'FAIL staged package and live app paths must share a filesystem for atomic installation' >&2; exit 1; }
install -d -m 700 "$backup_root"
stamp="$(date -u +%Y%m%dT%H%M%SZ)-$$"; backup="$backup_root/${stamp}-${sha}"
umask 077; mkdir -m 700 "$backup"
before="$(php -r '$s=json_decode($argv[1],true); $s["version"]=$argv[2]; $s["build"]=$argv[3]; echo json_encode($s, JSON_UNESCAPED_SLASHES);' "$before" "$live_version" "$live_build")"
printf '%s\n' "$before" > "$backup/before.json"
tar -C "$root/wp-content" -czf "$backup/application-files.tar.gz" plugins/delnavazan-platform themes/delnavazan-theme
wp --path="$root" --skip-plugins --skip-themes db export "$backup/database.sql" --add-drop-table >/dev/null
printf '%s\n' "{\"target\":\"https://delnavazan.com\",\"sha\":\"$sha\",\"platform_version\":\"$version\",\"platform_build\":\"$build\",\"declared_source_build\":\"$declared_build\",\"expected_schema\":\"$expected_schema\",\"archive_sha256\":\"$expected_archive_sha\",\"theme_style_sha256\":\"$expected_theme_sha\",\"backup\":\"$backup\",\"status\":\"preflight_complete\"}" > "$backup/deployment.json"

maintenance=0
deployment_mutation_started=0
deployment_committed=0
plugin_previous="${plugin}.dzn-previous-${stamp}"
theme_previous="${theme}.dzn-previous-${stamp}"
restore_files(){
  local restore_failed=0
  if [[ -e "$plugin_previous" ]]; then
    rm -rf "$plugin" && mv "$plugin_previous" "$plugin" || restore_failed=1
  fi
  if [[ -e "$theme_previous" ]]; then
    rm -rf "$theme" && mv "$theme_previous" "$theme" || restore_failed=1
  fi
  if [[ ! -d "$plugin" || ! -d "$theme" ]]; then
    install -d "$(dirname "$plugin")" "$(dirname "$theme")"
    tar -C "$root/wp-content" -xzf "$backup/application-files.tar.gz" || restore_failed=1
  fi
  return "$restore_failed"
}
on_exit(){
  local result=$?
  trap - EXIT
  trap '' HUP INT TERM
  if [[ "$deployment_mutation_started" == 1 && "$deployment_committed" == 0 ]]; then
    if ! restore_files; then
      echo "FAIL automatic application-file rollback failed; file backup: $backup" >&2
    else
      echo "Application files restored after failed deployment; maintenance mode remains on; file backup: $backup" >&2
    fi
  fi
  rm -rf "$staged" || echo 'WARN could not remove staged package directory' >&2
  exit "$result"
}
trap on_exit EXIT
deployment_mutation_started=1
wp --path="$root" maintenance-mode activate >/dev/null; maintenance=1
if ! mv "$plugin" "$plugin_previous" || ! mv "$staged_plugin" "$plugin"; then
  echo "FAIL atomic Platform directory replacement failed; file backup: $backup" >&2
  exit 1
fi
if ! mv "$theme" "$theme_previous" || ! mv "$staged_theme" "$theme"; then
  echo "FAIL atomic theme directory replacement failed; file backup: $backup" >&2
  exit 1
fi
rm -f "$upload"

# Force WordPress through the Platform's canonical Migrator. This call is
# idempotent and fails the deployment if migration or its verifier throws.
if ! wp --path="$root" eval '\Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();'; then
  printf '%s\n' "{\"status\":\"migration_failed\",\"sha\":\"$sha\",\"backup\":\"$backup\",\"database_restore\":\"manual operator decision required\"}" > "$backup/failure.json"
  echo "FAIL migration failed; maintenance mode remains on; application-file rollback will run; DB backup and state: $backup" >&2
  exit 1
fi

after="$(wp --path="$root" eval 'echo json_encode(["schema" => (string) get_option("dzn_platform_schema_version", "not-installed"), "version" => DZN_PLATFORM_VERSION, "build" => DZN_PLATFORM_BUILD_ID, "plugin_active" => in_array("delnavazan-platform/delnavazan-platform.php", (array) get_option("active_plugins", []), true), "stylesheet" => get_option("stylesheet")]);')"
installed_theme_sha="$(sha256sum "$theme/style.css" | awk '{print $1}')"
installed_theme_css_sha="$(sha256sum "$theme/assets/css/theme.css" | awk '{print $1}')"
installed_booking_css_sha="$(sha256sum "$theme/assets/css/booking.css" | awk '{print $1}')"
installed_navigation_js_sha="$(sha256sum "$theme/assets/js/navigation.js" | awk '{print $1}')"
installed_booking_js_sha="$(sha256sum "$theme/assets/js/booking.js" | awk '{print $1}')"
if ! php -r '$s=json_decode($argv[1],true); exit(($s["schema"]??"")===$argv[2]&&($s["version"]??"")===$argv[3]&&($s["build"]??"")===$argv[4]&&($s["plugin_active"]??false)===true&&($s["stylesheet"]??"")==="delnavazan-theme"?0:1);' "$after" "$expected_schema" "$version" "$build" || [[ "$installed_theme_sha" != "$expected_theme_sha" || "$installed_theme_css_sha" != "$expected_theme_css_sha" || "$installed_booking_css_sha" != "$expected_booking_css_sha" || "$installed_navigation_js_sha" != "$expected_navigation_js_sha" || "$installed_booking_js_sha" != "$expected_booking_js_sha" ]]; then
  printf '%s\n' "{\"status\":\"identity_verification_failed\",\"sha\":\"$sha\",\"before\":$before,\"after\":$after,\"backup\":\"$backup\"}" > "$backup/failure.json"
  echo "FAIL post-deploy identity/schema verification failed; maintenance mode remains on; application-file rollback will run; file backup: $backup" >&2
  exit 1
fi
printf '%s\n' "{\"target\":\"https://delnavazan.com\",\"sha\":\"$sha\",\"archive_sha256\":\"$expected_archive_sha\",\"theme_style_sha256\":\"$expected_theme_sha\",\"declared_source_build\":\"$declared_build\",\"before\":$before,\"after\":$after,\"backup\":\"$backup\",\"status\":\"deployed\"}" > "$backup/deployment.json"
deployment_committed=1
wp --path="$root" maintenance-mode deactivate >/dev/null; maintenance=0
rm -rf "$plugin_previous" "$theme_previous" || echo 'WARN prior app directories remain beside the live paths' >&2
cat "$backup/deployment.json"
REMOTE
