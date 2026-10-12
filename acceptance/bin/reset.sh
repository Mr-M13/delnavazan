#!/usr/bin/env bash
set -euo pipefail
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd -P)"
source "$bin/common.sh"
dzn_acceptance_assert_state
dzn_acceptance_compose down --volumes --remove-orphans || true
for path in "$DZN_ACCEPTANCE_DB_DIR" "$DZN_ACCEPTANCE_WP_DIR" "$DZN_ACCEPTANCE_STATE_DIR/cookies" "$DZN_ACCEPTANCE_STATE_DIR/http"; do dzn_acceptance_remove_child "$path"; done
"$bin/up.sh"
"$bin/install-wordpress.sh"
