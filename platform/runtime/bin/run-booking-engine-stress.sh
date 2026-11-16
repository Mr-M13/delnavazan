#!/usr/bin/env bash
# Multi-process stress harness for the introductory booking engine.
#
# Run only against the disposable local WordPress/MariaDB stack. It refuses any
# non-local site URL and never contacts a provider, mail or payment service.
#
# Every caller-supplied selection is validated before a single derived path is
# built or deleted (see booking-stress-inputs.sh). Set DZN_STRESS_VALIDATE_ONLY=1
# to print the resolved plan and exit without touching Docker or the filesystem.
set -euo pipefail
dzn_stress_bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
# shellcheck source=booking-stress-inputs.sh
. "$dzn_stress_bin/booking-stress-inputs.sh"
source "$dzn_stress_bin/common.sh"

# --- Selection resolution and validation -------------------------------------
# Defaults apply only when the variable is unset; an explicitly empty value
# disables that loop. This runs before `dzn_require_cached_images` so a malformed
# selection can never reach a filesystem operation, and so the negative tests do
# not need Docker.
levels="$(dzn_stress_selection DZN_STRESS_LEVELS '2 5 10 25 50 100')"
actions="$(dzn_stress_selection DZN_STRESS_ACTIONS 'submit accept')"
# Split the documented whitespace-separated lists with globbing disabled, so a
# value such as `*` is validated as the literal token it is instead of expanding
# to the working directory. Globbing is restored afterwards: every surviving
# token is now a fixed action name or a plain integer.
set -f
# shellcheck disable=SC2086 # word splitting is the documented list syntax
dzn_stress_require_actions $actions || exit 2
# shellcheck disable=SC2086
dzn_stress_require_levels $levels || exit 2
set +f

levels_selected=0
for level in $levels; do levels_selected=$((levels_selected + 1)); done
actions_selected=0
for action in $actions; do actions_selected=$((actions_selected + 1)); done
concurrency_jobs=$((levels_selected * actions_selected))

if [ "${DZN_STRESS_VALIDATE_ONLY:-0}" = "1" ]; then
  printf 'plan actions=%s\n' "${actions:-<none>}"
  printf 'plan levels=%s\n' "${levels:-<none>}"
  printf 'plan concurrency_jobs=%s\n' "$concurrency_jobs"
  for action in $actions; do
    for level in $levels; do printf 'plan job=%s-%s\n' "$action" "$level"; done
  done
  printf 'plan scenarios=%s\n' "$([ "${DZN_STRESS_SKIP_SCENARIOS:-0}" = "1" ] && echo skipped || echo enabled)"
  printf 'plan logs_root=%s\n' "$DZN_RUNTIME_STATE_DIR/booking-stress-evidence/$DZN_COMPOSE_PROJECT"
  exit 0
fi

dzn_require_cached_images
dzn_assert_runtime_paths

plugin_root="$(dzn_plugin_source_from_worktree "$DZN_PLUGIN_WORKTREE")"
# Raw execution output is evidence, not source: keep it under the disposable
# state directory (never inside the tracked checkout) and delete it through the
# validated disposable-path primitive.
logs="$DZN_RUNTIME_STATE_DIR/booking-stress-evidence/$DZN_COMPOSE_PROJECT"
dzn_remove_disposable_path "$logs"
mkdir -p "$logs"

gate_root="$DZN_WP_DIR/booking-stress-gate"
gate_container="/var/www/html/booking-stress-gate"
# The WordPress volume is owned by the container user on Linux CI, so the host
# user cannot create directories inside it: `mkdir` fails with EACCES. Every
# mutation of the shared gate directory therefore runs as root in the already
# required CLI container, matching run-booking-slot-conflict.sh. The host side
# still validates the path before anything is removed.
gate_exec(){ dzn_assert_runtime_paths; docker run --rm --pull=never -u 0 -v "$DZN_WP_DIR:/var/www/html" --entrypoint sh "$DZN_CLI_IMAGE" -c "$1"; }
gate_open(){ dzn_assert_disposable_path "$1"; gate_exec "mkdir -p '$2'"; }
gate_exec "mkdir -p '$gate_container'"

