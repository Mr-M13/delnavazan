<?php
/** Isolated executable check for the version-marker capability reconciliation. */
final class Phase2A2ARole {
    public array $caps = array();
    public int $adds = 0;
    public function add_cap(string $cap): void { $this->caps[$cap] = true; $this->adds++; }
    public function has_cap(string $cap): bool { return ! empty($this->caps[$cap]); }
}
$phase2a2aOptions = array();
$phase2a2aAdmin = new Phase2A2ARole();
$phase2a2aRoles = array( 'administrator' => $phase2a2aAdmin );
function get_option(string $key, mixed $default = false): mixed { global $phase2a2aOptions; return $phase2a2aOptions[$key] ?? $default; }
function update_option(string $key, mixed $value, mixed $autoload = null): bool { global $phase2a2aOptions; $phase2a2aOptions[$key] = $value; return true; }
function get_role(string $name): ?Phase2A2ARole { global $phase2a2aRoles; return $phase2a2aRoles[$name] ?? null; }
function add_role(string $name, string $display, array $caps): ?Phase2A2ARole {
    global $phase2a2aRoles;
    if ($name === '' || isset($phase2a2aRoles[$name])) return null;
    $role = new Phase2A2ARole();
    foreach ($caps as $cap => $grant) {
        if (is_int($cap)) { $role->add_cap((string)$grant); continue; }
        if ($grant) $role->add_cap((string)$cap);
    }
    $phase2a2aRoles[$name] = $role;
    return $role;
}
require dirname(__DIR__) . '/src/Core/Infrastructure/Migration/Migrator.php';

// The reconciliation marker is read from the authority itself so a later additive phase that
// advances the package version cannot silently invalidate this lifecycle proof.
$phase2a2aMigrator = new ReflectionClass( 'Delnavazan\\Platform\\Core\\Infrastructure\\Migration\\Migrator' );
$phase2a2aMarker = (string) $phase2a2aMigrator->getConstant( 'CAPABILITY_VERSION' );
$phase2a2aExpected = array(
    'dzn_manage_platform','dzn_manage_students','dzn_manage_teachers','dzn_manage_courses','dzn_manage_enrolments',
    'dzn_manage_terms','dzn_manage_lessons','dzn_manage_exceptions','dzn_view_diagnostics','dzn_manage_onboarding',
    'dzn_issue_teacher_invitations','dzn_revoke_teacher_invitations','dzn_view_onboarding_audit','dzn_relink_teacher_principal',
    'dzn_manage_teaching_eligibility','dzn_manage_teacher_availability','dzn_view_booking_requests',
    'dzn_review_booking_request_duplicates','dzn_erase_booking_request_pii','dzn_prepare_booking_request_matches',
    'dzn_manage_booking_request_coordination','dzn_manage_teacher_availability_assent','dzn_issue_booking_request_proposals',
    'dzn_record_booking_request_provisional_acceptance','dzn_resolve_booking_request_student_identity',
    'dzn_manage_student_acceptance_authority','dzn_view_student_acceptance_eligibility','dzn_finalize_service_arrangements',
    'dzn_convert_service_arrangements_to_enrolments','dzn_manage_teacher_assignments','dzn_manage_canonical_terms',
    'dzn_manage_canonical_enrolment_lifecycle','dzn_manage_canonical_lessons','dzn_manage_canonical_lesson_schedules',
    'dzn_override_canonical_lesson_schedule_availability',
    // Phase O: canonical Lesson delivery / attendance authority.
    'dzn_manage_canonical_lesson_delivery',
    // Phase P: canonical attendance intake and review.
    'dzn_ingest_canonical_attendance_evidence','dzn_submit_own_attendance_claim','dzn_submit_own_delivery_claim',
    'dzn_manage_canonical_attendance_review','dzn_view_canonical_attendance_review','dzn_manage_canonical_attendance_identity',
    // Phase Q: post-intro continuation and slot reservation.
    'dzn_manage_canonical_continuation','dzn_view_canonical_continuation',
    // Phase R1: commercial purchase, funding and current-Term capacity.
    'dzn_manage_commercial_catalogue','dzn_manage_commercial_promotions','dzn_manage_commercial_adjustments',
    'dzn_issue_commercial_offers','dzn_ingest_commercial_payment_evidence','dzn_bind_commercial_term_funding',
    'dzn_manage_commercial_capacity','dzn_manage_commercial_policies','dzn_manage_commercial_exceptions',
    'dzn_view_commercial_authority',
    // Phase R2: renewal, recurring collection, recovery, lapse and refund review.
    'dzn_manage_recurring_enrolments','dzn_manage_renewal_cycles','dzn_manage_collection_intents','dzn_manage_recovery',
    'dzn_manage_refund_reviews','dzn_manage_recurring_protection','dzn_view_recurring_authority',
);
$phase2a2aMethod = new ReflectionMethod('Delnavazan\\Platform\\Core\\Infrastructure\\Migration\\Migrator', 'ensure_capabilities');
$phase2a2aMethod->setAccessible(true);

