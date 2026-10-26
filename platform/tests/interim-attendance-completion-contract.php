<?php
/**
 * Source contract for the interim attendance and completion policy.
 *
 * This is a bounded source-level guard. Behavioural proof lives in the disposable WordPress/MariaDB
 * runtime suite; this contract proves the policy cannot quietly regress into provider coupling, a
 * competing attendance table, an unverified payable completion or a non-idempotent report path.
 */
$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $file = $root . '/' . $relative;
    if (!is_file($file)) throw new RuntimeException('Missing required file: ' . $relative);
    return (string) file_get_contents($file);
};
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$authority = $read('src/Core/Application/CanonicalLessonAuthorityService.php');
$policy = $read('src/Core/Application/CanonicalLessonCompletionPolicy.php');
$worker = $read('src/Integrations/LessonCompletionAutomationWorker.php');
$delivery = $read('src/Core/Application/CanonicalLessonDeliveryService.php');
$validator = $read('src/Core/Application/CanonicalLessonDeliveryValidator.php');
$intake = $read('src/Core/Application/CanonicalAttendanceIntakeService.php');
$student = $read('src/Portals/StudentAbsenceController.php');
$teacher = $read('src/Portals/TeacherDeliveryClaimController.php');
$screen = $read('src/Admin/Controller/ScreenController.php');
$financeRule = $read('src/Core/Application/Finance/FinanceRule.php');
$financeFacts = $read('src/Core/Application/Finance/FinanceFacts.php');
$payability = $read('src/Core/Application/Finance/LessonPayabilityService.php');
$bootstrap = $read('delnavazan-platform.php');

// The presumed completion is a distinct lifecycle reason, not a Phase-O delivered outcome.
$assert(str_contains($authority, "canonical_lesson_presumed_completed"), 'The presumed completion reason must be explicit');
$assert(str_contains($authority, 'function presume('), 'Canonical Lesson authority must own presumption');
$assert(str_contains($authority, 'CanonicalLessonCompletionPolicy'), 'Presumption must run the canonical completion guard');
$assert(str_contains($policy, "REASON_PRESUMED='canonical_lesson_presumed_completed'"), 'The policy must lock the presumed reason');
$assert(str_contains($policy, "UNRESOLVED_CASE_STATES=array('open','ready_for_review','settlement_pending')"), 'Unresolved attendance cases must block presumption');
$assert(str_contains($policy, 'delivery_outcome_exists'), 'An existing outcome must block presumption');
$assert(str_contains($policy, 'attendance_conflict_unresolved'), 'A conflicting report must block presumption');
$assert(str_contains($policy, 'occurrence_not_ended') && str_contains($policy, 'occurrence_not_started'), 'A future or running occurrence must never be presumed complete');

// The worker is scheduled, bounded, canonical, UTC-only and idempotent.
$assert(str_contains($bootstrap, 'LessonCompletionAutomationWorker::register()'), 'The completion worker must be registered');
$assert(str_contains($worker, "HOOK='dzn_lesson_completion_automation_dispatch'"), 'The completion worker must use its own cron hook');
$assert(str_contains($worker, 'endedAuthorisedLessonIds'), 'The worker must evaluate ended authorised Lessons only');
$assert(str_contains($worker, "'evidence_at'=>(string)\$version->ends_at_utc"), 'The evidence instant must be the canonical UTC occurrence end');
$assert(str_contains($worker, "'lesson-completion-presumption:'.\$lesson->uid.':'.\$version->id"), 'The command key must be deterministic per Lesson and schedule version');
$assert(!str_contains($worker, 'google') && !str_contains($worker, 'meet'), 'Completion automation must not depend on Google Meet');
$assert(str_contains($worker, 'PostIntroSystemActor::run'), 'Automation must use the existing provisioned system-identity seam');
$assert(str_contains($worker, 'CanonicalLessonAuthorityService') && !str_contains($worker, '$wpdb->update'), 'The worker must delegate to canonical authority, never write lifecycle or attendance itself');

// Late reports become append-only corrections, never deleted history.
$assert(str_contains($delivery, 'function dispute('), 'A completed occurrence must support an explicit dispute correction');
$assert(str_contains($delivery, "'dispute_completion'"), 'The dispute operation must be a distinct append-only command');
$assert(str_contains($delivery, "'disputed'"), 'A dispute must record its own command result state');
$assert(str_contains($delivery, 'function reconcile('), 'Non-delivery correction must reuse O-D8 reconciliation');
$assert(str_contains($validator, "in_array(\$effective,array('teacher_non_delivery','review_required'),true)"), 'A disputed completion must validate against its immutable completion event');
$assert(str_contains($intake, "\$delivery->reconcile") && str_contains($intake, "\$delivery->dispute"), 'Administrative adjudication of a completed occurrence must correct append-only');

// Reports are principal-scoped Phase-P evidence and repeated submissions converge.
$assert(str_contains($student, "ACTION_ISSUE='dzn_student_report_lesson_issue'"), 'Students must be able to report a missed/disputed class');
$assert(str_contains($student, "'student_reports_missed_class'") && str_contains($student, "'student_disputes_class'"), 'Student issue reports must use controlled reason codes');
$assert(str_contains($student, "'student-claim:'.get_current_user_id()"), 'Student report key must be deterministic');
$assert(str_contains($teacher, "ACTION_DELIVERED='dzn_teacher_report_delivery'"), 'Teachers must be able to report a delivered class');
$assert(str_contains($teacher, "'teacher_reports_delivered'"), 'The delivered report must use a controlled reason code');
$assert(str_contains($teacher, "'teacher-claim:'"), 'Teacher report key must be deterministic');
$assert(str_contains($intake, 'authoriseOwnership') && str_contains($intake, 'hasTeacherPrincipal') && str_contains($intake, 'hasStudentPrincipal'), 'Report authority must stay bound to the owning principal');
$assert(str_contains($screen, 'record_canonical_delivery') && str_contains($screen, 'reconcile_canonical_delivery') && str_contains($screen, 'dispute_canonical_delivery'), 'Administrators must have completed/not-delivered/disputed correction actions');
$assert(str_contains($screen, 'dzn_manage_canonical_lesson_delivery'), 'Administrative corrections must stay capability-protected');

// Presumed completion is never automatically payable or statement-releasable.
$assert(str_contains($financeFacts, "'completion_presumed'"), 'Finance facts must carry the completion basis');
$assert(str_contains($payability, "case 'presumed_completion':"), 'Payability must explicitly evaluate presumed completion');
$assert(str_contains($financeRule, "array('row'=>6,'fact'=>'presumed_completion','disposition'=>'pending','basis_code'=>'presumed_completion_review'"), 'Presumed completion must remain pending, not payable');

// Provider independence and no competing attendance storage.
$assert(!str_contains($policy, 'google') && !str_contains($policy, 'meet'), 'The completion policy must be provider-independent');
foreach (array('delivery', 'policy', 'worker') as $name) {
    $source = ${$name};
    $assert(!str_contains($source, 'canonical_lesson_attendance_'), 'No competing attendance table may be introduced');
}
$assert(str_contains($policy, 'CanonicalAttendanceRepository') && str_contains($policy, 'CanonicalLessonDeliveryRepository'), 'The guard must read existing canonical intake and delivery authority');

echo "Interim attendance and completion source contract: PASS\n";
