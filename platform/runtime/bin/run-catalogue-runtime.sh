#!/usr/bin/env bash
# Disposable WordPress/MariaDB proof for automatic catalogue courses and naming.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"

"$bin/run-fresh-install.sh"
dzn_wp_env DZN_CATALOGUE_RUNTIME_TEST=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/catalogue-auto-courses-runtime.php" --user=1
echo 'Catalogue runtime: PASS'
