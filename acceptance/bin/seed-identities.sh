#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
dzn_acceptance_wp eval-file /workspace/acceptance/fixtures/seed-identities.php --user=1
