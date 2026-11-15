#!/usr/bin/env bash
# Run tools/ci/check.sh in the same PHP 8.3 image the devcontainer uses.
# The image tag is derived from .devcontainer/Dockerfile, so a Dockerfile change
# rebuilds instead of silently reusing a stale image.
set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
readonly DOCKERFILE="$REPO_ROOT/.devcontainer/Dockerfile"
if command -v sha256sum >/dev/null 2>&1; then
  dockerfile_hash="$(sha256sum "$DOCKERFILE" | awk '{print substr($1,1,12)}')"
else
  dockerfile_hash="$(shasum -a 256 "$DOCKERFILE" | awk '{print substr($1,1,12)}')"
fi
readonly IMAGE="delnavazan-ci-php83:$dockerfile_hash"

if ! command -v docker >/dev/null 2>&1; then
  echo 'FAIL  docker is not available; run tools/ci/check.sh inside the devcontainer instead.' >&2
  exit 2
fi

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
  printf 'Building %s from %s\n' "$IMAGE" "$DOCKERFILE"
  docker build --quiet -t "$IMAGE" "$REPO_ROOT/.devcontainer" >/dev/null
fi

exec docker run --rm \
  -e DZN_CI_CONTAINER=1 \
  -v "$REPO_ROOT":/repo \
  -w /repo \
  "$IMAGE" \
  bash tools/ci/check.sh "$@"
