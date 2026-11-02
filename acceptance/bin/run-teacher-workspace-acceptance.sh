#!/usr/bin/env bash
# Runs the synthetic failed-onboarding recovery, open-ended unavailability and current-state checks.
set -euo pipefail
bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd -P)"
source "$bin/common.sh"

"$bin/reset.sh"
dzn_acceptance_wp eval-file /workspace/acceptance/fixtures/teacher-workspace-recovery.php --user=1
