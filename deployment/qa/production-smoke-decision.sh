#!/usr/bin/env bash
# Decide whether a failed smoke probe requires the fail-closed maintenance window.
# Exit 0 = activate maintenance; exit 1 = keep production live.
# Maintenance is required unless the primary probe succeeded, or a second
# independent confirmation probe proved the application healthy.
set -Eeuo pipefail
primary="${1:?primary smoke outcome required}"
confirmation="${2:-skipped}"
if [[ "$primary" == 'success' ]]; then exit 1; fi
if [[ "$confirmation" == 'success' ]]; then exit 1; fi
exit 0
