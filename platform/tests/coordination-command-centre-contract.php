<?php
/**
 * Source contract for the Booking Coordination command centre.
 *
 * Presentation and workflow shape only; runtime authority remains in the application
 * services exercised by their own contracts and isolated runtimes.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$test = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($test): void {
    foreach ($needles as $needle) $test(str_contains($source, $needle), $message . ': ' . $needle);
};

$controller = $read('src/Admin/Controller/CoordinationCaseController.php');
$view = $read('src/Admin/View/CoordinationWorkspaceView.php');
$readService = $read('src/Core/Application/CoordinationWorkspaceReadService.php');
$blockers = $read('src/Core/Application/CoordinationBlockerAssessment.php');
$css = $read('src/Admin/Assets/admin.css');
$menu = $read('src/Admin/Controller/Menu.php');
$shell = $read('src/Admin/Controller/AdminShellController.php');

// One command-centre surface, using the existing protected route.
$contains($controller, array(
    'CoordinationWorkspaceReadService', 'CoordinationWorkspaceView',
    "public const PAGE = 'dzn-booking-request-coordination'",
    "private const CAPABILITY = 'dzn_manage_booking_request_coordination'",
    "private const VIEW_CAPABILITY = 'dzn_view_booking_requests'",
    'Delnavazan\\Platform\\Admin\\View\\CoordinationWorkspaceView',
), 'The coordination controller must use the new command-centre read model and view');
$contains($menu, array("'dzn-booking-request-coordination'"), 'The protected coordination route must remain registered');
$contains($shell, array("'dzn-booking-request-coordination' => 'enrolment'"), 'Coordination must remain inside the updated Enrolment navigation area');

// Every section requested by the operating model.
$contains($view, array(
    'Case overview', 'Request details', 'Action required', 'Recommended next action',
    'Preferred scheduling options', 'Student', 'Acceptance authority', 'Teacher matching',
    'Agreements', 'Proposals', 'Provisional acceptance', 'Final acceptance',
    'Enrolment and assignment', 'Scheduling', 'Payment', 'Communications', 'Case state',
    'Activity and audit', 'Advanced / audit details',
), 'The command centre must expose every operational section');

// Human-readable forms; technical values stay in the advanced disclosure.
$contains($view, array(
    'Existing verified Student', 'Reviewed display name', 'Teacher', 'Course',
    'Commencement from (local)', 'Agreement observed (local)', 'Fresh confirmation (local)',
    'Payment date and time (local)', 'Exact Proposal Version', 'Accepting principal',
), 'Ordinary forms must use human labels and local time controls');
foreach (array('Candidate ID', 'Family UID', 'Option UID', 'Version number</span>', 'Idempotency key', 'Expected version', 'Replacement assent ID', 'Raw UTC timestamp') as $leak) {
    $test(!str_contains($view, $leak), 'Technical operator field leaked into the ordinary view: ' . $leak);
}
$test(!str_contains($view, 'name="idempotency_key"'), 'The ordinary view must not expose an idempotency field');
$test(!str_contains($view, 'name="arrangement_fingerprint"'), 'The ordinary view must not ask for an arrangement fingerprint');
$test(!str_contains($view, 'name="family_uid"') && !str_contains($view, 'name="option_uid"'), 'The ordinary view must not ask for Proposal UIDs');
$test(str_contains($readService, "'case_uid'") && str_contains($view, 'Advanced / audit details'), 'Technical references must remain available in the collapsible advanced section');

// Generated values are server-side and mutations go through authoritative services.
$contains($controller, array(
    'wp_generate_uuid4()', 'TeacherAvailabilityAssentService', 'ProposalService',
    'ProposalAcceptanceService', 'FinalAcceptanceService', 'StudentIdentityResolutionService',
    'StudentAcceptanceAuthorityService', 'EnrolmentConversionService', 'TeacherAssignmentService',
    'StudentAccountClaimNotificationService', 'NotificationManualDeliveryService',
    'IntroPaymentService', 'BookingRequestMatchAssessmentService',
), 'Mutations and generated keys must stay in authoritative services');
$test(!preg_match('/\$wpdb\s*->\s*(?:insert|update|delete)\s*\(/', $controller), 'The coordination controller must not write domain tables');
$test(!preg_match('/\$wpdb\s*->\s*(?:insert|update|delete)\s*\(/', $readService), 'The coordination read service must not write domain tables');
$test(!str_contains($readService, 'INSERT INTO') && !str_contains($readService, 'UPDATE ') && !str_contains($readService, 'DELETE FROM'), 'The coordination read service must remain read-only');

// Permission gates for every narrow action.
$capabilities = $read('src/Core/Application/CoordinationCapabilities.php');
$contains($controller, array(
    'dzn_manage_teacher_availability_assent', 'dzn_issue_booking_request_proposals',
    'dzn_record_booking_request_provisional_acceptance', 'dzn_finalize_service_arrangements',
    'dzn_convert_service_arrangements_to_enrolments', 'CoordinationCapabilities::ASSIGNMENT',
    'dzn_issue_student_account_claims', 'dzn_operate_notification_dispatch',
    'dzn_manage_payment_execution', 'dzn_resolve_booking_request_student_identity',
    'dzn_manage_student_acceptance_authority', 'requireAcceptanceCapability',
    'requireFinalAcceptanceCapability', 'requireProposalCapability',
), 'Every action must keep its narrow capability gate');
$contains($capabilities, array("ASSIGNMENT = 'dzn_manage_teacher_assignments'"), 'The assignment capability must remain narrow and explicit');

// Blockers explain the missing fact, evidence, supported action and consequence.
$contains($blockers . $view, array(
    'What is missing', 'Why it matters', 'Evidence required', 'What to do', 'Once resolved',
    'identity_unresolved', 'capacity_unresolved', 'authority_missing', 'no_candidates',
    'teacher_conflict', 'student_conflict', 'assent_missing', 'proposal_missing',
    'provisional_missing', 'arrangement_missing', 'conversion_pending', 'assignment_missing',
    'account_claim_missing', 'notification_failed', 'calendar_pending',
    'recommended', 'alternatives', 'available', 'capability',
), 'Blockers must be contextual and must explain disabled prerequisites');
$contains($view, array('Why it matters', 'Evidence required', 'What to do', 'Once resolved', 'Requires '), 'The view must render complete blocker guidance, not one-line errors');
$test(str_contains($blockers, 'The decision is not a simple') === false, 'Blockers must not use generic placeholders');

// Flexible, non-linear workflow and explicit unsupported boundaries.
$contains($view, array(
    'Other valid actions', 'These alternatives are shown so the administrator is not forced into a one-directional wizard',
    'no administrator command to rewrite a submitted request’s preferred times',
    'no administrator edit command for submitted request preferences',
    'does not simulate them', 'Reopening is not supported',
    'immutable', 'cannot be overwritten', 'direct editing is deliberately unavailable',
), 'The workspace must support non-linear work and state unsupported operations honestly');
$contains($readService, array(
    'CanonicalTeacherOccupancyReadService', 'LessonRescheduleRepository',
    'studentConflict', 'overlapping', 'TeacherAvailabilityService',
    'EnrolmentConversionReadinessService', 'Provisional', 'authority',
), 'Scheduling conflicts and conversion readiness must come from canonical read authorities');

// The redesign must not collapse the workflow stages into one another.
$contains($view, array(
    'Candidates are advisory', 'does not contact, reserve, assign, or select a Teacher',
    'Teacher Availability Assent is attributable, time-bounded evidence',
    'It does not reserve capacity, assign a Teacher, or create acceptance',
    'Evidence, not finality', 'Final acceptance does not create an Enrolment',
    'The arrangement is immutable',
), 'Advisory candidate, agreement, proposal, provisional evidence, final arrangement and Enrolment must remain distinct');

// Responsive design system and accessible structure.
$contains($css, array(
    '.dzn-coordination', '.dzn-coordination__hero', '.dzn-coordination__grid',
    '.dzn-coordination__workspace-nav', '.dzn-blocker', '.dzn-coordination__recommend',
    '.dzn-coordination__summary', '@media (max-width: 782px)', '@media (max-width: 520px)',
    'scroll-margin-block-start', 'var(--dzn-teal)', 'var(--dzn-gold)',
), 'The command centre must use the Delnavazan design system and responsive breakpoints');
$contains($view, array('aria-label="Essential case actions"', 'aria-label="Coordination workspace sections"', 'aria-disabled="true"'), 'The workspace must carry accessible navigation and disabled-action semantics');

// Navigation labels stay in English; customer-facing localisation is untouched.
foreach (array('Booking coordination', 'Action required', 'Teacher matching', 'Final acceptance') as $label) {
    $test(str_contains($view, $label), 'Admin label must remain in English: ' . $label);
}
$test(!str_contains($controller, 'پ'), 'The admin controller must not introduce Persian customer copy');

// Preserve the canonical handoff to the booking route.
$contains($view, array('dzn-enrolment-workspace', 'Open Enrolment', 'Routine or exceptional?'), 'Class admin must distinguish routine booking from exceptional coordination');
$contains($blockers . $view, array('No further coordination action', 'Review the closed case history'), 'Archived/closed cases must remain readable without a fake mutation');

echo "Coordination command centre contract passed\n";
