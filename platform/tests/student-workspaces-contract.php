<?php
/** Consolidated Students workspace authority, safety and presentation contract. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$has = static function (string $haystack, array $needles, string $message) use ($assert): void {
    foreach ($needles as $needle) $assert(str_contains($haystack, $needle), $message . ': ' . $needle);
};

$controller = $read('src/Admin/Controller/StudentWorkspaceController.php');
$service = $read('src/Core/Application/StudentAdministrationService.php');
$readService = $read('src/Core/Application/StudentWorkspaceReadService.php');
$repository = $read('src/Core/Infrastructure/Repository/StudentRepository.php');
$registry = $read('src/Admin/RecordAction/RecordActionRegistry.php');
$menu = $read('src/Admin/Controller/Menu.php');
$shell_controller = $read('src/Admin/Controller/AdminShellController.php');
$filtersSource = $read('src/Core/Application/StudentWorkspaceFilters.php');
$shell = $read('src/Admin/Controller/AdminShellController.php');

$assert(preg_match("/'students' => \\['Students', \\[/", $shell) === 1, 'Students must remain a first-class administration area.');
$assert(preg_match_all("/\\['Directory', 'dzn-student'/", $shell) === 1, 'Students must expose exactly one visible directory destination.');
$assert(str_contains($menu, "'Identity & authentication', 'dzn_view_student_acceptance_eligibility', 'dzn-student-identity-authority'"), 'The protected identity authority route must remain registered for specialist review.');
$assert(!str_contains($shell, 'Identity & accounts'), 'The specialist identity route must no longer be part of the ordinary Students navigation.');

$has($repository, array(
    'last_activity_at',
    'GREATEST(',
    'br.retention_due_at>UTC_TIMESTAMP()',
    'current_term_completed',
    'payment_outstanding_minor',
    'absence_count',
    'LIMIT %d OFFSET %d',
), 'The directory must rank recent activity from canonical timestamps and expose the required operational columns.');
$has($readService, array(
    'current_identity_resolution_id IS NULL',
    'student_acceptance_authority_grants',
    'canonical_attendance_cases',
    'canonical_lesson_delivery_outcomes',
    'commercial_offers',
    'commercial_offer_obligations',
    'commercial_obligation_settlements',
    'commercial_payment_evidence',
    'commercial_promotion_redemptions',
    'renewal_cycles',
    'recording_decisions',
    'operational_exceptions',
    'notification_failed',
    'applyDirectoryNotificationCounts',
), 'Profile sections must be read from their canonical owners.');

$has($service, array(
    "require('dzn_manage_students')",
    "require('dzn_issue_student_account_claims')",
    "require('dzn_manage_student_acceptance_authority')",
    "require('dzn_manage_exceptions')",
    "require('dzn_manage_teacher_assignments')",
    'retrieve_password(',
    'student_identity_already_exists',
    'random_bytes(16)',
), 'Every Student mutation must enforce its narrow authority and preserve idempotency evidence.');
$assert(!str_contains($service, '$wpdb->update($this->p . \'student_principal_links\''), 'Student principal links must only be mutated by the canonical authority.');
$assert(!str_contains($service, '$wpdb->insert($this->p . \'student_acceptance_authority_grants\''), 'Guardian grants must only be mutated by the canonical authority.');

$has($registry, array(
    "'student-profile'",
    "'student-enrolment-transition'",
    "'student-term-transition'",
    "'student-lesson-reschedule'",
    "'student-attendance-review'",
    "'student-reschedule-decision'",
    "'student-resolve-booking-identity'",
    "'student-booking-identity-outcome'",
    "'student-archive'",
    "'student-restore'",
    "'student-send-password-reset'",
    "'student-issue-account-claim'",
    "'student-link-account'",
    "'student-supersede-account'",
    "'student-deactivate-account'",
    "'student-guardian-grant'",
    "'student-guardian-revoke'",
    "'student-guardian-supersede'",
    "'student-task-acknowledge'",
    "'student-task-resolve'",
    "'student-task-dismiss'",
    "'student-reassign-teacher'",
    "'record_capability' => 'dzn_manage_students'",
), 'Student actions must be contextual, re-checked against the Student record and handled through shared actions.');
foreach (array('Create distinct student unresolved','Record non-resolved review outcome','Classify access','Establish Student principal link','Revoke Student principal link','Atomically supersede Student principal link') as $forbidden) {
    $assert(!str_contains($controller, $forbidden), 'Raw technical control leaked into the ordinary Students workspace: ' . $forbidden);
}

$has($controller, array(
    'No checkout or payment evidence',
    'lesson_page',
    'Older lessons',
    'Start re-enrolment for this student',
    'This profile does not offer a notification retry button',
    'Distinct lifecycle meanings',
    'Archiving is refused while operational enrolments still depend on the record',
    'never delete a WordPress user',
), 'The presentation must distinguish payment, consent and lifecycle truth without overclaiming.');
$has($readService, array(
    'pendingBookingIdentityMatches',
    'enrolmentActionOptions',
    'termActionOptions',
    'lessonActionOptions',
    'attendanceCaseOptions',
    'rescheduleProposalOptions',
    'Checkout initiated — not paid',
    'SMS fallback is available only after an eligible, evidenced WhatsApp failure',
    'A queued or handed-off message is not evidence of delivery',
    'No canonical storage or processing workflow exists in this release',
), 'Payment, consent, SMS fallback and delivery reads must use evidence-backed wording.');

$assert(!str_contains($controller, 'wp_set_password'), 'The interface must never set a password.');
$assert(!str_contains($controller, 'api_key') && !str_contains($controller, 'access_token') && !str_contains($controller, 'secret_digest'), 'The interface must never expose provider secrets or token digests.');
$assert(!str_contains($controller, '$wpdb->'), 'The controller must not write or read canonical tables directly.');
$has($shell_controller, array('dzn_return_to', 'wp_validate_redirect', 'preserveReturnContext', 'Back to student profile'), 'Central workflows must carry a validated return destination back to the student profile.');
$assert(str_contains($filtersSource, "DEFAULT_STATUS = 'active'"), 'The first page must prioritise current active students.');

echo "Student workspaces contract passed\n";
