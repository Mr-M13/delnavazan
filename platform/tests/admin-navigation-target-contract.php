<?php
/** Current six-area administration navigation and protected legacy-route contract. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/src/Admin/Controller/' . $path);
$require = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$shell = $read('AdminShellController.php');
$menu = $read('Menu.php');
$general = $read('GeneralSettingsController.php');
$finance = $read('FinanceAdminController.php');

foreach (array('overview' => 'Overview', 'teachers' => 'Teachers', 'students' => 'Students', 'enrolment' => 'Enrolment', 'finance' => 'Finance', 'settings' => 'Settings', 'crm' => 'CRM') as $key => $label) {
    $require(str_contains($shell, "'{$key}' => ['{$label}'"), "Missing primary area {$label}");
}
$require(7 === preg_match_all("/\\n        '([a-z]+)' => \\['(?:Overview|Teachers|Students|Enrolment|Finance|Settings|CRM)'/", $shell), 'Exactly seven primary areas are required');
$require(strpos($shell, "'settings' => ['Settings'") < strpos($shell, "'crm' => ['CRM'") && strpos($shell, "'crm' => ['CRM'") < strpos($shell, "'godmode' => ['God Mode'"), 'CRM must sit between Settings and God Mode');
foreach (array(
    "['Overview', 'dzn-academy-dashboard', 'dzn_view_booking_requests']",
    "['Teachers', 'dzn-teachers-menu', Menu::TEACHER_OPERATIONS_CAP]",
    "['Directory', 'dzn-student', 'dzn_manage_students']",
    "['CRM', 'dzn-people', Menu::PEOPLE_DIRECTORY_CAP]",
    "['Enrolment', 'dzn-enrolment-workspace', Menu::ENROLMENT_WORKSPACE_CAP]",
    "['Finance', 'dzn-finance', Menu::FINANCE_WORKSPACE_CAP, ['tab' => 'payments']]",
    "'general' => ['General'", "'access' => ['Roles'",
    "'policy' => ['Policy'", "'notifications' => ['Notifications'",
    "'integrations' => ['Integrations'", "'diagnostics' => ['Diagnostics'",
    "'dzn-catalogue-workspace' => 'enrolment'",
) as $needle) $require(str_contains($shell, $needle), 'Missing current destination: ' . $needle);
$require(str_contains($general, 'Backup and rollback'), 'General must own the read-only backup and rollback status card');
$require(str_contains($general, 'Site information') && !str_contains($general, 'dzn-catalogue-workspace'), 'General must be simplified and must not own catalogue management');
foreach (array('dzn-booking-requests','dzn-booking-request-coordination','dzn-enrolment','dzn-term','dzn-course','dzn-instrument','dzn-attendance-review','dzn-post-intro','dzn-recording-notices','dzn-core-dataset-readiness') as $slug) {
    $require(str_contains($menu . $shell . $read('RecordingNoticeAdminController.php'), $slug), 'Protected legacy route lost: ' . $slug);
}
foreach (array("self::TAB_PAYMENTS => 'Student payments'", "self::TAB_STATEMENTS => 'Teacher payments'", "self::TAB_LEDGER => 'Manual ledger'", "self::TAB_SETTINGS => 'Settings'") as $needle) {
    $require(str_contains($finance, $needle), 'Finance tab missing: ' . $needle);
}
$require(str_contains($shell, 'count($visible) <= 1') && str_contains($shell, 'renderSettingsNavigation'), 'Shared shell must suppress redundant subnavigation');

echo "Admin navigation target contract passed\n";
