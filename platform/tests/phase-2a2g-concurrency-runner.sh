#!/bin/sh
# Deterministic two-session final-acceptance race controller.
set -eu
: "${DZN_PHASE_2A2G_WP_CLI:?Set wp executable}" "${DZN_PHASE_2A2G_WP_PATH:?Set WordPress root}" "${DZN_PHASE_2A2G_WP_USER:?Set disposable administrator}" "${DZN_PHASE_2A2G_GATE_DIR:?Set empty shared gate directory}"
[ "${DZN_PHASE_2A2G_RUNTIME_TEST:-}" = concurrency ] || { echo "Phase 2A.2-G runner refused." >&2; exit 1; }
case "${DZN_PHASE_2A2G_MODE:-}" in a|o|p|e|c|pr|g|x|u|i|rp|rg);; *) echo "Unknown race." >&2; exit 1;; esac
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd);gate=$DZN_PHASE_2A2G_GATE_DIR
[ -d "$gate" ] && [ -z "$(find "$gate" -mindepth 1 -maxdepth 1 -print -quit)" ] || { echo "Gate directory must be empty." >&2; exit 1; }
wp(){ "$DZN_PHASE_2A2G_WP_CLI" --path="$DZN_PHASE_2A2G_WP_PATH" --user="$DZN_PHASE_2A2G_WP_USER" eval-file "$1"; }
wait_gate(){ i=0;while [ ! -f "$gate/$1" ]&&[ "$i" -lt 600 ];do i=$((i+1));sleep 0.1;done;[ -f "$gate/$1" ]||{ echo "Missing gate $1" >&2;exit 1;}; }
connection(){ value=$(tr -d '\n' <"$gate/$1.connection"); case "$value" in ''|*[!0-9]*) echo "$1 MySQL connection identity is invalid" >&2; exit 1;; esac; printf '%s' "$value"; }
assert_distinct_connections(){ w1_connection=$(connection w1);w2_connection=$(connection w2);[ "$w1_connection" != "$w2_connection" ]||{ echo "Race workers unexpectedly share one MySQL connection" >&2;exit 1;}; }
wp "$root/tests/phase-2a2g-concurrency-setup.php"
if [ "$DZN_PHASE_2A2G_MODE" = u ]; then
  env DZN_PHASE_2A2G_WORKER=w1 DZN_PHASE_2A2G_ACTION=proposal "$DZN_PHASE_2A2G_WP_CLI" --path="$DZN_PHASE_2A2G_WP_PATH" --user="$DZN_PHASE_2A2G_WP_USER" eval-file "$root/tests/phase-2a2g-concurrency-worker.php" >"$gate/w1.out" 2>&1 & p1=$!
  wait_gate w1.locked; wait_gate w1.connection
  env DZN_PHASE_2A2G_WORKER=w2 DZN_PHASE_2A2G_ACTION=accept "$DZN_PHASE_2A2G_WP_CLI" --path="$DZN_PHASE_2A2G_WP_PATH" --user="$DZN_PHASE_2A2G_WP_USER" eval-file "$root/tests/phase-2a2g-concurrency-worker.php" >"$gate/w2.out" 2>&1 & p2=$!
  wait_gate w2.started; wait_gate w2.connection; wait_gate w2.finished
  assert_distinct_connections
  : >"$gate/release"; wait "$p1"; wait "$p2"
  grep -q 'outcome=issued' "$gate/w1.out"; grep -q 'outcome=accepted' "$gate/w2.out"
  wp "$root/tests/phase-2a2g-concurrency-verify.php"
  printf 'race=u unrelated_completed_before_release=1 connections=w1:%s,w2:%s gates=w1.locked,w2.started,w2.finished,release holder="%s" contender="%s"\n' "$(tr -d '\n' <"$gate/w1.connection")" "$(tr -d '\n' <"$gate/w2.connection")" "$(tr '\n' ' ' <"$gate/w1.out")" "$(tr '\n' ' ' <"$gate/w2.out")"
  rm -f "$gate"/*
  exit 0
fi
if [ "$DZN_PHASE_2A2G_MODE" = i ]; then
  env DZN_PHASE_2A2G_WORKER=w1 DZN_PHASE_2A2G_ACTION=accept "$DZN_PHASE_2A2G_WP_CLI" --path="$DZN_PHASE_2A2G_WP_PATH" --user="$DZN_PHASE_2A2G_WP_USER" eval-file "$root/tests/phase-2a2g-concurrency-worker.php" >"$gate/w1.out" 2>&1 & p1=$!
  wait_gate w1.locked; wait_gate w1.connection
  env DZN_PHASE_2A2G_WORKER=w2 DZN_PHASE_2A2G_ACTION=accept "$DZN_PHASE_2A2G_WP_CLI" --path="$DZN_PHASE_2A2G_WP_PATH" --user="$DZN_PHASE_2A2G_WP_USER" eval-file "$root/tests/phase-2a2g-concurrency-worker.php" >"$gate/w2.out" 2>&1 & p2=$!
  wait_gate w2.started; wait_gate w2.connection; wait_gate w2.finished
  assert_distinct_connections
  : >"$gate/release"; wait "$p1"; wait "$p2"
  grep -q 'outcome=accepted' "$gate/w1.out"; grep -q 'outcome=accepted' "$gate/w2.out"
  wp "$root/tests/phase-2a2g-concurrency-verify.php"
  printf 'race=i independent_completed_before_release=1 connections=w1:%s,w2:%s gates=w1.locked,w2.started,w2.finished,release holder="%s" contender="%s"\n' "$(tr -d '\n' <"$gate/w1.connection")" "$(tr -d '\n' <"$gate/w2.connection")" "$(tr '\n' ' ' <"$gate/w1.out")" "$(tr '\n' ' ' <"$gate/w2.out")"
  rm -f "$gate"/*
  exit 0
fi
if [ "$DZN_PHASE_2A2G_MODE" = rp ] || [ "$DZN_PHASE_2A2G_MODE" = rg ]; then
  [ "$DZN_PHASE_2A2G_MODE" = rp ] && first=principal || first=guardian
  env DZN_PHASE_2A2G_WORKER=w1 DZN_PHASE_2A2G_ACTION="$first" "$DZN_PHASE_2A2G_WP_CLI" --path="$DZN_PHASE_2A2G_WP_PATH" --user="$DZN_PHASE_2A2G_WP_USER" eval-file "$root/tests/phase-2a2g-concurrency-worker.php" >"$gate/w1.out" 2>&1 & p1=$!
  wait_gate w1.locked; wait_gate w1.connection
  env DZN_PHASE_2A2G_WORKER=w2 DZN_PHASE_2A2G_ACTION=accept "$DZN_PHASE_2A2G_WP_CLI" --path="$DZN_PHASE_2A2G_WP_PATH" --user="$DZN_PHASE_2A2G_WP_USER" eval-file "$root/tests/phase-2a2g-concurrency-worker.php" >"$gate/w2.out" 2>&1 & p2=$!
  wait_gate w2.started; wait_gate w2.connection; assert_distinct_connections
  wp "$root/tests/phase-2a2g-concurrency-wait.php" >"$gate/wait.out"
  wait_gate w2.blocked
  : >"$gate/release"; wait "$p1"; wait "$p2"
  grep -q "outcome=${first}_revoked" "$gate/w1.out"
  if [ "$DZN_PHASE_2A2G_MODE" = rp ]; then grep -q 'outcome=rejected class=InvalidArgumentException message=Adult self authority is no longer current' "$gate/w2.out"; else grep -q 'outcome=rejected class=InvalidArgumentException message=Guardian representative authority is no longer current' "$gate/w2.out"; fi
  ! grep -Eiq 'WordPress database error|Duplicate entry|deadlock|lock wait timeout|mysqli_sql_exception' "$gate/w1.out" "$gate/w2.out"
  wp "$root/tests/phase-2a2g-concurrency-verify.php"
  printf 'race=%s authority_committed_first=1 connections=w1:%s,w2:%s gates=w1.locked,w2.started,w2.blocked,release attribution="%s" holder="%s" contender="%s"\n' "$DZN_PHASE_2A2G_MODE" "$w1_connection" "$w2_connection" "$(tr -d '\n' <"$gate/w2.blocked")" "$(tr '\n' ' ' <"$gate/w1.out")" "$(tr '\n' ' ' <"$gate/w2.out")"
  rm -f "$gate"/*
  exit 0
fi
case "$DZN_PHASE_2A2G_MODE" in a|o|x) second=accept;;p) second=proposal;;e) second=erase;;c) second=capacity;;pr) second=principal;;g) second=guardian;;esac
env DZN_PHASE_2A2G_WORKER=w1 DZN_PHASE_2A2G_ACTION=accept "$DZN_PHASE_2A2G_WP_CLI" --path="$DZN_PHASE_2A2G_WP_PATH" --user="$DZN_PHASE_2A2G_WP_USER" eval-file "$root/tests/phase-2a2g-concurrency-worker.php" >"$gate/w1.out" 2>&1 & p1=$!
wait_gate w1.locked
wait_gate w1.connection
env DZN_PHASE_2A2G_WORKER=w2 DZN_PHASE_2A2G_ACTION="$second" "$DZN_PHASE_2A2G_WP_CLI" --path="$DZN_PHASE_2A2G_WP_PATH" --user="$DZN_PHASE_2A2G_WP_USER" eval-file "$root/tests/phase-2a2g-concurrency-worker.php" >"$gate/w2.out" 2>&1 & p2=$!
wait_gate w2.started
wait_gate w2.connection
: >"$gate/release";wait "$p1";wait "$p2"
grep -q 'outcome=accepted' "$gate/w1.out" || [ "$DZN_PHASE_2A2G_MODE" = x ]
case "$DZN_PHASE_2A2G_MODE" in a|o)grep -Eq 'outcome=(family_conflict|rejected)' "$gate/w2.out";;x)grep -q 'outcome=idempotency_conflict' "$gate/w1.out";grep -q 'outcome=accepted' "$gate/w2.out";;p)grep -q 'outcome=rejected' "$gate/w2.out";;e)grep -q 'outcome=erased' "$gate/w2.out";;c)grep -q 'outcome=capacity_changed' "$gate/w2.out";;pr)grep -q 'outcome=principal_revoked' "$gate/w2.out";;g)grep -q 'outcome=guardian_revoked' "$gate/w2.out";;esac
wp "$root/tests/phase-2a2g-concurrency-verify.php"
printf 'race=%s connections=w1:%s,w2:%s gates=w1.locked,w2.started,release holder="%s" contender="%s"\n' "$DZN_PHASE_2A2G_MODE" "$(tr -d '\n' <"$gate/w1.connection")" "$(tr -d '\n' <"$gate/w2.connection")" "$(tr '\n' ' ' <"$gate/w1.out")" "$(tr '\n' ' ' <"$gate/w2.out")"
rm -f "$gate"/*
