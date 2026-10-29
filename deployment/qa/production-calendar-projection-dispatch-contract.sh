#!/usr/bin/env bash
set -Eeuo pipefail
root="$(cd "$(dirname "$BASH_SOURCE")/../.." && pwd)"
script="$root/deployment/qa/production-calendar-projection-dispatch.sh"
payload="$root/deployment/qa/production-calendar-projection-dispatch.php"
workflow="$root/.github/workflows/dispatch-production-calendar-projections.yml"
fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
for file in "$script" "$payload" "$workflow"; do [[ -f "$file" ]] || fail "missing $file"; done
bash -n "$script" || fail 'projection dispatch script shell syntax'
php -l "$payload" >/dev/null || fail 'projection dispatch PHP syntax'
grep -Fq 'DISPATCH' "$script" || fail 'projection dispatch requires explicit confirmation'
grep -Fq 'SHA_RE' "$script" || fail 'projection dispatch requires an exact main SHA'
for needle in 'GoogleCalendarProjectionWorker' 'pending_before' 'pending_after' 'verified_join_reference_count' 'projection_worker_principal_required'; do
  grep -Fq "$needle" "$payload" || fail "projection dispatch payload missing $needle"
done
for forbidden in 'user create' 'user delete' 'option delete' 'db query' 'wp_insert' 'wp_update' 'wp_delete' 'maintenance-mode' 'plugin activate' 'plugin deactivate'; do
  if grep -Fq "$forbidden" "$script" "$payload"; then fail "projection dispatch must stay narrow: $forbidden"; fi
done
grep -Fq 'workflow_dispatch' "$workflow" || fail 'projection dispatch workflow must be manual'
grep -Fq 'environment: production' "$workflow" || fail 'projection dispatch workflow must use production environment'
grep -Fq 'refs/heads/main' "$workflow" || fail 'projection dispatch workflow must require main'
grep -Fq 'DISPATCH' "$workflow" || fail 'projection dispatch workflow must require confirmation'
echo 'calendar projection dispatch contract: PASS'
