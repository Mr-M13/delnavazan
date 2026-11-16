#!/usr/bin/env bash
# Source contract for the production rollback-point tooling.
#
# Static, hermetic and offline: it never contacts production, never runs real
# WP-CLI, curl or rclone, and needs only bash, gzip, tar, awk, grep, php and
# coreutils. It asserts the fail-closed boundaries, exercises the retention
# function and prune to two points on a temporary directory, and drives
# deployment/qa/restore-point.sh end to end against a stubbed wp-cli and curl so
# the maintenance ordering, the database-before-files order and the refusal
# paths are proven, not just grepped.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
backup_sh="$ROOT/deployment/qa/backup-point.sh"
restore_sh="$ROOT/deployment/qa/restore-point.sh"
runbook="$ROOT/docs/BACKUP-ROLLBACK-RUNBOOK.md"
workflow="$ROOT/.github/workflows/pr-runtime-gate.yml"
agents="$ROOT/AGENTS.md"

fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
need(){ grep -Fq -- "$2" "$1" || fail "$(basename "$1") is missing required boundary: $2"; }
forbid(){ if grep -Fq -- "$2" "$1"; then fail "$(basename "$1") must not contain: $2"; fi; }
forbid_re(){ if grep -Eqi -- "$2" "$1"; then fail "$(basename "$1") must not contain: $2"; fi; }
pass(){ printf 'PASS  %s\n' "$1"; }

sha256_of(){
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | awk '{print $1}'
  else
    shasum -a 256 "$1" | awk '{print $1}'
  fi
}

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

# ---------------------------------------------------------------- syntax ----
bash -n "$backup_sh" || fail 'backup-point.sh is not valid bash'
bash -n "$restore_sh" || fail 'restore-point.sh is not valid bash'
for file in "$backup_sh" "$restore_sh"; do
  [[ "$(head -n 1 "$file")" == '#!/usr/bin/env bash' ]] || fail "$(basename "$file") must use the env bash shebang"
done
pass 'both scripts parse as bash'

# ------------------------------------------------------- fail-closed shape --
need "$backup_sh" 'DZN_BACKUP_KEEP=2'
need "$backup_sh" '--skip-plugins --skip-themes db export'
need "$backup_sh" '--add-drop-table'
need "$backup_sh" 'gzip -t'
need "$backup_sh" 'tar -tzf'
need "$backup_sh" 'sha256sum'
need "$backup_sh" 'wp-content/cache'
need "$backup_sh" "not 'production'"
need "$backup_sh" 'DZN_PLATFORM_BUILD_ID'
need "$backup_sh" 'dzn_platform_schema_version'
need "$backup_sh" 'dzn_prune_points'
need "$backup_sh" 'DZN_BACKUP_REMOTE'
need "$backup_sh" 'rclone copy'
need "$backup_sh" 'rclone purge'
need "$backup_sh" 'OFFSITE=skipped'
need "$backup_sh" 'chmod 700'
need "$backup_sh" 'chmod 600'
forbid "$backup_sh" 'wp db reset'
forbid "$backup_sh" 'DROP DATABASE'
forbid "$backup_sh" 'TRUNCATE'

need "$restore_sh" "CONFIRM_VALUE='RESTORE'"
need "$restore_sh" '--confirm'
need "$restore_sh" 'maintenance-mode activate'
need "$restore_sh" 'maintenance-mode deactivate'
need "$restore_sh" 'maintenance-mode is-active'
need "$restore_sh" 'db import -'
need "$restore_sh" 'gzip -t'
need "$restore_sh" 'sha256sum'
need "$restore_sh" 'dzn_platform_schema_version'
need "$restore_sh" 'OLDER than the deployed code schema'
need "$restore_sh" '--force'
need "$restore_sh" 'pre-restore'
need "$restore_sh" 'tar -C "$stage" -xzf'
need "$restore_sh" 'RESTORE=ok'
# Database restore must precede the file swap: the file swap is the only step
# that can lose the previous tree, so a failed import must never reach it.
db_line="$(grep -n 'db import -' "$restore_sh" | head -1 | cut -d: -f1)"
files_line="$(grep -n 'restore_files "\$point_dir' "$restore_sh" | head -1 | cut -d: -f1)"
[[ -n "$db_line" && -n "$files_line" ]] || fail 'restore order is not discoverable'
(( db_line < files_line )) || fail 'database restore must run before the file restore'
forbid "$restore_sh" 'wp db reset'
forbid "$restore_sh" 'DROP DATABASE'
forbid "$restore_sh" 'TRUNCATE'
forbid "$restore_sh" 'Authorization:'
forbid "$restore_sh" 'curl -u'
pass 'fail-closed boundaries and database-before-files order are present'

