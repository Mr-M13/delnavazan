<?php
/** Teacher consolidated workspace and Onboarding source contract. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($expect): void {
    foreach ($needles as $needle) $expect(str_contains($source, $needle), $message . ': ' . $needle);
};

$shell = $read('src/Admin/Controller/AdminShellController.php');
$screen = $read('src/Admin/Controller/ScreenController.php');
$view = $read('src/Admin/View/TeacherWorkspaceView.php');
$controller = $read('src/Admin/Controller/TeacherWorkspaceController.php');
$registry = $read('src/Admin/RecordAction/RecordActionRegistry.php');
$resolver = $read('src/Admin/RecordAction/RecordActionResolver.php');
$onboarding = $read('src/Core/Application/TeacherOnboardingWorkspaceReadService.php');
$onboardingService = $read('src/Core/Application/TeacherOnboardingService.php');
$repository = $read('src/Core/Infrastructure/Repository/TeacherOnboardingRepository.php');

$expect(1 === preg_match_all("/\\['Teachers', 'dzn-teachers-menu'/", $shell), 'Teachers must expose exactly one consolidated destination.');

$contains($controller, array('TeacherWorkspaceReadService', 'TeacherWorkspaceView::render', 'dzn-teachers-menu'), 'The consolidated route must render the workspace.');
$contains($view, array(
    'Teacher directory',
    'Onboarding and invitations',
    'onboardingOverview',
    'teacher-onboarding',
    "'teacher-profile'",
    "'teacher-eligibility'",
    "'teacher-availability-profile'",
    "'teacher-availability-rule'",
    "'teacher-offboard'",
    "'teacher-approve-onboarding'",
    "'teacher-return-onboarding'",
    "'teacher-reject-onboarding'",
    'Rate timeline',
    'Statements',
    'Lesson payability',
), 'The Teacher workspace must expose focused profile, eligibility, availability, lifecycle, onboarding and finance actions.');
$expect(str_contains($screen, "issue_teacher_invitation_by_email"), 'Invitation creation remains a deliberate invitation action.');
$contains($view, array('Google account', 'Blockers', 'Next action', 'Google Meet', 'Expiry', 'invitation_form_id'), 'Onboarding must render Google account, blockers, next action and invitation evidence.');
$expect(!str_contains($view, "'#dzn-teacher-onboarding-' . (int)"), 'Onboarding anchors must not expose raw Teacher identifiers.');

$contains($registry, array(
    "'teacher-profile'",
    "'teacher-accepting-state'",
    "'teacher-eligibility'",
    "'teacher-availability-profile'",
    "'teacher-availability-rule'",
    "'teacher-offboard'",
    "'teacher-approve-onboarding'",
    "'teacher-return-onboarding'",
    "'teacher-reject-onboarding'",
    "self::teacherProfileFields",
    "self::courseEligibilityField",
), 'The shared registry must define all Teacher contextual actions with prepopulated fields.');
foreach (array("'name' => 'teacher_id'", "'name' => 'idempotency_key'", "'name' => 'evidence_reference'", "'name' => 'evidence_at'", "'name' => 'evidence_channel'") as $forbidden) {
    $expect(!str_contains($registry, $forbidden), 'Ordinary Teacher actions must not ask administrators for technical identifiers or evidence fields: ' . $forbidden);
}
$contains($resolver, array("'teacher' => array('dzn_manage_teachers', 'dzn_manage_onboarding')", 'teacherOnboardingProfile'), 'Onboarding-scoped Teacher reads must be allowed without widening Teacher maintenance.');

$expect(!str_contains($onboarding, "\$progress['readiness_state']"), 'Next-action logic must use the stored readiness state, not a missing progress key.');
$expect(!str_contains($onboarding, "if (\$state === 'pending_review') return array();"), 'Pending-review rows must still compute blockers.');
$contains($onboarding, array(
    'workspaceRows',
    'progressForAdministrator',
    'googleConnection',
    'projectionReadiness',
    'profile_issues',
    'blockers',
    'next_action',
    'meet_caveat',
    'calendar',
    'meeting',
    'A Google account connection does not prove that Google Meet is operational.',
), 'Onboarding must show progress, blockers, next action, Google account, Calendar and Meet evidence separately.');
$contains($repository, array(
    'workspaceRows',
    'googleConnection',
    'projectionReadiness',
    'provider_calendar_event_mappings',
    'provider_meeting_mappings',
    'revoked_at',
), 'Onboarding reads must use bounded provider projections without exposing raw references.');
$expect(!str_contains($repository, 'credential_material') && !str_contains($repository, 'access_token') && !str_contains($repository, 'refresh_token'), 'Onboarding reads must never select credential material.');
$contains($onboardingService, array('progressForAdministrator', "current_user_can('dzn_manage_onboarding')"), 'Administrator progress reads must be capability-gated and reuse the canonical progress rules.');

echo "Teacher workspaces contract passed\n";
