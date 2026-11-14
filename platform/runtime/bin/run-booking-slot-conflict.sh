#!/usr/bin/env bash
# Disposable DB proof for simultaneous intake, acceptance and non-overlap behaviour.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
dzn_require_cached_images
dzn_assert_runtime_paths
"$(dirname "$BASH_SOURCE")/reset-disposable.sh"
plugin_root="$(dzn_plugin_source_from_worktree "$DZN_PLUGIN_WORKTREE")"
gate="$DZN_WP_DIR/slot-conflict-gate"
gate_container="/var/www/html/slot-conflict-gate"
# The WordPress volume is owned by the container user on Linux CI, so every
# mutation of the shared gate directory runs as root in a disposable CLI
# container. Host-side logs stay outside the mounted volume.
logs="$DZN_RUNTIME_STATE_DIR/slot-conflict-logs"
dzn_assert_disposable_path "$logs"
dzn_remove_disposable_path "$logs"
mkdir -p "$logs"
trap 'dzn_remove_disposable_path "$logs"' EXIT
gate_exec(){ docker run --rm --pull=never -u 0 -v "$DZN_WP_DIR:/var/www/html" --entrypoint sh "$DZN_CLI_IMAGE" -c "$1"; }
gate_reset(){ dzn_assert_runtime_paths; gate_exec "if [ -d /var/www/html/slot-conflict-gate ]; then find /var/www/html/slot-conflict-gate -mindepth 1 -depth -delete; fi; mkdir -p /var/www/html/slot-conflict-gate"; }
gate_prepare(){ gate_exec "mkdir -p '/var/www/html/slot-conflict-gate/$1' && rm -f '/var/www/html/slot-conflict-gate/$1'/*"; }
gate_release(){ gate_exec ": > '/var/www/html/slot-conflict-gate/$1'"; }
gate_reset
wp(){ dzn_wp --user=1 "$@"; }
worker(){
  local race="$1" name="$2" action="$3"
  gate_exec "mkdir -p '/var/www/html/slot-conflict-gate/$race'"
  dzn_wp_env "DZN_BOOKING_SLOT_RUNTIME=concurrency" "DZN_SLOT_WORKER=$name" "DZN_SLOT_ACTION=$action" "DZN_SLOT_GATE_DIR=$gate_container/$race" -- eval-file "$plugin_root/tests/booking-slot-conflict-concurrency-worker.php"
}
waitfor(){ local path="$1" i=0; while [ ! -f "$path" ] && [ $i -lt 1200 ]; do i=$((i+1)); sleep 0.1; done; [ -f "$path" ]; }
run_race(){
  local race="$1" action="$2" out="$logs/$1"
  mkdir -p "$out"
  gate_prepare "$race"
  worker "$race" w1 "$action" >"$out/w1.out" 2>&1 & local p1=$!
  waitfor "$gate/$race/w1.locked" || { echo "race=$race holder=FAIL"; kill "$p1" 2>/dev/null || true; return 1; }
  worker "$race" w2 "$action" >"$out/w2.out" 2>&1 & local p2=$!
  waitfor "$gate/$race/w2.started" || { echo "race=$race contender=FAIL"; kill "$p1" "$p2" 2>/dev/null || true; return 1; }
  [ ! -f "$gate/$race/w2.result" ] || { echo "race=$race contender=FAIL finished before release"; gate_release "$race/release"; wait "$p1" "$p2" 2>/dev/null || true; return 1; }
  dzn_wp_env "DZN_BOOKING_SLOT_RUNTIME=concurrency" "DZN_SLOT_GATE_DIR=$gate_container/$race" -- eval-file "$plugin_root/tests/booking-slot-conflict-concurrency-wait.php" >"$out/wait.out" 2>&1 & local pw=$!
  sleep 2
  gate_release "$race/release"
  wait "$p1" 2>/dev/null || true
  wait "$p2" 2>/dev/null || true
  wait "$pw" 2>/dev/null || true
  if grep -Eiq 'deadlock|lock wait timeout|WordPress database error' "$out/w1.out" "$out/w2.out"; then
    echo "race=$race stability=FAIL database contention error"; return 1
  fi
  [ -f "$gate/$race/w1.result" ] && [ -f "$gate/$race/w2.result" ] || { echo "race=$race artefacts=FAIL"; return 1; }
  echo "race=$race PASS"
}
run_single(){
  local race="$1" action="$2" out="$logs/$1"
  mkdir -p "$out"
  gate_prepare "$race"
  worker "$race" w2 "$action" >"$out/w2.out" 2>&1
  [ -f "$gate/$race/w2.result" ] || { echo "single=$race artefacts=FAIL"; return 1; }
  echo "single=$race PASS"
}

dzn_wp_env "DZN_BOOKING_SLOT_RUNTIME=concurrency" -- eval-file "$plugin_root/tests/booking-slot-conflict-concurrency-setup.php"
run_race submission submission
run_single single-nonoverlap non_overlap
run_single single-timezone timezone_overlap
run_race acceptance acceptance
dzn_wp_env "DZN_BOOKING_SLOT_RUNTIME=concurrency" "DZN_SLOT_GATE_DIR=$gate_container" -- eval-file "$plugin_root/tests/booking-slot-conflict-concurrency-verify.php"
dzn_wp_env "DZN_BOOKING_SLOT_RUNTIME=runtime" -- eval-file "$plugin_root/tests/booking-slot-conflict-runtime.php"
echo 'Booking Student-slot database integration: PASS'
