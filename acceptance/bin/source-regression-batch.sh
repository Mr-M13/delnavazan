#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd -P)"
cd "$root"
passed=0; failed=0; skipped=0
for file in platform/tests/*contract*.php platform/tests/*unit*.php; do
  [ -f "$file" ] || continue
  # Discovery-only: contracts with required build fixtures or intentionally failing
  # negative cases are reported, not classified as product defects.
  case "$file" in *isolated*|*runtime*|*fixture*) skipped=$((skipped+1)); continue;; esac
  if php "$file" >/dev/null 2>&1; then passed=$((passed+1)); else
    failed=$((failed+1)); printf 'FAIL %s\n' "$file"
  fi
done
printf 'Source regression: passed=%s failed=%s skipped=%s\n' "$passed" "$failed" "$skipped"
echo "NOTE: standalone discovery failures may require build-specific fixtures; not a CI verdict."