# ------------------------------------------------------------ secret scan --
for file in "$backup_sh" "$restore_sh"; do
  forbid_re "$file" '(password|passwd|api[_-]?key|secret)[[:space:]]*[:=][[:space:]]*[^[:space:]]'
  forbid_re "$file" 'BEGIN (RSA|OPENSSH|EC) PRIVATE KEY'
  forbid_re "$file" 'AKIA[0-9A-Z]{16}'
  forbid_re "$file" 'rclone\.conf'
  forbid "$file" 'PRODUCTION_SSH_PRIVATE_KEY'
done
pass 'no credentials, keys or rclone configuration are hardcoded'

# ------------------------------------------------------- documentation sync --
[[ -f "$runbook" ]] || fail 'docs/BACKUP-ROLLBACK-RUNBOOK.md is missing'
need "$runbook" 'DZN_BACKUP_REMOTE'
need "$runbook" 'crypt'
need "$runbook" 'rclone'
need "$runbook" '--confirm RESTORE'
need "$runbook" 'DZN_BACKUP_KEEP'
need "$runbook" 'drill'
need "$runbook" 'Non-goals'
need "$workflow" 'bash deployment/qa/backup-rollback-contract.sh'
need "$agents" 'deployment/qa/backup-rollback-contract.sh'
pass 'runbook, PR gate wiring and AGENTS.md agree on the tooling'

# --------------------------------------------------------- retention unit ---
points="$tmp/retention"
for stamp in 20260101T000000Z 20260102T000000Z 20260103T000000Z 20260104T000000Z 20260105T000000Z; do
  mkdir -p "$points/$stamp"
  : > "$points/$stamp/manifest.json"
  : > "$points/$stamp/database.sql.gz"
  : > "$points/$stamp/public_html.tar.gz"
done
mkdir -p "$points/not-a-point"
: > "$points/not-a-point/manifest.json"
# Newer than every complete point and still being written: it must not evict one.
mkdir -p "$points/20260106T000000Z"
: > "$points/20260106T000000Z/database.sql.gz"
( . "$backup_sh"; dzn_prune_points "$points" 2 ) > "$tmp/retention.out"
grep -q 'RETENTION kept=2 pruned=3 limit=2' "$tmp/retention.out" || fail 'retention did not report keeping exactly two points'
[[ -d "$points/20260105T000000Z" && -d "$points/20260104T000000Z" ]] || fail 'retention did not keep the two newest complete points'
[[ ! -d "$points/20260103T000000Z" && ! -d "$points/20260102T000000Z" && ! -d "$points/20260101T000000Z" ]] || fail 'retention did not delete older points'
[[ -d "$points/not-a-point" ]] || fail 'retention deleted a directory that is not a rollback point'
[[ -d "$points/20260106T000000Z" ]] || fail 'retention deleted a half-written point newer than the oldest kept point'
pass 'retention keeps exactly the newest two complete points'

# ------------------------------------------------------------- stubs -------
stub="$tmp/stub"
mkdir -p "$stub"
cat > "$stub/wp" <<'STUB'
#!/usr/bin/env bash
set -u
log="${DZN_STUB_LOG:?}"
state="${DZN_STUB_STATE:?}"
deployed_schema="${DZN_STUB_DEPLOYED_SCHEMA:-77}"
db_schema="${DZN_STUB_DB_SCHEMA:-77}"
build="${DZN_STUB_BUILD:-main-aaaaaaaaaaaa}"
env_type="${DZN_STUB_ENV:-production}"
cmd=""
for a in "$@"; do
  case "$a" in
    --path=*|--skip-plugins|--skip-themes|--add-drop-table) continue;;
  esac
  cmd="$cmd $a"
