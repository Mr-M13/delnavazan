#!/usr/bin/env bash
set -euo pipefail
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"
"$bin/check-cache.sh"
"$bin/run-fresh-install.sh"
"$bin/run-retained-migration.sh"
"$bin/run-schema30-to-32-rehearsal.sh"
"$bin/run-schema31-to-32-rehearsal.sh"
"$bin/run-pure-tests.sh"
echo 'Schema-33 bounded acceptance: PASS'
echo 'Historical regression/concurrency hooks are opt-in: run-regressions.sh / run-concurrency.sh'
