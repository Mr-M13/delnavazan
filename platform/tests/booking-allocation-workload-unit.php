<?php
/**
 * Weighted cross-instrument workload regressions for the approved allocation policy.
 *
 * Exercises the real BookingTeacherWorkloadService and
 * BookingTeacherWorkloadRepository against a recording $wpdb, so the numerical
 * weights and the non-double-counting query shape are both asserted. Runs on a
 * plain PHP 8.3 host.
 *
 * php platform/tests/booking-allocation-workload-unit.php
 */
namespace {
    if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

    final class WorkloadRecordingWpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        /** @var list<array{sql:string,args:array}> */
        public array $queries = array();
        /** @var array<string,list<array<string,mixed>>> */
        public array $rowsByTable = array();
        public function prepare(string $sql, mixed ...$args): string {
            if (count($args) === 1 && is_array($args[0])) $args = $args[0];
            $index = 0;
            return (string) preg_replace_callback('/%[ds]/', static function (array $match) use (&$index, $args): string {
                $value = $args[$index++] ?? '';
                return $match[0] === '%d' ? (string) (int) $value : (string) $value;
            }, $sql);
        }
        public function get_results(string $sql, mixed $mode = null): array {
            $this->queries[] = array('sql' => $sql, 'args' => array());
            foreach ($this->rowsByTable as $needle => $rows) {
                if (str_contains($sql, ' ' . $needle . ' ') || str_contains($sql, $needle . "\n") || str_contains($sql, $needle . ' WHERE')) return $rows;
            }
            return array();
        }
        public function queriesMatching(string $needle): array {
            return array_values(array_filter($this->queries, static fn(array $q): bool => str_contains($q['sql'], $needle)));
        }
    }

    require_once dirname(__DIR__) . '/src/Core/Infrastructure/Repository/BookingTeacherWorkloadRepository.php';
    require_once dirname(__DIR__) . '/src/Core/Application/BookingTeacherWorkloadService.php';

    $failures = 0;
    $check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
        if ($ok) { echo "ok   $label\n"; return; }
        $failures++;
        echo "FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    };

    // --- Weights ------------------------------------------------------------
    $check('a pending offer weighs a quarter of an accepted booking',
        Delnavazan\Platform\Core\Application\BookingTeacherWorkloadService::PENDING_OFFER_WEIGHT === 0.25
        && Delnavazan\Platform\Core\Application\BookingTeacherWorkloadService::ACCEPTED_BOOKING_WEIGHT === 1.0);    $check('a pending offer weighs strictly less than any commitment',
        Delnavazan\Platform\Core\Application\BookingTeacherWorkloadService::PENDING_OFFER_WEIGHT
        < min(
            Delnavazan\Platform\Core\Application\BookingTeacherWorkloadService::ACCEPTED_BOOKING_WEIGHT,
            Delnavazan\Platform\Core\Application\BookingTeacherWorkloadService::CONFIRMED_LESSON_WEIGHT,
            Delnavazan\Platform\Core\Application\BookingTeacherWorkloadService::COMPLETED_LESSON_WEIGHT
        ));

    $total = Delnavazan\Platform\Core\Application\BookingTeacherWorkloadService::weightedTotal(
        array('pending' => 4, 'accepted' => 1, 'confirmed' => 2, 'completed' => 3)
    );
    $check('the weighted total is 4*0.25 + 1 + 2 + 3 = 7.0', $total === 7.0, (string) $total);

    $check('a Teacher holding four pending offers is still lighter than one confirmed booking plus one pending offer',
        Delnavazan\Platform\Core\Application\BookingTeacherWorkloadService::weightedTotal(array('pending' => 4)) === 1.0
        && Delnavazan\Platform\Core\Application\BookingTeacherWorkloadService::weightedTotal(array('pending' => 1, 'accepted' => 1)) === 1.25);

    $check('an empty component set weighs zero',
        Delnavazan\Platform\Core\Application\BookingTeacherWorkloadService::weightedTotal(array()) === 0.0);

    // --- Repository query shape --------------------------------------------
    $wpdb = new WorkloadRecordingWpdb();
    $GLOBALS['wpdb'] = $wpdb;
    $service = new Delnavazan\Platform\Core\Application\BookingTeacherWorkloadService();
    $wpdb->rowsByTable = array(
        'booking_allocation_offers' => array(array('teacher_id' => 7, 'n' => 2)),
        'booking_request_confirmations' => array(array('teacher_id' => 7, 'n' => 3), array('teacher_id' => 9, 'n' => 1)),
        'lessons' => array(array('teacher_id' => 7, 'confirmed' => 4, 'completed' => 5)),
    );

    $now = '2026-11-16 00:00:00';
    $scores = $service->scores(array(7, 9, 404), $now);

    $check('every requested Teacher gets a score, including one with no rows',
        array_keys($scores) === array(7, 9, 404), (string) json_encode(array_keys($scores)));
    $check('a Teacher with no rows scores zero',
        $scores[404]['weighted'] === 0.0 && $scores[404]['pending'] === 0 && $scores[404]['completed'] === 0);
    $check('the weighted score combines every lifecycle source',
        $scores[7]['weighted'] === 2 * 0.25 + 3 * 1.0 + 4 * 1.0 + 5 * 1.0, (string) $scores[7]['weighted']);
    $check('a Teacher with only an accepted booking keeps that component',
        $scores[9]['weighted'] === 1.0 && $scores[9]['accepted'] === 1, (string) $scores[9]['weighted']);

    $pendingQueries = $wpdb->queriesMatching('booking_allocation_offers');
    $check('the pending term reads live offers only',
        count($pendingQueries) === 1
        && str_contains($pendingQueries[0]['sql'], "state='pending'")
        && str_contains($pendingQueries[0]['sql'], 'active_slot=1'));
    $check('the pending term is grouped by Teacher, not one query per Teacher',
        count($pendingQueries) === 1 && str_contains($pendingQueries[0]['sql'], 'GROUP BY teacher_id'));

    $acceptedQueries = $wpdb->queriesMatching('booking_request_confirmations');
    $check('the accepted term excludes cancelled and failed bookings',
        count($acceptedQueries) === 1
        && str_contains($acceptedQueries[0]['sql'], "state NOT IN ('failed','cancelled')"));
    $check('the accepted term applies the rolling window to the start',
        count($acceptedQueries) === 1
        && str_contains($acceptedQueries[0]['sql'], 'starts_at_utc>=')
        && str_contains($acceptedQueries[0]['sql'], '2026-10-17 00:00:00'));

    $lessonQueries = $wpdb->queriesMatching('dzn_lessons');
    $check('the lesson term excludes archived rows',
        count($lessonQueries) === 1 && str_contains($lessonQueries[0]['sql'], 'archived_at IS NULL'));
    $check('the lesson term excludes introductory rows so a projected intro is not counted twice',
        count($lessonQueries) === 1 && str_contains($lessonQueries[0]['sql'], "lesson_type<>'introductory'"));
    $check('only completed lessons inside the window count as completed',
        count($lessonQueries) === 1
        && str_contains($lessonQueries[0]['sql'], "COALESCE(lifecycle_state,status)='completed'")
        && str_contains($lessonQueries[0]['sql'], 'completed_at>='));

    $check('the workload read never consults the delivery table, so a manual assignment is counted once via its confirmation',
        $wpdb->queriesMatching('booking_request_teacher_deliveries') === array());
    $check('the workload read takes three aggregate queries for a whole candidate set',
        count($wpdb->queries) === 3, (string) count($wpdb->queries));

    // A failing read must fail closed rather than silently ranking everyone at zero.
    $wpdb->last_error = 'Table is marked as crashed';
    $threw = false;
    try { $service->scores(array(7), $now); } catch (Throwable $error) { $threw = true; }
    $check('a failed workload read fails closed instead of ranking everyone as idle', $threw);

    unset($GLOBALS['wpdb']);

    if ($failures > 0) {
        fwrite(STDERR, "Booking allocation workload unit failed: $failures assertion(s)\n");
        exit(1);
    }
    echo "Booking allocation workload unit passed\n";
}
