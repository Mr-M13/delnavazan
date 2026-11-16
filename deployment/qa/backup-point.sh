#!/usr/bin/env bash
# Take one production rollback point: a verified database export, a verified
# archive of the live WordPress tree, and a manifest that binds both to the
# deployed build identity and the database schema version.
#
# Owner decision (2026-11-16): two rollback points are enough; older points are
# deleted. Change DZN_BACKUP_KEEP below to keep more.
#
# Runs on the production host as the restricted DirectAdmin account, from host
# cron or piped over SSH:
#   ssh "$PRODUCTION_SSH_USER@cp119.hosting-cloud.net" 'bash -s' < deployment/qa/backup-point.sh
#
# Fail-closed: it refuses outside a production WordPress environment, without
# WP-CLI, without a deployed main-<12 SHA> build identity, and whenever either
# archive fails gzip/tar/SHA-256 verification. A failed run leaves no point.
#
# Off-site copy is optional. Set DZN_BACKUP_REMOTE to an rclone directory on an
# encrypted `crypt` remote (see docs/BACKUP-ROLLBACK-RUNBOOK.md). Unset means the
# point stays local and the run prints OFFSITE=skipped. Set means the new point
# is pushed, verified remotely, and the remote is pruned to the same newest
# DZN_BACKUP_KEEP points. A configured-but-failing push is reported as
# OFFSITE=failed and exits non-zero, because a silent off-site gap defeats the
# purpose of the copy; the local point is still valid and is named in the output.
#
# The archives contain wp-config.php and therefore live database credentials and
# salts. The backup root is created 0700 and every file 0600; never place it
# under public_html and never point DZN_BACKUP_REMOTE at an unencrypted remote.
#
# Environment (values only, never secrets):
#   DZN_BACKUP_ROOT     local rollback-point root
#                       (default $HOME/backups/delnavazan-rollback-points)
#   DZN_BACKUP_REMOTE   optional rclone target directory (default unset)
#   DZN_BACKUP_WP_ROOT  WordPress root for a rehearsal copy
#                       (default $HOME/domains/delnavazan.com/public_html)
set -Eeuo pipefail

readonly ROOT_REL='domains/delnavazan.com/public_html'
readonly DZN_BACKUP_KEEP=2
readonly POINT_RE='^[0-9]{8}T[0-9]{6}Z$'
readonly BUILD_RE='^main-[0-9a-f]{12}$'
readonly SCHEMA_RE='^[0-9]{1,6}$'
readonly SHA_RE='^[0-9a-f]{64}$'
readonly DB_SANITY_BYTES=1024

DZN_ROOT="${DZN_BACKUP_WP_ROOT:-$HOME/$ROOT_REL}"
DZN_BACKUP_ROOT="${DZN_BACKUP_ROOT:-$HOME/backups/delnavazan-rollback-points}"
DZN_BACKUP_REMOTE="${DZN_BACKUP_REMOTE:-}"

fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
warn(){ printf 'WARN  %s\n' "$1" >&2; }

usage(){
  cat >&2 <<'EOF'
usage: bash deployment/qa/backup-point.sh

Takes one rollback point on the production host and prunes older points.
No arguments. Configuration is environment-only; see the file header.
EOF
  exit 64
}

sha256_of(){
  local file="$1"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$file" | awk '{print $1}'
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$file" | awk '{print $1}'
  else
    return 1
  fi
}

file_bytes(){ wc -c < "$1" | tr -d ' '; }

dzn_canonical(){
  ( cd "$1" 2>/dev/null && pwd -P ) || printf '%s' "$1"
}

