#!/usr/bin/env bash
# Disposable WordPress/MariaDB proof for instrument-specific availability rules.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"

"$bin/run-fresh-install.sh"
dzn_wp_env DZN_TEACHER_AVAILABILITY_INSTRUMENT_RUNTIME=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/teacher-availability-instrument-runtime.php" --user=1
echo 'Teacher instrument availability runtime: PASS'
