#!/usr/bin/env bash
# Disposable WordPress/MariaDB proof for direct Teacher intro decisions and opportunity claims.
set -euo pipefail
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd -P)"
source "$bin/common.sh"
cleanup() {
  rm -f "$DZN_ACCEPTANCE_STATE_DIR"/opp-worker-*.json
  "$bin/destroy.sh" YES
}
trap cleanup EXIT

"$bin/reset.sh"
DZN_TEACHER_INTRO_RUNTIME=runtime dzn_acceptance_wp eval-file /workspace/platform/tests/teacher-intro-opportunity-runtime.php --user=1
DZN_TEACHER_INTRO_MIGRATION=runtime dzn_acceptance_wp eval-file /workspace/platform/tests/teacher-intro-migration-runtime.php --user=1

rm -f "$DZN_ACCEPTANCE_WP_DIR/.dzn-opp-ready-0" "$DZN_ACCEPTANCE_WP_DIR/.dzn-opp-ready-1" "$DZN_ACCEPTANCE_WP_DIR/.dzn-opp-go"

DZN_TEACHER_OPP_WORKER=runtime DZN_TEACHER_OPP_INDEX=0 \
  dzn_acceptance_wp eval-file /workspace/platform/tests/teacher-opportunity-claim-worker.php --user=1 \
  > "$DZN_ACCEPTANCE_STATE_DIR/opp-worker-0.json" 2>&1 &
worker0=$!
DZN_TEACHER_OPP_WORKER=runtime DZN_TEACHER_OPP_INDEX=1 \
  dzn_acceptance_wp eval-file /workspace/platform/tests/teacher-opportunity-claim-worker.php --user=1 \
  > "$DZN_ACCEPTANCE_STATE_DIR/opp-worker-1.json" 2>&1 &
worker1=$!

ready=false
for _ in $(seq 1 60); do
  if [ -f "$DZN_ACCEPTANCE_WP_DIR/.dzn-opp-ready-0" ] && [ -f "$DZN_ACCEPTANCE_WP_DIR/.dzn-opp-ready-1" ]; then
    ready=true
    break
  fi
  sleep 1
done
[ "$ready" = true ] || { echo 'error: opportunity workers did not reach the barrier.' >&2; exit 1; }
# The WordPress bind mount is populated by a root container; create the shared barrier
# through WP-CLI so the host runner never needs write access to that root-owned tree.
dzn_acceptance_wp eval 'file_put_contents(ABSPATH . ".dzn-opp-go", "go");'
wait "$worker0" || { cat "$DZN_ACCEPTANCE_STATE_DIR/opp-worker-0.json" >&2; exit 1; }
wait "$worker1" || { cat "$DZN_ACCEPTANCE_STATE_DIR/opp-worker-1.json" >&2; exit 1; }

cat "$DZN_ACCEPTANCE_STATE_DIR/opp-worker-0.json"
cat "$DZN_ACCEPTANCE_STATE_DIR/opp-worker-1.json"
DZN_TEACHER_OPP_VERIFY=runtime dzn_acceptance_wp eval-file /workspace/platform/tests/teacher-opportunity-claim-verify.php --user=1
