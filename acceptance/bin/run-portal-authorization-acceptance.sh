#!/usr/bin/env bash
# Disposable acceptance proof for classroom participant authorization, dual-role
# recording playback, legacy read-only portal behaviour and booking CSRF.
set -euo pipefail
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd -P)"
source "$bin/common.sh"

"$bin/reset.sh"
"$bin/seed-identities.sh"

run_fixture() {
  local env_name="$1" env_value="$2" file="$3"
  docker run --rm --pull=never --network "$DZN_ACCEPTANCE_NETWORK" -u 0 \
    -e WP_ENVIRONMENT_TYPE=local -e WORDPRESS_DB_HOST=db \
    -e "WORDPRESS_DB_NAME=$DZN_ACCEPTANCE_DB_NAME" -e "WORDPRESS_DB_USER=$DZN_ACCEPTANCE_DB_USER" -e "WORDPRESS_DB_PASSWORD=$DZN_ACCEPTANCE_DB_PASSWORD" \
    -e "$env_name=$env_value" \
    -v "$DZN_ACCEPTANCE_WP_DIR:/var/www/html" -v "$DZN_ACCEPTANCE_SOURCE_DIR:/workspace:ro" \
    --entrypoint php "$DZN_ACCEPTANCE_CLI_IMAGE" -d memory_limit=512M /usr/local/bin/wp --path=/var/www/html --allow-root eval-file "$file" --user=1
}
run_test() {
  local env_name="$1" file="$2"
  docker run --rm --pull=never --network "$DZN_ACCEPTANCE_NETWORK" -u 0 \
    -e WP_ENVIRONMENT_TYPE=local -e WORDPRESS_DB_HOST=db \
    -e "WORDPRESS_DB_NAME=$DZN_ACCEPTANCE_DB_NAME" -e "WORDPRESS_DB_USER=$DZN_ACCEPTANCE_DB_USER" -e "WORDPRESS_DB_PASSWORD=$DZN_ACCEPTANCE_DB_PASSWORD" \
    -e "$env_name=authority" \
    -v "$DZN_ACCEPTANCE_WP_DIR:/var/www/html" -v "$DZN_ACCEPTANCE_SOURCE_DIR:/workspace:ro" \
    --entrypoint php "$DZN_ACCEPTANCE_CLI_IMAGE" -d memory_limit=512M /usr/local/bin/wp --path=/var/www/html --allow-root eval-file "$file" --user=1
}

run_fixture DZN_PHASE_2A2J_RUNTIME_TEST fixture /workspace/platform/tests/phase-2a2j-fixture.php
run_fixture DZN_ENROLMENT_WORKSPACE_FIXTURE local_only /workspace/platform/tests/enrolment-workspace-runtime-fixture.php
run_test DZN_CLASSROOM_AUTH_RUNTIME /workspace/platform/tests/classroom-participant-authorization-runtime.php
run_test DZN_LEGACY_READONLY_RUNTIME /workspace/platform/tests/legacy-portal-readonly-runtime.php
"$bin/check-booking-csrf.sh"
echo 'Portal authorization and CSRF acceptance: PASS'
