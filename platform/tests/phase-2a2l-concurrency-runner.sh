#!/bin/sh
set -eu
: "${DZN_PHASE_2A2L_WP_CLI:?}" "${DZN_PHASE_2A2L_WP_PATH:?}" "${DZN_PHASE_2A2L_WP_USER:?}" "${DZN_PHASE_2A2L_GATE_DIR:?}"
[ "${DZN_PHASE_2A2L_RUNTIME_TEST:-}" = concurrency ] || exit 1
case "${DZN_PHASE_2A2L_MODE:-}" in same_key|different_key|competing_create|activate_activate|activate_cancel|close_cancel|close_create|cancel_create|unrelated);;*)exit 1;;esac
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd);gate=$DZN_PHASE_2A2L_GATE_DIR;rm -f "$gate"/*
wp(){ "$DZN_PHASE_2A2L_WP_CLI" --path="$DZN_PHASE_2A2L_WP_PATH" --user="$DZN_PHASE_2A2L_WP_USER" eval-file "$1"; }
waitfor(){ i=0;while [ ! -f "$gate/$1" ]&&[ $i -lt 600 ];do i=$((i+1));sleep .1;done;[ -f "$gate/$1" ];}
wp "$root/tests/phase-2a2l-concurrency-setup.php"
env DZN_PHASE_2A2L_WORKER=w1 "$DZN_PHASE_2A2L_WP_CLI" --path="$DZN_PHASE_2A2L_WP_PATH" --user="$DZN_PHASE_2A2L_WP_USER" eval-file "$root/tests/phase-2a2l-concurrency-worker.php" >"$gate/w1.out" 2>&1 & p1=$!;waitfor w1.locked
env DZN_PHASE_2A2L_WORKER=w2 "$DZN_PHASE_2A2L_WP_CLI" --path="$DZN_PHASE_2A2L_WP_PATH" --user="$DZN_PHASE_2A2L_WP_USER" eval-file "$root/tests/phase-2a2l-concurrency-worker.php" >"$gate/w2.out" 2>&1 & p2=$!;waitfor w2.started
if [ "$DZN_PHASE_2A2L_MODE" = unrelated ];then waitfor w2.finished;else wp "$root/tests/phase-2a2l-concurrency-wait.php" >"$gate/wait.out";waitfor w2.blocked;fi
: >"$gate/release";wait "$p1";wait "$p2";! grep -Eiq 'deadlock|lock wait timeout|WordPress database error' "$gate/w1.out" "$gate/w2.out"
case "$DZN_PHASE_2A2L_MODE" in close_create|cancel_create) grep -q 'outcome=stale_term_aggregate' "$gate/w2.out";; esac
wp "$root/tests/phase-2a2l-concurrency-verify.php" >"$gate/verify.out"
printf 'race=%s holder="%s" contender="%s"\n' "$DZN_PHASE_2A2L_MODE" "$(tr '\n' ' ' <"$gate/w1.out")" "$(tr '\n' ' ' <"$gate/w2.out")"
