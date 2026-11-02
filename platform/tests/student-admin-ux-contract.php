<?php
/** Students admin UX contract (source-level, plain PHP CLI). */
$root = dirname(__DIR__);
$readFile = static fn(string $relative): string => (string) file_get_contents($root . '/' . $relative);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$contains = static function (string $source, array $needles, string $message) use ($assert): void {
    foreach ($needles as $needle) $assert(str_contains($source, $needle), $message . ': ' . $needle);
};

$repo = $readFile('src/Core/Infrastructure/Repository/StudentRepository.php');
$read = $readFile('src/Core/Application/CoreReadService.php');
$workspaceRead = $readFile('src/Core/Application/StudentWorkspaceReadService.php');
$workspace = $readFile('src/Admin/Controller/StudentWorkspaceController.php');
$screen = $readFile('src/Admin/Controller/ScreenController.php');
$shell = $readFile('src/Admin/Controller/AdminShellController.php');

$assert(str_contains($repo, 'function adminRows(') && str_contains($repo, 'LIMIT %d OFFSET %d'), 'Student listing must remain bounded and paginated');
$assert(str_contains($repo, 'function adminWorkspaceDirectory(') && str_contains($repo, 'LIMIT %d OFFSET %d'), 'The Students workspace must use its own bounded directory query');
$assert(str_contains($repo, 'esc_like($query)') && str_contains($repo, 'reference_code LIKE %s'), 'Search must safely match human identifiers');
$assert(str_contains($read, "current_user_can('dzn_manage_students')") && str_contains($read, 'studentWorkspaceDirectory('), 'Student listing must use an authorised read service');
$assert(str_contains($screen, "StudentWorkspaceController::screen()"), 'The Students route must delegate to the consolidated workspace');
$assert(str_contains($shell, "['Directory', 'dzn-student', 'dzn_manage_students']"), 'Students must expose one visible directory destination');
$assert(!str_contains($shell, "['Identity & accounts'"), 'Technical identity administration must not remain a visible Students submenu');

$contains($workspace, array(
    "name=\"q\"",
    'Search students',
    "name=\"student_status\"",
    "name=\"student_sort\"",
    'aria-sort',
    'Next ›',
    '‹ Previous',
    'Add student',
    'student_form_id',
    'Recent activity comes first',
    'No students match these filters',
), 'The Students directory must expose search, canonical filters, sorting, paging and Add student.');

$contains($workspace, array(
    'student-details',
    'student-account',
    'student-enrolment',
    'student-courses',
    'student-terms',
    'student-lessons',
    'student-finance',
    'student-notifications',
    'student-recording',
    'student-tasks',
    'student-actions',
), 'The profile must expose all eleven management sections.');
$contains($workspace, array('dzn-student-layout', 'dzn-student-rail', 'Back to Students', 'Profile sections'), 'The profile must use an in-page navigation rail and stay in the Students context.');

$contains($workspace, array(
    'student-send-password-reset',
    'student-issue-account-claim',
    'student-deactivate-account',
    'student-link-account',
    'student-supersede-account',
    'student-guardian-grant',
    'student-guardian-revoke',
    'student-guardian-supersede',
    'student-classify-capacity',
    'student-task-resolve',
    'student-reassign-teacher',
    'student-enrolment-transition',
    'student-term-transition',
    'student-lesson-reschedule',
    'student-attendance-review',
    'student-reschedule-decision',
    'lesson_page',
    'Older lessons',
), 'The profile must expose meaningful account, guardian, capacity, task, teacher, enrolment, term, lesson, attendance and booking workflows.');

foreach (array(
    'Create distinct student unresolved',
    'Record non-resolved review outcome',
    'Classify access',
    'Establish Student principal link',
    'Revoke Student principal link',
    'Atomically supersede Student principal link',
) as $technicalLabel) {
    $assert(!str_contains($workspace, $technicalLabel), 'Raw technical identity control leaked into Students: ' . $technicalLabel);
}

$assert(str_contains($workspaceRead, 'termReplacementAllocation') && str_contains($workspaceRead, 'LessonRescheduleRepository'), 'Replacement and make-up summaries must reuse the canonical Term allocation rule.');
$assert(str_contains($workspaceRead, 'NotificationDeliveryStatusReadService') && str_contains($workspaceRead, 'categoryFor'), 'Notification status must use the canonical delivery evidence read model.');
$assert(str_contains($workspaceRead, 'recording_decisions'), 'Consent history must read append-only recording decisions.');
$assert(!str_contains($workspaceRead, 'INSERT INTO') && !str_contains($workspaceRead, 'UPDATE '), 'The Students read model must not write canonical facts.');
$assert(str_contains($workspaceRead, "current_user_can('dzn_manage_students')") || str_contains($workspaceRead, "can(self::CAP)"), 'The read model must enforce Students authority.');
$assert(str_contains($workspaceRead, 'no_show') && str_contains($workspaceRead, 'student_no_show'), 'Absence counts must distinguish reported absence from recorded no-show.');
$assert(!str_contains($workspaceRead, "status='cancelled' AND COALESCE") && !str_contains($workspaceRead, "lifecycle_state='cancelled'"), 'Cancelled lessons must never be counted as completed.');

$service = $readFile('src/Core/Application/StudentAdministrationService.php');
$contains($service, array('retrieve_password(', 'ArchiveService', 'StudentAcceptanceAuthorityService', 'StudentAccountClaimInvitationService', 'ExceptionService', 'TeacherAssignmentService'), 'Student administration must compose canonical services.');
$assert(!str_contains($service, 'wp_set_password') && !str_contains($service, 'user_pass') && !str_contains($service, 'password_hash'), 'The platform must never set or hash a student password directly.');
$assert(!str_contains($service, 'DELETE FROM') && !str_contains($service, 'wp_delete_user'), 'Student lifecycle must not cascade-delete protected history or WordPress users.');
$assert(str_contains($service, 'student_identity_already_exists'), 'Add Student must detect an existing email or phone identity before creating a duplicate.');

$registry = $readFile('src/Admin/RecordAction/RecordActionRegistry.php');
foreach (array('student-profile','student-deactivate','student-reactivate','student-archive','student-restore','student-send-password-reset','student-issue-account-claim','student-link-account','student-supersede-account','student-deactivate-account','student-classify-capacity','student-guardian-grant','student-guardian-revoke','student-guardian-supersede','student-task-resolve','student-reassign-teacher','student-enrolment-transition','student-term-transition','student-lesson-reschedule','student-attendance-review','student-reschedule-decision','student-resolve-booking-identity','student-booking-identity-outcome') as $action) {
    $assert(str_contains($registry, "'" . $action . "'"), 'Missing student record action: ' . $action);
}

echo "Student admin UX contract passed\n";
