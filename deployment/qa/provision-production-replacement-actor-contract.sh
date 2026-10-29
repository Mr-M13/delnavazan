#!/usr/bin/env bash
# Source contract for the one-time replacement-actor provisioning workflow.
set -Eeuo pipefail
root="$(cd "$(dirname "$BASH_SOURCE")/../.." && pwd)"
script="$root/deployment/qa/provision-production-replacement-actor.sh"
payload="$root/deployment/qa/provision-production-replacement-actor.php"
workflow="$root/.github/workflows/provision-production-replacement-actor.yml"
fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
for file in "$script" "$payload" "$workflow"; do [[ -f "$file" ]] || fail "missing $file"; done
bash -n "$script" || fail 'provisioning script shell syntax'
php -l "$payload" >/dev/null || fail 'provisioning PHP syntax'
grep -Fq 'PROVISION' "$script" || fail 'provisioning requires explicit confirmation'
grep -Fq 'SHA_RE' "$script" || fail 'provisioning requires an exact main SHA'
grep -Fq 'base64' "$script" || fail 'provisioning payload transport missing'
grep -Fq 'eval(base64_decode' "$script" || fail 'provisioning payload evaluation missing'
for needle in 'dzn_replacement_system_user_id' 'dzn_reschedule_system_user_id' 'dzn_manage_canonical_lessons' 'dzn_manage_canonical_lesson_schedules' 'manage_options' 'dzn_manage_provider_integrations'; do
  grep -Fq "$needle" "$payload" || fail "provisioning payload missing $needle"
done
for forbidden in 'user create' 'user delete' 'option delete' 'db query' 'wp_insert' 'wp_update' 'wp_delete' 'maintenance-mode' 'plugin activate' 'plugin deactivate'; do
  if grep -Fq "$forbidden" "$script" "$payload"; then fail "provisioning must stay narrow: $forbidden"; fi
done
grep -Fq 'workflow_dispatch' "$workflow" || fail 'provisioning workflow must be manual'
grep -Fq 'environment: production' "$workflow" || fail 'provisioning workflow must use the production environment'
grep -Fq 'refs/heads/main' "$workflow" || fail 'provisioning workflow must require main'
grep -Fq 'PROVISION' "$workflow" || fail 'provisioning workflow must require confirmation'
echo 'replacement actor provisioning contract: PASS'