done
cmd="${cmd# }"
printf 'wp %s\n' "$cmd" >> "$log"
case "$cmd" in
  'maintenance-mode is-active')
    if [[ -f "$state/active" ]]; then exit 0; else exit 1; fi ;;
  'maintenance-mode activate') : > "$state/active"; printf 'Maintenance mode activated.\n'; exit 0 ;;
  'maintenance-mode deactivate') rm -f "$state/active"; printf 'Maintenance mode deactivated.\n'; exit 0 ;;
  'core is-installed') exit 0 ;;
  'cache flush') printf 'Success: The cache was flushed.\n'; exit 0 ;;
  'db export '*)
    out="${cmd#db export }"
    printf -- '-- stub dump\n' > "$out"
    printf 'CREATE TABLE dzn_stub (id int);\n' >> "$out"
    # Incompressible filler so the compressed export exercises the size floor.
    head -c 8192 /dev/urandom | base64 >> "$out"
    exit 0 ;;
  'db import '*)
    bytes="$(wc -c | tr -d ' ')"
    printf 'STUB_DB_IMPORTED bytes=%s\n' "$bytes" >> "$log"
    if [[ "${DZN_STUB_FAIL_IMPORT:-0}" == '1' ]]; then
      printf 'Error: stub database import failure\n' >&2
      exit 1
    fi
    printf 'Success: Import completed.\n'
    exit 0 ;;
  eval\ *)
    code="${cmd#eval }"
    case "$code" in
      *wp_get_environment_type*) printf '%s' "$env_type" ;;
      *DZN_PLATFORM_BUILD_ID*) printf '%s' "$build" ;;
      *DZN_PLATFORM_SCHEMA_VERSION*) printf '%s' "$deployed_schema" ;;
      *dzn_platform_schema_version*) printf '%s' "$db_schema" ;;
      *) printf '' ;;
    esac
    exit 0 ;;
  *)
    printf 'stub wp: unhandled command: %s\n' "$cmd" >&2
    exit 0 ;;
esac
STUB
cat > "$stub/curl" <<'STUB'
#!/usr/bin/env bash
set -u
url=""
for a in "$@"; do
  case "$a" in
    http://*|https://*) url="$a" ;;
  esac
