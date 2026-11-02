<?php
$root = dirname(__DIR__);
$repo = file_get_contents($root . '/src/Core/Infrastructure/Repository/TeacherRepository.php');
$read = file_get_contents($root . '/src/Core/Application/CoreReadService.php');
$workspace = file_get_contents($root . '/src/Core/Application/TeacherWorkspaceReadService.php');
$view = file_get_contents($root . '/src/Admin/View/TeacherWorkspaceView.php');
$eligibility = file_get_contents($root . '/src/Admin/Controller/TeachingEligibilityController.php');
$screen = file_get_contents($root . '/src/Admin/Controller/ScreenController.php');
foreach (array('adminDirectory(string $search, string $status, int $page', '$wpdb->esc_like($search)', 'LIMIT %d OFFSET %d', 'teacher_course_eligibilities', 'teacher_availability_rules', 'teacher_assignments') as $needle) {
    if (strpos($repo, $needle) === false) throw new RuntimeException('Teacher repository read contract missing: ' . $needle);
}
if (strpos($read, "current_user_can('dzn_manage_teachers')") === false || strpos($read, 'adminDirectory($search, $status, $page, 20, $sort, $direction)') === false) throw new RuntimeException('Teacher directory read must remain capability-gated through CoreReadService');
foreach (array('Teacher directory', 'Contact', 'Instruments', 'Status', 'Availability', 'Golden hours', 'Students', 'Teaching', 'Introductions', 'Conversion', 'Rate', 'Payments', 'Actions', 'Open profile', 'Set unavailable', 'Archived records') as $needle) {
    if (strpos($view, $needle) === false) throw new RuntimeException('Teacher admin UX contract missing: ' . $needle);
}
foreach (array('canonical_lesson_delivery_outcomes', 'booking_request_intro_outcomes', 'teacher_availability_rules', 'finance_teacher_rates', 'finance_statements', 'payment_recorded', 'GoldenHourPolicyService') as $needle) {
    if (strpos($workspace, $needle) === false) throw new RuntimeException('Teacher directory statistics must use canonical records: ' . $needle);
}
foreach (array('dzn-searchable-select', 'courseOptionsForTeachingEligibility()', 'instrumentOptionsForTeachingEligibility()', 'teacherOptionsForTeachingEligibility()', 'TeachingEligibilityService') as $needle) {
    if (strpos($eligibility, $needle) === false) throw new RuntimeException('Searchable canonical selector contract missing: ' . $needle);
}
foreach (array('TeacherService', 'TeacherOnboardingService', 'teacherAdminDirectory') as $needle) if (strpos($read . $workspace . $screen, $needle) === false) throw new RuntimeException('Existing teacher read/write authority missing: ' . $needle);
if (strpos($repo, 'GROUP_CONCAT(DISTINCT') === false || strpos($repo, 'availability_summary') === false) throw new RuntimeException('Teacher directory must expose actual instruments and weekly windows, not only counts');
if (preg_match('/\$wpdb->(?:insert|update|delete)\([^\n]*(?:teachers|teacher_course_eligibilities|teacher_availability)/', $view . $workspace)) throw new RuntimeException('Admin UX bypasses canonical service writes');
if (strpos($view, 'reference_code') !== false) throw new RuntimeException('The directory must not expose internal Teacher references');
if (strpos($view, 'paginate_links') !== false) throw new RuntimeException('The directory must not paginate the expected small Teacher set');
echo "teacher-admin-ux-contract: pass\n";
