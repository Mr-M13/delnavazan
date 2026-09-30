#!/bin/sh
# Deterministic two-session controller for disposable Phase 2A.2-F runtimes.
set -eu
: "${DZN_PHASE_2A2F_WP_CLI:?Set the isolated wp executable}"
: "${DZN_PHASE_2A2F_WP_PATH:?Set the isolated WordPress root}"
: "${DZN_PHASE_2A2F_WP_USER:?Set a disposable administrator}"
: "${DZN_PHASE_2A2F_GATE_DIR:?Set an empty shared gate directory}"
[ "${DZN_PHASE_2A2F_RUNTIME_TEST:-}" = isolated ] || { echo "Phase 2A.2-F runner refused." >&2; exit 1; }
case "${DZN_PHASE_2A2F_MODE:-}" in
  r1|r2a|r2b|r3|r4|r5|r6|r7|r8|r9|r10|r11c|r11pr|r11ps|r11gr|r11gs|r11e) ;;
  *) echo "Unknown Phase 2A.2-F race mode." >&2; exit 1 ;;
esac

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
gate=$DZN_PHASE_2A2F_GATE_DIR
[ -d "$gate" ] && [ -z "$(find "$gate" -mindepth 1 -maxdepth 1 -print -quit)" ] || {
  echo "Gate directory must exist and be empty." >&2
  exit 1
}
wp() {
  "$DZN_PHASE_2A2F_WP_CLI" --path="$DZN_PHASE_2A2F_WP_PATH" --user="$DZN_PHASE_2A2F_WP_USER" eval-file "$1"
}
first_pid=
second_pid=
cleanup_needed=1
cleanup() {
  status=$?
  trap - EXIT HUP INT TERM
  set +e
  [ -d "$gate" ] && : >"$gate/release"
  for pid in "$first_pid" "$second_pid"; do
    [ -n "$pid" ] || continue
    kill -0 "$pid" 2>/dev/null && kill "$pid" 2>/dev/null
  done
  for pid in "$first_pid" "$second_pid"; do
    [ -n "$pid" ] && wait "$pid" 2>/dev/null
  done
  if [ "$status" -ne 0 ]; then
    echo "Phase 2A.2-F runner failed with status $status; worker logs follow." >&2
    for log in "$gate/w1.out" "$gate/w2.out"; do
      if [ -f "$log" ]; then
        echo "--- $(basename "$log") ---" >&2
        cat "$log" >&2
      fi
    done
  fi
  find "$gate" -mindepth 1 -maxdepth 1 -type f -exec rm -f -- {} \;
  cleanup_status=0
  if [ "$cleanup_needed" -eq 1 ]; then
    wp "$root/tests/phase-2a2f-concurrency-cleanup.php" || cleanup_status=$?
  fi
  if [ "$status" -eq 0 ] && [ "$cleanup_status" -ne 0 ]; then
    status=$cleanup_status
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
wait_gate() {
  file=$1
  i=0
  while [ ! -f "$gate/$file" ] && [ "$i" -lt 600 ]; do
    i=$((i+1))
    sleep 0.1
  done
  [ -f "$gate/$file" ] || { echo "Timed out waiting for explicit gate $file" >&2; exit 1; }
}

wp "$root/tests/phase-2a2f-concurrency-setup.php"
env DZN_PHASE_2A2F_WORKER=w1 "$DZN_PHASE_2A2F_WP_CLI" --path="$DZN_PHASE_2A2F_WP_PATH" --user="$DZN_PHASE_2A2F_WP_USER" eval-file "$root/tests/phase-2a2f-concurrency-worker.php" >"$gate/w1.out" 2>&1 &
first_pid=$!
wait_gate w1.connection
wait_gate w1.locked
env DZN_PHASE_2A2F_WORKER=w2 "$DZN_PHASE_2A2F_WP_CLI" --path="$DZN_PHASE_2A2F_WP_PATH" --user="$DZN_PHASE_2A2F_WP_USER" eval-file "$root/tests/phase-2a2f-concurrency-worker.php" >"$gate/w2.out" 2>&1 &
second_pid=$!
wait_gate w2.connection
wait_gate w2.started
case "$DZN_PHASE_2A2F_MODE" in
  r11*) wait_gate w2.observed ;;
  *) wp "$root/tests/phase-2a2f-concurrency-wait.php" ;;
esac
: >"$gate/release"
wait "$first_pid"
first_pid=
wait "$second_pid"
second_pid=

grep -q 'outcome=error' "$gate/w1.out" && { cat "$gate/w1.out"; exit 1; }
grep -q 'outcome=error' "$gate/w2.out" && { cat "$gate/w2.out"; exit 1; }
case "$DZN_PHASE_2A2F_MODE" in
  r1|r2b|r4|r5|r6|r7|r8|r9)
    grep -q 'outcome=success' "$gate/w1.out"
    grep -q 'outcome=rejected' "$gate/w2.out"
    ;;
  r2a|r3|r10)
    grep -q 'outcome=success' "$gate/w1.out"
    grep -q 'outcome=success' "$gate/w2.out"
    ;;
  r11*)
    grep -q 'outcome=success' "$gate/w1.out"
    grep -q 'outcome=observed' "$gate/w2.out"
    ;;
esac
wp "$root/tests/phase-2a2f-concurrency-verify.php"
printf '%s\n' "holder=$(tr '\n' ' ' < "$gate/w1.out")"
printf '%s\n' "contender=$(tr '\n' ' ' < "$gate/w2.out")"
printf '%s\n' "connections=w1:$(tr -d '\n' < "$gate/w1.connection"),w2:$(tr -d '\n' < "$gate/w2.connection")"
case "$DZN_PHASE_2A2F_MODE" in
  r11*) printf '%s\n' "gates=w1.locked,w2.started,w2.observed,release" ;;
  *)
    printf '%s\n' "attribution=$(tr -d '\n' < "$gate/w2.blocked")"
    printf '%s\n' "gates=w1.connection,w1.locked,w2.connection,w2.started,w2.blocked,release"
    ;;
esac
