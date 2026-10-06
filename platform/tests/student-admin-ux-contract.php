<?php
/** Students admin UX contract (source-level, plain PHP CLI). */
$root = dirname(__DIR__);
$repo = file_get_contents($root . '/src/Core/Infrastructure/Repository/StudentRepository.php');
$read = file_get_contents($root . '/src/Core/Application/CoreReadService.php');
$screen = file_get_contents($root . '/src/Admin/Controller/ScreenController.php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert(str_contains($repo, 'function adminRows(') && str_contains($repo, 'LIMIT %d OFFSET %d'), 'Student listing must be bounded and paginated');
$assert(str_contains($repo, 'esc_like($query)') && str_contains($repo, 'reference_code LIKE %s'), 'Search must safely match human identifiers');
$assert(str_contains($read, "current_user_can('dzn_manage_students')") && str_contains($read, 'studentAdminRows('), 'Student listing must use an authorised read service');
$assert(str_contains($screen, "sanitize_key(wp_unslash(\$_GET['student_status'] ?? 'active'))"), 'Active Students must be the operational default');
$assert(str_contains($screen, 'Previous') && str_contains($screen, 'Next') && str_contains($screen, 'Search students'), 'The list must support search and paging');
$assert(str_contains($screen, 'Overview') && str_contains($screen, 'Account &amp; Communication') && str_contains($screen, 'Administration'), 'Student detail must expose contextual sections');
$assert(str_contains($screen, 'dzn-student-identity-authority') && str_contains($screen, 'Communication history is not available'), 'Account review must delegate to its authority and state the communication limit');
echo "Student admin UX contract passed\n";
