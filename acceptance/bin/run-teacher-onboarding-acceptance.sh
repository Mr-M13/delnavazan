#!/usr/bin/env bash
# Runs the synthetic invitation, claim, readiness, and portal-gate authority path.
set -euo pipefail
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd -P)"
source "$bin/common.sh"

"$bin/reset.sh"
dzn_acceptance_wp eval-file /workspace/acceptance/fixtures/teacher-onboarding.php --user=1
