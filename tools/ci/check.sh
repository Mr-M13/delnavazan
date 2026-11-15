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
  all                    lint, then services. Does NOT run platform contracts;
                         pass the affected contracts explicitly.

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
  command -v php >/dev/null 2>&1 \
    || { echo 'FAIL  php not found; install PHP 8.3 or use tools/ci/run-in-container.sh' >&2; exit 1; }
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
  local status=0 version missing=() ext
  echo "repo:     $REPO_ROOT"
  if command -v php >/dev/null 2>&1; then
    version="$(php -r 'echo PHP_VERSION;')"
    echo "php:      $version"
    if [[ "$version" != "$EXPECTED_PHP".* ]]; then
      printf 'status:   FAIL php %s (CI uses %s)\n' "${version:-unknown}" "$EXPECTED_PHP" >&2
      status=1
    fi
    printf 'ext:      '
    php -r 'echo implode(" ", array_intersect(["curl","mbstring","pdo_sqlite","sqlite3","json","openssl"], get_loaded_extensions())), "\n";'
    for ext in curl mbstring pdo_sqlite sqlite3 json openssl; do
      php -r "exit(extension_loaded('$ext') ? 0 : 1);" || missing+=("$ext")
    done
    if (( ${#missing[@]} > 0 )); then
      printf 'status:   FAIL missing extensions: %s\n' "${missing[*]}" >&2
      status=1
    fi
  else
    echo "php:      missing"
    echo "status:   FAIL php not found" >&2
    status=1
  fi
  echo "git:      $(git --version 2>/dev/null || echo missing)"
  echo "docker:   $(docker --version 2>/dev/null || echo 'not available in this environment')"
  echo "tracked:  $(git ls-files '*.php' 2>/dev/null | wc -l | tr -d ' ') PHP files"
  if in_container; then echo "env:      container (CI parity)"; else echo "env:      host (run-in-container.sh for strict parity)"; fi
  if (( status == 0 )); then echo "status:   OK"; fi
  return "$status"
}

lint() {
  check_environment
  local count=0 failed=0 file base
  command -v git >/dev/null 2>&1 \
    || { echo 'FAIL  git not found' >&2; exit 1; }
  git rev-parse --is-inside-work-tree >/dev/null 2>&1 \
    || { echo 'FAIL  not inside a git work tree' >&2; exit 1; }
  # while/read instead of mapfile: macOS ships bash 3.2, which has no mapfile.
  while IFS= read -r -d '' file; do
    count=$((count + 1))
    if ! php -l "$file" >/dev/null 2>&1; then
      printf 'FAIL  php -l %s\n' "$file" >&2
      php -l "$file" >&2 || true
      failed=1
    fi
  done < <(git ls-files -z '*.php')
  if (( count == 0 )); then
    echo 'FAIL  no tracked PHP files found; refusing to report success' >&2
    exit 1
  fi
  [[ "$failed" == 0 ]] || exit 1
  # Cover unstaged, staged and already-committed changes; agents often lint after commit.
  git diff --check
  git diff --cached --check
  base="$(git merge-base HEAD origin/main 2>/dev/null || git merge-base HEAD main 2>/dev/null || true)"
  if [[ -n "$base" ]]; then
    git diff --check "$base"...HEAD
  fi
  printf 'OK    php -l %d tracked files; whitespace clean (worktree, index, committed)\n' "$count"
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
