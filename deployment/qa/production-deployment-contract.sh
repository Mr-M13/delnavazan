#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
deploy="$ROOT/deployment/production-deploy.sh"
workflow="$ROOT/.github/workflows/deploy-production.yml"
smoke="$ROOT/deployment/qa/production-smoke.sh"
need(){ grep -Fq -- "$2" "$1" || { echo "FAIL $1 missing required production boundary: $2" >&2; exit 1; }; }
need "$deploy" '[[ $1 == production ]]'
need "$deploy" "domains/delnavazan.com/public_html"
need "$deploy" 'git fetch --no-tags origin main'
need "$deploy" '[[ "$2" == "$main_sha" ]]'
need "$deploy" 'git archive "$2" platform'
need "$deploy" 'git archive "$2" theme'
need "$deploy" 'sha256sum'
need "$deploy" "php -l >/dev/null"
need "$deploy" 'db export'
need "$deploy" 'Migrator::maybe_upgrade()'
need "$deploy" 'main-${2:0:12}'
need "$deploy" 'production URL/indexability preflight failed'
need "$workflow" 'environment: production'
need "$workflow" 'refs/heads/main'
need "$workflow" 'deployment/production-deploy.sh "$TARGET" "$COMMIT_SHA"'
need "$workflow" 'deployment/qa/production-smoke.sh https://delnavazan.com'
need "$workflow" 'PRODUCTION_KNOWN_HOSTS'
need "$smoke" 'https://delnavazan.com'
need "$smoke" '/enrol/'
need "$smoke" 'booking-options'
need "$smoke" 'noindex'
if grep -Fq 'staging.delnavazan.com' "$deploy" "$workflow" "$smoke"; then
  echo 'FAIL staging host referenced in production deployment tooling' >&2
  exit 1
fi
if grep -Fq '162.255.118.68' "$deploy" "$workflow" "$smoke"; then
  echo 'FAIL retired origin referenced in production deployment tooling' >&2
  exit 1
fi
echo 'PASS production deployment source contract'
