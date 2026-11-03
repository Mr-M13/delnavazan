#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$BASH_SOURCE")/../.." && pwd)"
php "$root/services/tests/signature-contract.php"
php "$root/services/recorder-archiver/tests/resource-unit.php"
php "$root/services/recorder-archiver/tests/egress-unit.php"
php "$root/services/recorder-archiver/tests/integration.php"
php "$root/services/recorder-archiver/tests/resilience.php"
php "$root/services/playback-gateway/tests/integration.php"
echo 'Delnavazan recording services: PASS'
