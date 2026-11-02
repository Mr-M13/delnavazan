#!/usr/bin/env bash
# Disposable DB proof for simultaneous intake, acceptance and non-overlap behaviour.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
dzn_require_cached_images
dzn_assert_runtime_paths
"$(dirname "$BASH_SOURCE")/reset-disposable.sh"
plugin_root="$(dzn_plugin_source_from_worktree "$DZN_PLUGIN_WORKTREE")"
gate="$DZN_WP_DIR/slot-conflict-gate"
rm -rf "$gate"; mkdir -p "$gate"
wp(){ dzn_wp --user=1 "$@"; }
worker(){
  local race="$1" name="$2" action="$3"
  mkdir -p "$gate/$race"
  dzn_wp_env "DZN_BOOKING_SLOT_RUNTIME=concurrency" "DZN_SLOT_WORKER=$name" "DZN_SLOT_ACTION=$action" "DZN_SLOT_GATE_DIR=$gate/$race" -- eval-file "$plugin_root/tests/booking-slot-conflict-concurrency-worker.php"
}
waitfor(){ local path="$1" i=0; while [ ! -f "$path" ] && [ $i -lt 1200 ]; do i=$((i+1)); sleep 0.1; done; [ -f "$path" ]; }
run_race(){
  local race="$1" action="$2"
  mkdir -p "$gate/$race"; rm -f "$gate/$race"/*
  worker "$race" w1 "$action" >"$gate/$race/w1.out" 2>&1 & local p1=$!
  waitfor "$gate/$race/w1.locked" || { echo "race=$race holder=FAIL"; kill "$p1" 2>/dev/null || true; return 1; }
  worker "$race" w2 "$action" >"$gate/$race/w2.out" 2>&1 & local p2=$!
  waitfor "$gate/$race/w2.started" || { echo "race=$race contender=FAIL"; kill "$p1" "$p2" 2>/dev/null || true; return 1; }
  dzn_wp_env "DZN_BOOKING_SLOT_RUNTIME=concurrency" "DZN_SLOT_GATE_DIR=$gate/$race" -- eval-file "$plugin_root/tests/booking-slot-conflict-concurrency-wait.php"
  [ -f "$gate/$race/w2.blocked" ] || { echo "race=$race blocked=FAIL"; : >"$gate/$race/release"; wait "$p1" "$p2" 2>/dev/null || true; return 1; }
  : >"$gate/$race/release"
  wait "$p1" 2>/dev/null || true
  wait "$p2" 2>/dev/null || true
  if grep -Eiq 'deadlock|lock wait timeout|WordPress database error' "$gate/$race/w1.out" "$gate/$race/w2.out"; then
    echo "race=$race stability=FAIL database contention error"; return 1
  fi
  [ -f "$gate/$race/w1.result" ] && [ -f "$gate/$race/w2.result" ] || { echo "race=$race artefacts=FAIL"; return 1; }
  echo "race=$race PASS"
}
run_single(){
  local race="$1" action="$2"
  mkdir -p "$gate/$race"; rm -f "$gate/$race"/*
  worker "$race" w2 "$action" >"$gate/$race/w2.out" 2>&1
  [ -f "$gate/$race/w2.result" ] || { echo "single=$race artefacts=FAIL"; return 1; }
  echo "single=$race PASS"
}

dzn_wp_env "DZN_BOOKING_SLOT_RUNTIME=concurrency" -- eval-file "$plugin_root/tests/booking-slot-conflict-concurrency-setup.php"
run_race submission submission
run_single single-nonoverlap non_overlap
run_single single-timezone timezone_overlap
run_race acceptance acceptance
dzn_wp_env "DZN_BOOKING_SLOT_RUNTIME=concurrency" "DZN_SLOT_GATE_DIR=$gate" -- eval-file "$plugin_root/tests/booking-slot-conflict-concurrency-verify.php"
dzn_wp_env "DZN_BOOKING_SLOT_RUNTIME=runtime" -- eval-file "$plugin_root/tests/booking-slot-conflict-runtime.php"
echo 'Booking Student-slot database integration: PASS'
