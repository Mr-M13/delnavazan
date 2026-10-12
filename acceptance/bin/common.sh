#!/usr/bin/env bash
# Shared helpers for the local, disposable acceptance environment.
set -euo pipefail

DZN_ACCEPTANCE_BIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
DZN_ACCEPTANCE_DIR="$(cd "$DZN_ACCEPTANCE_BIN_DIR/.." && pwd -P)"
DZN_ACCEPTANCE_SOURCE_DIR="$(cd "$DZN_ACCEPTANCE_DIR/.." && pwd -P)"
DZN_ACCEPTANCE_STATE_DIR="$DZN_ACCEPTANCE_DIR/.state"
DZN_ACCEPTANCE_DB_DIR="$DZN_ACCEPTANCE_STATE_DIR/mariadb"
DZN_ACCEPTANCE_WP_DIR="$DZN_ACCEPTANCE_STATE_DIR/wordpress"
# Keep Compose ownership non-configurable so `destroy` can never be redirected
# at an unrelated local project through an inherited environment variable.
DZN_ACCEPTANCE_COMPOSE_PROJECT="dzn-delnavazan-acceptance-local"
DZN_ACCEPTANCE_WP_PORT="${DZN_ACCEPTANCE_WP_PORT:-18081}"
DZN_ACCEPTANCE_WP_URL="http://127.0.0.1:$DZN_ACCEPTANCE_WP_PORT"
DZN_ACCEPTANCE_DB_IMAGE="${DZN_ACCEPTANCE_DB_IMAGE:-mariadb:11.4}"
DZN_ACCEPTANCE_WP_IMAGE="${DZN_ACCEPTANCE_WP_IMAGE:-wordpress:php8.3-apache}"
DZN_ACCEPTANCE_CLI_IMAGE="${DZN_ACCEPTANCE_CLI_IMAGE:-wordpress:cli-php8.3}"
DZN_ACCEPTANCE_DB_NAME="${DZN_ACCEPTANCE_DB_NAME:-wordpress}"
DZN_ACCEPTANCE_DB_USER="${DZN_ACCEPTANCE_DB_USER:-wordpress}"
DZN_ACCEPTANCE_DB_PASSWORD="${DZN_ACCEPTANCE_DB_PASSWORD:-wordpress}"
DZN_ACCEPTANCE_DB_ROOT_PASSWORD="${DZN_ACCEPTANCE_DB_ROOT_PASSWORD:-root}"
DZN_ACCEPTANCE_NETWORK="${DZN_ACCEPTANCE_COMPOSE_PROJECT}_network"

export DZN_ACCEPTANCE_SOURCE_DIR DZN_ACCEPTANCE_STATE_DIR DZN_ACCEPTANCE_DB_DIR DZN_ACCEPTANCE_WP_DIR
export DZN_ACCEPTANCE_COMPOSE_PROJECT DZN_ACCEPTANCE_WP_PORT DZN_ACCEPTANCE_WP_URL DZN_ACCEPTANCE_DB_IMAGE DZN_ACCEPTANCE_WP_IMAGE DZN_ACCEPTANCE_CLI_IMAGE
export DZN_ACCEPTANCE_DB_NAME DZN_ACCEPTANCE_DB_USER DZN_ACCEPTANCE_DB_PASSWORD DZN_ACCEPTANCE_DB_ROOT_PASSWORD

dzn_acceptance_assert_state() {
  # State is fixed beneath this package. Refusing a symlink keeps cleanup from escaping.
  [ ! -L "$DZN_ACCEPTANCE_STATE_DIR" ] || { echo "error: acceptance state may not be a symlink: $DZN_ACCEPTANCE_STATE_DIR" >&2; exit 1; }
  [ "$(dirname "$DZN_ACCEPTANCE_STATE_DIR")" = "$DZN_ACCEPTANCE_DIR" ] || { echo 'error: invalid acceptance state location' >&2; exit 1; }
  [ "$(basename "$DZN_ACCEPTANCE_STATE_DIR")" = '.state' ] || { echo 'error: invalid acceptance state name' >&2; exit 1; }
  [ -d "$DZN_ACCEPTANCE_SOURCE_DIR/platform" ] && [ -d "$DZN_ACCEPTANCE_SOURCE_DIR/theme" ] || { echo 'error: acceptance source must be the repository root with platform/ and theme/.' >&2; exit 1; }
}

dzn_acceptance_remove_child() {
  local path="$1"
  case "$path" in "$DZN_ACCEPTANCE_DB_DIR"|"$DZN_ACCEPTANCE_WP_DIR"|"$DZN_ACCEPTANCE_STATE_DIR/cookies"|"$DZN_ACCEPTANCE_STATE_DIR/http") ;; *) echo "error: refusing unknown acceptance cleanup target: $path" >&2; exit 1;; esac
  [ ! -L "$path" ] || { echo "error: refusing symlinked acceptance cleanup target: $path" >&2; exit 1; }
  [ ! -e "$path" ] || rm -rf "$path"
}

dzn_acceptance_compose() {
  dzn_acceptance_assert_state
  docker compose --project-directory "$DZN_ACCEPTANCE_DIR" --project-name "$DZN_ACCEPTANCE_COMPOSE_PROJECT" -f "$DZN_ACCEPTANCE_DIR/compose.yaml" "$@"
}

dzn_acceptance_require_cached_images() {
  command -v docker >/dev/null || { echo 'error: Docker is required.' >&2; exit 1; }
  local image
  for image in "$DZN_ACCEPTANCE_DB_IMAGE" "$DZN_ACCEPTANCE_WP_IMAGE" "$DZN_ACCEPTANCE_CLI_IMAGE"; do
    docker image inspect "$image" >/dev/null 2>&1 || { echo "error: required image is not cached locally: $image" >&2; echo '       This acceptance harness never pulls images.' >&2; exit 1; }
  done
}

dzn_acceptance_wp() {
  dzn_acceptance_assert_state
  docker run --rm --pull=never --network "$DZN_ACCEPTANCE_NETWORK" -u 0 \
    -e WP_ENVIRONMENT_TYPE=local -e WORDPRESS_DB_HOST=db \
    -e "WORDPRESS_DB_NAME=$DZN_ACCEPTANCE_DB_NAME" -e "WORDPRESS_DB_USER=$DZN_ACCEPTANCE_DB_USER" -e "WORDPRESS_DB_PASSWORD=$DZN_ACCEPTANCE_DB_PASSWORD" \
    -v "$DZN_ACCEPTANCE_WP_DIR:/var/www/html" -v "$DZN_ACCEPTANCE_SOURCE_DIR:/workspace:ro" \
    --entrypoint php "$DZN_ACCEPTANCE_CLI_IMAGE" -d memory_limit=512M /usr/local/bin/wp --path=/var/www/html --allow-root "$@"
}
