#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
dzn_require_cached_images
dzn_assert_runtime_paths
dzn_candidate
mkdir -p "$DZN_DB_DIR" "$DZN_WP_DIR"
dzn_compose up --pull never -d --wait db
dzn_compose up --pull never -d wordpress
echo "runtime ready: $DZN_WP_URL (loopback only)"