if [ "${DZN_STRESS_SKIP_SCENARIOS:-0}" != "1" ]; then
dzn_wp --user=1 eval-file "$plugin_root/tests/booking-engine-scenario-a.php" | tee "$logs/scenario-a.log"
dzn_wp --user=1 eval-file "$plugin_root/tests/booking-engine-scenario-b.php" | tee "$logs/scenario-b.log"
dzn_wp --user=1 eval-file "$plugin_root/tests/booking-engine-scenario-c.php" | tee "$logs/scenario-c.log"
dzn_wp --user=1 eval-file "$plugin_root/tests/booking-engine-scenario-d.php" | tee "$logs/scenario-d.log"
dzn_wp --user=1 eval-file "$plugin_root/tests/booking-engine-scenario-e.php" | tee "$logs/scenario-e.log"
dzn_wp --user=1 eval-file "$plugin_root/tests/booking-engine-scenario-f.php" | tee "$logs/scenario-f.log"
dzn_wp --user=1 eval-file "$plugin_root/tests/booking-engine-scenario-g.php" | tee "$logs/scenario-g.log"
fi
dzn_wp --user=1 eval-file "$plugin_root/tests/booking-engine-concurrency-setup.php" | tee "$logs/concurrency-setup.log"

run_level(){
  local action="$1" level="$2"
  local gate="$gate_root/$action-$level"
  local cgate="$gate_container/$action-$level"
  dzn_remove_disposable_path "$gate"
  gate_open "$gate" "$cgate"
  local out="$logs/$action-$level"
  mkdir -p "$out"
  local container_cmd
  if [ "$action" = "idempotent" ]; then
    container_cmd="
      i=0
      while [ \$i -lt $level ]; do
        DZN_STRESS_INDEX=\$i php -d memory_limit=64M '$plugin_root/tests/booking-engine-concurrency-light-worker.php' > '$cgate/'\$i.log 2>&1 &
        i=\$((i+1))
      done
      wait
    "
  else
    container_cmd="
      i=0
      while [ \$i -lt $level ]; do
        DZN_STRESS_INDEX=\$i php -d memory_limit=64M '$plugin_root/tests/booking-engine-concurrency-light-worker.php' > '$cgate/'\$i.log 2>&1 &
        i=\$((i+1))
      done
      i=0
      while [ \$i -lt 1200 ]; do
        ready=\$(find '$cgate' -maxdepth 1 -name '*.ready' | wc -l | tr -d ' ')
        [ "\$ready" -ge $level ] && break
        sleep 0.05
        i=\$((i+1))
      done
      if [ -n \"\$DZN_STRESS_KILL_INDEX\" ]; then
        kill -9 \$(cat '$cgate/'\$DZN_STRESS_KILL_INDEX.pid) 2>/dev/null || true
      fi
      touch '$cgate/release'
      wait
    "
  fi
  docker run --rm --pull=never --network "$DZN_NETWORK" -u 0 \
    -e WP_ENVIRONMENT_TYPE=local -e WORDPRESS_DB_HOST=db \
    -e "WORDPRESS_DB_NAME=$DZN_DB_NAME" -e "WORDPRESS_DB_USER=$DZN_DB_USER" -e "WORDPRESS_DB_PASSWORD=$DZN_DB_PASSWORD" \
    -e DZN_BOOKING_STRESS_RUNTIME=concurrency \
    -e "DZN_STRESS_LEVEL=$level" -e "DZN_STRESS_ACTION=$action" -e "DZN_STRESS_GATE=$cgate" -e "DZN_STRESS_KILL_INDEX=${DZN_STRESS_KILL_INDEX:-}" \
    -v "$DZN_WP_DIR:/var/www/html" -v "$DZN_PLUGIN_SOURCE:$DZN_PLUGIN_SOURCE:ro" \
    --entrypoint sh "$DZN_CLI_IMAGE" -c "$container_cmd" >"$out/launcher.log" 2>&1 || true
  if [ "${DZN_STRESS_SKIP_VERIFY:-0}" != "1" ]; then
    dzn_wp_env "DZN_BOOKING_STRESS_RUNTIME=concurrency" "DZN_STRESS_LEVEL=$level" "DZN_STRESS_ACTION=$action" "DZN_STRESS_GATE=$cgate" -- eval-file "$plugin_root/tests/booking-engine-concurrency-verify.php" | tee "$out/verify.log"
  fi
  if grep -Eiq 'deadlock|lock wait timeout|WordPress database error' "$out"/*.log; then
    echo "FAIL $action $level: database contention error" >&2
    return 1
  fi
}


run_crash(){
  local level=5
  local gate="$gate_root/submit_crash-$level"
  local cgate="$gate_container/submit_crash-$level"
  local out="$logs/crash-$level"
  dzn_remove_disposable_path "$gate"
  gate_open "$gate" "$cgate"; mkdir -p "$out"
  DZN_STRESS_KILL_INDEX=0 DZN_STRESS_SKIP_VERIFY=1 run_level submit_crash "$level" || true
  # The killed worker's transaction rolled back. Retry the same Student/key once.
  local retry="$gate_root/crash-retry"
  local cretry="$gate_container/crash-retry"
  dzn_remove_disposable_path "$retry"
  gate_open "$retry" "$cretry"; gate_exec ": > '$cretry/release'"
  docker run --rm --pull=never --network "$DZN_NETWORK" -u 0 \
    -e WP_ENVIRONMENT_TYPE=local -e WORDPRESS_DB_HOST=db \
    -e "WORDPRESS_DB_NAME=$DZN_DB_NAME" -e "WORDPRESS_DB_USER=$DZN_DB_USER" -e "WORDPRESS_DB_PASSWORD=$DZN_DB_PASSWORD" \
    -e DZN_BOOKING_STRESS_RUNTIME=concurrency \
    -e "DZN_STRESS_LEVEL=$level" -e "DZN_STRESS_ACTION=submit_crash" -e "DZN_STRESS_GATE=$cretry" -e "DZN_STRESS_INDEX=0" \
    -v "$DZN_WP_DIR:/var/www/html" -v "$DZN_PLUGIN_SOURCE:$DZN_PLUGIN_SOURCE:ro" \
    --entrypoint php "$DZN_CLI_IMAGE" -d memory_limit=64M "$plugin_root/tests/booking-engine-concurrency-light-worker.php" >"$out/retry.log" 2>&1 || true
  gate_exec "cp -f '$cretry/0.result' '$cgate/0.result' 2>/dev/null || true"
  dzn_wp_env "DZN_BOOKING_STRESS_RUNTIME=concurrency" "DZN_STRESS_LEVEL=$level" "DZN_STRESS_ACTION=submit_crash" "DZN_STRESS_GATE=$cgate" -- eval-file "$plugin_root/tests/booking-engine-concurrency-verify.php" | tee "$out/verify.log"
}

for action in $actions; do
  for level in $levels; do
    run_level "$action" "$level"
  done
done
if [ "${DZN_STRESS_PARTIAL:-1}" = "1" ]; then run_level submit_partial 25; fi
if [ "${DZN_STRESS_PROCESS:-1}" = "1" ]; then run_level process 2; fi
if [ "${DZN_STRESS_IDEMPOTENT:-1}" = "1" ]; then run_level idempotent 25; fi
if [ "${DZN_STRESS_CRASH:-1}" = "1" ]; then run_crash; fi
echo "Booking engine stress harness completed. Logs: $logs"
