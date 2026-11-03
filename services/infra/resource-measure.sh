#!/usr/bin/env bash
# Read-only OVH resource-safety measurement. It never enables recording or changes a service.
# Usage: sudo ./resource-measure.sh --label baseline|live --seconds 120 [--output file]
set -Eeuo pipefail
label=baseline
seconds=60
out=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --label) label="$2"; shift 2 ;;
    --seconds) seconds="$2"; shift 2 ;;
    --output) out="$2"; shift 2 ;;
    *) echo "error: unknown argument $1" >&2; exit 2 ;;
  esac
done
[[ "$seconds" =~ ^[0-9]+$ ]] || { echo 'error: seconds must be an integer' >&2; exit 2; }
tmp="$(mktemp "${TMPDIR:-/tmp}/dzn-resource.XXXXXX")"
trap 'rm -f "$tmp"' EXIT

cpu_model="$(awk -F: '/model name/{gsub(/^ +/,"",$2);print $2;exit}' /proc/cpuinfo 2>/dev/null || sysctl -n machdep.cpu.brand_string 2>/dev/null || uname -m)"
cpus="$(getconf _NPROCESSORS_ONLN 2>/dev/null || nproc 2>/dev/null || echo 0)"
mem_total="$(awk '/MemTotal/{print $2*1024}' /proc/meminfo 2>/dev/null || echo 0)"
swap_total="$(awk '/SwapTotal/{print $2*1024}' /proc/meminfo 2>/dev/null || echo 0)"
disk_total="$(df -B1 --output=size "$PWD" 2>/dev/null | awk 'NR==2{print $1}' || df -k "$PWD" | awk 'NR==2{print $2*1024}')"
disk_free="$(df -B1 --output=avail "$PWD" 2>/dev/null | awk 'NR==2{print $1}' || df -k "$PWD" | awk 'NR==2{print $4*1024}')"
pressure_before="$(awk '/some avg10/{print $2}' /proc/pressure/cpu 2>/dev/null || echo 0)"
oom_before="$(dmesg 2>/dev/null | grep -ci 'out of memory' || true)"
stat_before="$(awk 'NR==1{for(i=1;i<=NF;i++)if($i=="cpu"){idle=i+4;total=i+1}} NR==2{print $total,$idle}' /proc/stat 2>/dev/null || echo '0 0')"
sleep "$seconds"
stat_after="$(awk 'NR==1{for(i=1;i<=NF;i++)if($i=="cpu"){idle=i+4;total=i+1}} NR==2{print $total,$idle}' /proc/stat 2>/dev/null || echo '0 0')"
read -r total_before idle_before <<< "$stat_before"
read -r total_after idle_after <<< "$stat_after"
total_delta=$(( total_after - total_before )); idle_delta=$(( idle_after - idle_before ))
cpu_busy=0
[[ $total_delta -gt 0 ]] && cpu_busy=$(( 100 * (total_delta - idle_delta) / total_delta ))
pressure_after="$(awk '/some avg10/{print $2}' /proc/pressure/cpu 2>/dev/null || echo 0)"
oom_after="$(dmesg 2>/dev/null | grep -ci 'out of memory' || true)"
mem_available="$(awk '/MemAvailable/{print $2*1024}' /proc/meminfo 2>/dev/null || echo 0)"
swap_free="$(awk '/SwapFree/{print $2*1024}' /proc/meminfo 2>/dev/null || echo 0)"
steal="$(vmstat 1 2 2>/dev/null | tail -1 | awk '{print $19}' || echo 0)"
load="$(cut -d' ' -f1-3 /proc/loadavg 2>/dev/null || uptime | sed 's/.*load averages*: //')"

{
  printf '{\n'
  printf '  "label": "%s",\n' "$label"
  printf '  "measured_at": "%s",\n' "$(date -u +%FT%TZ)"
  printf '  "cpu_model": "%s",\n' "$(echo "$cpu_model" | sed 's/"/\\"/g')"
  printf '  "v CPUs": %s,\n' "$cpus"
  printf '  "cpu_busy_percent": %s,\n' "$cpu_busy"
  printf '  "cpu_steal_percent": %s,\n' "$steal"
  printf '  "psi_cpu_some_avg10_before": %s,\n' "$pressure_before"
  printf '  "psi_cpu_some_avg10_after": %s,\n' "$pressure_after"
  printf '  "load_average": "%s",\n' "$load"
  printf '  "memory_total_bytes": %s,\n' "$mem_total"
  printf '  "memory_available_bytes": %s,\n' "$mem_available"
  printf '  "swap_total_bytes": %s,\n' "$swap_total"
  printf '  "swap_free_bytes": %s,\n' "$swap_free"
  printf '  "disk_total_bytes": %s,\n' "$disk_total"
  printf '  "disk_free_bytes": %s,\n' "$disk_free"
  printf '  "oom_events_before": %s,\n' "$oom_before"
  printf '  "oom_events_after": %s,\n' "$oom_after"
  printf '  "observations": {\n'
  printf '    "livekit": "%s",\n' "$(systemctl show -p ActiveState --value livekit 2>/dev/null || echo unavailable)"
  printf '    "redis": "%s",\n' "$(systemctl show -p ActiveState --value redis-server 2>/dev/null || systemctl show -p ActiveState --value redis 2>/dev/null || echo unavailable)"
  printf '    "caddy": "%s",\n' "$(systemctl show -p ActiveState --value caddy 2>/dev/null || echo unavailable)"
  printf '    "egress_worker": "%s"\n' "$(systemctl show -p ActiveState --value delnavazan-egress-worker 2>/dev/null || echo unavailable)"
  printf '  }\n'
  printf '}\n'
} | tee "$tmp"
if [[ -n "$out" ]]; then install -m 0600 "$tmp" "$out"; fi

# Activation verdict is deliberately conservative. A separate Egress worker is recommended unless
# the measured CPU steal, PSI pressure, available memory and disk latency all show proven headroom.
if [[ $cpu_busy -ge 70 || $oom_after -gt $oom_before || $mem_available -lt $((2*1024*1024*1024)) ]]; then
  echo 'VERDICT: NOT SAFE to enable capture on this host; use a separate Egress worker or larger VPS.' >&2
  exit 3
fi
echo 'VERDICT: measurement recorded; staging load test with one active two-person lesson is still required before capture.'
