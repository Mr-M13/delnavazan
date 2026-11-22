#!/usr/bin/env bash
# Production backup retention for the deployment backup directory.
#
# Host retention is deliberately minimal; the encrypted rclone remote is the
# durable history. Keep only the newest full application archive for the
# immediate rollback of the current release.
#
# Older application-files.tar.gz archives are deleted only when a non-empty
# database.sql exists beside them. database.sql, before.json and deployment.json
# are always retained. Dry-run is the default; pass --confirm to delete.
set -Eeuo pipefail

BACKUP_ROOT="${DZN_BACKUP_ROOT:-$HOME/backups/delnavazan-production-cutover}"
LOG="$BACKUP_ROOT/prune.log"
CONFIRM=0
[[ "${1:-}" == '--confirm' ]] && CONFIRM=1

[[ -d "$BACKUP_ROOT" ]] || { echo "missing backup root: $BACKUP_ROOT" >&2; exit 1; }

exec 9>"$BACKUP_ROOT/.prune.lock"
if command -v flock >/dev/null 2>&1; then
  flock -n 9 || { echo 'another retention run is active' >&2; exit 0; }
fi

tmp="$(mktemp -d "$BACKUP_ROOT/.prune.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT

find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r > "$tmp/all"
: > "$tmp/full"
while IFS= read -r name; do
  [[ -f "$BACKUP_ROOT/$name/application-files.tar.gz" ]] && printf '%s\n' "$name" >> "$tmp/full"
done < "$tmp/all"

full_count="$(wc -l < "$tmp/full" | tr -d ' ')"
if (( full_count < 1 )); then
  echo "refusing retention: only $full_count full backups" >&2
  exit 1
fi

head -n 1 "$tmp/full" > "$tmp/keep"

head -n 1 "$tmp/full" >> "$tmp/keep"
sort -u "$tmp/keep" -o "$tmp/keep"

deleted=0
deleted_bytes=0
skipped=0
while IFS= read -r name; do
  grep -qxF "$name" "$tmp/keep" && continue
  app="$BACKUP_ROOT/$name/application-files.tar.gz"
  db="$BACKUP_ROOT/$name/database.sql"
  if [[ ! -s "$db" ]]; then
    printf 'SKIP %s: database.sql missing or empty\n' "$name" >> "$LOG"
    skipped=$((skipped + 1))
    continue
  fi
  size="$(stat -c %s "$app")"
  if (( CONFIRM )); then
    rm -f -- "$app"
    printf 'DELETED %s application-files.tar.gz\n' "$name" >> "$LOG"
  else
    printf 'WOULD-DELETE %s application-files.tar.gz\n' "$name"
  fi
  deleted=$((deleted + 1))
  deleted_bytes=$((deleted_bytes + size))
done < "$tmp/full"

# The Softaculous cron creates a full WordPress archive each week. Keep the two
# newest complete archives and prune older ones; this prevents unbounded growth
# without changing the deployment backup retention above.
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

printf '%s full=%s kept=%s pruned=%s skipped=%s bytes=%s softaculous_pruned=%s softaculous_bytes=%s mode=%s\n' \
  "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  "$full_count" \
  "$(wc -l < "$tmp/keep" | tr -d ' ')" \
  "$deleted" \
  "$skipped" \
  "$deleted_bytes" \
  "$soft_deleted" \
  "$soft_deleted_bytes" \
  "$([[ $CONFIRM == 1 ]] && echo confirm || echo dry-run)" | tee -a "$LOG"