done
printf 'curl %s\n' "$url" >> "${DZN_STUB_LOG:?}"
if [[ "$url" == */wp-content/themes/* ]]; then
  printf '%s' "${DZN_STUB_HTTP_STATIC:-200}"
else
  printf '%s' "${DZN_STUB_HTTP_HOME:-200}"
fi
exit 0
STUB
cat > "$stub/sleep" <<'STUB'
#!/usr/bin/env bash
exit 0
STUB
chmod +x "$stub/wp" "$stub/curl" "$stub/sleep"

fixture="$tmp/fixture"
mkdir -p "$fixture/public_html/wp-content/plugins/delnavazan-platform" \
  "$fixture/public_html/wp-content/themes/delnavazan-theme/assets/css" \
  "$fixture/public_html/wp-content/cache"
printf '<?php // contract fixture\n' > "$fixture/public_html/wp-config.php"
printf '<?php // contract fixture\n' > "$fixture/public_html/wp-load.php"
printf '<?php // contract fixture\n' > "$fixture/public_html/wp-content/plugins/delnavazan-platform/delnavazan-platform.php"
printf '/* contract fixture */\n' > "$fixture/public_html/wp-content/themes/delnavazan-theme/assets/css/theme.css"
printf 'cache-from-the-live-tree\n' > "$fixture/public_html/wp-content/cache/stale.txt"

# make_point <dir> <stamp> <build> <schema> <marker>
make_point(){
  local dir="$1" stamp="$2" build="$3" schema="$4" marker="$5"
  local sql="$tmp/point.sql" db_sha files_sha db_bytes files_bytes
  mkdir -p "$dir"
  {
    printf -- '-- stub rollback point %s\n' "$stamp"
    printf 'CREATE TABLE dzn_stub (id int);\n'
    head -c 8192 /dev/urandom | base64
  } > "$sql"
  gzip -9 -c "$sql" > "$dir/database.sql.gz"
  printf 'RESTORED_MARKER=%s\n' "$marker" > "$fixture/public_html/rollback-marker.txt"
  tar -C "$fixture" -czf "$dir/public_html.tar.gz" public_html
  db_sha="$(sha256_of "$dir/database.sql.gz")"
  db_bytes="$(wc -c < "$dir/database.sql.gz" | tr -d ' ')"
  files_sha="$(sha256_of "$dir/public_html.tar.gz")"
  files_bytes="$(wc -c < "$dir/public_html.tar.gz" | tr -d ' ')"
  printf '%s  database.sql.gz\n' "$db_sha" > "$dir/database.sql.gz.sha256"
  printf '%s  public_html.tar.gz\n' "$files_sha" > "$dir/public_html.tar.gz.sha256"
  cat > "$dir/manifest.json" <<JSON
{
  "tool": "deployment/qa/backup-point.sh",
  "format": 1,
  "point": "$stamp",
  "created_utc": "2026-01-01T00:00:00Z",
  "environment": "production",
  "build_id": "$build",
  "schema_version": "$schema",
  "retention_keep": 2,
  "wordpress_root": "public_html",
  "database": {"file": "database.sql.gz", "sha256": "$db_sha", "bytes": $db_bytes},
  "files": {"file": "public_html.tar.gz", "sha256": "$files_sha", "bytes": $files_bytes, "excluded": ["public_html/wp-content/cache"]},
  "restore_tool": "deployment/qa/restore-point.sh"
}
JSON
}

# fresh_home <name>: a home with one live WordPress tree and a point root.
fresh_home(){
  local name="$1"
  local home="$tmp/$name"
  mkdir -p "$home/domains/delnavazan.com/public_html/wp-content/plugins/delnavazan-platform" \
    "$home/domains/delnavazan.com/public_html/wp-content/themes/delnavazan-theme/assets/css" \
    "$home/backups/points" "$home/stub-state"
  printf '<?php // live fixture\n' > "$home/domains/delnavazan.com/public_html/wp-config.php"
  printf '<?php // live fixture\n' > "$home/domains/delnavazan.com/public_html/wp-load.php"
  printf '<?php // live fixture\n' > "$home/domains/delnavazan.com/public_html/wp-content/plugins/delnavazan-platform/delnavazan-platform.php"
  printf '/* live fixture */\n' > "$home/domains/delnavazan.com/public_html/wp-content/themes/delnavazan-theme/assets/css/theme.css"
  printf 'LIVE_MARKER=before-restore\n' > "$home/domains/delnavazan.com/public_html/live-marker.txt"
  : > "$home/stub.log"
  make_point "$home/backups/points/20260101T000000Z" 20260101T000000Z main-aaaaaaaaaaaa 77 restored-old
  printf '%s' "$home"
}

stub_env(){
  local home="$1"
  printf 'HOME=%s PATH=%s DZN_BACKUP_ROOT=%s DZN_STUB_LOG=%s DZN_STUB_STATE=%s DZN_STUB_BUILD=%s DZN_STUB_DEPLOYED_SCHEMA=%s DZN_STUB_DB_SCHEMA=%s DZN_STUB_ENV=%s' \
    "$home" "$stub:$PATH" "$home/backups" "$home/stub.log" "$home/stub-state" \
    "${STUB_BUILD:-main-aaaaaaaaaaaa}" "${STUB_DEPLOYED_SCHEMA:-77}" "${STUB_DB_SCHEMA:-77}" "${STUB_ENV:-production}"
}

run_restore(){
  local home="$1"; shift
  # shellcheck disable=SC2046
  env $(stub_env "$home") \
    DZN_BACKUP_WP_ROOT="${STUB_WP_ROOT:-$home/domains/delnavazan.com/public_html}" \
    DZN_STUB_HTTP_STATIC="${STUB_HTTP_STATIC:-200}" \
    DZN_STUB_HTTP_HOME="${STUB_HTTP_HOME:-200}" \
    DZN_STUB_FAIL_IMPORT="${STUB_FAIL_IMPORT:-0}" \
    bash "$restore_sh" "$@"
}

expect_refusal(){
  local label="$1" needle="$2"; shift 2
  local home out
  home="$(fresh_home "refuse-$label")"
  if out="$(run_restore "$home" "$@" 2>&1)"; then
    fail "restore should have refused: $label"
  fi
  case "$out" in
    *"$needle"*) : ;;
    *) fail "restore refusal $label did not mention '$needle'; got: $out" ;;
  esac
  if grep -q 'db import' "$home/stub.log"; then
    fail "restore refusal $label reached the database import"
  fi
  if grep -q 'maintenance-mode activate' "$home/stub.log"; then
    fail "restore refusal $label opened a maintenance window"
  fi
  pass "restore refuses $label"
}

