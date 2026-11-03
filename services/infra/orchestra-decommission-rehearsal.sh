#!/usr/bin/env bash
# Read-only Orchestra inventory and reversible-decommission rehearsal.
# It never removes packages, data, credentials, timers, tunnels or tailnet devices.
set -Eeuo pipefail
mode="${1:-plan}"
out="${2:-}"
case "$mode" in inventory|plan|verify) ;; *) echo 'usage: orchestra-decommission-rehearsal.sh inventory|plan|verify [output]' >&2; exit 2 ;; esac

collect(){ printf '### %s\n' "$1"; shift; "$@" 2>&1 || true; printf '\n'; }
{
  printf '# Orchestra / shared-infrastructure decommission rehearsal (%s)\n' "$(date -u +%FT%TZ)"
  printf 'NO destructive action is performed by this script.\n\n'
  collect 'systemd units' bash -c "systemctl list-units --all --no-pager | grep -Ei 'orchestra|livekit|redis|caddy|backup|tailscale|pixelbox|mbox' || true"
  collect 'timers' bash -c "systemctl list-timers --all --no-pager | grep -Ei 'orchestra|backup|tailscale|livekit' || true"
  collect 'cron' bash -c "crontab -l 2>/dev/null || true; ls -la /etc/cron.d 2>/dev/null || true"
  collect 'listening ports' bash -c "ss -lntup 2>/dev/null || netstat -lntup 2>/dev/null || true"
  collect 'tailscale' bash -c "tailscale status 2>/dev/null || echo 'tailscale CLI unavailable'"
  collect 'mounts' bash -c "findmnt -R /mnt/mbox 2>/dev/null || mount | grep -Ei 'mbox|pixelbox|nfs|cifs|sshfs' || true"
  collect 'containers' bash -c "docker ps --format '{{.Names}} {{.Image}} {{.Ports}}' 2>/dev/null || true"
  collect 'credential references (paths only)' bash -c "find /etc /opt /srv -maxdepth 4 -type f \\( -iname '*orchestra*' -o -iname '*livekit*' -o -iname '*stripe*' -o -iname '*telnyx*' \\) 2>/dev/null | sed 's#^#path: #' || true"
  printf '## Shared-resource declaration\n'
  printf -- '- Tailscale: SHARED until a dedicated Delnavazan identity is proven.\n'
  printf -- '- Pi archive mount and backup storage: SHARED until mount ownership is proven.\n'
  printf -- '- Orchestra credentials: NOT revoked by this rehearsal.\n'
  printf -- '- Required proof before shutdown: recorder/playback use only the dedicated archive identity; no Orchestra service, timer, tunnel or backup is a dependency.\n'
} > "${out:-/dev/stdout}"

if [[ "$mode" == verify ]]; then
  grep -q 'NO destructive action' "${out:-/dev/null}" || { echo 'rehearsal output lacks the no-destructive-action marker' >&2; exit 1; }
  grep -q 'Tailscale: SHARED' "${out:-/dev/null}" || { echo 'rehearsal must preserve shared Tailscale' >&2; exit 1; }
  echo 'Orchestra rehearsal verification: PASS (plan only, nothing removed)'
fi
