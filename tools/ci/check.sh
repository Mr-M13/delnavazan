#!/usr/bin/env bash
# CI-equivalent checks for Delnavazan agents.
# Run through tools/ci/run-in-container.sh so PHP matches .github/workflows/pr-runtime-gate.yml.
set -Eeuo pipefail

readonly EXPECTED_PHP='8.3'
readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$REPO_ROOT"

usage() {
  cat <<'EOF'
Usage: tools/ci/check.sh <command> [args]

Commands:
  doctor                 Show PHP, extension, git and repository status (host or container)
  lint                   php -l every tracked PHP file, then git diff --check
  contracts <file...>    Run the given platform/tests/*.php contracts, e.g.
                         tools/ci/check.sh contracts platform/tests/phase-1b-contract.php

The PR runtime gate in .github/workflows/pr-runtime-gate.yml remains authoritative.
Runtime suites (platform/runtime/bin/run-*-runtime.sh) and services tests
(services/tests/run-all.sh) are separate and are not run by this script.
EOF
}

in_container() {
  [[ -f /.dockerenv || "${DZN_CI_CONTAINER:-0}" == "1" ]]
}

require_container() {
  if ! in_container; then
    printf 'FAIL  run inside the CI container: tools/ci/run-in-container.sh %s\n' "$*" >&2
    exit 2
  fi
}

check_php() {
  local version
  version="$(php -r 'echo PHP_VERSION;')"
  if [[ "$version" != "$EXPECTED_PHP".* ]]; then
    printf 'FAIL  PHP %s found; CI uses %s\n' "$version" "$EXPECTED_PHP" >&2
    exit 1
  fi
}

doctor() {
  echo "php:      $(php -r 'echo PHP_VERSION;')"
  echo "git:      $(git --version 2>/dev/null || echo missing)"
  echo "docker:   $(docker --version 2>/dev/null || echo 'not available in this environment')"
  echo "repo:     $REPO_ROOT"
  echo "tracked:  $(git ls-files '*.php' | wc -l | tr -d ' ') PHP files"
  printf 'ext:      '
  php -r 'echo implode(" ", array_intersect(["curl","mbstring","pdo_sqlite","sqlite3","json","openssl"], get_loaded_extensions())), "\n";'
  if in_container; then echo "env:      container"; else echo "env:      host (run-in-container.sh for CI parity)"; fi
}

lint() {
  require_container "$*"
  check_php
  local -a files=()
  local count=0 failed=0 file
  mapfile -d '' files < <(git ls-files -z '*.php')
  for file in "${files[@]}"; do
    count=$((count + 1))
    if ! php -l "$file" >/dev/null 2>&1; then
      printf 'FAIL  php -l %s\n' "$file" >&2
      php -l "$file" >&2 || true
      failed=1
    fi
  done
  [[ "$failed" == 0 ]] || exit 1
  git diff --check
  printf 'OK    php -l %d tracked files; git diff --check clean\n' "$count"
}

contracts() {
  require_container "$*"
  check_php
  [[ $# -gt 0 ]] || { usage >&2; exit 2; }
  local file
  for file in "$@"; do
    [[ -f "$file" ]] || { printf 'FAIL  missing file: %s\n' "$file" >&2; exit 1; }
    printf 'RUN   %s\n' "$file"
    php "$file"
  done
  printf 'OK    %d contract(s)\n' "$#"
}

[[ $# -gt 0 ]] || { usage >&2; exit 2; }
command="$1"
shift
case "$command" in
  doctor) doctor ;;
  lint) lint "$@" ;;
  contracts) contracts "$@" ;;
  -h|--help|help) usage ;;
  *) usage >&2; exit 2 ;;
esac
