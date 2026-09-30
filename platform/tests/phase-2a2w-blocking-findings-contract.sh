#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
principal="$root/src/Portals/PortalPrincipalResolver.php"
owners="$root/src/Core/Application/PortalOwnerReadPorts.php"
subject="$root/src/Portals/PortalOwnerPorts.php"
action="$root/src/Portals/PortalPublicActionService.php"
controller="$root/src/Portals/PortalPublicActionController.php"
migrator="$root/src/Core/Infrastructure/Migration/Migrator.php"

rg -q "teacher_principal_links WHERE wordpress_user_id=%d AND status='active' AND revoked_at IS NULL" "$principal"
! rg -q "teacher_principal_links.*active_slot" "$principal" "$owners"
rg -q 'GUARDIAN_PORTAL_READ_SCOPE' "$principal" "$subject"
rg -q 'authority_scope=%s' "$owners"
rg -q 'guardianGrantId' "$owners" "$subject"
rg -q "'action_state'=>'refused'" "$action"
rg -q 'outcome_reason_code' "$action"
rg -q 'recordRefusal' "$controller"
rg -q 'capability_id bigint unsigned NULL,lesson_id bigint unsigned NULL' "$migrator"
rg -q 'e.capability_id IS NOT NULL AND c.id IS NULL' "$migrator"

# Correction round 2 — W-D18/§15.1 total ordering and §9.7/§15.3 single delegation.
# Every capability-bearing event appends under the Lesson root, re-read after the lock.
rule="$root/src/Portals/PortalRule.php"
diagnostics="$root/src/Portals/PortalDiagnosticsService.php"
concurrency="$root/tests/phase-2a2w-concurrency-runner.sh"
rg -q 'private function underLessonRoot' "$action"
rg -q 'portal_lesson_capability_roots WHERE lesson_id=%d FOR UPDATE' "$action"
rg -Fq '$capability=$hint?$this->underLessonRoot($hint):null;' "$action"
rg -Fq '$capability=$this->underLessonRoot($capability);' "$action"
rg -q 'private function recordOutcome' "$action"
rg -Fq "action_state IN ('submitted','refused')" "$action"
rg -q 'private function claimDelegation' "$action"
rg -Fq "'action_state'=>'delegating'" "$action"
rg -Fq "'|delegating|'" "$action"
for source in "$rule" "$action" "$diagnostics"; do
  rg -q 'DELEGATION_LEASE_SECONDS' "$source" || { echo "missing declared delegation lease bound in $source" >&2; exit 1; }
done
for source in "$rule" "$migrator"; do
  rg -q "'portal_absence_submission_pending'" "$source" || { echo "missing declared pending-delegation reason in $source" >&2; exit 1; }
done
rg -Fq "PortalPublicActionRefusalRecorded((string)\$claim['reason'])" "$action"
rg -Fq '$converged=true' "$action"
for artifact in setup worker wait verify; do
  test -s "$root/tests/phase-2a2w-concurrency-$artifact.php" || { echo "missing Phase-W concurrency artifact: $artifact" >&2; exit 1; }
done
for race in mint_vs_mint rotate_vs_redeem revoke_vs_redeem two_redemptions_one_confirmation replay_during_delegation replay_after_crash refusal_vs_redeem outcome_vs_rotation stale_schedule_vs_rotate two_lessons_disjoint; do
  grep -q "$race" "$concurrency" || { echo "missing Phase-W concurrency mode: $race" >&2; exit 1; }
done
rg -q 'portal_lesson_capability_roots' "$root/tests/phase-2a2w-concurrency-wait.php"

# Correction round 3 — §15.6 refusal-evidence transaction and the locked §5.2 action-state vocabulary.
# The public refusal's action row and its denial row are one piece of evidence: the service appends
# both inside the root-serialized transaction and fails closed, so the controller writes no denial.
doc="$root/docs/PHASE-2A-2W-PORTAL-FACING-SERVICES-PRINCIPAL-AUTHORIZATION-CONTRACT.md"
rg -q 'portal_access_denials' "$action"
! rg -q 'portal_access_denials' "$controller"
rg -Fq "false===\$wpdb->insert(\$p.'portal_access_denials'" "$action"
rg -Fq 'Public action state (`PortalRule::ACTION_STATES`) | `confirmed_submitting`, `delegating`, `submitted`, `redirected`, `refused`' "$doc"
rg -Fq "array('confirmed_submitting','delegating','submitted','redirected','refused')" "$rule"

