<?php
/** Teacher Overview and Onboarding workspace source contract. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($expect): void {
    foreach ($needles as $needle) $expect(str_contains($source, $needle), $message . ': ' . $needle);
};

$shell = $read('src/Admin/Controller/AdminShellController.php');
$screen = $read('src/Admin/Controller/ScreenController.php');
$registry = $read('src/Admin/RecordAction/RecordActionRegistry.php');
$resolver = $read('src/Admin/RecordAction/RecordActionResolver.php');
$onboarding = $read('src/Core/Application/TeacherOnboardingWorkspaceReadService.php');
$onboardingService = $read('src/Core/Application/TeacherOnboardingService.php');
$repository = $read('src/Core/Infrastructure/Repository/TeacherOnboardingRepository.php');

$expect(2 === preg_match_all("/\\['(?:Overview|Onboarding & Invitations)', 'dzn-teachers-menu'/", $shell), 'Teachers must expose exactly two tabs.');

$contains($screen, array(
    "if (\$view === 'onboarding')",
    'self::teacherInvitationPanel();',
    'TeacherOnboardingWorkspaceReadService',
    'teacherReadinessPanel',
    'teacherFinanceLinks',
    "'teacher-profile'",
    "'teacher-eligibility'",
    "'teacher-availability-profile'",
    "'teacher-availability-rule'",
    "'teacher-offboard'",
    "'teacher-approve-onboarding'",
    "'teacher-return-onboarding'",
    "'teacher-reject-onboarding'",
    'Current rate report',
    'Current statement report',
    'Central reconciliation',
), 'The Teacher workspaces must expose focused profile, eligibility, availability, lifecycle, onboarding and finance follow-up actions.');
$expect(!str_contains($screen, "self::formStart('update_teacher_profile')"), 'Teacher profile maintenance must use the shared action renderer.');
$contains($screen, array("self::formStart('issue_teacher_invitation_by_email')"), 'Invitation creation remains a deliberate invitation action.');
$expect(!str_contains($screen, 'teacherArchiveActions('), 'Teacher lifecycle must not retain a parallel archive form.');
$expect(!str_contains($screen, 'name="command_key"'), 'Invitation idempotency keys must be generated server-side, not exposed in the form.');
$contains($screen, array('Google account', 'Calendar', 'Meet', 'Blockers / next action', 'meet_caveat', 'generation_expires_at'), 'Onboarding must render Google account, Calendar, Meet, blockers, next action and invitation expiry with the Meet caveat.');

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
$contains($resolver, array("'teacher' => array('dzn_manage_teachers', 'dzn_manage_onboarding')", 'TeacherRepository', 'adminProfile'), 'Onboarding-scoped Teacher reads must be allowed without widening Teacher maintenance.');

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
