#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
dzn_assert_state_outside_checkout
dzn_assert_runtime_paths
dzn_compose down --volumes --remove-orphans || true
dzn_remove_disposable_path "$DZN_DB_DIR"; dzn_remove_disposable_path "$DZN_WP_DIR"
"$(dirname "$BASH_SOURCE")/up.sh"
"$(dirname "$BASH_SOURCE")/install-wordpress.sh"
