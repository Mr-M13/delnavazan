#!/usr/bin/env bash
set -Eeuo pipefail
root="$(cd "$(dirname "$BASH_SOURCE")/../.." && pwd)"
script="$root/deployment/qa/provision-production-projection-worker.sh"
payload="$root/deployment/qa/provision-production-projection-worker.php"
workflow="$root/.github/workflows/provision-production-projection-worker.yml"
fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
for file in "$script" "$payload" "$workflow"; do [[ -f "$file" ]] || fail "missing $file"; done
bash -n "$script" || fail 'projection worker script shell syntax'
php -l "$payload" >/dev/null || fail 'projection worker PHP syntax'
grep -Fq 'PROVISION_PROJECTION' "$script" || fail 'projection worker requires explicit confirmation'
grep -Fq 'SHA_RE' "$script" || fail 'projection worker requires an exact main SHA'
for needle in 'dzn_platform_provider_projection_worker_principal' 'dzn_dispatch_provider_integrations' 'manage_options' 'dzn_manage_provider_integrations' 'existing_principal_invalid'; do
  grep -Fq "$needle" "$payload" || fail "projection worker payload missing $needle"
done
for forbidden in 'user delete' 'option delete' 'db query' 'wp_update' 'wp_delete' 'maintenance-mode' 'plugin activate' 'plugin deactivate'; do
  if grep -Fq "$forbidden" "$script" "$payload"; then fail "projection worker must stay narrow: $forbidden"; fi
done
grep -Fq 'workflow_dispatch' "$workflow" || fail 'projection worker workflow must be manual'
grep -Fq 'environment: production' "$workflow" || fail 'projection worker workflow must use production environment'
grep -Fq 'refs/heads/main' "$workflow" || fail 'projection worker workflow must require main'
grep -Fq 'PROVISION_PROJECTION' "$workflow" || fail 'projection worker workflow must require confirmation'
echo 'projection worker provisioning contract: PASS'
