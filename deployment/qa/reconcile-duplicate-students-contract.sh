#!/usr/bin/env bash
# Offline source contract for the guarded duplicate-Student reconciler.
# Proves the safety shape without touching a database.
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
php="$root/deployment/qa/reconcile-duplicate-students.php"
sh="$root/deployment/qa/reconcile-duplicate-students.sh"
wf="$root/.github/workflows/reconcile-duplicate-students.yml"
fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
need(){ [[ -f "$1" ]] || fail "missing $(basename "$1")"; }
has(){ grep -Fq -- "$2" "$1" || fail "missing contract: $3"; }
hasnot(){ ! grep -Fq -- "$2" "$1" || fail "forbidden contract: $3"; }
hasre(){ grep -Eq -- "$2" "$1" || fail "missing pattern: $3"; }

need "$php"; need "$sh"; need "$wf"

# 1. Nothing destructive.
hasnot "$php" 'wp_delete_user' 'the reconciler must never delete a WordPress user'
hasnot "$php" 'DELETE FROM' 'the reconciler must never delete rows'
hasnot "$php" 'TRUNCATE' 'the reconciler must never truncate a table'
has "$php" 'StudentAdministrationService' 'archiving must use the reviewed administration service'
has "$php" '->archive(' 'the only mutation is archive'

# 2. Conservative classification.
has "$php" "'student_principal_links', 'student_acceptance_capacity_classifications'" 'only identity evidence may be ignored when deciding empty'
has "$php" 'manual_review' 'multi-history groups must be manual review'
has "$php" "count( \$keep ) === 1" 'exactly one history owner is required for an automatic archive'
has "$php" "hash_equals( \$fingerprint, \$dzn_rec_expected )" 'execute must match the reviewed fingerprint'
has "$php" "'RECONCILE_DUPLICATES'" 'the confirm token is fixed'
has "$php" 'b74053a74d5419f2bb026036e1dd05ca233b7ccef2df5949acd4ef1303041102' 'the protected attestation digest is pinned'
has "$php" 'backup_implausible' 'a missing/implausible backup must stop execute'
has "$php" 'backup_digest_mismatch' 'the backup digest must be verified'
has "$php" "no_active_principal_remains" 'post-verification must prove no active principal remains'
has "$php" "'archives_performed'" 'report and execute must expose their mutation state'

# 3. Read-only report: the report branch must emit before the execute guards.
report_line="$(grep -n "'mode'                 => 'report'" "$php" | cut -d: -f1 | head -n1 || true)"
[[ -n "$report_line" ]] || fail 'report mode must be explicit'
execute_line="$(grep -n 'if ( .execute. !== \$dzn_rec_mode )' "$php" | cut -d: -f1 | head -n1 || true)"
[[ -n "$execute_line" ]] || fail 'execute mode must be validated'
(( report_line < execute_line )) || fail 'report must return before any execute path'

# 4. Host wrapper guards.
has "$sh" 'DZN_RECONCILE_ATTESTATION:?' 'a direct execute must require the protected attestation'
has "$sh" 'RECONCILE_DUPLICATES' 'execute must require the confirm token'
has "$sh" 'pre-reconcile-' 'execute must take a fresh backup'
has "$sh" 'sha256sum' 'the backup must be digested'
has "$sh" "main-\${sha:0:12}" 'the tool must refuse a build that is not the reviewed SHA'
has "$sh" 'gzip -t' 'the backup must pass gzip integrity'

# 5. Workflow is protected and reviewable.
has "$wf" 'workflow_dispatch:' 'the workflow must be dispatch-only'
has "$wf" 'environment: production' 'the workflow must use the protected production environment'
has "$wf" 'refs/heads/main' 'dispatch must come from main'
has "$wf" 'group: delnavazan-production-deploy' 'it must serialize with production deploys'
has "$wf" 'RECONCILE_DUPLICATES' 'execute must require the confirm token'
has "$wf" 'PURGE_ATTESTATION_TOKEN' 'execute must reuse the protected attestation secret'
has "$wf" 'reconcile-report.json' 'the report must be preserved'
has "$wf" 'reconcile-execute.json' 'the execute evidence must be preserved'
has "$wf" 'merge-base --is-ancestor' 'source and deployed SHAs must be on main'

echo "Duplicate student reconciler contract passed"
