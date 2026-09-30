#!/bin/sh
set -eu
: "${DZN_PHASE_2A2I_WP_CLI:?}" "${DZN_PHASE_2A2I_WP_PATH:?}" "${DZN_PHASE_2A2I_WP_USER:?}" "${DZN_PHASE_2A2I_GATE_DIR:?}"
[ "${DZN_PHASE_2A2I_RUNTIME_TEST:-}" = concurrency ] || exit 1
case "${DZN_PHASE_2A2I_MODE:-}" in a|b|u1|u2|p1|p2);;*)exit 1;;esac
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd);gate=$DZN_PHASE_2A2I_GATE_DIR
[ -d "$gate" ]&&[ -z "$(find "$gate" -mindepth 1 -maxdepth 1 -print -quit)" ]||exit 1
wp(){ "$DZN_PHASE_2A2I_WP_CLI" --path="$DZN_PHASE_2A2I_WP_PATH" --user="$DZN_PHASE_2A2I_WP_USER" eval-file "$1"; }
wait_gate(){ i=0;while [ ! -f "$gate/$1" ]&&[ "$i" -lt 600 ];do i=$((i+1));sleep 0.1;done;[ -f "$gate/$1" ]||{ echo "missing gate $1" >&2;exit 1;}; }
wp "$root/tests/phase-2a2i-concurrency-setup.php"
mode=$DZN_PHASE_2A2I_MODE
if [ "$mode" = p1 ];then action1=erase;action2=convert;else action1=convert;[ "$mode" = p2 ]&&action2=erase||action2=convert;fi
env DZN_PHASE_2A2I_WORKER=w1 DZN_PHASE_2A2I_ACTION=$action1 "$DZN_PHASE_2A2I_WP_CLI" --path="$DZN_PHASE_2A2I_WP_PATH" --user="$DZN_PHASE_2A2I_WP_USER" eval-file "$root/tests/phase-2a2i-concurrency-worker.php" >"$gate/w1.out" 2>&1&p1=$!
wait_gate w1.started;wait_gate w1.connection
env DZN_PHASE_2A2I_WORKER=w2 DZN_PHASE_2A2I_ACTION=$action2 "$DZN_PHASE_2A2I_WP_CLI" --path="$DZN_PHASE_2A2I_WP_PATH" --user="$DZN_PHASE_2A2I_WP_USER" eval-file "$root/tests/phase-2a2i-concurrency-worker.php" >"$gate/w2.out" 2>&1&p2=$!
wait_gate w2.started;wait_gate w2.connection
c1=$(tr -d '\n' <"$gate/w1.connection");c2=$(tr -d '\n' <"$gate/w2.connection");[ "$c1" != "$c2" ]||exit 1
if [ "$mode" = a ];then wait_gate w1.source_locked;wait_gate w2.source_locked;s1=$(cat "$gate/w1.source_locked");s2=$(cat "$gate/w2.source_locked");[ "$(printf '%s' "$s1"|cut -d: -f1)" != "$(printf '%s' "$s2"|cut -d: -f1)" ]&&[ "$(printf '%s' "$s1"|cut -d: -f2)" != "$(printf '%s' "$s2"|cut -d: -f2)" ]&&[ "$(printf '%s' "$s1"|cut -d: -f3-4)" = "$(printf '%s' "$s2"|cut -d: -f3-4)" ]||exit 1;: >"$gate/converge.w1";wait_gate w1.locked;: >"$gate/converge.w2";i=0;while [ "$i" -lt 20 ]&&[ ! -f "$gate/w2.finished" ];do i=$((i+1));sleep 0.1;done;[ ! -f "$gate/w2.finished" ]||{ echo 'contender unexpectedly completed before release' >&2;exit 1;};overlap=both_source_lineages_locked_then_same_root_contender_blocked
elif [ "$mode" = u1 ]||[ "$mode" = u2 ];then wait_gate w1.locked;wait_gate w2.finished;overlap=independent_completed_before_release
else wait_gate w1.locked;i=0;while [ "$i" -lt 20 ]&&[ ! -f "$gate/w2.finished" ];do i=$((i+1));sleep 0.1;done;[ ! -f "$gate/w2.finished" ]||{ echo 'contender unexpectedly completed before release' >&2;exit 1;};overlap=contender_blocked_before_release;fi
: >"$gate/release";wait "$p1";wait "$p2"
! grep -Eiq 'deadlock|lock wait timeout|WordPress database error|mysqli_sql_exception|Duplicate entry' "$gate/w1.out" "$gate/w2.out"
case "$mode" in a)grep -q 'outcome=created' "$gate/w1.out";grep -q 'outcome=canonical_conflict' "$gate/w2.out";;b)grep -q 'outcome=created' "$gate/w1.out";grep -q 'outcome=already_converted' "$gate/w2.out";;u1|u2)grep -q 'outcome=created' "$gate/w1.out";grep -q 'outcome=created' "$gate/w2.out";;p1)grep -q 'outcome=erased' "$gate/w1.out";grep -q 'outcome=created' "$gate/w2.out";;p2)grep -q 'outcome=created' "$gate/w1.out";grep -q 'outcome=erased' "$gate/w2.out";;esac
wp "$root/tests/phase-2a2i-concurrency-verify.php"
printf 'race=%s overlap=%s connections=w1:%s,w2:%s gates=w1.locked,w2.started,release source_lineages="%s|%s" holder="%s" contender="%s"\n' "$mode" "$overlap" "$c1" "$c2" "${s1:-not_applicable}" "${s2:-not_applicable}" "$(tr '\n' ' ' <"$gate/w1.out")" "$(tr '\n' ' ' <"$gate/w2.out")"
rm -f "$gate"/*
