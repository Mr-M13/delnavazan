#!/usr/bin/env bash
# CI-equivalent checks for Delnavazan agents.
# Runs natively when PHP matches .github/workflows/pr-runtime-gate.yml (8.3 + extensions);
# otherwise use tools/ci/run-in-container.sh for exact container parity.
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
  services               Run services/tests/run-all.sh (recording services)
  all                    lint, then services

Runs natively on PHP 8.3 with the CI extensions; use tools/ci/run-in-container.sh
for exact container parity or when the host PHP differs.

The PR runtime gate in .github/workflows/pr-runtime-gate.yml remains authoritative.
Runtime suites (platform/runtime/bin/run-*-runtime.sh) still need Docker/MariaDB
and are run by CI; they are not part of this script.
EOF
}

in_container() {
  [[ -f /.dockerenv || "${DZN_CI_CONTAINER:-0}" == "1" ]]
}

check_environment() {
  local version missing=() ext
  version="$(php -r 'echo PHP_VERSION;')"
  if [[ "$version" != "$EXPECTED_PHP".* ]]; then
    printf 'FAIL  PHP %s found; CI uses %s. Use tools/ci/run-in-container.sh.\n' "$version" "$EXPECTED_PHP" >&2
    exit 1
  fi
  for ext in curl mbstring pdo_sqlite sqlite3 json openssl; do
    php -r "exit(extension_loaded('$ext') ? 0 : 1);" || missing+=("$ext")
  done
  if (( ${#missing[@]} > 0 )); then
    printf 'FAIL  missing PHP extensions: %s\n' "${missing[*]}" >&2
    printf 'Use tools/ci/run-in-container.sh for the CI extension set.\n' >&2
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
  if in_container; then echo "env:      container (CI parity)"; else echo "env:      host (native PHP 8.3; run-in-container.sh for strict parity)"; fi
}

lint() {
  check_environment
  local count=0 failed=0 file
  # while/read instead of mapfile: macOS ships bash 3.2, which has no mapfile.
  while IFS= read -r -d '' file; do
    count=$((count + 1))
    if ! php -l "$file" >/dev/null 2>&1; then
      printf 'FAIL  php -l %s\n' "$file" >&2
      php -l "$file" >&2 || true
      failed=1
    fi
  done < <(git ls-files -z '*.php')
  [[ "$failed" == 0 ]] || exit 1
  git diff --check
  printf 'OK    php -l %d tracked files; git diff --check clean\n' "$count"
}

contracts() {
  check_environment
  [[ $# -gt 0 ]] || { usage >&2; exit 2; }
  local file
  for file in "$@"; do
    [[ -f "$file" ]] || { printf 'FAIL  missing file: %s\n' "$file" >&2; exit 1; }
    printf 'RUN   %s\n' "$file"
    php "$file"
  done
  printf 'OK    %d contract(s)\n' "$#"
}

services() {
  check_environment
  bash services/tests/run-all.sh
  printf 'OK    services tests\n'
}

all() {
  lint
  services
}

[[ $# -gt 0 ]] || { usage >&2; exit 2; }
command="$1"
shift
case "$command" in
  doctor) doctor ;;
  lint) lint "$@" ;;
  contracts) contracts "$@" ;;
  services) services "$@" ;;
  all) all "$@" ;;
  -h|--help|help) usage ;;
  *) usage >&2; exit 2 ;;
esac
