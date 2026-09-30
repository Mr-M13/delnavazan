#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
[ "${1:-}" = YES ] || { echo 'refusing destructive cleanup; rerun with: bin/destroy.sh YES' >&2; exit 2; }
# Destructive cleanup is scoped twice over.  The state directory must be a
# dedicated, narrowly scoped disposable area (never `/`, `/tmp` or another broad
# shared directory), and only the children this harness itself creates are ever
# deleted.  The state directory is then removed with `rmdir`, which cannot delete
# anything else and fails harmlessly while unrecognised content remains.  It is
# never `rm -rf`'d.
dzn_assert_state_outside_checkout
dzn_assert_state_dir_dedicated
dzn_assert_runtime_paths
for entry in "$DZN_RUNTIME_STATE_DIR"/.* "$DZN_RUNTIME_STATE_DIR"/*; do
  [ -e "$entry" ] || [ -L "$entry" ] || continue
  case "${entry##*/}" in
    .|..|.DS_Store) continue;;
    candidate|schema30|schema31|mariadb|wordpress|no-pull-bin) continue;;
    historical-r2|historical-v|historical-t|historical-u) continue;;
    historical-source|rehearsal-before.json|rehearsal-after.json) continue;;
  esac
  echo "refusing destructive cleanup; unrecognised entry inside the state directory: $entry" >&2
  echo '       remove it yourself, then rerun: bin/destroy.sh YES' >&2
  exit 1
done
dzn_compose down --volumes --remove-orphans || true
for tree in "$DZN_PLUGIN_WORKTREE" "$DZN_BASE30_WORKTREE" "$DZN_BASE31_WORKTREE" "$DZN_RUNTIME_STATE_DIR/historical-r2" "$DZN_RUNTIME_STATE_DIR/historical-v" "$DZN_RUNTIME_STATE_DIR/historical-t" "$DZN_RUNTIME_STATE_DIR/historical-u"; do
  if [ -e "$tree" ] || [ -L "$tree" ]; then
    dzn_assert_not_symlink "$tree"; dzn_assert_disposable_path "$tree"
    git -C "$DZN_SHARED_CHECKOUT" worktree remove --force "$tree" 2>/dev/null || dzn_remove_disposable_path "$tree"
  fi
done
for child in "$DZN_DB_DIR" "$DZN_WP_DIR" "$DZN_RUNTIME_STATE_DIR/no-pull-bin" "$DZN_RUNTIME_STATE_DIR/historical-source" "$DZN_RUNTIME_STATE_DIR/rehearsal-before.json" "$DZN_RUNTIME_STATE_DIR/rehearsal-after.json"; do
  if [ -e "$child" ] || [ -L "$child" ]; then dzn_remove_disposable_path "$child"; fi
done
if rmdir "$DZN_RUNTIME_STATE_DIR" 2>/dev/null; then
  echo 'Disposed local runtime state and worktrees.'
else
  echo "Disposed local runtime worktrees; kept $DZN_RUNTIME_STATE_DIR (it still contains entries this harness does not own)."
fi