# --------------------------------------------------------- backup run ------
bhome="$tmp/backup-home"
mkdir -p "$bhome/domains/delnavazan.com/public_html/wp-content/plugins/delnavazan-platform" \
  "$bhome/domains/delnavazan.com/public_html/wp-content/themes/delnavazan-theme/assets/css" \
  "$bhome/domains/delnavazan.com/public_html/wp-content/cache" "$bhome/stub-state"
printf '<?php // live fixture\n' > "$bhome/domains/delnavazan.com/public_html/wp-config.php"
printf '<?php // live fixture\n' > "$bhome/domains/delnavazan.com/public_html/wp-load.php"
printf '<?php // live fixture\n' > "$bhome/domains/delnavazan.com/public_html/wp-content/plugins/delnavazan-platform/delnavazan-platform.php"
printf '/* live fixture */\n' > "$bhome/domains/delnavazan.com/public_html/wp-content/themes/delnavazan-theme/assets/css/theme.css"
printf 'must-never-be-archived\n' > "$bhome/domains/delnavazan.com/public_html/wp-content/cache/stale.txt"
: > "$bhome/stub.log"

run_backup(){
  local home="$1"
  env HOME="$home" PATH="$stub:$PATH" DZN_BACKUP_ROOT="$home/backups" \
    DZN_STUB_LOG="$home/stub.log" DZN_STUB_STATE="$home/stub-state" \
    DZN_STUB_BUILD="${STUB_BUILD:-main-aaaaaaaaaaaa}" \
    DZN_STUB_DEPLOYED_SCHEMA="${STUB_DEPLOYED_SCHEMA:-77}" \
    DZN_STUB_DB_SCHEMA="${STUB_DB_SCHEMA:-77}" \
    DZN_STUB_ENV="${STUB_ENV:-production}" \
    bash "$backup_sh"
}

if ! backup_out="$(run_backup "$bhome" 2>&1)"; then
  fail "backup-point.sh failed on a stubbed production host: $backup_out"
fi
case "$backup_out" in
  *'ROLLBACK_POINT=ok'*) : ;;
  *) fail "backup run did not report ROLLBACK_POINT=ok: $backup_out" ;;
esac
case "$backup_out" in
  *'OFFSITE=skipped'*) : ;;
  *) fail "an unset DZN_BACKUP_REMOTE must be a documented skip: $backup_out" ;;
esac
backup_point="$(printf '%s\n' "$backup_out" | sed -n 's/^POINT=//p' | head -1)"
[[ -d "$backup_point" ]] || fail 'backup run did not create a rollback point'
[[ -s "$backup_point/manifest.json" ]] || fail 'backup point has no manifest'
grep -Fq '"build_id": "main-aaaaaaaaaaaa"' "$backup_point/manifest.json" || fail 'manifest does not record the build identity'
grep -Fq '"schema_version": "77"' "$backup_point/manifest.json" || fail 'manifest does not record the schema version'
grep -Fq '"point":' "$backup_point/manifest.json" || fail 'manifest does not record the point'
[[ "$(stat -c '%a' "$backup_point" 2>/dev/null || stat -f '%Lp' "$backup_point")" == '700' ]] || fail 'rollback point directory must be 0700'
if tar -tzf "$backup_point/public_html.tar.gz" | grep -q 'wp-content/cache'; then
  fail 'file archive must exclude wp-content/cache'
