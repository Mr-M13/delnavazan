#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
[ "${1:-}" = YES ] || { echo 'refusing destructive cleanup; rerun with: bin/destroy.sh YES' >&2; exit 2; }
dzn_acceptance_assert_state

if [ -d "$DZN_ACCEPTANCE_STATE_DIR" ]; then
  for entry in "$DZN_ACCEPTANCE_STATE_DIR"/.* "$DZN_ACCEPTANCE_STATE_DIR"/*; do
    [ -e "$entry" ] || [ -L "$entry" ] || continue
    case "${entry##*/}" in .|..|mariadb|wordpress|cookies|http) ;; *) echo "refusing destructive cleanup; unrecognised acceptance state entry: $entry" >&2; exit 1;; esac
  done
fi
dzn_acceptance_compose down --volumes --remove-orphans || true
for path in "$DZN_ACCEPTANCE_DB_DIR" "$DZN_ACCEPTANCE_WP_DIR" "$DZN_ACCEPTANCE_STATE_DIR/cookies" "$DZN_ACCEPTANCE_STATE_DIR/http"; do dzn_acceptance_remove_child "$path"; done
rmdir "$DZN_ACCEPTANCE_STATE_DIR" 2>/dev/null || true
echo 'Disposed local acceptance state.'
