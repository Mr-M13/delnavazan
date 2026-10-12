#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
dzn_acceptance_require_cached_images
dzn_acceptance_assert_state
mkdir -p "$DZN_ACCEPTANCE_DB_DIR" "$DZN_ACCEPTANCE_WP_DIR"
dzn_acceptance_compose up --pull never -d --wait db
dzn_acceptance_compose up --pull never -d wordpress
echo "acceptance runtime ready: $DZN_ACCEPTANCE_WP_URL (loopback only)"
