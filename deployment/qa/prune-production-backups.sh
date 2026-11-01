#!/usr/bin/env bash
# Production backup retention for the deployment backup directory.
#
# Keep full application archives for:
#   - the four newest full backups;
#   - the newest deployed full backup for each of the current and two previous
#     release SHAs (current release plus known-good rollbacks);
#   - the newest full backup for each of the last 14 days;
#   - the newest full backup for each of the last 6 ISO weeks.
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
if (( full_count < 4 )); then
  echo "refusing retention: only $full_count full backups" >&2
  exit 1
fi

head -n 4 "$tmp/full" > "$tmp/keep"
cut -c1-8 "$tmp/full" | awk '!seen[$0]++' | head -n 14 > "$tmp/days"
while IFS= read -r day; do
  grep -m1 "^$day" "$tmp/full" >> "$tmp/keep" || true
done < "$tmp/days"

: > "$tmp/weeks"
week_count=0
while IFS= read -r name; do
  day="${name:0:8}"
  week="$(date -u -d "$day" +%G-%V 2>/dev/null || true)"
  [[ -n "$week" ]] || continue
  if ! grep -qxF "$week" "$tmp/weeks"; then
    (( week_count < 6 )) || continue
    printf '%s\n' "$week" >> "$tmp/weeks"
    printf '%s\n' "$name" >> "$tmp/keep"
    week_count=$((week_count + 1))
  fi
done < "$tmp/full"

# Multiple deployments of the same release can otherwise push the previous
# known-good backup out of the newest-four set. Keep the newest successfully
# deployed backup for each of the current and two previous release SHAs.
: > "$tmp/shas"
known_good=0
while IFS= read -r name; do
  json="$BACKUP_ROOT/$name/deployment.json"
  [[ -f "$json" ]] || continue
  grep -q '"status":"deployed"' "$json" || continue
  sha="$(grep -o '"sha":"[^"]*"' "$json" | head -1 | cut -d'"' -f4 || true)"
  [[ -n "$sha" ]] || continue
  if ! grep -qxF "$sha" "$tmp/shas"; then
    (( known_good < 3 )) || continue
    printf '%s\n' "$sha" >> "$tmp/shas"
    printf '%s\n' "$name" >> "$tmp/keep"
    known_good=$((known_good + 1))
  fi
done < "$tmp/full"

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
