#!/usr/bin/env bash
# Disposable WordPress/MariaDB proof that FinanceFacts::context() hydrates a legacy introductory
# Lesson (regression for the `$self::INTRO_MODEL` typo that broke statement preview/draft for any
# Teacher with an introductory Lesson in the period).
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
plugin_tests="$DZN_PLUGIN_SOURCE/platform/tests"

"$bin/run-fresh-install.sh"
dzn_wp_env DZN_FINANCE_INTRO_RUNTIME_TEST=authority -- eval-file "$plugin_tests/finance-intro-context-runtime.php" --user=1
echo "Finance introductory Lesson context runtime: PASS"
