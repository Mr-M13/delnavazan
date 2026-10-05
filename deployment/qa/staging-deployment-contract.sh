#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(git rev-parse --show-toplevel)"
deploy="$ROOT/deployment/staging-deploy.sh"
workflow="$ROOT/.github/workflows/deploy-staging.yml"
smoke="$ROOT/deployment/qa/staging-smoke.sh"
rollback="$ROOT/deployment/qa/STAGING-DEPLOY-ROLLBACK.md"
need(){ grep -Fq -- "$2" "$1" || { echo "FAIL $1 is missing required contract: $2" >&2; exit 1; }; }
need "$deploy" '[[ $1 == staging ]]'
need "$deploy" '[[ "$2" == "$main_sha" ]]'
need "$deploy" 'domains/staging.delnavazan.com/public_html'
need "$deploy" 'git archive "$2" platform'
need "$deploy" 'git archive "$2" theme'
need "$deploy" 'sha256sum'
need "$deploy" 'theme_style_sha256'
need "$deploy" 'db export'
need "$deploy" 'main-${2:0:12}'
need "$deploy" 'Migrator::maybe_upgrade()'
need "$deploy" '"schema"'
need "$workflow" 'workflow_dispatch:'
need "$workflow" 'environment: staging'
need "$workflow" 'uses: ./.github/workflows/pr-runtime-gate.yml'
need "$workflow" 'needs: source-gate'
need "$workflow" 'deployment/staging-deploy.sh "$TARGET" "$COMMIT_SHA"'
need "$workflow" 'platform/tests/phase-student-portal-state-contract.php'
need "$workflow" 'platform/tests/admin-navigation-regression-contract.php'
need "$workflow" 'deployment/qa/staging-smoke.sh https://staging.delnavazan.com'
need "$smoke" '/wp-json/delnavazan-platform/v1/booking-options'
need "$rollback" 'forward-only'
need "$rollback" 'Do not automatically restore it'
if grep -Fq 'https://delnavazan.com' "$deploy" "$workflow" || grep -Fq 'https://www.delnavazan.com' "$deploy" "$workflow" || grep -Fq 'domains/delnavazan.com/public_html' "$deploy" "$workflow"; then
  echo 'FAIL production host/path reference in staging deployment tooling' >&2
  exit 1
fi
if grep -Eiq '(^|[[:space:]])(ALTER|CREATE|DROP)[[:space:]]+TABLE' "$deploy"; then
  echo 'FAIL ad-hoc SQL migration found in deploy script' >&2
  exit 1
fi
db_line="$(grep -n 'db export' "$deploy" | head -1 | cut -d: -f1)"
app_backup_line="$(grep -n 'application-files.tar.gz' "$deploy" | head -1 | cut -d: -f1)"
migration_line="$(grep -nF 'if ! wp --path="$root" eval' "$deploy" | head -1 | cut -d: -f1)"
mutation_line="$(grep -nF 'if ! { rm -rf "$plugin" "$theme" && tar' "$deploy" | head -1 | cut -d: -f1)"
[[ "$app_backup_line" -lt "$mutation_line" && "$db_line" -lt "$mutation_line" && "$mutation_line" -lt "$migration_line" ]] || { echo 'FAIL application/database backups must precede replacement and migration' >&2; exit 1; }
echo 'PASS staging deployment source contract'
