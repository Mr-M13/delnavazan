#!/usr/bin/env bash
# Disposable WordPress/MariaDB proof for post-introductory enrolment automation.
# Synthetic local data only; no live Stripe or Google provider call is made.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"

"$bin/run-fresh-install.sh"
# Run the impersonation regression outside WP-CLI so WordPress sees a real non-CLI request.
dzn_assert_runtime_paths
docker run --rm --pull=never --network "$DZN_NETWORK" -u 0 \
  -e WP_ENVIRONMENT_TYPE=local \
  -e WORDPRESS_DB_HOST=db \
  -e "WORDPRESS_DB_NAME=$DZN_DB_NAME" \
  -e "WORDPRESS_DB_USER=$DZN_DB_USER" \
  -e "WORDPRESS_DB_PASSWORD=$DZN_DB_PASSWORD" \
  -e "DZN_POST_INTRO_ACTOR_WEB_TEST=authority" \
  -v "$DZN_WP_DIR:/var/www/html" \
  -v "$DZN_PLUGIN_SOURCE:$DZN_PLUGIN_SOURCE:ro" \
  --entrypoint php "$DZN_CLI_IMAGE" -d memory_limit=512M \
  "$DZN_PLUGIN_SOURCE/platform/tests/post-intro-system-actor-web-runtime.php"
"$bin/provision-post-intro-actor.sh"
dzn_wp_env DZN_PHASE_2A2J_RUNTIME_TEST=fixture -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/phase-2a2j-fixture.php" --user=1
dzn_wp_env DZN_POST_INTRO_RUNTIME_TEST=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/post-intro-enrolment-automation-runtime.php" --user=1
echo 'Post-intro runtime: PASS'
