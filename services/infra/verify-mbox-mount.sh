#!/usr/bin/env bash
# Verify the expected M-Box mount before any write or playback. Never fall back to the root FS.
set -Eeuo pipefail
root="${DZN_ARCHIVE_ROOT:-/mnt/mbox/DelnavazanRecordings}"
marker="${DZN_ARCHIVE_MOUNT_MARKER:-/mnt/mbox/.delnavazan-mount}"
fail(){ echo "mount verification failed: $1" >&2; exit 1; }
[[ -d /mnt/mbox ]] || fail '/mnt/mbox absent'
[[ -d "$root" ]] || fail 'archive root absent'
[[ -f "$marker" ]] || fail 'mount identity marker absent'
target="$(findmnt -n -o TARGET -T /mnt/mbox || true)"
source="$(findmnt -n -o SOURCE -T /mnt/mbox || true)"
[[ -n "$target" && "$target" != "/" ]] || fail '/mnt/mbox resolves to root filesystem'
[[ "$(stat -c %d / 2>/dev/null)" != "$(stat -c %d /mnt/mbox 2>/dev/null)" ]] || fail '/mnt/mbox is on the root device'
identity="$(head -n 1 "$marker")"
[[ -n "$identity" ]] || fail 'empty mount identity'
printf 'mounted=true\nsource=%s\ntarget=%s\nidentity=%s\nroot=%s\n' "$source" "$target" "$identity" "$root"