fi
db_sha="$(sha256_of "$backup_point/database.sql.gz")"
grep -Fq "$db_sha" "$backup_point/database.sql.gz.sha256" || fail 'database sidecar does not match the archive'
files_sha="$(sha256_of "$backup_point/public_html.tar.gz")"
grep -Fq "$files_sha" "$backup_point/public_html.tar.gz.sha256" || fail 'file sidecar does not match the archive'
gzip -t "$backup_point/database.sql.gz" || fail 'database archive is not valid gzip'
tar -tzf "$backup_point/public_html.tar.gz" >/dev/null || fail 'file archive is not a valid tar'
pass 'backup takes a verified point and documents the off-site skip'

# Second and third runs prove retention keeps the newest two.
sleep 1
run_backup "$bhome" >/dev/null 2>&1 || fail 'second backup run failed'
sleep 1
run_backup "$bhome" >/dev/null 2>&1 || fail 'third backup run failed'
point_count="$(cd "$bhome/backups/points" && ls -1 | grep -Ec '^[0-9]{8}T[0-9]{6}Z$')"
[[ "$point_count" == '2' ]] || fail "retention must keep exactly two points, found $point_count"
pass 'repeated backup runs keep exactly two points'

# Backup refusal paths through the stub.
refuse_backup(){
  local label="$1" needle="$2"
  local home out
  home="$tmp/backup-refuse-$label"
  mkdir -p "$home/stub-state"
  cp -R "$bhome/domains" "$home/domains"
  : > "$home/stub.log"
  if out="$(run_backup "$home" 2>&1)"; then
    fail "backup should have refused: $label"
  fi
  case "$out" in
    *"$needle"*) : ;;
    *) fail "backup refusal $label did not mention '$needle'; got: $out" ;;
  esac
  if [[ -d "$home/backups/points" ]] && [[ -n "$(ls -A "$home/backups/points" 2>/dev/null)" ]]; then
    fail "backup refusal $label still left a rollback point behind"
  fi
  pass "backup refuses $label"
}
( STUB_ENV=local
  refuse_backup 'non-production-environment' "not 'production'" )
( STUB_BUILD=dev
  refuse_backup 'no-deployed-build' 'deployed build identity' )
( STUB_DB_SCHEMA=not-a-schema
  refuse_backup 'no-schema-version' 'dzn_platform_schema_version' )

# -------------------------------------------------------- restore refusals --
expect_refusal 'missing-confirm-token' 'requires the exact token --confirm RESTORE' \
  --point 20260101T000000Z
expect_refusal 'wrong-confirm-token' 'requires the exact token --confirm RESTORE' \
  --point 20260101T000000Z --confirm restore
expect_refusal 'missing-point' 'rollback point is missing' \
  --point 20200101T000000Z --confirm RESTORE

# A root that is not the canonical production path is refused unless the
# operator explicitly asks for a drill copy.
alt_home="$(fresh_home 'alt-root')"
mkdir -p "$alt_home/elsewhere/public_html"
printf '<?php // alt fixture\n' > "$alt_home/elsewhere/public_html/wp-config.php"
printf '<?php // alt fixture\n' > "$alt_home/elsewhere/public_html/wp-load.php"
alt_rc=0
( STUB_WP_ROOT="$alt_home/elsewhere/public_html"
  run_restore "$alt_home" --point 20260101T000000Z --confirm RESTORE > "$tmp/alt-root.out" 2>&1 ) || alt_rc=$?
if (( alt_rc == 0 )); then
  fail 'restore accepted a non-canonical root without --allow-alt-root'
