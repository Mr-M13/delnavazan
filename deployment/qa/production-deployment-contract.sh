#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
deploy="$ROOT/deployment/production-deploy.sh"
workflow="$ROOT/.github/workflows/deploy-production.yml"
smoke="$ROOT/deployment/qa/production-smoke.sh"
need(){ grep -Fq -- "$2" "$1" || { echo "FAIL $1 missing required production boundary: $2" >&2; exit 1; }; }
line(){ grep -nF -- "$2" "$1" | head -1 | cut -d: -f1; }
before(){ local earlier later; earlier="$(line "$1" "$2")"; later="$(line "$1" "$3")"; [[ -n "$earlier" && -n "$later" && "$earlier" -lt "$later" ]] || { echo "FAIL production ordering: '$2' must precede '$3' in $1" >&2; exit 1; }; }
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
need "$workflow" 'workflow_dispatch:'
need "$workflow" 'options: [production]'
need "$workflow" 'type: string'
need "$workflow" 'required: true'
need "$workflow" 'refs/heads/main'
need "$workflow" 'uses: ./.github/workflows/pr-runtime-gate.yml'
need "$workflow" 'needs: source-gate'
need "$workflow" 'git rev-parse origin/main'
need "$workflow" '[[ "$(git rev-parse origin/main)" == "$COMMIT_SHA" ]]'
need "$workflow" 'deployment/production-deploy.sh "$TARGET" "$COMMIT_SHA"'
need "$workflow" 'deployment/qa/production-smoke.sh https://delnavazan.com'
need "$workflow" 'PRODUCTION_KNOWN_HOSTS'
need "$smoke" 'https://delnavazan.com'
need "$smoke" '/enrol/'
need "$smoke" 'booking-options'
need "$smoke" 'noindex'
need "$deploy" 'readonly PRODUCTION_ROOT="domains/delnavazan.com/public_html"'
need "$deploy" 'readonly PRODUCTION_HOST="cp119.hosting-cloud.net"'
need "$deploy" 'maintenance-mode activate'
need "$deploy" 'maintenance mode remains on'
need "$deploy" 'manual operator decision required'
need "$deploy" 'identity_verification_failed'
need "$deploy" 'DZN_PLATFORM_BUILD_ID'
need "$deploy" 'expected_archive_sha'
need "$deploy" 'expected_theme_sha'

# Guard relationships between safety steps, not just the presence of markers.
before "$deploy" 'php -l >/dev/null' 'install -d -m 700 "$backup_root"'
before "$deploy" 'db export "$backup/database.sql"' 'maintenance-mode activate'
before "$deploy" 'maintenance-mode activate' 'rm -rf "$plugin" "$theme" && tar'
before "$deploy" 'rm -rf "$plugin" "$theme" && tar' 'Migrator::maybe_upgrade()'
before "$deploy" 'Migrator::maybe_upgrade()' 'identity_verification_failed'
before "$deploy" 'identity_verification_failed' 'maintenance-mode deactivate'
before "$deploy" 'git archive "$2" platform' 'sha256sum "$scratch/deploy.tar.gz"'
before "$deploy" 'sha256sum "$scratch/deploy.tar.gz"' '[[ "$(git rev-parse origin/main)" == "$2" ]]'

# Production release tooling may replace only the application package and run
# its forward-only migrator. DNS, mail, and catalogue administration stay out.
if grep -Eiq '(^|[[:space:]])(wp[[:space:]]+(option[[:space:]]+update|plugin|theme|post)|wp[[:space:]]+db[[:space:]]+import|dig[[:space:]]|nslookup[[:space:]]|wp[[:space:]]+mail|wp[[:space:]]+catalogue)' "$deploy" "$workflow" "$smoke"; then
  echo 'FAIL production release tooling contains an out-of-scope DNS/mail/catalogue or database-restore mutation' >&2
  exit 1
fi
if grep -Eiq 'curl[^\n]*(-X[[:space:]]*(POST|PUT|PATCH|DELETE)|--request[=[:space:]]*(POST|PUT|PATCH|DELETE))|--data([=[:space:]]|$)' "$smoke"; then
  echo 'FAIL production smoke must remain read-only HTTP GET checks' >&2
  exit 1
fi
if grep -Eiq '(echo|printf|cat)[^\n]*(PRODUCTION_SSH_PRIVATE_KEY|PRODUCTION_KNOWN_HOSTS|PRODUCTION_BACKUP_DIR|PRODUCTION_SSH_USER)' "$workflow" "$deploy"; then
  echo 'FAIL production tooling may emit a secret value' >&2
  exit 1
fi
if grep -Fq 'staging.delnavazan.com' "$deploy" "$workflow" "$smoke"; then
  echo 'FAIL staging host referenced in production deployment tooling' >&2
  exit 1
fi
if grep -Fq '162.255.118.68' "$deploy" "$workflow" "$smoke"; then
  echo 'FAIL retired origin referenced in production deployment tooling' >&2
  exit 1
fi
echo 'PASS production deployment source contract'
