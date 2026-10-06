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
need "$deploy" 'staged_plugin="$staged/wp-content/plugins/delnavazan-platform"'
need "$deploy" 'atomic installation'
need "$deploy" 'trap on_exit EXIT'
need "$deploy" 'deployment_mutation_started=1'
need "$deploy" 'mv "$plugin" "$plugin_previous"'
need "$deploy" 'mv "$staged_plugin" "$plugin"'
if grep -Fq 'tar -xzf "$upload" -C "$root"' "$deploy"; then
  echo 'FAIL production deploy must not extract an uploaded package directly into the live tree' >&2
  exit 1
fi
committed_line="$(grep -nF 'deployment_committed=1' "$deploy" | tail -1 | cut -d: -f1)"
cleanup_line="$(grep -nF 'rm -rf "$plugin_previous" "$theme_previous"' "$deploy" | tail -1 | cut -d: -f1)"
[[ -n "$committed_line" && -n "$cleanup_line" && "$cleanup_line" -gt "$committed_line" ]] || { echo 'FAIL production deploy must retain previous app directories until post-deploy verification commits' >&2; exit 1; }
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