// Absent marker installs the current protected capability set and marker.
$phase2a2aMethod->invoke(null);
foreach ($phase2a2aExpected as $phase2a2aCapability) if (!$phase2a2aAdmin->has_cap($phase2a2aCapability)) throw new RuntimeException('Absent capability marker was not installed: ' . $phase2a2aCapability);
if (get_option('dzn_platform_capability_version') !== $phase2a2aMarker) throw new RuntimeException('Absent capability marker was not installed');
$phase2a2aTeacher = get_role('dzn_teacher');
if (!$phase2a2aTeacher || !$phase2a2aTeacher->has_cap('read') || !$phase2a2aTeacher->has_cap('dzn_record_own_availability_assent') || !$phase2a2aTeacher->has_cap('dzn_accept_own_teacher_assignments') || !$phase2a2aTeacher->has_cap('dzn_submit_own_delivery_claim') || !$phase2a2aTeacher->has_cap('dzn_submit_own_continuation_match_exception')) throw new RuntimeException('Teacher capability was not installed');
// Least privilege: the Teacher role never holds administrative or commercial authority.
foreach (array('dzn_manage_canonical_attendance_review','dzn_view_canonical_attendance_review','dzn_manage_canonical_attendance_identity','dzn_ingest_canonical_attendance_evidence','dzn_submit_own_attendance_claim','dzn_manage_canonical_continuation','dzn_view_canonical_continuation','dzn_view_commercial_authority','dzn_manage_commercial_catalogue','dzn_manage_recurring_enrolments','dzn_manage_renewal_cycles','dzn_manage_collection_intents','dzn_manage_recovery','dzn_manage_refund_reviews','dzn_manage_recurring_protection','dzn_view_recurring_authority') as $phase2a2aReserved) if ($phase2a2aTeacher->has_cap($phase2a2aReserved)) throw new RuntimeException('The Teacher role must never hold ' . $phase2a2aReserved);
$adds = $phase2a2aAdmin->adds;
// Current marker plus present capability is a harmless no-op.
$phase2a2aMethod->invoke(null);
if ($phase2a2aAdmin->adds !== $adds) throw new RuntimeException('Current capability marker was not a harmless no-op');
// A damaged current marker cannot suppress reconciliation.
unset($phase2a2aAdmin->caps['dzn_manage_terms'], $phase2a2aAdmin->caps['dzn_prepare_booking_request_matches'], $phase2a2aAdmin->caps['dzn_issue_booking_request_proposals'], $phase2a2aAdmin->caps['dzn_record_booking_request_provisional_acceptance'], $phase2a2aAdmin->caps['dzn_resolve_booking_request_student_identity'], $phase2a2aAdmin->caps['dzn_manage_student_acceptance_authority'], $phase2a2aAdmin->caps['dzn_view_student_acceptance_eligibility'], $phase2a2aAdmin->caps['dzn_finalize_service_arrangements'], $phase2a2aAdmin->caps['dzn_convert_service_arrangements_to_enrolments'], $phase2a2aAdmin->caps['dzn_manage_teacher_assignments'], $phase2a2aAdmin->caps['dzn_manage_canonical_terms'], $phase2a2aAdmin->caps['dzn_manage_canonical_enrolment_lifecycle'], $phase2a2aAdmin->caps['dzn_manage_canonical_lessons'], $phase2a2aAdmin->caps['dzn_view_commercial_authority'], $phase2a2aAdmin->caps['dzn_view_recurring_authority']);
$phase2a2aMethod->invoke(null);
foreach ($phase2a2aExpected as $phase2a2aCapability) if (!$phase2a2aAdmin->has_cap($phase2a2aCapability)) throw new RuntimeException('Missing current capability was not restored: ' . $phase2a2aCapability);
// A damaged Teacher role must be repaired even when the lifecycle marker is current.
unset($phase2a2aTeacher->caps['dzn_record_own_availability_assent']);
$phase2a2aTeacher->caps['dzn_accept_own_teacher_assignments'] = false;
$phase2a2aMethod->invoke(null);
if (!$phase2a2aTeacher->has_cap('dzn_record_own_availability_assent') || !$phase2a2aTeacher->has_cap('dzn_accept_own_teacher_assignments')) throw new RuntimeException('Missing Teacher capability was not restored');
// A partially installed phase marker must be repaired without touching the current marker option.
unset($phase2a2aAdmin->caps['dzn_manage_recurring_enrolments'], $phase2a2aAdmin->caps['dzn_manage_renewal_cycles']);
$phase2a2aMethod->invoke(null);
foreach (array('dzn_manage_recurring_enrolments','dzn_manage_renewal_cycles') as $phase2a2aCapability) if (!$phase2a2aAdmin->has_cap($phase2a2aCapability)) throw new RuntimeException('Partially installed capability was not repaired: ' . $phase2a2aCapability);
echo "Phase 2A.2-A capability lifecycle passed\n";
