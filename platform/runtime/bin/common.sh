#!/usr/bin/env bash
# Shared, offline-only helpers. Source this file; do not execute it directly.
set -euo pipefail
DZN_BIN_DIR="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
DZN_RUNTIME_DIR="$(cd "$DZN_BIN_DIR/.." && pwd)"
DZN_SHARED_CHECKOUT="$(realpath -q "$DZN_RUNTIME_DIR/..")"
if [ -f "$DZN_RUNTIME_DIR/.env" ]; then set -a; . "$DZN_RUNTIME_DIR/.env"; set +a; fi
dzn_canonicalize_path(){
  # Resolve any path (state directory, worktree, database, WordPress or plugin
  # source) to its physical form without creating the requested directory.  A
  # missing final component is retained under its physical existing ancestor, so
  # the boundary tests below cannot be bypassed by a symlink or a relative alias.
  local path="${1:?path is required}" resolved part suffix=''
  case "$path" in /*) ;; *) path="$(pwd -P)/$path";; esac
  while ! resolved="$(realpath -q "$path" 2>/dev/null)"; do
    [ ! -L "$path" ] || { echo "error: path contains an unresolved symlink: $path" >&2; return 1; }
    part="${path##*/}"
    [ -n "$part" ] || { echo "error: cannot resolve path: $1" >&2; return 1; }
    if [ -n "$suffix" ]; then suffix="$part/$suffix"; else suffix="$part"; fi
    path="${path%/*}"; [ -n "$path" ] || path=/
  done
  while [ -n "$suffix" ]; do
    part="${suffix%%/*}"
    if [ "$suffix" = "$part" ]; then suffix=''; else suffix="${suffix#*/}"; fi
    case "$part" in ''|.) ;; ..) resolved="$(dirname "$resolved")";; *) resolved="$resolved/$part";; esac
  done
  # A lexical `..` can make the completed path exist after the loop above; resolve
  # it once more so an alias introduced after a missing component is also physical.
  realpath -q "$resolved" 2>/dev/null || printf '%s\n' "$resolved"
}
DZN_RUNTIME_STATE_DIR="$(dzn_canonicalize_path "${DZN_RUNTIME_STATE_DIR:-/tmp/dzn-platform-schema33-local}")"
DZN_COMPOSE_PROJECT="${DZN_COMPOSE_PROJECT:-dzn-platform-schema33-local}"
DZN_DB_DIR="$DZN_RUNTIME_STATE_DIR/mariadb"; DZN_WP_DIR="$DZN_RUNTIME_STATE_DIR/wordpress"
DZN_PLUGIN_WORKTREE="$DZN_RUNTIME_STATE_DIR/candidate"; DZN_BASE30_WORKTREE="$DZN_RUNTIME_STATE_DIR/schema30"; DZN_BASE31_WORKTREE="$DZN_RUNTIME_STATE_DIR/schema31"
DZN_PLUGIN_SOURCE="$(dzn_canonicalize_path "${DZN_PLUGIN_SOURCE:-$DZN_PLUGIN_WORKTREE}")"
dzn_assert_state_outside_checkout(){ case "$DZN_RUNTIME_STATE_DIR" in "$DZN_SHARED_CHECKOUT"|"$DZN_SHARED_CHECKOUT"/*) echo 'error: DZN_RUNTIME_STATE_DIR must be outside the shared checkout.' >&2; exit 1;; esac; }
dzn_count_path_components(){
  local path="${1#/}" count=0 part
  while [ -n "$path" ]; do
    part="${path%%/*}"
    [ -z "$part" ] || count=$((count + 1))
    if [ "$path" = "$part" ]; then path=''; else path="${path#*/}"; fi
  done
  printf '%s\n' "$count"
}
dzn_path_device(){
  # Best-effort device number.  BSD `stat -f` prints a bare device number; GNU
  # `stat -f` means "filesystem" and prints prose, so it is reported as
  # unavailable and the mount-point test below is skipped rather than guessed.
  local out
  out="$(stat -f '%d' "$1" 2>/dev/null || true)"
  case "$out" in ''|*[!0-9]*) return 1;; esac
  printf '%s\n' "$out"
}
dzn_assert_state_dir_dedicated(){
  # The state directory is the only directory this harness may ever delete, so it
  # must itself be a dedicated, narrowly scoped disposable area.  The filesystem
  # root, the broad shared roots below, a mount point, a directory that contains
  # the shared checkout and the user's home directory (or any of its ancestors)
  # are all refused.  A broad state directory can therefore never become a
  # destructive target, whatever value the caller supplies.
  local dir="$DZN_RUNTIME_STATE_DIR" physical check parent forbidden dir_device parent_device
  [ -n "$dir" ] || { echo 'error: DZN_RUNTIME_STATE_DIR must not be empty.' >&2; exit 1; }
  physical="$(dzn_canonicalize_path "$dir")" || exit 1
  case "$physical" in
    '/') echo 'error: refusing the filesystem root as DZN_RUNTIME_STATE_DIR.' >&2; exit 1;;
    /*) ;;
    *) echo "error: DZN_RUNTIME_STATE_DIR must be an absolute path: $dir" >&2; exit 1;;
  esac
  # macOS makes /tmp, /var and /etc aliases of /private/...; strip that prefix so
  # both spellings of a broad shared root are judged by the same list.
  case "$physical" in
    /private) check='/';;
    /private/*) check="${physical#/private}";;
    *) check="$physical";;
  esac
  for forbidden in / /tmp /var /var/tmp /etc /usr /usr/local /bin /sbin /opt /srv /mnt /media /Volumes /System /Library /Applications /Users /home /root /dev /proc /sys /cores /private; do
    [ "$physical" != "$forbidden" ] && [ "$check" != "$forbidden" ] || { echo "error: refusing a broad shared directory as DZN_RUNTIME_STATE_DIR: $physical" >&2; exit 1; }
  done
  [ "$(dzn_count_path_components "$check")" -ge 2 ] || { echo "error: DZN_RUNTIME_STATE_DIR must be a dedicated directory at least two levels deep: $physical" >&2; exit 1; }
  case "$DZN_SHARED_CHECKOUT/" in "$physical"/*) echo "error: DZN_RUNTIME_STATE_DIR must not contain the shared checkout: $physical" >&2; exit 1;; esac
  case "${HOME:-/nonexistent-home}/" in "$physical"/*) echo "error: DZN_RUNTIME_STATE_DIR must not be a user data directory: $physical" >&2; exit 1;; esac
  parent="${physical%/*}"; [ -n "$parent" ] || parent=/
  if [ -d "$physical" ] && [ -d "$parent" ] && command -v stat >/dev/null 2>&1; then
    dir_device="$(dzn_path_device "$physical")" || dir_device=''
    parent_device="$(dzn_path_device "$parent")" || parent_device=''
    if [ -n "$dir_device" ] && [ -n "$parent_device" ] && [ "$dir_device" != "$parent_device" ]; then
      echo "error: refusing a mount point as DZN_RUNTIME_STATE_DIR: $physical" >&2; exit 1
    fi
  fi
}
dzn_assert_disposable_path(){
  # Compare physical paths, never lexical ones: an existing symlink, a relative
  # alias or a `..` component must not be able to pose as a disposable path.  The
  # state directory itself is never a disposable path: only a path strictly below
  # it may be created or deleted, so a broad DZN_RUNTIME_STATE_DIR can never be
  # turned into a destructive target by being its own child.
  local path="${1:?disposable path is required}" physical
  physical="$(dzn_canonicalize_path "$path")" || exit 1
  case "$physical" in
    "$DZN_RUNTIME_STATE_DIR"/*) ;;
    "$DZN_RUNTIME_STATE_DIR") echo "refusing the state directory itself as a disposable path: $path" >&2; exit 1;;
    *) echo "refusing non-disposable path: $path (physical path: $physical)" >&2; exit 1;;
  esac
}
dzn_assert_not_symlink(){
  local path="${1:?path is required}"
  [ ! -L "$path" ] || { echo "error: refusing a symlinked runtime path: $path" >&2; exit 1; }
}
dzn_remove_disposable_path(){
  # The one deletion primitive.  Every removal re-validates the physical path it
  # is about to delete, so there is no code path that deletes anything that is not
  # a real, non-symlinked child of the dedicated state directory.
  local path="${1:?disposable path is required}"
  dzn_assert_not_symlink "$path"
  dzn_assert_disposable_path "$path"
  rm -rf "$path"
}
dzn_assert_runtime_paths(){
  # Every worktree, database, WordPress and plugin-source path this harness
  # creates, deletes or mounts into a container is re-resolved and validated
  # before use, so a pre-existing symlink cannot redirect a run to the shared
  # checkout or to any other non-disposable location.  This runs at source time
  # (below) and again at each container entry point, because a sourcing script
  # may retarget DZN_PLUGIN_SOURCE afterwards.
  local path
  dzn_assert_state_outside_checkout
  dzn_assert_state_dir_dedicated
  for path in "$DZN_PLUGIN_SOURCE" "$DZN_DB_DIR" "$DZN_WP_DIR" "$DZN_PLUGIN_WORKTREE" "$DZN_BASE30_WORKTREE" "$DZN_BASE31_WORKTREE"; do
    dzn_assert_disposable_path "$path"
  done
}
dzn_assert_runtime_paths
DZN_NETWORK="$DZN_COMPOSE_PROJECT"_runtime
DZN_DB_IMAGE="${DZN_DB_IMAGE:-mariadb:11.4}"; DZN_WP_IMAGE="${DZN_WP_IMAGE:-wordpress:php8.3-apache}"; DZN_CLI_IMAGE="${DZN_CLI_IMAGE:-wordpress:cli-php8.3}"
DZN_DB_NAME="${DZN_DB_NAME:-wordpress}"; DZN_DB_USER="${DZN_DB_USER:-wordpress}"; DZN_DB_PASSWORD="${DZN_DB_PASSWORD:-wordpress}"; DZN_DB_ROOT_PASSWORD="${DZN_DB_ROOT_PASSWORD:-root}"
DZN_WP_PORT="${DZN_WP_PORT:-18080}"; DZN_WP_URL="${DZN_WP_URL:-http://127.0.0.1:$DZN_WP_PORT}"; DZN_TARGET_REF="${DZN_TARGET_REF:-HEAD}"
export DZN_RUNTIME_STATE_DIR DZN_COMPOSE_PROJECT DZN_DB_DIR DZN_WP_DIR DZN_NETWORK DZN_DB_IMAGE DZN_WP_IMAGE DZN_CLI_IMAGE DZN_DB_NAME DZN_DB_USER DZN_DB_PASSWORD DZN_DB_ROOT_PASSWORD DZN_WP_PORT DZN_WP_URL DZN_PLUGIN_SOURCE
dzn_compose(){ dzn_assert_runtime_paths; docker compose --project-directory "$DZN_RUNTIME_DIR" --project-name "$DZN_COMPOSE_PROJECT" -f "$DZN_RUNTIME_DIR/compose.yaml" "$@"; }
dzn_require_cached_images(){
  command -v docker >/dev/null || { echo 'error: Docker is required.' >&2; exit 1; }
  local image
  for image in "$DZN_DB_IMAGE" "$DZN_WP_IMAGE" "$DZN_CLI_IMAGE"; do
    docker image inspect "$image" >/dev/null 2>&1 || { echo "error: required image is not cached locally: $image" >&2; echo '       This harness never pulls images. Preload it, then retry.' >&2; exit 1; }
  done
}
dzn_prepare_worktree(){
  local path="$1" ref="$2" actual expected
  dzn_assert_state_outside_checkout; expected="$(git -C "$DZN_SHARED_CHECKOUT" rev-parse "$ref^{commit}")"
  # A pre-existing worktree path is only reusable when it is a real directory
  # that still resolves inside the disposable state directory.  A symlink is
  # refused even when its target is disposable, so an existing
  # `state/candidate -> <shared checkout>` alias can never be reused as the
  # mounted plugin source merely because its Git HEAD matches the wanted ref.
  dzn_assert_not_symlink "$path"; dzn_assert_disposable_path "$path"
  if [ -e "$path" ]; then actual="$(git -C "$path" rev-parse HEAD 2>/dev/null || true)"; [ "$actual" = "$expected" ] || { echo "error: worktree exists at a different ref: $path" >&2; exit 1; }; return; fi
  mkdir -p "$DZN_RUNTIME_STATE_DIR"; git -C "$DZN_SHARED_CHECKOUT" worktree add --detach "$path" "$expected" >/dev/null
}
dzn_candidate(){ dzn_prepare_worktree "$DZN_PLUGIN_WORKTREE" "$DZN_TARGET_REF"; }
dzn_wp(){
  dzn_assert_runtime_paths
  docker run --rm --pull=never --network "$DZN_NETWORK" -u 0 -e WP_ENVIRONMENT_TYPE=local -e WORDPRESS_DB_HOST=db -e "WORDPRESS_DB_NAME=$DZN_DB_NAME" -e "WORDPRESS_DB_USER=$DZN_DB_USER" -e "WORDPRESS_DB_PASSWORD=$DZN_DB_PASSWORD" -v "$DZN_WP_DIR:/var/www/html" -v "$DZN_PLUGIN_SOURCE:$DZN_PLUGIN_SOURCE:ro" --entrypoint php "$DZN_CLI_IMAGE" -d memory_limit=512M /usr/local/bin/wp --path=/var/www/html --allow-root "$@"
}
dzn_wp_env(){
  local -a envs=(); while [ "$#" -gt 0 ] && [ "$1" != -- ]; do envs+=(-e "$1"); shift; done; [ "$#" -gt 0 ] && shift
  dzn_assert_runtime_paths
  docker run --rm --pull=never --network "$DZN_NETWORK" -u 0 "${envs[@]}" -e WP_ENVIRONMENT_TYPE=local -e WORDPRESS_DB_HOST=db -e "WORDPRESS_DB_NAME=$DZN_DB_NAME" -e "WORDPRESS_DB_USER=$DZN_DB_USER" -e "WORDPRESS_DB_PASSWORD=$DZN_DB_PASSWORD" -v "$DZN_WP_DIR:/var/www/html" -v "$DZN_PLUGIN_SOURCE:$DZN_PLUGIN_SOURCE:ro" --entrypoint php "$DZN_CLI_IMAGE" -d memory_limit=512M /usr/local/bin/wp --path=/var/www/html --allow-root "$@"
}
dzn_php(){ dzn_assert_runtime_paths; docker run --rm --pull=never -v "$DZN_RUNTIME_STATE_DIR:/state" --entrypoint php "$DZN_CLI_IMAGE" "$@"; }
dzn_historical_runner(){
  # Historical runner sources are deliberately unmodified. Their private docker shim
  # adds the harness transport policy without altering their test semantics.
  local runner="$1"; shift
  local shim="$DZN_RUNTIME_STATE_DIR/no-pull-bin" real
  dzn_assert_not_symlink "$shim"; dzn_assert_disposable_path "$shim"
  real="$(command -v docker)"
  mkdir -p "$shim"
  printf '#!/usr/bin/env sh\nif [ "$1" = run ]; then shift; exec "%s" run --pull=never "$@"; fi\nexec "%s" "$@"\n' "$real" "$real" >"$shim/docker"
  chmod 700 "$shim/docker"
  PATH="$shim:$PATH" sh "$runner" "$@"
}
