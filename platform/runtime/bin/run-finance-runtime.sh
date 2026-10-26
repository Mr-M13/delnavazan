#!/usr/bin/env bash
# Disposable WordPress/MariaDB proof for finance administration, pricing, teacher inheritance and paid intro payments.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"

"$bin/run-fresh-install.sh"
dzn_wp_env DZN_FINANCE_RUNTIME_TEST=authority -- eval-file "$DZN_PLUGIN_SOURCE/platform/tests/finance-administration-runtime.php" --user=1
echo 'Finance administration runtime: PASS'