# Correction round 4 — §15.6 refusal-versus-failure split.  Only a declared business refusal may append
# refusal evidence; a persistence, corruption or infrastructure failure has already rolled its own
# transaction back, so it is re-raised unchanged and can never be followed by a durable refusal record.
capctrl="$root/src/Admin/Controller/PortalCapabilityController.php"
unit="$root/tests/phase-2a2w-persistence-failure-unit.php"
rg -q 'PERSISTENCE_FAILURE_CODES' "$rule"
rg -q 'public static function refusalReason' "$rule"
rg -Fq 'portal_action_evidence_persistence_failed' "$rule"
rg -Fq 'portal_capability_persistence_failed' "$rule"
for source in "$controller" "$capctrl"; do
  rg -Fq 'PortalRule::refusalReason($e)' "$source" || { echo "missing declared-refusal classification in $source" >&2; exit 1; }
  rg -Fq 'if($reason===null)throw $e;' "$source" || { echo "a persistence or unrecognised throwable must be re-raised in $source" >&2; exit 1; }
  ! rg -q 'in_array\(\$e->getMessage\(\),PortalRule::EXCEPTION_REASON_CODES,true\)' "$source" || { echo "unrecognised throwables must not be recorded as refusals in $source" >&2; exit 1; }
done
test -s "$unit" || { echo 'missing Phase-W §15.6 persistence-failure proof' >&2; exit 1; }
rg -q 'no subsequent refusal record' "$unit"
rg -q 'rolls the whole refusal evidence back' "$unit"
rg -Fq '$refusal=PortalRule::refusalReason($e);if($refusal===null)throw $e;' "$action" || { echo "the post-delegation owner handoff must re-raise a non-refusal owner failure" >&2; exit 1; }
rg -q 'an owner persistence failure propagates unchanged out of the absence handoff' "$unit"

# Correction round 3 — §15.6 capability transaction boundaries.  Every `START TRANSACTION`/`COMMIT` result
# is checked, a failed boundary is the declared persistence failure (never a returned capability, never the
# Join `302`), and a `ROLLBACK` is issued only for a transaction the call actually opened.
capability="$root/src/Portals/PortalCapabilityService.php"
rg -Fq "private function begin():void { global \$wpdb; if(\$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('portal_capability_persistence_failed'); }" "$capability" || { echo 'the capability transaction begin is not checked' >&2; exit 1; }
rg -Fq "private function commit():void { global \$wpdb; if(\$wpdb->query('COMMIT')===false)throw new \RuntimeException('portal_capability_persistence_failed'); }" "$capability" || { echo 'the capability transaction commit is not checked' >&2; exit 1; }
rg -Fq "private function rollback():void { global \$wpdb; \$wpdb->query('ROLLBACK'); }" "$capability" || { echo 'the capability rollback helper is missing' >&2; exit 1; }
test 1 = "$(rg -c -F "\$wpdb->query('START TRANSACTION')" "$capability")" || { echo 'START TRANSACTION must be issued only by the checked begin helper' >&2; exit 1; }
test 1 = "$(rg -c -F "\$wpdb->query('COMMIT')" "$capability")" || { echo 'COMMIT must be issued only by the checked commit helper' >&2; exit 1; }
test 1 = "$(rg -c -F "\$wpdb->query('ROLLBACK')" "$capability")" || { echo 'ROLLBACK must be issued only by the rollback helper' >&2; exit 1; }
test 4 = "$(grep -o -F '$opened=false;$this->begin();$opened=true;' "$capability" | wc -l | tr -d ' ')" || { echo 'every unconditional capability transaction must open through the checked begin helper' >&2; exit 1; }
rg -Fq '$opened=false;if($transaction){$this->begin();$opened=true;}' "$capability" || { echo 'the optional capability transaction must open through the checked begin helper' >&2; exit 1; }
test 5 = "$(grep -o -F 'if($opened)$this->rollback();' "$capability" | wc -l | tr -d ' ')" || { echo 'a rollback must be issued only for a transaction that was opened' >&2; exit 1; }
for proof in \
  'a failed begin is the declared persistence failure, never a business refusal' \
  'a failed begin produces no Join response at all' \
  'a failed begin opens no transaction and appends no evidence' \
  'a failed commit is the declared persistence failure, never a 302 redirect' \
  'a failed commit returns no success response and no Location header' \
  'a failed commit commits no redirect evidence' \
  'a failed commit rolls back the transaction it opened and never re-commits' \
  'mint never returns a minted capability after a failed commit' \
  'revoke re-raises the declared persistence failure when its begin fails'; do
  rg -Fq "$proof" "$unit" || { echo "missing capability transaction failure-path proof: $proof" >&2; exit 1; }
done
printf 'Phase-W blocking findings source contract passed\n'
