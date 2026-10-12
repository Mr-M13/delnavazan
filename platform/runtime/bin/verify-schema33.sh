#!/usr/bin/env bash
# Backward-compatible entrypoint for historical automation.
set -euo pipefail
exec "$(dirname "$BASH_SOURCE")/verify-current-schema.sh" "$@"