fi
grep -Fq 'is not the canonical production root' "$tmp/alt-root.out" \
  || fail "non-canonical root refusal did not name the guard: $(cat "$tmp/alt-root.out")"
if grep -q 'db import' "$alt_home/stub.log"; then
  fail 'non-canonical root refusal reached the database import'
fi
pass 'restore refuses a non-canonical root without --allow-alt-root'

# A corrupt archive must abort before anything is touched.
corrupt_home="$(fresh_home 'corrupt')"
printf 'X' >> "$corrupt_home/backups/points/20260101T000000Z/database.sql.gz"
if corrupt_out="$(run_restore "$corrupt_home" --point 20260101T000000Z --confirm RESTORE 2>&1)"; then
  fail 'restore accepted a corrupt database archive'
fi
case "$corrupt_out" in
  *'SHA-256 mismatch'*|*'size does not match'*) : ;;
  *) fail "corrupt archive refusal did not mention the checksum: $corrupt_out" ;;
esac
grep -Fq 'LIVE_MARKER=before-restore' "$corrupt_home/domains/delnavazan.com/public_html/live-marker.txt" \
  || fail 'corrupt archive refusal modified the live tree'
if grep -q 'db import' "$corrupt_home/stub.log"; then
  fail 'corrupt archive refusal reached the database import'
fi
[[ ! -f "$corrupt_home/stub-state/active" ]] || fail 'corrupt archive refusal enabled maintenance mode'
pass 'restore refuses a corrupt archive before touching the site'

# A rollback point older than the deployed code schema must refuse unless forced.
( STUB_DEPLOYED_SCHEMA=78
  expect_refusal 'older-schema' 'OLDER than the deployed code schema' \
    --point 20260101T000000Z --confirm RESTORE )

# ---------------------------------------------------------- restore dry run --
dry_home="$(fresh_home 'dry-run')"
if ! dry_out="$(run_restore "$dry_home" --point 20260101T000000Z --dry-run --confirm RESTORE 2>&1)"; then
  fail "restore --dry-run failed on a valid point: $dry_out"
fi
case "$dry_out" in
  *'DRY_RUN=ok'*) : ;;
  *) fail 'restore --dry-run did not report DRY_RUN=ok' ;;
esac
if grep -qE 'maintenance-mode (activate|deactivate)|db import|db export' "$dry_home/stub.log"; then
  fail 'restore --dry-run mutated state'
fi
grep -Fq 'LIVE_MARKER=before-restore' "$dry_home/domains/delnavazan.com/public_html/live-marker.txt" \
  || fail 'restore --dry-run modified the live tree'
pass 'restore --dry-run verifies without mutating anything'

# --------------------------------------------------------- restore success --
ok_home="$(fresh_home 'happy')"
if ! ok_out="$(run_restore "$ok_home" --point 20260101T000000Z --confirm RESTORE 2>&1)"; then
  fail "restore failed on a valid point: $ok_out"
fi
case "$ok_out" in
  *'RESTORE=ok'*) : ;;
  *) fail "restore did not report RESTORE=ok: $ok_out" ;;
esac
grep -Fq 'RESTORED_MARKER=restored-old' "$ok_home/domains/delnavazan.com/public_html/rollback-marker.txt" \
  || fail 'restore did not replace the live application files'
[[ ! -f "$ok_home/domains/delnavazan.com/public_html/live-marker.txt" ]] \
  || fail 'restore left a file that exists only in the newer live tree'
compgen -G "$ok_home/domains/delnavazan.com/public_html.dzn-pre-restore-*" >/dev/null \
  || fail 'restore did not keep the previous tree for the operator'
[[ -s "$ok_home/backups/pre-restore/20260101T000000Z.sql.gz" || -n "$(ls -1 "$ok_home/backups/pre-restore" 2>/dev/null)" ]] \
  || fail 'restore did not take a pre-restore database export'
[[ ! -f "$ok_home/stub-state/active" ]] || fail 'restore left maintenance mode enabled after a healthy smoke'
grep -q 'STUB_DB_IMPORTED' "$ok_home/stub.log" || fail 'restore never imported the database'

