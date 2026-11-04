#!/usr/bin/env bash
# Mount the Pi archive read-only for the playback gateway over Tailscale SSH.
set -Eeuo pipefail
remote="${DZN_ARCHIVE_SSH_USER:-delnavazan-archive}@${DZN_ARCHIVE_SSH_HOST:?set DZN_ARCHIVE_SSH_HOST}"
local="${DZN_ARCHIVE_ROOT:-/mnt/mbox/DelnavazanRecordings}"
key="${DZN_ARCHIVE_SSH_KEY:-/etc/delnavazan/keys/playback_ed25519}"
[[ $EUID -eq 0 ]] || { echo 'error: run as root' >&2; exit 1; }
mkdir -p "$local"
if mountpoint -q "$local"; then echo "already mounted: $local"; exit 0; fi
sshfs -o "IdentityFile=$key,IdentitiesOnly=yes,StrictHostKeyChecking=yes,BatchMode=yes,ro,reconnect,ServerAliveInterval=15,ServerAliveCountMax=3" "$remote:$local" "$local"
# The mount marker must be visible and non-empty; otherwise refuse to treat the mount as valid.
marker="${DZN_ARCHIVE_MOUNT_MARKER:-/mnt/mbox/.delnavazan-mount}"
[[ -f "$marker" ]] || { umount "$local"; echo 'error: archive mount marker absent' >&2; exit 1; }
echo "playback archive mounted read-only: $local"
