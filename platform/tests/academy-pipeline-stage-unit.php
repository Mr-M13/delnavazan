<?php
/**
 * The pipeline chart and the stage queue must share one mutually exclusive rule
 * set, and both must count distinct bookings rather than joined rows.
 */
if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool { return $capability === 'dzn_view_booking_requests'; }
}

final class DznStageFakeWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var list<string> */
    public array $queries = array();
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
        return array();
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

$GLOBALS['wpdb']->queries = array();
$service->search('', 'needs_identity', 1);
$queueSql = implode("\n", $GLOBALS['wpdb']->queries);
$expect(str_contains($queueSql, 'COUNT(DISTINCT br.id)'), 'Queue must count distinct bookings');
$expect(str_contains($queueSql, "br.lifecycle_status='submitted'"), 'Stage queues must share the pipeline submitted-booking scope');
$expect(str_contains($queueSql, 'cc.id IS NOT NULL AND br.student_id IS NULL'), 'Queue must use the same identity predicate as the pipeline');
$expect(!str_contains($queueSql, 'br.student_id IS NULL AND asa.id IS NULL'), 'Queue must not retain the old overlapping predicates');

echo "Academy pipeline stage unit passed\n";
