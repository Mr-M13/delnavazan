#!/usr/bin/env bash
set -Eeuo pipefail
root="$(cd "$(dirname "$BASH_SOURCE")/../.." && pwd)"
script="$root/deployment/qa/production-calendar-job-recovery.sh"
payload="$root/deployment/qa/production-calendar-job-recovery.php"
workflow="$root/.github/workflows/recover-production-calendar-job.yml"
fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
for file in "$script" "$payload" "$workflow"; do [[ -f "$file" ]] || fail "missing $file"; done
bash -n "$script" || fail 'recovery script shell syntax'
php -l "$payload" >/dev/null || fail 'recovery PHP syntax'
grep -Fq 'RECOVER' "$script" || fail 'recovery requires explicit confirmation'
grep -Fq 'SHA_RE' "$script" || fail 'recovery requires an exact main SHA'
grep -Fq 'DZN_CALENDAR_JOB_ID' "$script" "$payload" || fail 'recovery must bind one job id'
for needle in 'job_not_failed' 'proposal_not_accepted' 'target_schedule_not_current' 'requeue_conflict' 'dispatchJob' 'GoogleCalendarProjectionWorker' 'meeting_join_present'; do
  grep -Fq "$needle" "$payload" || fail "recovery payload missing $needle"
done
for forbidden in 'user create' 'user delete' 'option delete' 'db query' 'wp_insert' 'wp_update' 'wp_delete' 'maintenance-mode' 'plugin activate' 'plugin deactivate'; do
  if grep -Fq "$forbidden" "$script" "$payload"; then fail "recovery must stay narrow: $forbidden"; fi
done
grep -Fq 'workflow_dispatch' "$workflow" || fail 'recovery workflow must be manual'
grep -Fq 'environment: production' "$workflow" || fail 'recovery workflow must use production environment'
grep -Fq 'refs/heads/main' "$workflow" || fail 'recovery workflow must require main'
grep -Fq 'RECOVER' "$workflow" || fail 'recovery workflow must require confirmation'
echo 'calendar job recovery contract: PASS'
