#!/usr/bin/env bash
set -Eeuo pipefail

# DirectAdmin SSH must be enabled for the restricted staging account before
# this workflow can run. The destination and WordPress paths are fixed here.
readonly STAGING_HOST="staging.delnavazan.com"
readonly STAGING_ROOT="domains/staging.delnavazan.com/public_html"
readonly SHA_RE='^[0-9a-f]{40}$'

fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
[[ $# == 2 ]] || fail 'usage: deployment/staging-deploy.sh staging <full-40-character-main-sha>'
[[ $1 == staging ]] || fail 'target must be exactly staging'
[[ $2 =~ $SHA_RE ]] || fail 'source must be one full Git commit SHA'
: "${STAGING_SSH_USER:?Set the restricted DirectAdmin staging SSH user}"
: "${STAGING_BACKUP_DIR:?Set an absolute private backup directory outside public_html}"
[[ "$STAGING_SSH_USER" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'SSH username contains unsupported characters'
[[ "$STAGING_BACKUP_DIR" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail 'backup directory must be an absolute safe path'

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
git fetch --no-tags origin main
[[ "$(git rev-parse origin/main)" == "$2" ]] || fail 'origin/main moved while the deployment package was being prepared'

readonly ssh_target="${STAGING_SSH_USER}@${STAGING_HOST}"
readonly upload=".dzn-staging-deploy-${2}.tar.gz"
scp -q "$scratch/deploy.tar.gz" "$ssh_target:$upload"
ssh -T "$ssh_target" bash -s -- "$2" "$version" "$build" "$schema" "$STAGING_ROOT" "$STAGING_BACKUP_DIR" "$upload" "$archive_sha" "$declared_build" "$theme_sha" <<'REMOTE'
set -Eeuo pipefail
sha="$1"; version="$2"; build="$3"; expected_schema="$4"; root_rel="$5"; backup_root="$6"; upload="$7"; expected_archive_sha="$8"; declared_build="$9"; expected_theme_sha="${10}"
[[ "$sha" =~ ^[0-9a-f]{40}$ && "$root_rel" == 'domains/staging.delnavazan.com/public_html' && "$expected_archive_sha" =~ ^[0-9a-f]{64}$ && "$expected_theme_sha" =~ ^[0-9a-f]{64}$ ]] || { echo 'FAIL invalid deployment identity or target' >&2; exit 1; }
[[ "$backup_root" == /* && "$backup_root" =~ ^/[A-Za-z0-9._/-]+$ ]] || { echo 'FAIL backup path must be absolute and contain only safe path characters' >&2; exit 1; }
root="$HOME/$root_rel"; plugin="$root/wp-content/plugins/delnavazan-platform"; theme="$root/wp-content/themes/delnavazan-theme"
[[ -f "$root/wp-load.php" && -f "$plugin/delnavazan-platform.php" && -f "$theme/style.css" ]] || { echo 'FAIL staging WordPress/plugin/theme preflight failed' >&2; exit 1; }
command -v wp >/dev/null && command -v tar >/dev/null && command -v realpath >/dev/null || { echo 'FAIL WP-CLI, tar, and realpath are required' >&2; exit 1; }
root_real="$(realpath -m "$root")"; backup_real="$(realpath -m "$backup_root")"
[[ "$backup_real" != "$root_real" && "$backup_real" != "$root_real"/* ]] || { echo 'FAIL backup path resolves inside staging public_html' >&2; exit 1; }
wp --path="$root" --skip-plugins --skip-themes core is-installed >/dev/null
live_plugin="$(cat "$plugin/delnavazan-platform.php")"
live_version="$(sed -n "s/.*DZN_PLATFORM_VERSION', '\([^']*\)'.*/\1/p" <<<"$live_plugin")"
live_build="$(sed -n "s/.*DZN_PLATFORM_BUILD_ID', '\([^']*\)'.*/\1/p" <<<"$live_plugin")"
[[ -n "$live_version" && -n "$live_build" ]] || { echo 'FAIL current Platform identity is unavailable' >&2; exit 1; }
before="$(wp --path="$root" --skip-plugins --skip-themes eval 'echo json_encode(["schema" => (string) get_option("dzn_platform_schema_version", "not-installed"), "plugin_active" => in_array("delnavazan-platform/delnavazan-platform.php", (array) get_option("active_plugins", []), true), "stylesheet" => get_option("stylesheet")]);')"
[[ "$(php -r '$s=json_decode($argv[1],true); echo ($s["plugin_active"]??false)?"active":"inactive";' "$before")" == active ]] || { echo 'FAIL Platform plugin is not active' >&2; exit 1; }
[[ "$(php -r '$s=json_decode($argv[1],true); echo $s["stylesheet"]??"";' "$before")" == delnavazan-theme ]] || { echo 'FAIL active theme is not delnavazan-theme' >&2; exit 1; }
[[ -f "$upload" && "$(sha256sum "$upload" | awk '{print $1}')" == "$expected_archive_sha" ]] || { echo 'FAIL uploaded package checksum mismatch' >&2; exit 1; }
install -d -m 700 "$backup_root"
stamp="$(date -u +%Y%m%dT%H%M%SZ)-$$"; backup="$backup_root/${stamp}-${sha}"
umask 077; mkdir -m 700 "$backup"
before="$(php -r '$s=json_decode($argv[1],true); $s["version"]=$argv[2]; $s["build"]=$argv[3]; echo json_encode($s, JSON_UNESCAPED_SLASHES);' "$before" "$live_version" "$live_build")"
printf '%s\n' "$before" > "$backup/before.json"
tar -C "$root/wp-content" -czf "$backup/application-files.tar.gz" plugins/delnavazan-platform themes/delnavazan-theme
wp --path="$root" --skip-plugins --skip-themes db export "$backup/database.sql" --add-drop-table >/dev/null
printf '%s\n' "{\"target\":\"https://staging.delnavazan.com\",\"sha\":\"$sha\",\"platform_version\":\"$version\",\"platform_build\":\"$build\",\"declared_source_build\":\"$declared_build\",\"expected_schema\":\"$expected_schema\",\"archive_sha256\":\"$expected_archive_sha\",\"theme_style_sha256\":\"$expected_theme_sha\",\"backup\":\"$backup\",\"status\":\"preflight_complete\"}" > "$backup/deployment.json"

maintenance=0
restore_files(){
  rm -rf "$plugin" "$theme"
  install -d "$(dirname "$plugin")" "$(dirname "$theme")"
  tar -C "$root/wp-content" -xzf "$backup/application-files.tar.gz"
}
wp --path="$root" maintenance-mode activate >/dev/null; maintenance=1
if ! { rm -rf "$plugin" "$theme" && tar -xzf "$upload" -C "$root"; }; then
  restore_files || true
  echo "FAIL package extraction failed; file backup: $backup" >&2
  exit 1
fi
rm -f "$upload"

# Force WordPress through the Platform's canonical Migrator. This call is
# idempotent and fails the deployment if migration or its verifier throws.
if ! wp --path="$root" eval '\Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();'; then
  restore_files || true
  printf '%s\n' "{\"status\":\"migration_failed\",\"sha\":\"$sha\",\"backup\":\"$backup\",\"database_restore\":\"manual operator decision required\"}" > "$backup/failure.json"
  echo "FAIL migration failed; maintenance mode remains on; app files restored; DB backup and state: $backup" >&2
  exit 1
fi

after="$(wp --path="$root" eval 'echo json_encode(["schema" => (string) get_option("dzn_platform_schema_version", "not-installed"), "version" => DZN_PLATFORM_VERSION, "build" => DZN_PLATFORM_BUILD_ID, "plugin_active" => in_array("delnavazan-platform/delnavazan-platform.php", (array) get_option("active_plugins", []), true), "stylesheet" => get_option("stylesheet")]);')"
installed_theme_sha="$(sha256sum "$theme/style.css" | awk '{print $1}')"
if ! php -r '$s=json_decode($argv[1],true); exit(($s["schema"]??"")===$argv[2]&&($s["version"]??"")===$argv[3]&&($s["build"]??"")===$argv[4]&&($s["plugin_active"]??false)===true&&($s["stylesheet"]??"")==="delnavazan-theme"?0:1);' "$after" "$expected_schema" "$version" "$build" || [[ "$installed_theme_sha" != "$expected_theme_sha" ]]; then
  restore_files || true
  printf '%s\n' "{\"status\":\"identity_verification_failed\",\"sha\":\"$sha\",\"before\":$before,\"after\":$after,\"backup\":\"$backup\"}" > "$backup/failure.json"
  echo "FAIL post-deploy identity/schema verification failed; maintenance mode remains on; file backup: $backup" >&2
  exit 1
fi
printf '%s\n' "{\"target\":\"https://staging.delnavazan.com\",\"sha\":\"$sha\",\"archive_sha256\":\"$expected_archive_sha\",\"theme_style_sha256\":\"$expected_theme_sha\",\"declared_source_build\":\"$declared_build\",\"before\":$before,\"after\":$after,\"backup\":\"$backup\",\"status\":\"deployed\"}" > "$backup/deployment.json"
wp --path="$root" maintenance-mode deactivate >/dev/null; maintenance=0
cat "$backup/deployment.json"
REMOTE
