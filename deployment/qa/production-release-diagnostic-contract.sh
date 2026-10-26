#!/usr/bin/env bash
# Source contract for the read-only production release diagnostic.
set -Eeuo pipefail
root="$(cd "$(dirname "$BASH_SOURCE")/../.." && pwd)"
script="$root/deployment/qa/production-release-diagnostic.sh"
payload="$root/deployment/qa/production-release-diagnostic.php"
workflow="$root/.github/workflows/diagnose-production-release.yml"
fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
for file in "$script" "$payload" "$workflow"; do [[ -f "$file" ]] || fail "missing $file"; done
bash -n "$script" || fail 'diagnostic script shell syntax'
php -l "$payload" >/dev/null || fail 'diagnostic PHP syntax'
grep -Fq 'set -Eeuo pipefail' "$script" || fail 'diagnostic script must fail closed'
grep -Fq 'SHA_RE' "$script" || fail 'diagnostic script must require a full SHA'
grep -Fq 'base64' "$script" || fail 'diagnostic payload transport missing'
grep -Fq 'eval(base64_decode' "$script" || fail 'diagnostic payload evaluation missing'
grep -Fq 'wp --path="$root" --skip-themes eval' "$script" || fail 'diagnostic must use WP-CLI eval'
for needle in 'schema' 'build' 'plugin_active' 'completed_052' 'activation_jobs' 'attendance_cases' 'payability' 'instalment_policy' 'stripe' 'cron_hooks'; do
  grep -Fq "'$needle'" "$payload" || fail "diagnostic payload missing $needle"
done
for forbidden in 'option update' 'option delete' 'db query' 'cron event run' 'cache flush' 'plugin activate' 'plugin deactivate' 'theme activate' 'post delete' 'user create' 'maintenance-mode activate' 'maintenance-mode deactivate' 'wp_insert' 'wp_update' 'wp_delete'; do
  if grep -Fq "$forbidden" "$script" "$payload"; then fail "diagnostic must remain read-only: $forbidden"; fi
done
grep -Fq 'workflow_dispatch' "$workflow" || fail 'diagnostic workflow must be manual'
grep -Fq 'environment: production' "$workflow" || fail 'diagnostic workflow must use the production environment'
grep -Fq 'refs/heads/main' "$workflow" || fail 'diagnostic workflow must require main'
grep -Fq 'production-release-diagnostic.sh' "$workflow" || fail 'diagnostic workflow must run the script'
echo 'production release diagnostic contract: PASS'
