#!/usr/bin/env bash
# Provision the restricted PixelBox archive account and dedicated archive root.
# Run only on PixelBox with an already-verified mounted filesystem.
set -Eeuo pipefail
account="${DZN_ARCHIVE_ACCOUNT:-delnavazan-archive}"
root="${DZN_ARCHIVE_ROOT:-/mnt/mbox/DelnavazanRecordings}"
marker="${DZN_ARCHIVE_MOUNT_MARKER:-/mnt/mbox/.delnavazan-mount}"
key_file="${DZN_ARCHIVE_AUTHORIZED_KEYS:-/home/${account}/.ssh/authorized_keys}"

[[ $EUID -eq 0 ]] || { echo 'error: run as root on PixelBox' >&2; exit 1; }
[[ "$root" == /mnt/mbox/* ]] || { echo 'error: archive root must be under /mnt/mbox' >&2; exit 1; }
[[ -d /mnt/mbox ]] || { echo 'error: /mnt/mbox is absent; refusing to create it on the root filesystem' >&2; exit 1; }
mount_source="$(findmnt -n -o SOURCE -T /mnt/mbox || true)"
mount_target="$(findmnt -n -o TARGET -T /mnt/mbox || true)"
root_device="$(stat -c %d / 2>/dev/null || true)"
mbox_device="$(stat -c %d /mnt/mbox 2>/dev/null || true)"
[[ -n "$mount_source" && -n "$mount_target" ]] || { echo 'error: /mnt/mbox is not a mounted filesystem' >&2; exit 1; }
[[ "$mount_target" != "/" ]] || { echo 'error: /mnt/mbox resolves to the PixelBox root filesystem' >&2; exit 1; }
[[ "$root_device" != "$mbox_device" ]] || { echo 'error: /mnt/mbox is on the root filesystem' >&2; exit 1; }

getent group "$account" >/dev/null || groupadd --system "$account"
id "$account" >/dev/null 2>&1 || useradd --system --gid "$account" --home-dir "/home/${account}" --shell /bin/bash --create-home "$account"
install -d -m 0700 -o "$account" -g "$account" "$root"
install -d -m 0700 -o "$account" -g "$account" "/home/${account}/.ssh"
findmnt -n -o SOURCE -T /mnt/mbox > "$marker.tmp"
printf '\n%s\n' "$(hostname -f 2>/dev/null || hostname)" >> "$marker.tmp"
fs_uuid="$(findmnt -n -o UUID -T /mnt/mbox || true)"
if [[ -n "$fs_uuid" ]]; then printf '%s\n' "$fs_uuid" >> "$marker.tmp"; fi
chown "$account:$account" "$marker.tmp" && chmod 0600 "$marker.tmp" && mv "$marker.tmp" "$marker"

# The archive account may use rsync only inside this root. Install rrsync when available.
if command -v rrsync >/dev/null 2>&1; then
  printf 'command="/usr/bin/rrsync -wo %s",restrict %s\n' "$root" "$(cat "${key_file}.pub" 2>/dev/null || echo SSH_PUBLIC_KEY_PLACEHOLDER)" >> "$key_file"
  chown "$account:$account" "$key_file"
  chmod 0600 "$key_file"
else
  echo 'warning: rrsync is not installed; install rsync and enable a restricted forced command before adding keys' >&2
fi

# Playback sees the same mount read-only through a separate sshfs identity/key managed by infra.
install -d -m 0700 -o "$account" -g "$account" /var/lib/delnavazan-archive-playback 2>/dev/null || true
echo "archive root ready: $root (source=$mount_source target=$mount_target marker=$marker)"
