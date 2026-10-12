#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
dzn_acceptance_require_cached_images
echo 'acceptance cached-image preflight: PASS (no pull attempted)'
