#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
sh="$ROOT/deployment/qa/provision-production-legal-pages.sh"
php_file="$ROOT/deployment/qa/provision-production-legal-pages.php"
workflow="$ROOT/.github/workflows/provision-production-legal-pages.yml"
fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
need(){ grep -Fq -- "$2" "$1" || fail "$1 missing required boundary: $2"; }
bash -n "$sh"
php -l "$php_file" >/dev/null
need "$sh" 'PROVISION_LEGAL_DRAFTS'
need "$sh" 'main-${sha:0:12}'
need "$sh" 'domains/delnavazan.com/public_html'
need "$php_file" 'LegalPageProvisioner::provisionDrafts'
need "$php_file" "'dry_run' => false"
need "$php_file" 'production environment only'
if grep -Fq "'post_status' => 'publish'" "$php_file" || grep -Fq 'wp_publish_post' "$php_file"; then
  fail 'legal draft provisioning payload must not publish'
fi
need "$workflow" 'workflow_dispatch'
need "$workflow" 'environment: production'
need "$workflow" 'refs/heads/main'
need "$workflow" 'PROVISION_LEGAL_DRAFTS'
need "$workflow" 'provision-production-legal-pages.sh'
echo 'PASS legal draft provisioning source contract'
