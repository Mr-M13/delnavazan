#!/usr/bin/env bash
# Production backup retention for the deployment backup directory.
#
# The encrypted rclone remote is the durable history. The host keeps only the
# newest deploy preflight directory so an in-flight release still has its
# immediate rollback; every older preflight directory is removed whole,
# including its database dump and manifest. The deployment record is preserved
# in the workflow artifact and run log.
#
# Dry-run is the default; pass --confirm to delete.
set -Eeuo pipefail

BACKUP_ROOT="${DZN_BACKUP_ROOT:-$HOME/backups/delnavazan-production-cutover}"
LOG="$BACKUP_ROOT/prune.log"
CONFIRM=0
KEEP=1
[[ "${1:-}" == '--confirm' ]] && CONFIRM=1

[[ -d "$BACKUP_ROOT" ]] || { echo "missing backup root: $BACKUP_ROOT" >&2; exit 1; }

exec 9>"$BACKUP_ROOT/.prune.lock"
if command -v flock >/dev/null 2>&1; then
  flock -n 9 || { echo 'another retention run is active' >&2; exit 0; }
fi

tmp="$(mktemp -d "$BACKUP_ROOT/.prune.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT

# Eligible deploy preflights are directories with a non-empty database dump and
# a manifest written by the deploy. Incomplete directories are never deleted.
find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | LC_ALL=C sort -r > "$tmp/all"
: > "$tmp/full"
while IFS= read -r name; do
  [[ -n "$name" ]] || continue
  dir="$BACKUP_ROOT/$name"
  [[ -s "$dir/database.sql" ]] || continue
  [[ -f "$dir/deployment.json" || -f "$dir/before.json" ]] || continue
  printf '%s\n' "$name" >> "$tmp/full"
done < "$tmp/all"

full_count="$(wc -l < "$tmp/full" | tr -d ' ')"
if (( full_count == 0 )); then
  echo "nothing to prune: no complete deploy preflight"
  exit 0
fi

head -n "$KEEP" "$tmp/full" > "$tmp/keep"

deleted=0
deleted_bytes=0
skipped=0
while IFS= read -r name; do
  [[ -n "$name" ]] || continue
  grep -qxF -- "$name" "$tmp/keep" && continue
  dir="$BACKUP_ROOT/$name"
  if [[ ! -s "$dir/database.sql" ]]; then
    printf 'SKIP %s: database.sql missing or empty\n' "$name" >> "$LOG"
    skipped=$((skipped + 1))
    continue
  fi
  size="$(du -sb "$dir" | cut -f1)"
  if (( CONFIRM )); then
    rm -rf -- "$dir"
    printf 'DELETED %s\n' "$name" >> "$LOG"
  else
    printf 'WOULD-DELETE %s (%s bytes)\n' "$name" "$size"
  fi
  deleted=$((deleted + 1))
  deleted_bytes=$((deleted_bytes + size))
done < "$tmp/full"

# The Softaculous cron creates a full WordPress archive each week. Keep the two
# newest complete archives and prune older ones.
softaculous="${DZN_SOFTACULOUS_DIR:-$HOME/softaculous_backups}"
soft_deleted=0
soft_deleted_bytes=0
if [[ -d "$softaculous" ]]; then
  find "$softaculous" -maxdepth 1 -type f -name '*.tar.gz' -printf '%T@ %p\n' > "$tmp/soft"
  sort -nr "$tmp/soft" -o "$tmp/soft"
  tail -n +3 "$tmp/soft" | cut -d' ' -f2- > "$tmp/soft-prune"
  while IFS= read -r file; do
    [[ -f "$file" ]] || continue
    size="$(stat -c %s "$file")"
    if (( CONFIRM )); then
      rm -f -- "$file"
      printf 'DELETED softaculous %s\n' "$(basename "$file")" >> "$LOG"
    else
      printf 'WOULD-DELETE softaculous %s\n' "$(basename "$file")"
    fi
    soft_deleted=$((soft_deleted + 1))
    soft_deleted_bytes=$((soft_deleted_bytes + size))
  done < "$tmp/soft-prune"
fi

printf '%s complete=%s kept=%s pruned=%s skipped=%s bytes=%s softaculous_pruned=%s softaculous_bytes=%s mode=%s\n' \
  "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  "$full_count" \
  "$(wc -l < "$tmp/keep" | tr -d ' ')" \
  "$deleted" \
  "$skipped" \
  "$deleted_bytes" \
  "$soft_deleted" \
  "$soft_deleted_bytes" \
  "$([[ $CONFIRM == 1 ]] && echo confirm || echo dry-run)" | tee -a "$LOG"
