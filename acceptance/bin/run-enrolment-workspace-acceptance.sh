#!/usr/bin/env bash
# Disposable runtime acceptance for the consolidated Enrolment workspace.
# Synthetic @example.invalid identities only; no external provider or message transport is called.
set -euo pipefail
bin="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
source "$bin/common.sh"

dzn_acceptance_assert_state
"$bin/reset.sh"
"$bin/seed-identities.sh"

run_fixture() {
  local env_name="$1" file="$2"
  docker run --rm --pull=never --network "$DZN_ACCEPTANCE_NETWORK" -u 0 \
    -e WP_ENVIRONMENT_TYPE=local -e WORDPRESS_DB_HOST=db \
    -e "WORDPRESS_DB_NAME=$DZN_ACCEPTANCE_DB_NAME" -e "WORDPRESS_DB_USER=$DZN_ACCEPTANCE_DB_USER" -e "WORDPRESS_DB_PASSWORD=$DZN_ACCEPTANCE_DB_PASSWORD" \
    -e "$env_name=local_only" \
    -v "$DZN_ACCEPTANCE_WP_DIR:/var/www/html" -v "$DZN_ACCEPTANCE_SOURCE_DIR:/workspace:ro" \
    --entrypoint php "$DZN_ACCEPTANCE_CLI_IMAGE" -d memory_limit=512M /usr/local/bin/wp --path=/var/www/html --allow-root eval-file "/workspace/platform/tests/$file"
}

run_fixture DZN_ENROLMENT_WORKSPACE_FIXTURE enrolment-workspace-runtime-fixture.php
run_fixture DZN_ENROLMENT_CANONICAL_FIXTURE enrolment-workspace-canonical-fixture.php
run_fixture DZN_ENROLMENT_POST_INTRO_FIXTURE enrolment-workspace-post-intro-fixture.php
run_fixture DZN_ENROLMENT_ATTENDANCE_FIXTURE enrolment-workspace-attendance-fixture.php
run_fixture DZN_ENROLMENT_WORKSPACE_RUNTIME_TEST enrolment-workspace-runtime-test.php
run_fixture DZN_ENROLMENT_ACTIONS_RUNTIME_TEST enrolment-workspace-actions-runtime-test.php

echo 'Enrolment workspace runtime acceptance: PASS'
