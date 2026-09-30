#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
dzn_require_cached_images
echo 'cached-image preflight: PASS (no pull attempted)'
