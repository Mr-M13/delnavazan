#!/usr/bin/env bash
# Current stored-state verification for the package's Schema-33 candidate.
# The schema32-named checker is retained as historical source evidence.
set -euo pipefail
exec "$(dirname "$BASH_SOURCE")/verify-schema32.sh" "$@"
