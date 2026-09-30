#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
echo '=== Fresh install: zero -> Schema 33 ==='
"$(dirname "$BASH_SOURCE")/reset-disposable.sh"
"$(dirname "$BASH_SOURCE")/verify-schema33.sh"
echo 'Fresh install: PASS'
