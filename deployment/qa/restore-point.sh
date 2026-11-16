#!/usr/bin/env bash
# One-step production rollback: restore a verified rollback point taken by
# deployment/qa/backup-point.sh.
#
# Runs on the production host as the restricted DirectAdmin account:
#   ssh "$PRODUCTION_SSH_USER@cp119.hosting-cloud.net" 'bash -s' -- --confirm RESTORE \
#     < deployment/qa/restore-point.sh
# or, with the script copied to the host:
#   bash ~/bin/dzn-restore-point.sh --point 20261116T031700Z --confirm RESTORE
#
# Order of work, and why:
#   1. verify the manifest, both checksum sidecars, gzip/tar integrity and the
#      schema pairing before anything is touched (a corrupt point never opens a
#      maintenance window);
#   2. detect an existing maintenance window and preserve it, otherwise enable
#      one, so no Student/Teacher/provider write lands mid-restore;
#   3. export the CURRENT database as a verified pre-restore safety copy;
#   4. restore the database FIRST. If the import fails or the restored schema
#      does not match the manifest, the live application files are still the
#      previous ones, so the failure is a database-only failure with a known
#      safety copy;
#   5. only then replace the files, atomically, keeping the previous tree beside
#      the live one for the operator;
#   6. flush caches, prove the restored code identity, run a read-only static
#      HTTP probe (which still answers 200 while maintenance mode is on because
#      the web server serves it without PHP), deactivate maintenance, then run a
#      read-only homepage smoke.
#
# Maintenance mode answers every anonymous PHP request with HTTP 503, so a
# homepage "HTTP 200, no auth" check can only run after maintenance is
# deactivated. If that smoke fails, maintenance is re-activated immediately and
# the run fails closed: the site is never left live on an unverified restore.
#
# Fail-closed: the exact `--confirm RESTORE` token is mandatory, a missing or
# corrupt archive aborts before anything changes, and an older or newer database
# schema than the deployed code expects is refused unless --force is given.
#
# Environment (values only, never secrets):
#   DZN_BACKUP_ROOT         rollback-point root
#                           (default $HOME/backups/delnavazan-rollback-points)
#   DZN_RESTORE_SMOKE_URL   read-only smoke base URL (default https://delnavazan.com;
#                           override only for the documented disposable-stack drill)
#   DZN_BACKUP_WP_ROOT      WordPress root; a non-canonical root also needs
#                           --allow-alt-root and is refused otherwise
set -Eeuo pipefail

readonly ROOT_REL='domains/delnavazan.com/public_html'
readonly DZN_RESTORE_KEEP=2
readonly CONFIRM_VALUE='RESTORE'
readonly POINT_RE='^[0-9]{8}T[0-9]{6}Z$'
readonly BUILD_RE='^main-[0-9a-f]{12}$'
readonly SCHEMA_RE='^[0-9]{1,6}$'
readonly SHA_RE='^[0-9a-f]{64}$'
readonly STATIC_PROBE_FALLBACKS='assets/css/theme.css style.css'
readonly DB_FILE='database.sql.gz'
readonly FILES_FILE='public_html.tar.gz'

DZN_ROOT="${DZN_BACKUP_WP_ROOT:-$HOME/$ROOT_REL}"
DZN_BACKUP_ROOT="${DZN_BACKUP_ROOT:-$HOME/backups/delnavazan-rollback-points}"
DZN_SMOKE_URL="${DZN_RESTORE_SMOKE_URL:-https://delnavazan.com}"
DZN_SMOKE_URL="${DZN_SMOKE_URL%/}"

fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
warn(){ printf 'WARN  %s\n' "$1" >&2; }

