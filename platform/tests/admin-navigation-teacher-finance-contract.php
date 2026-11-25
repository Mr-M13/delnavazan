<?php
/** Current navigation with Teacher finance and protected route boundaries. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/src/Admin/Controller/' . $path);
$need = static function (string $source, array $fragments, string $message): void {
    foreach ($fragments as $fragment) if (!str_contains($source, $fragment)) throw new RuntimeException($message . ': ' . $fragment);
};
$shell = $read('AdminShellController.php');
$menu = $read('Menu.php');
$finance = $read('FinanceAdminController.php');
$general = $read('GeneralSettingsController.php');
$policies = $read('PoliciesController.php');
$teacher = (string) file_get_contents($root . '/src/Admin/View/TeacherWorkspaceView.php');
$need($shell, array(
    "'overview' => ['Overview'", "'teachers' => ['Teachers'", "'students' => ['Students'",
    "'enrolment' => ['Enrolment'", "'finance' => ['Finance'", "'settings' => ['Settings'",
    "['Teachers', 'dzn-teachers-menu', Menu::TEACHER_OPERATIONS_CAP]",
    "['Enrolment', 'dzn-enrolment-workspace'",
    "'dzn-recording-notices' => 'settings'", "'dzn-catalogue-workspace' => 'enrolment'",
    "'access' => ['Roles'", "'policy' => ['Policy'",
), 'Navigation must retain six work areas and protected routes');
$need($menu, array('mappedCoreWorkflowCaps','TEACHER_OPERATIONS_CAP','ENROLMENT_TRIALS_CAP','ENROLLED_CLASSES_CAP','CATALOGUE_WORKSPACE_CAP','FINANCE_WORKSPACE_CAP'), 'Composite navigation must be capability-scoped');
$need($general, array('Site information','Contact and public presence'), 'General must own site identity and frontline contact only');
$need($policies, array('Introductory booking horizon','INTRO_BOOKING_HORIZON','LegalPageProvisioner','Consent Management'), 'Policy must own the booking horizon, legal pages and consent');
$need($finance, array('Student payments','Teacher payments','Manual ledger','Regional pricing','Teacher compensation'), 'Finance must expose the redesigned payments and settings');
$need($teacher, array('Rate timeline','Statements','Lesson payability'), 'Teacher profile must retain Teacher-scoped finance evidence');
foreach (array('FinanceRateController.php','FinanceStatementController.php','FinancePayabilityController.php') as $file) {
    $source = $read($file);
    $need($source, array('FinanceTeacherOptionsReadService','teacher_id','dzn_view_finance_authority'), 'Protected Teacher finance view must remain scoped');
}
// A bundle must not open a page whose callback refuses it (OPS-28), and a denial must emit a
// response instead of a blank admin frame (OPS-31).
$need($read('CoordinationCaseController.php'), array("if (!current_user_can(self::CAPABILITY))"), 'Class admin must accept the route capability it is reached by');
$need($read('NotificationManagementController.php'), array("dzn_operate_notification_dispatch", "dzn_manage_notification_workflows"), 'Notifications must accept the capabilities its route maps to');
$need($read('IntegrationControlPlaneController.php'), array("dzn_manage_payment_providers"), 'Integration settings must accept the capability its route maps to');
foreach (array('FinancePolicyController.php','FinanceRateController.php','FinanceStatementController.php','FinancePayabilityController.php','PaymentExecutionController.php','PortalCapabilityController.php','AcademyCoverageController.php','FinanceReconciliationController.php') as $denied) {
    $source = $read($denied);
    if (str_contains($source, 'current_user_can(self::CAPABILITY))return;')) { throw new RuntimeException('A denial must emit a response, not a blank page: ' . $denied); }
    if (!str_contains($source, "wp_die('Forbidden'")) throw new RuntimeException('A denied screen must say Forbidden: ' . $denied);
}
// A grantable capability must open a screen (OPS-30).
foreach (array('dzn_manage_finance_policies','dzn_manage_finance_statements','dzn_manage_teacher_rates','dzn_manage_lesson_payability','dzn_manage_cash_ledger') as $financeCap) {
    if (!str_contains($menu, $financeCap)) { throw new RuntimeException('A grantable Finance capability must open the Finance route: ' . $financeCap); }
}
$need($menu, array("PORTAL_CAPABILITIES_CAP", "dzn_manage_portal_capabilities"), 'Portal capabilities must be reachable by its manage capability');

echo "Consolidated admin navigation and Teacher finance contract passed\n";
