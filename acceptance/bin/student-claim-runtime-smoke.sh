#!/usr/bin/env bash
set -euo pipefail
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd -P)"
source "$bin/common.sh"
dzn_acceptance_assert_state
run_id="dznstudent$(date +%s)"
dzn_acceptance_wp option update dzn_student_claim_runtime_marker "$run_id" >/dev/null
docker run --rm --pull=never --network "$DZN_ACCEPTANCE_NETWORK" -u 0 \
  -e WP_ENVIRONMENT_TYPE=local -e WORDPRESS_DB_HOST=db \
  -e "WORDPRESS_DB_NAME=$DZN_ACCEPTANCE_DB_NAME" -e "WORDPRESS_DB_USER=$DZN_ACCEPTANCE_DB_USER" \
  -e "WORDPRESS_DB_PASSWORD=$DZN_ACCEPTANCE_DB_PASSWORD" \
  -e DZN_STUDENT_CLAIM_RUNTIME_TEST=isolated -e "DZN_STUDENT_CLAIM_RUNTIME_RUN_ID=$run_id" \
  -v "$DZN_ACCEPTANCE_WP_DIR:/var/www/html" -v "$DZN_ACCEPTANCE_SOURCE_DIR:/workspace:ro" \
  --entrypoint php "$DZN_ACCEPTANCE_CLI_IMAGE" -d memory_limit=512M /usr/local/bin/wp \
  --path=/var/www/html --allow-root eval-file \
  /workspace/platform/tests/student-claim-isolated-runtime.php --user=1
