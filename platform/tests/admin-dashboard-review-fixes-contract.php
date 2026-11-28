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
$postIntroRepository = $read('src/Core/Infrastructure/Repository/PostIntroRepository.php');
$postIntroAttention = $read('src/Core/Application/PostIntroAttentionReadService.php');
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

// A resolved item must leave the Overview. The assignment stage may only count an Enrolment that is
// still open, and the post-intro card may only count provisioning that has not completed — a later
// classroom projection failure belongs to the class/classroom surface, and an older failed attempt
// is history once a newer run has been recorded. The Overview now reads the same actionable list the
// protected diagnostics page renders, so a card can never outlive its resolving action.
$expect(str_contains($queueRead, "e.lifecycle_state IN ('authorised','current')"), 'The assignment stage must only count an active Enrolment.');
$expect(str_contains($postIntroRepository, "p.state NOT IN ('activated','expired')"), 'Post-intro attention must require incomplete academic provisioning.');
$expect(str_contains($postIntroRepository, 'latest.id') && !str_contains($postIntroRepository, "WHERE p.state='exception' OR j.state='failed'"), 'Post-intro attention must read the latest activation attempt, not any historical failed job.');
$contains($postIntroRepository, array('provisioningCompleteSql', 'pc_term.applicable_slot=1', 'pc_assignment.applicable_slot=1', 'terminalOutcomeSql', "terminal_outcome.outcome<>'delivered'"), 'A completed Term/Assignment and a non-delivered intro are closed history, not Overview work.');
$contains($postIntroAttention, array('class PostIntroAttentionReadService', 'exceptionPreparations', 'failedJobs', 'paidPreparationsWithoutActivationJob', "'retry_preparation'", "'retry_activation'", "'queue_activation'"), 'Every counted post-intro preparation must carry a resolvable diagnostics action.');
$contains($postIntroRepository, array(
    'pendingOutcomeConfirmationCount',
    'c.id=(SELECT MAX(cf.id)',
    'NOT EXISTS (SELECT 1 FROM {$this->p}booking_request_intro_outcomes o WHERE o.booking_request_id=r.id)',
), 'An outcome card must follow the current confirmation and close the whole request once an outcome exists.');
$contains($dashboard, array('PostIntroAttentionReadService', 'pendingOutcomeConfirmationCount', "'request_id' => (int) (\$outcomeRows[0]->booking_request_id"), 'The Overview must count and link the exact outcome records it names.');
$expect(!str_contains($dashboard, "LEFT JOIN {\$p}booking_request_intro_outcomes o ON o.confirmation_id=c.id"), 'The outcome queue must close the whole request, not only one confirmation.');

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