order(){
  grep -n "$1" "$ok_home/stub.log" | head -1 | cut -d: -f1
}
maintenance_line="$(order 'maintenance-mode activate')"
export_line="$(order 'wp db export')"
import_line="$(order 'db import')"
flush_line="$(order 'cache flush')"
deactivate_line="$(order 'maintenance-mode deactivate')"
home_smoke_line="$(grep -n 'curl https://delnavazan.com/$' "$ok_home/stub.log" | head -1 | cut -d: -f1)"
for name in maintenance_line export_line import_line flush_line deactivate_line home_smoke_line; do
  eval "value=\$$name"
  [[ -n "${value:-}" ]] || fail "restore log is missing the $name step"
done
(( maintenance_line < export_line )) || fail 'maintenance must be active before the pre-restore export'
(( export_line < import_line )) || fail 'the pre-restore export must precede the database import'
(( import_line < flush_line )) || fail 'the cache flush must follow the database and file restore'
(( flush_line < deactivate_line )) || fail 'maintenance must stay active until the restore is verified'
(( deactivate_line < home_smoke_line )) || fail 'the homepage smoke must run after maintenance is deactivated'
pass 'restore orders verification, maintenance, database-before-files, flush, deactivate and smoke'

# ----------------------------------------------- restore fails closed on smoke --
smoke_home="$(fresh_home 'smoke-fail')"
smoke_rc=0
( STUB_HTTP_HOME=500
  run_restore "$smoke_home" --point 20260101T000000Z --confirm RESTORE > "$tmp/smoke-fail.out" 2>&1 ) || smoke_rc=$?
if (( smoke_rc == 0 )); then
  fail 'restore accepted a failing homepage smoke'
fi
smoke_out="$(cat "$tmp/smoke-fail.out")"
case "$smoke_out" in
  *'maintenance mode re-activated'*|*'maintenance mode remains active'*) : ;;
  *) fail "smoke failure did not leave production closed: $smoke_out" ;;
esac
[[ -f "$smoke_home/stub-state/active" ]] || fail 'smoke failure must re-activate maintenance mode'
pass 'restore re-activates maintenance and fails closed on a failed smoke'

# --------------------------------------------- import failure keeps the files --
import_home="$(fresh_home 'import-fail')"
import_before="$(sha256_of "$import_home/domains/delnavazan.com/public_html/live-marker.txt")"
import_rc=0
( STUB_FAIL_IMPORT=1
  run_restore "$import_home" --point 20260101T000000Z --confirm RESTORE > "$tmp/import-fail.out" 2>&1 ) || import_rc=$?
if (( import_rc == 0 )); then
  fail 'restore accepted a failing database import'
fi
import_out="$(cat "$tmp/import-fail.out")"
case "$import_out" in
  *'database import failed'*) : ;;
  *) fail "import failure did not name the database import: $import_out" ;;
esac
[[ "$(sha256_of "$import_home/domains/delnavazan.com/public_html/live-marker.txt")" == "$import_before" ]] \
  || fail 'a failed database import must leave the application files untouched'
[[ -f "$import_home/stub-state/active" ]] || fail 'a failed database import must leave maintenance mode active'
pass 'a failed database import leaves the application files and maintenance untouched'

# Force accepts the schema mismatch but still restores.
force_home="$(fresh_home 'force')"
force_rc=0
( STUB_DEPLOYED_SCHEMA=78
  run_restore "$force_home" --point 20260101T000000Z --force --confirm RESTORE > "$tmp/force.out" 2>&1 ) || force_rc=$?
if (( force_rc != 0 )); then
  fail "restore --force did not accept the schema mismatch: $(cat "$tmp/force.out")"
fi
force_out="$(cat "$tmp/force.out")"
case "$force_out" in
  *'RESTORE=ok'*) : ;;
  *) fail 'restore --force did not complete' ;;
esac
pass 'restore --force accepts an older schema and still verifies the restore'

printf 'PASS production backup and rollback source contract\n'
