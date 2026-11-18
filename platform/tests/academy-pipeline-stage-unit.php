<?php
/**
 * The pipeline chart and the stage queue must share one mutually exclusive rule
 * set, and both must count distinct bookings rather than joined rows.
 */
if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool { return $capability === 'dzn_view_booking_requests'; }
}
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . $path; }
}

final class DznStageFakeWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var list<string> */
    public array $queries = array();
    /** @var list<object> */
    public array $rows = array();
    public object $stageRow;

    public function __construct() {
        $this->stageRow = (object) array(
            'needs_coordination' => 4,
            'needs_identity' => 3,
            'needs_acceptance' => 2,
            'needs_enrolment' => 1,
            'needs_assignment' => 5,
            'complete' => 7,
        );
    }

    public function get_row(string $sql) {
        $this->queries[] = $sql;
        return $this->stageRow;
    }

    public function get_var(string $sql) {
        $this->queries[] = $sql;
        return 0;
    }

    public function get_results(string $sql): array {
        $this->queries[] = $sql;
        return $this->rows;
    }

    public function prepare(string $sql, ...$args): string {
        $this->queries[] = $sql;
        return $sql;
    }

    public function esc_like(string $term): string { return $term; }
}

$GLOBALS['wpdb'] = new DznStageFakeWpdb();

require_once dirname(__DIR__) . '/src/Core/Application/AcademyJourneyReadService.php';

$serviceClass = 'Delnavazan\\Platform\\Core\\Application\\AcademyJourneyReadService';
$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

foreach (array('needs_coordination', 'needs_identity', 'needs_acceptance', 'needs_enrolment', 'needs_assignment', 'complete') as $stage) {
    $expect($serviceClass::stageSql($stage) !== '', 'Missing shared pipeline stage SQL: ' . $stage);
}
$expect($serviceClass::stageSql('needs_action') === 'ta.id IS NULL', 'Action queue must be the complement of complete');
$expect($serviceClass::stageSql('unknown') === '', 'Unknown stages must not produce SQL');
$expect(str_contains($serviceClass::stageSql('needs_identity'), 'cc.id IS NOT NULL'), 'Identity stage must not overlap coordination');
$expect(str_contains($serviceClass::stageSql('needs_identity'), 'br.student_id IS NULL'), 'Identity stage must name the unresolved student');
$expect(str_contains($serviceClass::stageSql('needs_enrolment'), 'asa.id IS NOT NULL') && str_contains($serviceClass::stageSql('needs_enrolment'), 'e.id IS NULL'), 'Enrolment stage must require the accepted arrangement and missing enrolment');
$expect(str_contains($serviceClass::stageSql('needs_assignment'), 'e.id IS NOT NULL') && str_contains($serviceClass::stageSql('needs_assignment'), 'ta.id IS NULL'), 'Assignment stage must require the enrolment and missing assignment');
foreach (array('needs_coordination', 'needs_identity', 'needs_acceptance', 'needs_enrolment', 'needs_assignment') as $stage) {
    $expect(str_contains($serviceClass::stageSql($stage), 'ta.id IS NULL'), 'Incomplete stages must not overlap the complete stage: ' . $stage);
}

$service = new $serviceClass();
$counts = $service->stageCounts();
$expect($counts === array('needs_coordination' => 4, 'needs_identity' => 3, 'needs_acceptance' => 2, 'needs_enrolment' => 1, 'needs_assignment' => 5, 'complete' => 7), 'stageCounts must return every pipeline stage');
$pipelineSql = implode("\n", $GLOBALS['wpdb']->queries);
$expect(substr_count($pipelineSql, 'COUNT(DISTINCT CASE WHEN') === 6, 'Pipeline must count distinct bookings for every stage');
$expect(!str_contains($pipelineSql, 'SUM('), 'Pipeline must not use row-summing aggregates across joins');
$expect(str_contains($pipelineSql, 'COUNT(DISTINCT CASE WHEN ta.id IS NULL AND cc.id IS NOT NULL AND br.student_id IS NULL THEN br.id END)'), 'Pipeline must use the shared identity predicate');
$expect(str_contains($pipelineSql, "br.lifecycle_status='submitted' AND br.resolution_state='unresolved'"), 'Pipeline must retain the original submitted intake');
$expect(str_contains($pipelineSql, "br.lifecycle_status='closed' AND br.resolution_state='teacher_confirmed'"), 'Pipeline must include the authorised closed/teacher_confirmed continuation');
$expect(str_contains($pipelineSql, 'br.privacy_erased_at IS NULL'), 'Pipeline must exclude privacy-erased bookings');

