#!/bin/sh
# Deterministic Race A-D controller. Requires an isolated local/development
# WP-CLI root and the synthetic fixture consumed by concurrency-setup.php.
set -eu
: "${DZN_PHASE_2A2E_WP_CLI:?Set the isolated wp executable}"
: "${DZN_PHASE_2A2E_WP_PATH:?Set the isolated WordPress root}"
: "${DZN_PHASE_2A2E_WP_USER:?Set a disposable user with the required Platform capabilities}"
: "${DZN_PHASE_2A2E_GATE_DIR:?Set an empty gate directory visible to both WP-CLI sessions}"
case "${DZN_PHASE_2A2E_RUNTIME_TEST:-}" in isolated) ;; *) echo "Phase 2A.2-E runner refused." >&2; exit 1;; esac
case "${DZN_PHASE_2A2E_MODE:-}" in a|b|c1|c2|d1|d2|x) ;; *) echo "Set DZN_PHASE_2A2E_MODE to a, b, c1, c2, d1, d2 or x." >&2; exit 1;; esac
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
gate=$DZN_PHASE_2A2E_GATE_DIR
[ -d "$gate" ] && [ -z "$(find "$gate" -mindepth 1 -maxdepth 1 -print -quit)" ] || { echo "Gate directory must exist and be empty." >&2; exit 1; }
wp() { "$DZN_PHASE_2A2E_WP_CLI" --path="$DZN_PHASE_2A2E_WP_PATH" --user="$DZN_PHASE_2A2E_WP_USER" eval-file "$1"; }
has_fixture() { "$DZN_PHASE_2A2E_WP_CLI" --path="$DZN_PHASE_2A2E_WP_PATH" --user="$DZN_PHASE_2A2E_WP_USER" eval 'exit(is_array(get_option("dzn_phase_2a2e_concurrency_fixture"))?0:1);'; }
wait_gate() { file=$1; i=0; while [ ! -f "$gate/$file" ] && [ "$i" -lt 300 ]; do i=$((i+1)); sleep 0.1; done; [ -f "$gate/$file" ] || { echo "Timed out waiting for explicit gate $file" >&2; exit 1; }; }
has_fixture || wp "$root/tests/phase-2a2e-concurrency-fixture.php"
wp "$root/tests/phase-2a2e-concurrency-setup.php"
case "$DZN_PHASE_2A2E_MODE" in
  a) first=a1:acceptance; second=a2:acceptance;; b) first=b1:acceptance; second=b2:acceptance;; x) first=x1:acceptance; second=x2:acceptance;;
  c1) first=c1:issuance; second=c2:acceptance;; c2) first=c1:acceptance; second=c2:issuance;;
  d1) first=d1:erasure; second=d2:acceptance;; d2) first=d1:acceptance; second=d2:erasure;;
esac
first_name=${first%%:*}; first_action=${first#*:}; second_name=${second%%:*}; second_action=${second#*:}
env DZN_PHASE_2A2E_WORKER="$first_name" DZN_PHASE_2A2E_ACTION="$first_action" "$DZN_PHASE_2A2E_WP_CLI" --path="$DZN_PHASE_2A2E_WP_PATH" --user="$DZN_PHASE_2A2E_WP_USER" eval-file "$root/tests/phase-2a2e-concurrency-worker.php" >"$gate/$first_name.out" 2>&1 & first_pid=$!
wait_gate "$first_name.locked"
env DZN_PHASE_2A2E_WORKER="$second_name" DZN_PHASE_2A2E_ACTION="$second_action" "$DZN_PHASE_2A2E_WP_CLI" --path="$DZN_PHASE_2A2E_WP_PATH" --user="$DZN_PHASE_2A2E_WP_USER" eval-file "$root/tests/phase-2a2e-concurrency-worker.php" >"$gate/$second_name.out" 2>&1 & second_pid=$!
wait_gate "$second_name.started"
: > "$gate/release"; wait "$first_pid"; wait "$second_pid"
case "$DZN_PHASE_2A2E_MODE" in a) grep -q 'outcome=success' "$gate/a1.out"; grep -q 'idempotent=1' "$gate/a2.out";; b) grep -q 'outcome=success' "$gate/b1.out"; grep -q 'outcome=conflict' "$gate/b2.out";; x) grep -q 'outcome=success' "$gate/x2.out"; grep -q 'outcome=conflict' "$gate/x1.out";; c1) grep -q 'outcome=issued' "$gate/c1.out"; grep -q 'outcome=rejected' "$gate/c2.out";; c2) grep -q 'outcome=success' "$gate/c1.out"; grep -q 'outcome=issued' "$gate/c2.out";; d1) grep -q 'outcome=erased' "$gate/d1.out"; grep -q 'outcome=rejected' "$gate/d2.out";; d2) grep -q 'outcome=success' "$gate/d1.out"; grep -q 'outcome=erased' "$gate/d2.out";; esac
wp "$root/tests/phase-2a2e-concurrency-verify.php"
rm -f "$gate"/*
wp "$root/tests/phase-2a2e-concurrency-cleanup.php"
