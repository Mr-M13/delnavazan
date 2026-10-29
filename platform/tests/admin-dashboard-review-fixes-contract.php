<?php
/** Source contract for the four review findings on the Academy dashboard PR. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($expect): void {
    foreach ($needles as $needle) $expect(str_contains($source, $needle), $message . ': ' . $needle);
};

$dashboard = $read('src/Admin/Controller/AcademyOperationsController.php');
$dashboardRead = $read('src/Core/Application/AcademyDashboardReadService.php');
$queueRead = $read('src/Core/Application/AcademyJourneyReadService.php');
$rateRead = $read('src/Core/Application/Finance/Read/TeacherRateReadService.php');
$rateAdmin = $read('src/Admin/Controller/FinanceRateController.php');
$statementRead = $read('src/Core/Application/Finance/Read/TeacherStatementReadService.php');
$statementAdmin = $read('src/Admin/Controller/FinanceStatementController.php');

// 1. One shared, mutually exclusive pipeline/queue rule set with DISTINCT counts.
$contains($queueRead, array(
    'public const PIPELINE_STAGES',
    'public const QUEUE_STAGES',
    'private const STAGE_SQL',
    'public static function stageSql',
    'public function stageCounts',
    'COUNT(DISTINCT CASE WHEN',
    "self::stageSql(\$stage)",
    'ELIGIBLE_SQL',
    "br.lifecycle_status='closed' AND br.resolution_state='teacher_confirmed'",
    'br.privacy_erased_at IS NULL',
), 'Pipeline and queue must share one eligible stage rule source.');
$expect(!str_contains($dashboardRead, 'SUM(cc.id IS NULL)'), 'Dashboard pipeline must not sum joined rows.');
$contains($dashboardRead, array('new AcademyJourneyReadService())->stageCounts()'), 'Dashboard must use the shared queue stage counts.');
$contains($dashboard, array('AcademyJourneyReadService::PIPELINE_STAGES'), 'Dashboard labels must come from the shared stage map.');

// 2. Course-specific rate rows show the course name.
$contains($rateRead, array(
    'public function timeline',
    "'course_name'",
    'private function courseNames',
    'scope_kind',
), 'Rate timeline read seam must resolve a course label.');
$contains($rateAdmin, array('course_name', 'Scope / course'), 'Rate timeline must display the course name.');

// 3. Reconciliation differences use a class label/link and formatted currency.
$contains($statementRead, array(
    'lessonLabels',
    "'lesson_label'",
    "'currency'=>(string)\$statement->currency",
), 'Reconciliation must expose a class label and currency.');
$contains($statementAdmin, array(
    "lesson_label",
    "PricingMoney::format",
    "current_user_can('dzn_manage_lessons')",
    '>Class<',
), 'Reconciliation differences must use a class label/link and currency formatting.');
$expect(!str_contains($statementAdmin, "(string)\$difference['lesson_id']"), 'Reconciliation must not print the raw lesson ID.');

// 4. KPI cards without data access are non-links.
$contains($dashboard, array(
    '$isLink = $value !== null',
    'dzn-kpi--restricted',
), 'Restricted KPI cards must render as non-links.');
$expect(!str_contains($dashboard, '$isLink = str_starts_with($target'), 'KPI link state must depend on data access, not only the target URL.');

echo "Admin dashboard review fixes contract passed\n";
