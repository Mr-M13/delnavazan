#!/usr/bin/env bash
# Disposable runtime acceptance for the Booking Coordination command centre.
# Synthetic @example.invalid identities only; no external provider or message transport is called.
set -euo pipefail
bin="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
source "$bin/common.sh"

dzn_acceptance_assert_state
"$bin/reset.sh"
"$bin/seed-identities.sh"

run_fixture() {
  local env_name="$1" env_value="$2" file="$3"
  docker run --rm --pull=never --network "$DZN_ACCEPTANCE_NETWORK" -u 0 \
    -e WP_ENVIRONMENT_TYPE=local -e WORDPRESS_DB_HOST=db \
    -e "WORDPRESS_DB_NAME=$DZN_ACCEPTANCE_DB_NAME" -e "WORDPRESS_DB_USER=$DZN_ACCEPTANCE_DB_USER" -e "WORDPRESS_DB_PASSWORD=$DZN_ACCEPTANCE_DB_PASSWORD" \
    -e "$env_name=$env_value" \
    -v "$DZN_ACCEPTANCE_WP_DIR:/var/www/html" -v "$DZN_ACCEPTANCE_SOURCE_DIR:/workspace:ro" \
    --entrypoint php "$DZN_ACCEPTANCE_CLI_IMAGE" -d memory_limit=512M /usr/local/bin/wp --path=/var/www/html --allow-root eval-file "/workspace/$file"
}

run_fixture DZN_COORDINATION_COMMAND_CENTRE_FIXTURE local_only acceptance/fixtures/coordination-command-centre.php
run_fixture DZN_COORDINATION_COMMAND_CENTRE_RUNTIME_TEST isolated platform/tests/coordination-command-centre-runtime.php

echo 'Coordination command-centre runtime acceptance: PASS'
