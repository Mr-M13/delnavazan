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
$teacher = (string) file_get_contents($root . '/src/Admin/View/TeacherWorkspaceView.php');
$need($shell, array(
    "'overview' => ['Overview'", "'teachers' => ['Teachers'", "'students' => ['Students'",
    "'enrolment' => ['Enrolment'", "'finance' => ['Finance'", "'settings' => ['Settings'",
    "['Teachers', 'dzn-teachers-menu', Menu::TEACHER_OPERATIONS_CAP]",
    "['Intro', 'dzn-enrolment-workspace'", "['Review', 'dzn-enrolment-workspace'", "['Enrolled classes', 'dzn-enrolment-workspace'",
    "'dzn-recording-notices' => 'settings'", "'dzn-catalogue-workspace' => Menu::CATALOGUE_WORKSPACE_CAP",
), 'Navigation must retain six work areas and protected routes');
$need($menu, array('mappedCoreWorkflowCaps','TEACHER_OPERATIONS_CAP','ENROLMENT_TRIALS_CAP','ENROLLED_CLASSES_CAP','CATALOGUE_WORKSPACE_CAP','FINANCE_WORKSPACE_CAP'), 'Composite navigation must be capability-scoped');
$need($general, array('Site information','Contact and public presence','Legal and policy pages','Introductory booking horizon','StaffAccessController::renderPanel'), 'General must own site, policy and access work');
$need($finance, array('Student payments','Teacher payments','Manual ledger','Regional pricing','Teacher compensation'), 'Finance must expose the redesigned payments and settings');
$need($teacher, array('Rate timeline','Statements','Lesson payability'), 'Teacher profile must retain Teacher-scoped finance evidence');
foreach (array('FinanceRateController.php','FinanceStatementController.php','FinancePayabilityController.php') as $file) {
    $source = $read($file);
    $need($source, array('FinanceTeacherOptionsReadService','teacher_id','dzn_view_finance_authority'), 'Protected Teacher finance view must remain scoped');
}
echo "Consolidated admin navigation and Teacher finance contract passed\n";