usage(){
  cat >&2 <<'EOF'
usage: bash deployment/qa/restore-point.sh [--point <UTC-point>] [--force]
                                           [--dry-run] [--allow-alt-root]
                                           --confirm RESTORE

  --point <UTC-point>  rollback point to restore (default: newest complete point)
  --force              accept a database schema that differs from the deployed code
  --dry-run            verify everything and print the plan; change nothing
  --allow-alt-root     permit a non-canonical DZN_BACKUP_WP_ROOT (drill only)
  --confirm RESTORE    mandatory exact confirmation token
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

# Print one tab-separated manifest record. Exit 2 with a short reason on any
# structural problem so the shell can fail closed without printing PII.
read_manifest(){
  php -r '
    $file = (string) ($argv[1] ?? "");
    $raw = is_file($file) ? (string) file_get_contents($file) : "";
    $m = "" === $raw ? null : json_decode($raw, true);
    if (!is_array($m)) { fwrite(STDERR, "manifest_unreadable\n"); exit(2); }
    foreach (["format","point","created_utc","build_id","schema_version"] as $key) {
        if (!isset($m[$key]) || !is_scalar($m[$key])) { fwrite(STDERR, "manifest_missing_$key\n"); exit(2); }
    }
    foreach (["database","files"] as $group) {
        if (!isset($m[$group]) || !is_array($m[$group])) { fwrite(STDERR, "manifest_missing_$group\n"); exit(2); }
        foreach (["file","sha256","bytes"] as $key) {
            if (!isset($m[$group][$key]) || !is_scalar($m[$group][$key])) { fwrite(STDERR, "manifest_missing_{$group}_$key\n"); exit(2); }
        }
    }
    printf("%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n",
        $m["format"], $m["point"], $m["created_utc"], $m["build_id"], $m["schema_version"],
        $m["database"]["file"], $m["database"]["sha256"], $m["database"]["bytes"],
        $m["files"]["file"], $m["files"]["sha256"], $m["files"]["bytes"]);
  ' "$1"
}

require_sha(){
  local file="$1" expected="$2" actual
  actual="$(sha256_of "$file")" || fail 'no SHA-256 tool is available'
  [[ "$actual" == "$expected" ]] || fail "SHA-256 mismatch for $(basename "$file")"
}

verify_sidecar(){
  local dir="$1" name="$2" expected="$3"
  local sidecar="$dir/$name.sha256" recorded='' sidecar_sha=''
  [[ -s "$sidecar" ]] || fail "checksum sidecar is missing: $name.sha256"
  read -r sidecar_sha recorded _ < "$sidecar" || true
  [[ "$recorded" == "$name" ]] || fail "checksum sidecar does not name $name"
  [[ "$sidecar_sha" == "$expected" ]] || fail "checksum sidecar disagrees with the manifest for $name"
}

http_code(){
  local url="$1" code
  code="$(curl -sS --max-time 25 -o /dev/null -w '%{http_code}' \
    -A 'Mozilla/5.0 (compatible; DznRollbackSmoke/1.0)' \
    -H 'Accept: text/html,application/xhtml+xml' \
    -H 'Cache-Control: no-cache' \
    "$url" 2>/dev/null || true)"
  printf '%s' "$code"
}

smoke_expect_200(){
  local label="$1" url="$2" attempt=1 code=''
  while (( attempt <= 3 )); do
    code="$(http_code "$url")"
    if [[ "$code" == '200' ]]; then
      printf 'SMOKE ok  %s 200\n' "$label"
      return 0
    fi
    attempt=$((attempt + 1))
    if (( attempt <= 3 )); then sleep 2; fi
  done
  printf 'SMOKE fail %s got %s for %s\n' "$label" "${code:-none}" "$url" >&2
  return 1
}

dzn_prune_files(){
  local dir="$1" keep="$2"
  local tmp name n=0
  [[ -d "$dir" ]] || return 0
  [[ "$keep" =~ ^[0-9]+$ ]] || return 1
  tmp="$(mktemp -d)" || return 1
  : > "$tmp/all"
  if ! ( cd "$dir" && ls -1 ) > "$tmp/list"; then rm -rf "$tmp"; return 1; fi
  while IFS= read -r name; do
    [[ -f "$dir/$name" ]] || continue
    [[ "$name" =~ ^[0-9]{8}T[0-9]{6}Z(-[0-9]+)?\.sql\.gz$ ]] || continue
    printf '%s\n' "$name" >> "$tmp/all"
  done < "$tmp/list"
  LC_ALL=C sort -r -o "$tmp/all" "$tmp/all"
  while IFS= read -r name; do
    [[ -n "$name" ]] || continue
    n=$((n + 1))
    if (( n <= keep )); then continue; fi
    rm -f -- "$dir/$name" "$dir/$name.sha256"
  done < "$tmp/all"
  rm -rf "$tmp"
}

take_pre_restore_export(){
  local dir="$DZN_BACKUP_ROOT/pre-restore" name="$DZN_STAMP"
  local target raw sha bytes
  mkdir -p "$dir" 2>/dev/null || fail 'could not create the pre-restore export directory'
  chmod 700 "$dir" 2>/dev/null || true
  if [[ -e "$dir/$name.sql.gz" ]]; then name="$DZN_STAMP-$$"; fi
  target="$dir/$name.sql.gz"
  [[ ! -e "$target" ]] || fail 'refusing to overwrite an existing pre-restore export'
  raw="$dir/$name.sql"
  if ! wp --path="$DZN_ROOT" --skip-plugins --skip-themes db export "$raw" --add-drop-table >/dev/null; then
    fail 'pre-restore database export failed; nothing was restored'
  fi
  [[ -s "$raw" ]] || fail 'pre-restore database export is empty; nothing was restored'
  gzip -9 "$raw" || fail 'pre-restore database export could not be compressed'
  rm -f "$raw"
  gzip -t "$target" || fail 'pre-restore database export failed gzip integrity; nothing was restored'
  sha="$(sha256_of "$target")" || fail 'no SHA-256 tool is available'
  bytes="$(file_bytes "$target")"
  (( bytes >= 1024 )) || fail "pre-restore database export is implausibly small ($bytes bytes); nothing was restored"
  chmod 600 "$target"
  printf '%s  %s\n' "$sha" "$name.sql.gz" > "$target.sha256"
  chmod 600 "$target.sha256"
  dzn_prune_files "$dir" "$DZN_RESTORE_KEEP" || warn 'pre-restore export retention failed'
  printf 'PRE_RESTORE_EXPORT=%s sha256=%s bytes=%s\n' "$target" "$sha" "$bytes"
}

restore_files(){
  local archive="$1" base parent stage prev
  base="$(basename "$DZN_ROOT")"
  parent="$(dirname "$DZN_ROOT")"
  stage="$parent/.dzn-restore-$DZN_STAMP"
  prev="$DZN_ROOT.dzn-pre-restore-$DZN_STAMP"
  [[ ! -e "$stage" ]] || fail "refusing: restore staging path already exists: $stage"
  [[ ! -e "$prev" ]] || fail "refusing: previous-tree path already exists: $prev"
  mkdir -m 700 "$stage" || fail 'could not create the restore staging directory'
  if ! tar -C "$stage" -xzf "$archive"; then
    rm -rf "$stage"
    fail 'application-file extraction failed; the live tree is untouched'
  fi
  if [[ ! -f "$stage/$base/wp-config.php" || ! -f "$stage/$base/wp-load.php" ]]; then
    rm -rf "$stage"
    fail "application-file archive does not contain a complete $base tree"
  fi
  DZN_LIVE_TREE_MOVED=1
  if ! mv "$DZN_ROOT" "$prev"; then
    DZN_LIVE_TREE_MOVED=0
    rm -rf "$stage"
    fail 'could not move the live tree aside'
  fi
  if ! mv "$stage/$base" "$DZN_ROOT"; then
    fail 'could not move the restored tree into place; the previous tree is preserved'
  fi
  DZN_LIVE_TREE_MOVED=0
  rmdir "$stage" 2>/dev/null || true
  chmod 755 "$DZN_ROOT" 2>/dev/null || true
  printf 'FILES=restored previous=%s\n' "$prev"
}

main(){
  local point_name='latest' force=0 dry_run=0 allow_alt=0 confirm=''
  local point_dir='' manifest_line='' file='' name='' static_rel=''
  local m_format m_point m_created m_build m_schema m_dbfile m_dbsha m_dbbytes m_filesfile m_filessha m_filesbytes
  local deployed_schema build_sha env_type live_build restored_schema db_bytes files_bytes

  while (( $# )); do
    case "$1" in
      --point|-point)
        [[ $# -ge 2 ]] || usage
        point_name="$2"; shift 2 ;;
      --force) force=1; shift ;;
      --dry-run) dry_run=1; shift ;;
      --allow-alt-root) allow_alt=1; shift ;;
      --confirm)
        [[ $# -ge 2 ]] || usage
        confirm="$2"; shift 2 ;;
      -h|--help) usage ;;
      *) usage ;;
    esac
  done
  [[ "$confirm" == "$CONFIRM_VALUE" ]] || fail "refusing: restore requires the exact token --confirm $CONFIRM_VALUE"

  command -v wp >/dev/null 2>&1 || fail 'WP-CLI is required on the production host'
  command -v gzip >/dev/null 2>&1 || fail 'gzip is required'
  command -v tar >/dev/null 2>&1 || fail 'tar is required'
  command -v curl >/dev/null 2>&1 || fail 'curl is required for the read-only smoke'
  command -v php >/dev/null 2>&1 || fail 'php is required to read the rollback-point manifest'
  [[ -d "$DZN_ROOT" ]] || fail 'expected WordPress root is unavailable'
  DZN_ROOT="$(cd "$DZN_ROOT" && pwd -P)"
  [[ -f "$DZN_ROOT/wp-load.php" && -f "$DZN_ROOT/wp-config.php" ]] || fail 'expected WordPress root is unavailable'
  if [[ "$DZN_ROOT" != "$(dzn_canonical "$HOME/$ROOT_REL")" ]]; then
    (( allow_alt )) || fail "refusing: $DZN_ROOT is not the canonical production root; pass --allow-alt-root for a drill copy"
    warn "restoring a non-canonical root: $DZN_ROOT"
  fi
  [[ "$DZN_BACKUP_ROOT" == /* ]] || fail 'DZN_BACKUP_ROOT must be an absolute path'
  [[ -d "$DZN_BACKUP_ROOT/points" ]] || fail "no rollback points under $DZN_BACKUP_ROOT/points"

  if [[ "$point_name" == 'latest' ]]; then
    point_list="$(mktemp)" || fail 'temporary file is unavailable'
    if ! ( cd "$DZN_BACKUP_ROOT/points" && ls -1 | LC_ALL=C sort -r ) > "$point_list"; then
      rm -f "$point_list"
      fail 'rollback point listing failed'
    fi
    while IFS= read -r name; do
      [[ -n "$name" ]] || continue
      [[ -d "$DZN_BACKUP_ROOT/points/$name" ]] || continue
      [[ "$name" =~ $POINT_RE ]] || continue
      if [[ -f "$DZN_BACKUP_ROOT/points/$name/manifest.json" && -f "$DZN_BACKUP_ROOT/points/$name/$DB_FILE" && -f "$DZN_BACKUP_ROOT/points/$name/$FILES_FILE" ]]; then
        point_dir="$DZN_BACKUP_ROOT/points/$name"
        break
      fi
    done < "$point_list"
    rm -f "$point_list"
    [[ -n "$point_dir" ]] || fail 'no complete rollback point is available'
    point_name="$(basename "$point_dir")"
  else
    [[ "$point_name" =~ $POINT_RE ]] || fail "refusing: '$point_name' is not a UTC rollback-point name"
    point_dir="$DZN_BACKUP_ROOT/points/$point_name"
    [[ -d "$point_dir" ]] || fail "rollback point is missing: $point_name"
  fi

  [[ -s "$point_dir/manifest.json" ]] || fail "rollback point manifest is missing: $point_name"
  manifest_line="$(read_manifest "$point_dir/manifest.json")" \
    || fail "rollback point manifest is unreadable or incomplete: $point_name"
  IFS=$'\t' read -r m_format m_point m_created m_build m_schema m_dbfile m_dbsha m_dbbytes m_filesfile m_filessha m_filesbytes <<< "$manifest_line"
  [[ "$m_format" == '1' ]] || fail "unsupported rollback-point manifest format: $m_format"
  [[ "$m_point" == "$point_name" ]] || fail "manifest point '$m_point' does not match directory '$point_name'"
  [[ "$m_build" =~ $BUILD_RE ]] || fail "manifest build identity '$m_build' is not main-<12 hex>"
  [[ "$m_schema" =~ $SCHEMA_RE ]] || fail "manifest schema version '$m_schema' is not numeric"
  [[ "$m_dbfile" == "$DB_FILE" && "$m_filesfile" == "$FILES_FILE" ]] || fail 'manifest archive names are not the expected rollback-point files'
  [[ "$m_dbsha" =~ $SHA_RE && "$m_filessha" =~ $SHA_RE ]] || fail 'manifest checksums are malformed'
  [[ "$m_dbbytes" =~ ^[0-9]+$ && "$m_filesbytes" =~ ^[0-9]+$ ]] || fail 'manifest archive sizes are malformed'
  build_sha="${m_build#main-}"

  for file in "$DB_FILE" "$FILES_FILE"; do
    [[ -s "$point_dir/$file" ]] || fail "rollback point archive is missing: $file"
  done
  verify_sidecar "$point_dir" "$DB_FILE" "$m_dbsha"
  verify_sidecar "$point_dir" "$FILES_FILE" "$m_filessha"
  require_sha "$point_dir/$DB_FILE" "$m_dbsha"
  require_sha "$point_dir/$FILES_FILE" "$m_filessha"
  db_bytes="$(file_bytes "$point_dir/$DB_FILE")"
  files_bytes="$(file_bytes "$point_dir/$FILES_FILE")"
  [[ "$db_bytes" == "$m_dbbytes" ]] || fail 'database archive size does not match the manifest'
  [[ "$files_bytes" == "$m_filesbytes" ]] || fail 'file archive size does not match the manifest'
  gzip -t "$point_dir/$DB_FILE" || fail 'database archive failed gzip integrity'
  gzip -t "$point_dir/$FILES_FILE" || fail 'file archive failed gzip integrity'
  if ! gzip -dc "$point_dir/$DB_FILE" | grep -c 'CREATE TABLE' >/dev/null; then
    fail 'database archive contains no CREATE TABLE statement'
  fi
  tar -tzf "$point_dir/$FILES_FILE" > /dev/null || fail 'file archive failed tar integrity'
  printf 'VERIFY=ok point=%s created=%s build=%s schema=%s db_bytes=%s files_bytes=%s\n' \
    "$point_name" "$m_created" "$m_build" "$m_schema" "$db_bytes" "$files_bytes"

  env_type="$(wp --path="$DZN_ROOT" --skip-plugins --skip-themes eval 'echo function_exists("wp_get_environment_type") ? wp_get_environment_type() : "unknown";')"
  [[ "$env_type" == 'production' ]] || fail "refusing: wp_get_environment_type() is '$env_type', not 'production'"

  deployed_schema="$(wp --path="$DZN_ROOT" eval 'echo defined("DZN_PLATFORM_SCHEMA_VERSION") ? (string) DZN_PLATFORM_SCHEMA_VERSION : "";' 2>/dev/null || true)"
  [[ "$deployed_schema" =~ $SCHEMA_RE ]] || deployed_schema=''
  if [[ -z "$deployed_schema" ]]; then
    (( force )) || fail "refusing: the deployed Platform schema constant is unreadable, so the rollback point cannot be paired with the live code. Point $point_name was taken from build $m_build (schema $m_schema). Redeploy that build, or pass --force to restore the pair anyway."
    warn 'deployed schema constant is unreadable; continuing because --force was given'
  elif (( 10#$m_schema < 10#$deployed_schema )); then
    (( force )) || fail "refusing: rollback point schema $m_schema is OLDER than the deployed code schema $deployed_schema. Point $point_name was taken from build $m_build; redeploy the main commit whose SHA starts with $build_sha first, or pass --force to accept the older database."
  elif (( 10#$m_schema > 10#$deployed_schema )); then
    (( force )) || fail "refusing: rollback point schema $m_schema is NEWER than the deployed code schema $deployed_schema. Restore the matching build $m_build, or pass --force."
  fi

  local maintenance_active=0
  if wp --path="$DZN_ROOT" --skip-plugins --skip-themes maintenance-mode is-active >/dev/null 2>&1; then
    maintenance_active=1
    printf 'MAINTENANCE=preexisting (preserved until the restore verifies)\n'
  else
    printf 'MAINTENANCE=inactive (will be enabled for the restore)\n'
  fi

  if (( dry_run )); then
    printf 'DRY_RUN=ok point=%s build=%s schema=%s deployed_schema=%s\n' \
      "$point_name" "$m_build" "$m_schema" "${deployed_schema:-unknown}"
    printf 'DRY_RUN plan: enable maintenance -> pre-restore database export -> import %s -> replace files from %s -> cache flush -> static probe -> deactivate maintenance -> homepage smoke\n' "$DB_FILE" "$FILES_FILE"
    return 0
  fi

  DZN_STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
  DZN_LIVE_TREE_MOVED=0

  on_exit(){
    local rc=$?
    trap - EXIT
    trap '' HUP INT TERM
    if (( rc != 0 )); then
      if (( DZN_LIVE_TREE_MOVED == 1 )); then
        local prev="$DZN_ROOT.dzn-pre-restore-$DZN_STAMP"
        if [[ -e "$prev" && ! -e "$DZN_ROOT" ]]; then
          mv "$prev" "$DZN_ROOT" && printf 'RECOVERED live tree from %s\n' "$prev" >&2
        fi
      fi
      if wp --path="$DZN_ROOT" --skip-plugins --skip-themes maintenance-mode is-active >/dev/null 2>&1; then
        printf 'FAIL  maintenance mode remains active; production is closed until an operator verifies it\n' >&2
      else
        wp --path="$DZN_ROOT" --skip-plugins --skip-themes maintenance-mode activate >/dev/null 2>&1 || true
        printf 'FAIL  maintenance mode re-activated after the failed restore\n' >&2
      fi
    fi
    exit "$rc"
  }
  trap on_exit EXIT

  if (( maintenance_active == 0 )); then
    wp --path="$DZN_ROOT" --skip-plugins --skip-themes maintenance-mode activate >/dev/null || fail 'could not enable maintenance mode; nothing was restored'
    maintenance_active=1
  fi
  printf 'MAINTENANCE=active\n'

  take_pre_restore_export

  if ! gzip -dc "$point_dir/$DB_FILE" | wp --path="$DZN_ROOT" --skip-plugins --skip-themes db import - >/dev/null; then
    fail 'database import failed; application files were not replaced and maintenance mode remains active'
  fi
  restored_schema="$(wp --path="$DZN_ROOT" --skip-plugins --skip-themes eval 'echo (string) get_option("dzn_platform_schema_version", "");')"
  [[ "$restored_schema" == "$m_schema" ]] || fail "restored database schema '$restored_schema' does not match the rollback point schema '$m_schema'; application files were not replaced and maintenance mode remains active"
  if ! wp --path="$DZN_ROOT" --skip-plugins --skip-themes core is-installed >/dev/null 2>&1; then
    fail 'restored database is not a readable WordPress installation; application files were not replaced and maintenance mode remains active'
  fi
  printf 'DATABASE=restored schema=%s\n' "$restored_schema"

  restore_files "$point_dir/$FILES_FILE"

  # The probe path comes from the restored tree: a broken deployed tree must not
  # be able to block the recovery that replaces it.
  static_rel=''
  for file in $STATIC_PROBE_FALLBACKS; do
    if [[ -f "$DZN_ROOT/wp-content/themes/delnavazan-theme/$file" ]]; then
      static_rel="/wp-content/themes/delnavazan-theme/$file"
      break
    fi
  done
  [[ -n "$static_rel" ]] || fail 'the restored tree is missing the Delnavazan theme, so the static probe cannot run'

  wp --path="$DZN_ROOT" --skip-plugins --skip-themes cache flush >/dev/null 2>&1 \
    || warn 'cache flush reported a problem; continuing with the verified restore'

  live_build="$(wp --path="$DZN_ROOT" eval 'echo defined("DZN_PLATFORM_BUILD_ID") ? (string) DZN_PLATFORM_BUILD_ID : "";' 2>/dev/null || true)"
  [[ "$live_build" == "$m_build" ]] || fail "restored application files report build '$live_build', expected '$m_build'; maintenance mode remains active"
  printf 'BUILD=restored %s\n' "$live_build"

  smoke_expect_200 'static-asset' "$DZN_SMOKE_URL$static_rel" \
    || fail 'static HTTP probe failed while maintenance mode was active; maintenance stays on and the homepage was never verified'

  wp --path="$DZN_ROOT" --skip-plugins --skip-themes maintenance-mode deactivate >/dev/null || fail 'could not deactivate maintenance mode after a verified restore'
  maintenance_active=0
  printf 'MAINTENANCE=inactive\n'

  if ! smoke_expect_200 'homepage' "$DZN_SMOKE_URL/"; then
    wp --path="$DZN_ROOT" --skip-plugins --skip-themes maintenance-mode activate >/dev/null 2>&1 || true
    maintenance_active=1
    fail 'post-restore homepage smoke failed; maintenance mode re-activated and the site is closed'
  fi

  printf 'RESTORE=ok point=%s build=%s schema=%s previous_tree=%s\n' \
    "$point_name" "$m_build" "$m_schema" "$DZN_ROOT.dzn-pre-restore-$DZN_STAMP"
  printf 'NEXT=confirm the site by hand, then delete %s when satisfied\n' \
    "$DZN_ROOT.dzn-pre-restore-$DZN_STAMP"
  printf '%s restore=ok point=%s build=%s schema=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$point_name" "$m_build" "$m_schema" \
    >> "$DZN_BACKUP_ROOT/restore.log" 2>/dev/null || true
}

if [[ "${BASH_SOURCE[0]:-}" == "$0" || -z "${BASH_SOURCE[0]:-}" ]]; then
  main "$@"
fi