# Print the path of $1 relative to $2 when $1 is inside $2, else nothing.
dzn_under(){
  local child="$1" parent="${2%/}"
  if [[ "$child" == "$parent"/* ]]; then
    printf '%s' "${child#"$parent"/}"
  fi
}

write_sidecar(){
  local dir="$1" name="$2" sha="$3"
  printf '%s  %s\n' "$sha" "$name" > "$dir/$name.sha256"
  chmod 600 "$dir/$name.sha256"
}

verify_archive(){
  local dir="$1" name="$2"
  local target="$dir/$name" sidecar="$dir/$name.sha256"
  local expected='' recorded='' actual
  [[ -s "$target" ]] || fail "archive is missing or empty: $name"
  [[ -s "$sidecar" ]] || fail "checksum sidecar is missing: $name.sha256"
  read -r expected recorded _ < "$sidecar" || true
  [[ "$recorded" == "$name" ]] || fail "checksum sidecar does not name $name"
  [[ "$expected" =~ $SHA_RE ]] || fail "checksum sidecar is malformed: $name.sha256"
  actual="$(sha256_of "$target")" || fail 'no SHA-256 tool is available'
  [[ "$actual" == "$expected" ]] || fail "SHA-256 mismatch for $name"
  gzip -t "$target" || fail "gzip integrity failed for $name"
  printf '%s' "$actual"
}

# Keep exactly the newest $2 complete points under $1. A directory is only a
# complete point when the manifest and both archives exist, so a point that is
# still being written can never evict a verified one.
dzn_prune_points(){
  local points="$1" keep="$2"
  local tmp name kept=0 pruned=0 kept_oldest=''
  [[ -d "$points" ]] || return 0
  [[ "$keep" =~ ^[0-9]+$ ]] || return 1
  tmp="$(mktemp -d)" || return 1
  : > "$tmp/all"
  : > "$tmp/complete"
  : > "$tmp/keep"
  while IFS= read -r name; do
    [[ -n "$name" ]] || continue
    [[ -d "$points/$name" ]] || continue
    [[ "$name" =~ $POINT_RE ]] || continue
    printf '%s\n' "$name" >> "$tmp/all"
    if [[ -f "$points/$name/manifest.json" && -f "$points/$name/database.sql.gz" && -f "$points/$name/public_html.tar.gz" ]]; then
      printf '%s\n' "$name" >> "$tmp/complete"
    fi
  done < <( cd "$points" && ls -1 )
  LC_ALL=C sort -r -o "$tmp/all" "$tmp/all"
  LC_ALL=C sort -r -o "$tmp/complete" "$tmp/complete"
  head -n "$keep" "$tmp/complete" > "$tmp/keep"
  kept="$(wc -l < "$tmp/keep" | tr -d ' ')"
  if [[ -s "$tmp/keep" ]]; then kept_oldest="$(tail -n 1 "$tmp/keep")"; fi
  while IFS= read -r name; do
    [[ -n "$name" ]] || continue
    if grep -qxF -- "$name" "$tmp/keep"; then continue; fi
    if ! grep -qxF -- "$name" "$tmp/complete"; then
      # Never delete a half-written point that is newer than the oldest kept one.
      if [[ -n "$kept_oldest" && "$name" > "$kept_oldest" ]]; then
        warn "incomplete rollback point left in place for inspection: $name"
        continue
      fi
    fi
    rm -rf -- "$points/$name" || return 1
    printf 'PRUNED %s\n' "$name"
    pruned=$((pruned + 1))
  done < "$tmp/all"
  rm -rf "$tmp"
  printf 'RETENTION kept=%s pruned=%s limit=%s\n' "$kept" "$pruned" "$keep"
}

push_offsite(){
  local point="$1" name="$2"
  local remote="${DZN_BACKUP_REMOTE%/}" listing tmp dir kept=0 pruned=0 entry
  if [[ -z "$remote" ]]; then
    printf 'OFFSITE=skipped (DZN_BACKUP_REMOTE unset; the local point is the recovery copy)\n'
    return 0
  fi
  command -v rclone >/dev/null 2>&1 || { printf 'OFFSITE=failed (rclone is not installed)\n' >&2; return 1; }
  if ! rclone copy "$point" "$remote/$name" >/dev/null; then
    printf 'OFFSITE=failed (upload of %s)\n' "$name" >&2
    return 1
  fi
  listing="$(rclone lsf "$remote/$name" 2>/dev/null || true)"
  for dir in manifest.json database.sql.gz public_html.tar.gz; do
    if ! printf '%s\n' "$listing" | grep -qxF -- "$dir"; then
      printf 'OFFSITE=failed (remote point is incomplete: %s)\n' "$dir" >&2
      return 1
    fi
  done
  tmp="$(mktemp -d)" || return 1
  : > "$tmp/remote"
  rclone lsf --dirs-only "$remote" 2>/dev/null | sed 's:/$::' | LC_ALL=C grep -E "$POINT_RE" | LC_ALL=C sort -r > "$tmp/remote" || true
  while IFS= read -r entry; do
    [[ -n "$entry" ]] || continue
    kept=$((kept + 1))
    if (( kept <= DZN_BACKUP_KEEP )); then continue; fi
    if ! rclone purge "$remote/$entry" >/dev/null 2>&1; then
      warn "could not prune remote point: $entry"
      continue
    fi
    printf 'PRUNED-REMOTE %s\n' "$entry"
    pruned=$((pruned + 1))
  done < "$tmp/remote"
  rm -rf "$tmp"
  printf 'OFFSITE=ok (remote=%s kept=%s pruned=%s)\n' "$remote" "$kept" "$pruned"
  return 0
}

main(){
  local arg env_type build schema stamp created point base parent excluded rel_backup
  local db_sha files_sha db_bytes files_bytes offsite='skipped' tar_args=()

  for arg in "$@"; do
    case "$arg" in
      -h|--help) usage ;;
      *) fail "unknown argument: $arg (this tool takes no arguments)" ;;
    esac
  done

  command -v wp >/dev/null 2>&1 || fail 'WP-CLI is required on the production host'
  command -v gzip >/dev/null 2>&1 || fail 'gzip is required'
  command -v tar >/dev/null 2>&1 || fail 'tar is required'
  [[ -d "$DZN_ROOT" ]] || fail 'expected WordPress root is unavailable'
  DZN_ROOT="$(cd "$DZN_ROOT" && pwd -P)"
  [[ -f "$DZN_ROOT/wp-load.php" && -f "$DZN_ROOT/wp-config.php" ]] || fail 'expected WordPress root is unavailable'
  if [[ "$DZN_ROOT" != "$(dzn_canonical "$HOME/$ROOT_REL")" ]]; then
    warn "taking a point for a non-canonical root: $DZN_ROOT"
  fi

  [[ "$DZN_BACKUP_ROOT" == /* ]] || fail 'DZN_BACKUP_ROOT must be an absolute path'
  [[ "$DZN_BACKUP_ROOT" != '/' ]] || fail 'DZN_BACKUP_ROOT must not be the filesystem root'
  mkdir -p "$DZN_BACKUP_ROOT/points" 2>/dev/null || true
  [[ -d "$DZN_BACKUP_ROOT/points" ]] || fail "backup root is unavailable: $DZN_BACKUP_ROOT"
  chmod 700 "$DZN_BACKUP_ROOT" "$DZN_BACKUP_ROOT/points" 2>/dev/null || true
  exec 9>"$DZN_BACKUP_ROOT/.backup-point.lock" || fail 'cannot open the run lock'
  if command -v flock >/dev/null 2>&1; then
    flock -n 9 || { printf 'SKIP  another rollback-point run holds the lock\n'; exit 0; }
  fi

  env_type="$(wp --path="$DZN_ROOT" --skip-plugins --skip-themes eval 'echo function_exists("wp_get_environment_type") ? wp_get_environment_type() : "unknown";')"
  [[ "$env_type" == 'production' ]] || fail "refusing: wp_get_environment_type() is '$env_type', not 'production'"

  build="$(wp --path="$DZN_ROOT" eval 'echo defined("DZN_PLATFORM_BUILD_ID") ? (string) DZN_PLATFORM_BUILD_ID : "";' 2>/dev/null || true)"
  [[ "$build" =~ $BUILD_RE ]] || fail "refusing: deployed build identity '$build' is not main-<12 hex>; deploy the current production build first"

  schema="$(wp --path="$DZN_ROOT" --skip-plugins --skip-themes eval 'echo (string) get_option("dzn_platform_schema_version", "");')"
  [[ "$schema" =~ $SCHEMA_RE ]] || fail "refusing: dzn_platform_schema_version is '$schema', not a numeric schema version"

  stamp="$(date -u +%Y%m%dT%H%M%SZ)"
  [[ "$stamp" =~ $POINT_RE ]] || fail 'could not derive a UTC point name'
  DZN_WORK="$DZN_BACKUP_ROOT/points/.work-$stamp-$$"
  point="$DZN_BACKUP_ROOT/points/$stamp"
  [[ ! -e "$point" ]] || fail "refusing to overwrite the existing rollback point $stamp"
  umask 077
  mkdir -p "$DZN_WORK" || fail 'could not create the rollback-point workspace'

  cleanup(){
    local rc=$?
    trap - EXIT
    if [[ -n "${DZN_WORK:-}" && -d "$DZN_WORK" ]]; then rm -rf "$DZN_WORK"; fi
    exit "$rc"
  }
  trap cleanup EXIT

  if ! wp --path="$DZN_ROOT" --skip-plugins --skip-themes db export "$DZN_WORK/database.sql" --add-drop-table >/dev/null; then
    fail 'database export failed'
  fi
  [[ -s "$DZN_WORK/database.sql" ]] || fail 'database export is empty'
  gzip -9 "$DZN_WORK/database.sql" || fail 'database export could not be compressed'
  rm -f "$DZN_WORK/database.sql"
  gzip -t "$DZN_WORK/database.sql.gz" || fail 'database export failed gzip integrity'
  db_sha="$(sha256_of "$DZN_WORK/database.sql.gz")" || fail 'no SHA-256 tool is available'
  write_sidecar "$DZN_WORK" 'database.sql.gz' "$db_sha"
  db_sha="$(verify_archive "$DZN_WORK" 'database.sql.gz')" || fail 'database export failed verification'
  db_bytes="$(file_bytes "$DZN_WORK/database.sql.gz")"
  if (( db_bytes < DB_SANITY_BYTES )); then
    fail "database export is implausibly small ($db_bytes bytes); stop and inspect"
  fi
  if ! gzip -dc "$DZN_WORK/database.sql.gz" | grep -c 'CREATE TABLE' >/dev/null; then
    fail 'database export contains no CREATE TABLE statement'
  fi

  base="$(basename "$DZN_ROOT")"
  parent="$(dirname "$DZN_ROOT")"
  tar_args=(-C "$parent" -czf "$DZN_WORK/public_html.tar.gz" "--exclude=$base/wp-content/cache")
  rel_backup="$(dzn_under "$DZN_BACKUP_ROOT" "$DZN_ROOT")"
  excluded="\"$base/wp-content/cache\""
  if [[ -n "$rel_backup" ]]; then
    tar_args+=("--exclude=$base/$rel_backup")
    excluded="$excluded,\"$base/$rel_backup\""
  fi
  tar_args+=("$base")
  tar "${tar_args[@]}" || fail 'file archive failed'
  gzip -t "$DZN_WORK/public_html.tar.gz" || fail 'file archive failed gzip integrity'
  files_sha="$(sha256_of "$DZN_WORK/public_html.tar.gz")" || fail 'no SHA-256 tool is available'
  write_sidecar "$DZN_WORK" 'public_html.tar.gz' "$files_sha"
  files_sha="$(verify_archive "$DZN_WORK" 'public_html.tar.gz')" || fail 'file archive failed verification'
  files_bytes="$(file_bytes "$DZN_WORK/public_html.tar.gz")"
  tar -tzf "$DZN_WORK/public_html.tar.gz" > "$DZN_WORK/files.list" || fail 'file archive failed to list'
  for required in "$base/wp-config.php" "$base/wp-load.php" "$base/wp-content/plugins/delnavazan-platform/delnavazan-platform.php"; do
    if ! grep -qxF -- "$required" "$DZN_WORK/files.list"; then
      fail "file archive is missing $required"
    fi
  done
  rm -f "$DZN_WORK/files.list"

  created="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  cat > "$DZN_WORK/manifest.json" <<JSON
{
  "tool": "deployment/qa/backup-point.sh",
  "format": 1,
  "point": "$stamp",
  "created_utc": "$created",
  "environment": "production",
  "build_id": "$build",
  "schema_version": "$schema",
  "retention_keep": $DZN_BACKUP_KEEP,
  "wordpress_root": "$base",
  "database": {"file": "database.sql.gz", "sha256": "$db_sha", "bytes": $db_bytes},
  "files": {"file": "public_html.tar.gz", "sha256": "$files_sha", "bytes": $files_bytes, "excluded": [$excluded]},
  "restore_tool": "deployment/qa/restore-point.sh"
}
JSON
  chmod 600 "$DZN_WORK/manifest.json" "$DZN_WORK/database.sql.gz" "$DZN_WORK/public_html.tar.gz" \
    "$DZN_WORK/database.sql.gz.sha256" "$DZN_WORK/public_html.tar.gz.sha256"
  mv "$DZN_WORK" "$point" || fail 'could not publish the rollback point'
  chmod 700 "$point"

  printf 'POINT=%s\n' "$point"
  printf 'BUILD=%s\n' "$build"
  printf 'SCHEMA=%s\n' "$schema"
  printf 'DB_SHA256=%s\n' "$db_sha"
  printf 'FILES_SHA256=%s\n' "$files_sha"
  printf 'KB=%s/%s\n' "$((db_bytes / 1024))" "$((files_bytes / 1024))"

  dzn_prune_points "$DZN_BACKUP_ROOT/points" "$DZN_BACKUP_KEEP" || warn 'retention pass failed; inspect the points directory'

  if push_offsite "$point" "$stamp"; then
    if [[ -n "$DZN_BACKUP_REMOTE" ]]; then offsite='ok'; fi
  else
    offsite='failed'
  fi
  printf 'OFFSITE=%s\n' "$offsite" | tee -a "$DZN_BACKUP_ROOT/backup-point.log"
  printf '%s point=%s build=%s schema=%s db_sha=%s files_sha=%s keep=%s offsite=%s\n' \
    "$created" "$stamp" "$build" "$schema" "$db_sha" "$files_sha" "$DZN_BACKUP_KEEP" "$offsite" \
    >> "$DZN_BACKUP_ROOT/backup-point.log"

  [[ "$offsite" != 'failed' ]] || fail "rollback point $stamp is valid locally but the off-site copy failed"
  printf 'ROLLBACK_POINT=ok\n'
}

if [[ "${BASH_SOURCE[0]:-}" == "$0" || -z "${BASH_SOURCE[0]:-}" ]]; then
  main "$@"
fi
