<?php
/** Current six-area administration navigation and protected legacy-route contract. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/src/Admin/Controller/' . $path);
$require = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$shell = $read('AdminShellController.php');
$menu = $read('Menu.php');
$general = $read('GeneralSettingsController.php');
$finance = $read('FinanceAdminController.php');

foreach (array('overview' => 'Overview', 'teachers' => 'Teachers', 'students' => 'Students', 'enrolment' => 'Enrolment', 'finance' => 'Finance', 'settings' => 'Settings') as $key => $label) {
    $require(str_contains($shell, "'{$key}' => ['{$label}'"), "Missing primary area {$label}");
}
$require(6 === preg_match_all("/\\n        '([a-z]+)' => \\['(?:Overview|Teachers|Students|Enrolment|Finance|Settings)'/", $shell), 'Exactly six primary areas are required');
foreach (array(
    "['Overview', 'dzn-academy-dashboard', 'dzn_view_booking_requests']",
    "['Teachers', 'dzn-teachers-menu', Menu::TEACHER_OPERATIONS_CAP]",
    "['Directory', 'dzn-student', 'dzn_manage_students']",
    "['Enrolment', 'dzn-enrolment-workspace', Menu::ENROLMENT_WORKSPACE_CAP]",
    "['Finance', 'dzn-finance', Menu::FINANCE_WORKSPACE_CAP, ['tab' => 'payments']]",
    "['Catalogue access', 'dzn-catalogue-workspace', Menu::CATALOGUE_WORKSPACE_CAP]",
    "'general' => ['General'", "'notifications' => ['Notifications'",
    "'integrations' => ['Integrations'", "'diagnostics' => ['Diagnostics'",
    "'dzn-catalogue-workspace' => Menu::CATALOGUE_WORKSPACE_CAP",
) as $needle) $require(str_contains($shell, $needle), 'Missing current destination: ' . $needle);
$require(1 === preg_match_all("/\\['Enrolment', 'dzn-enrolment-workspace'/", $shell), 'Enrolment must be one visible destination without sub-tabs');
$require(str_contains($general, 'admin.php?page=dzn-catalogue-workspace'), 'Course and instrument configuration must remain discoverable from General');
foreach (array('dzn-booking-requests','dzn-booking-request-coordination','dzn-enrolment','dzn-term','dzn-course','dzn-instrument','dzn-attendance-review','dzn-post-intro','dzn-recording-notices','dzn-core-dataset-readiness') as $slug) {
    $require(str_contains($menu . $shell . $read('RecordingNoticeAdminController.php'), $slug), 'Protected legacy route lost: ' . $slug);
}
foreach (array("self::TAB_PAYMENTS => 'Student payments'", "self::TAB_STATEMENTS => 'Teacher payments'", "self::TAB_LEDGER => 'Manual ledger'", "self::TAB_SETTINGS => 'Settings'") as $needle) {
    $require(str_contains($finance, $needle), 'Finance tab missing: ' . $needle);
}
$require(str_contains($shell, 'count($visible) <= 1') && str_contains($shell, 'renderSettingsNavigation'), 'Shared shell must suppress redundant subnavigation');
echo "Admin navigation target contract passed\n";
