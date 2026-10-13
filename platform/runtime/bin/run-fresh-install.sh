#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
echo '=== Fresh install: zero -> current schema ==='
"$(dirname "$BASH_SOURCE")/reset-disposable.sh"
"$(dirname "$BASH_SOURCE")/verify-current-schema.sh"
echo 'Fresh install: PASS'
