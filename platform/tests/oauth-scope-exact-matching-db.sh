#!/usr/bin/env bash
set -Eeuo pipefail
test_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_dir="$(cd "$test_dir/../.." && pwd)"
run_suffix="$$-${RANDOM:-0}"
network_name="dzn-oauth-scope-test-$run_suffix"
database_container="dzn-oauth-scope-db-$run_suffix"
database_name="dzn_oauth_scope_test_$run_suffix"
database_password="dzn-oauth-scope-test"
database_image="${DZN_OAUTH_SCOPE_TEST_DB_IMAGE:-mariadb:11.4}"
php_image="${DZN_OAUTH_SCOPE_TEST_PHP_IMAGE:-wordpress:cli-php8.3}"
cleanup(){
  docker rm -f "$database_container" >/dev/null 2>&1 || true
  docker network rm "$network_name" >/dev/null 2>&1 || true
}
trap cleanup EXIT
command -v docker >/dev/null || { echo 'error: Docker is required for the OAuth scope database regression.' >&2; exit 1; }
docker image inspect "$database_image" >/dev/null
docker image inspect "$php_image" >/dev/null
docker network create "$network_name" >/dev/null
docker run --detach --pull=never --name "$database_container" --network "$network_name" \
  -e MARIADB_DATABASE="$database_name" -e MARIADB_USER=wordpress -e MARIADB_PASSWORD="$database_password" -e MARIADB_ROOT_PASSWORD="$database_password" \
  "$database_image" --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci >/dev/null
for attempt in $(seq 1 30); do
  if docker exec "$database_container" mariadb-admin ping -h 127.0.0.1 -uroot -p"$database_password" --silent >/dev/null 2>&1; then break; fi
  if [ "$attempt" -eq 30 ]; then echo 'error: disposable MariaDB did not become ready.' >&2; exit 1; fi
  sleep 1
done
docker run --rm --pull=never --network "$network_name" -v "$repo_dir:/repo:ro" \
  -e DZN_OAUTH_SCOPE_TEST_DB_HOST="$database_container" -e DZN_OAUTH_SCOPE_TEST_DB_PORT=3306 -e DZN_OAUTH_SCOPE_TEST_DB_NAME="$database_name" \
  -e DZN_OAUTH_SCOPE_TEST_DB_USER=wordpress -e DZN_OAUTH_SCOPE_TEST_DB_PASSWORD="$database_password" \
  --entrypoint php "$php_image" /repo/platform/tests/oauth-scope-exact-matching-db.php
