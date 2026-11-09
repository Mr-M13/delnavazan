#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLASSIFIER="$ROOT/deployment/qa/deployment-risk-classifier.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
git -C "$tmp" init -q
git -C "$tmp" config user.email test@example.invalid
git -C "$tmp" config user.name 'Deployment Risk Test'
mkdir -p "$tmp/theme/assets/css" "$tmp/platform/src/Core/Application/Booking" "$tmp/.github/workflows"
printf 'base\n' > "$tmp/README.md"
git -C "$tmp" add . && git -C "$tmp" commit -qm base
base="$(git -C "$tmp" rev-parse HEAD)"

expect_risk(){
  local label="$1" expected="$2" path="$3"
  mkdir -p "$tmp/$(dirname "$path")"
  printf '%s\n' "$label" > "$tmp/$path"
  git -C "$tmp" add . && git -C "$tmp" commit -qm "$label"
  actual="$(cd "$tmp" && "$CLASSIFIER" "$base" HEAD | sed -n 's/^RISK=//p')"
  [[ "$actual" == "$expected" ]] || { echo "FAIL $label: expected $expected, got $actual" >&2; exit 1; }
  base="$(git -C "$tmp" rev-parse HEAD)"
}

expect_risk 'isolated CSS' FAST theme/assets/css/site.css
expect_risk 'application feature' STANDARD platform/src/Core/Application/Profile.php
expect_risk 'booking lifecycle' CRITICAL platform/src/Core/Application/Booking/Allocator.php
expect_risk 'workflow change' CRITICAL .github/workflows/new-check.yml
expect_risk 'classifier change' CRITICAL deployment/qa/deployment-risk-classifier.sh

printf 'mixed\n' > "$tmp/theme/assets/css/mixed.css"
printf 'mixed\n' > "$tmp/platform/src/Core/Application/Booking/mixed.php"
git -C "$tmp" add . && git -C "$tmp" commit -qm mixed
actual="$(cd "$tmp" && "$CLASSIFIER" "$base" HEAD | sed -n 's/^RISK=//p')"
[[ "$actual" == CRITICAL ]] || { echo "FAIL mixed sensitive change: got $actual" >&2; exit 1; }

echo 'PASS deployment risk classifier'
