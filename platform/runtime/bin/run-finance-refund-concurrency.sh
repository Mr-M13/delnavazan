#!/usr/bin/env bash
# Actual database-backed concurrency proof for administrator manual refunds.
set -euo pipefail
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
source "$bin/common.sh"

dzn_require_cached_images
"$bin/run-fresh-install.sh"
dzn_candidate
DZN_PLUGIN_SOURCE="$DZN_PLUGIN_WORKTREE/platform"
export DZN_PLUGIN_SOURCE
dzn_assert_runtime_paths

dzn_wp_env DZN_PHASE_2A2J_RUNTIME_TEST=fixture -- eval-file "$DZN_PLUGIN_SOURCE/tests/phase-2a2j-fixture.php" --user=1
dzn_wp_env DZN_FINANCE_REFUND_CONCURRENCY_TEST=setup -- eval-file "$DZN_PLUGIN_SOURCE/tests/finance-refund-concurrency-setup.php" --user=1

results="$DZN_WP_DIR/refund-results"
mkdir -p "$results"
rm -f "$results"/*.json "$results/start"
same_key="refund-same-$(date +%s)-$$"
same_evidence="CONC-SAME-$(date +%s)-$$"

dzn_wp_env \
  DZN_FINANCE_REFUND_CONCURRENCY_TEST=worker \
  DZN_REFUND_SCENARIO=same \
  DZN_REFUND_KEY="$same_key" \
  DZN_REFUND_AMOUNT=60.00 \
  DZN_REFUND_EVIDENCE="$same_evidence" \
  DZN_REFUND_START_FILE=/var/www/html/refund-results/start \
  DZN_REFUND_RESULT_FILE=/var/www/html/refund-results/same-a.json \
  -- eval-file "$DZN_PLUGIN_SOURCE/tests/finance-refund-concurrency-worker.php" --user=1 >/tmp/dzn-refund-same-a.log 2>&1 &
pid_a=$!
dzn_wp_env \
  DZN_FINANCE_REFUND_CONCURRENCY_TEST=worker \
  DZN_REFUND_SCENARIO=same \
  DZN_REFUND_KEY="$same_key" \
  DZN_REFUND_AMOUNT=60.00 \
  DZN_REFUND_EVIDENCE="$same_evidence" \
  DZN_REFUND_START_FILE=/var/www/html/refund-results/start \
  DZN_REFUND_RESULT_FILE=/var/www/html/refund-results/same-b.json \
  -- eval-file "$DZN_PLUGIN_SOURCE/tests/finance-refund-concurrency-worker.php" --user=1 >/tmp/dzn-refund-same-b.log 2>&1 &
pid_b=$!

dzn_wp_env \
  DZN_FINANCE_REFUND_CONCURRENCY_TEST=worker \
  DZN_REFUND_SCENARIO=diff \
  DZN_REFUND_KEY="refund-diff-a-$same_key" \
  DZN_REFUND_AMOUNT=80.00 \
  DZN_REFUND_EVIDENCE="CONC-DIFF-A-$same_key" \
  DZN_REFUND_START_FILE=/var/www/html/refund-results/start \
  DZN_REFUND_RESULT_FILE=/var/www/html/refund-results/diff-a.json \
  -- eval-file "$DZN_PLUGIN_SOURCE/tests/finance-refund-concurrency-worker.php" --user=1 >/tmp/dzn-refund-diff-a.log 2>&1 &
pid_c=$!
dzn_wp_env \
  DZN_FINANCE_REFUND_CONCURRENCY_TEST=worker \
  DZN_REFUND_SCENARIO=diff \
  DZN_REFUND_KEY="refund-diff-b-$same_key" \
  DZN_REFUND_AMOUNT=80.00 \
  DZN_REFUND_EVIDENCE="CONC-DIFF-B-$same_key" \
  DZN_REFUND_START_FILE=/var/www/html/refund-results/start \
  DZN_REFUND_RESULT_FILE=/var/www/html/refund-results/diff-b.json \
  -- eval-file "$DZN_PLUGIN_SOURCE/tests/finance-refund-concurrency-worker.php" --user=1 >/tmp/dzn-refund-diff-b.log 2>&1 &
pid_d=$!

# Release all four workers from the same barrier.
touch "$results/start"
set +e
wait "$pid_a"; rc_a=$?
wait "$pid_b"; rc_b=$?
wait "$pid_c"; rc_c=$?
wait "$pid_d"; rc_d=$?
set -e
(( rc_a == 0 && rc_b == 0 && rc_c == 0 && rc_d == 0 )) || { echo "Refund worker exited non-zero: $rc_a/$rc_b/$rc_c/$rc_d" >&2; exit 1; }

dzn_wp_env \
  DZN_FINANCE_REFUND_CONCURRENCY_TEST=verify \
  DZN_REFUND_SAME_KEY="$same_key" \
  DZN_REFUND_SAME_EVIDENCE="$same_evidence" \
  DZN_REFUND_RESULT_DIR=/var/www/html/refund-results \
  -- eval-file "$DZN_PLUGIN_SOURCE/tests/finance-refund-concurrency-verify.php" --user=1

echo 'Finance refund concurrency: PASS'