$GLOBALS['wpdb']->queries = array();
$service->search('', 'needs_acceptance', 1);
$queueSql = implode("\n", $GLOBALS['wpdb']->queries);
$expect(str_contains($queueSql, 'COUNT(DISTINCT br.id)'), 'Queue must count distinct bookings');
$expect(str_contains($queueSql, "br.lifecycle_status='submitted' AND br.resolution_state='unresolved'"), 'Stage queues must retain the original submitted intake');
$expect(str_contains($queueSql, "br.lifecycle_status='closed' AND br.resolution_state='teacher_confirmed'"), 'Stage queues must include the authorised closed/teacher_confirmed continuation');
$expect(str_contains($queueSql, 'br.privacy_erased_at IS NULL'), 'Stage queues must exclude privacy-erased bookings');
$expect(str_contains($queueSql, 'cc.id IS NOT NULL AND br.student_id IS NOT NULL AND asa.id IS NULL'), 'Acceptance queue must use the shared acceptance predicate');
$expect(!str_contains($queueSql, 'br.student_id IS NULL AND asa.id IS NULL'), 'Queue must not retain the old overlapping predicates');

$GLOBALS['wpdb']->queries = array();
$service->stageQueue('needs_acceptance', 5);
$detailSql = implode("\n", $GLOBALS['wpdb']->queries);
$expect(str_contains($detailSql, 'cc.id IS NOT NULL AND br.student_id IS NOT NULL AND asa.id IS NULL'), 'Stage details must use the shared acceptance predicate');
$expect(str_contains($detailSql, 'COUNT(*) FROM wp_dzn_coordination_case_candidates'), 'Stage details must explain the acceptance prerequisite from the coordination case');
$expect(str_contains($detailSql, 'LIMIT %d'), 'Stage details must stay bounded');
$rejected = false;
try { $service->stageQueue('complete'); } catch (\InvalidArgumentException) { $rejected = true; }
$expect($rejected, 'Stage details must refuse the complete and action-queue buckets');

// The card must name the exact missing acceptance prerequisite, not imply that every
// coordination case is ready for a final arrangement.
$base = array(
    'request_id' => 77, 'case_id' => 9, 'case_reference' => 'CC-9', 'case_state' => 'open',
    'reference_code' => 'BR-77', 'learner_name' => 'Synthetic Learner', 'instrument_name' => 'Piano',
    'course_name' => 'Introductory Piano', 'created_at' => gmdate('Y-m-d H:i:s'),
);
foreach (array(
    array(0, 0, 0, 0, 'No Teacher candidate is recorded', 'teacher-matching'),
    array(1, 0, 0, 0, 'Teacher agreement is not recorded', 'agreements'),
    array(1, 1, 0, 0, 'No current Proposal is issued', 'proposals'),
    array(1, 1, 1, 0, 'The learner response is not recorded', 'provisional'),
    array(1, 1, 1, 1, 'Ready for final acceptance', 'acceptance'),
) as [$candidates, $assents, $proposals, $responses, $expected, $anchor]) {
    $GLOBALS['wpdb']->rows = array((object) array_merge($base, array(
        'active_candidates' => $candidates,
        'current_assents' => $assents,
        'current_proposals' => $proposals,
        'acceptance_events' => $responses,
    )));
    $details = $service->stageQueue('needs_acceptance', 1);
    $expect(($details[0]['readiness'] ?? '') === $expected, 'Acceptance readiness must report: ' . $expected);
    $expect(($details[0]['anchor'] ?? '') === $anchor, 'Acceptance detail must link at the missing section: ' . $anchor);
}
$expect(str_contains((string) ($details[0]['url'] ?? ''), 'page=dzn-enrolment-workspace&request_id=77'), 'An operator without coordination authority must land on the read-only request');
$GLOBALS['wpdb']->rows = array();

echo "Academy pipeline stage unit passed\n";
