<?php
$root = dirname(__DIR__);
$repo = file_get_contents($root . '/src/Core/Infrastructure/Repository/TeacherRepository.php');
$read = file_get_contents($root . '/src/Core/Application/CoreReadService.php');
$screen = file_get_contents($root . '/src/Admin/Controller/ScreenController.php');
$eligibility = file_get_contents($root . '/src/Admin/Controller/TeachingEligibilityController.php');
foreach (array('adminDirectory(string $search, string $status, int $page', '$wpdb->esc_like($search)', 'LIMIT %d OFFSET %d', 'teacher_course_eligibilities', 'teacher_availability_rules', 'teacher_assignments') as $needle) {
    if (strpos($repo, $needle) === false) throw new RuntimeException('Teacher directory read contract missing: ' . $needle);
}
if (strpos($read, "current_user_can('dzn_manage_teachers')") === false || strpos($read, 'adminDirectory($search, $status, $page, 20, $sort, $direction)') === false) throw new RuntimeException('Teacher directory read must remain capability-gated through CoreReadService');
foreach (array('teacher_search', 'teacher_status', 'teacher_page', 'teacher_sort', 'teacher_order', 'aria-sort', 'Name, email or reference', 'Accepting students', 'instrument_names', 'Weekly availability', 'time windows', 'Current classes', 'Profile details', 'Account and onboarding', 'Profile details and lifecycle actions') as $needle) {
    if (strpos($screen, $needle) === false) throw new RuntimeException('Teacher admin UX contract missing: ' . $needle);
}
foreach (array('dzn-searchable-select', 'courseOptionsForTeachingEligibility()', 'instrumentOptionsForTeachingEligibility()', 'teacherOptionsForTeachingEligibility()', 'TeachingEligibilityService') as $needle) {
    if (strpos($eligibility, $needle) === false) throw new RuntimeException('Searchable canonical selector contract missing: ' . $needle);
}
foreach (array('TeacherService', 'TeacherOnboardingService', 'teacherAdminDirectory') as $needle) if (strpos($screen . $read, $needle) === false) throw new RuntimeException('Existing teacher read/write authority missing: ' . $needle);
if (strpos($repo, 'GROUP_CONCAT(DISTINCT') === false || strpos($repo, 'availability_summary') === false) throw new RuntimeException('Teacher directory must expose actual instruments and weekly windows, not only counts');
if (preg_match('/\$wpdb->(?:insert|update|delete)\([^\n]*(?:teachers|teacher_course_eligibilities|teacher_availability)/', $screen . $eligibility)) throw new RuntimeException('Admin UX bypasses canonical service writes');
echo "teacher-admin-ux-contract: pass\n";
