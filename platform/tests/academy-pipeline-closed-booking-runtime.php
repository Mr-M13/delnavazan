<?php
/**
 * Database-backed proof that a closed/teacher_confirmed booking is eligible for
 * the Academy pipeline and its stage queue, while other closed and privacy-erased
 * bookings stay excluded.
 *
 * Run in the disposable WordPress/MariaDB acceptance environment:
 *   wp eval-file platform/tests/academy-pipeline-closed-booking-runtime.php --user=1
 */
if (!defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Academy pipeline runtime refused outside a disposable local environment.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\AcademyJourneyReadService;
use Delnavazan\Platform\Core\Support\Identifier;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$now = gmdate('Y-m-d H:i:s');
$suffix = strtolower(substr(hash('sha256', (string) microtime(true) . wp_generate_uuid4()), 0, 10));
$created = array('coordination' => array(), 'bookings' => array(), 'instruments' => array(), 'students' => array());

function dzn_pipeline_runtime_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

function dzn_pipeline_runtime_insert(string $table, array $data): int {
    global $wpdb;
    $result = $wpdb->insert($wpdb->prefix . 'dzn_' . $table, $data);
    if ($result === false) throw new RuntimeException('Fixture insert failed for ' . $table . ': ' . $wpdb->last_error);
    return (int) $wpdb->insert_id;
}

try {
    wp_set_current_user(1);

    $studentId = dzn_pipeline_runtime_insert('students', array(
        'uid' => Identifier::uid(),
        'reference_code' => 'DZN-STU-PIPE-' . $suffix,
        'status' => 'active',
        'display_name' => 'Pipeline Closed Student ' . $suffix,
        'email' => 'pipeline-closed-' . $suffix . '@example.invalid',
        'country_code' => 'AU',
        'timezone' => 'Australia/Sydney',
        'locale' => 'en_AU',
        'calendar_preference' => 'auto',
        'created_at' => $now,
        'updated_at' => $now,
    ));
    $created['students'][] = $studentId;

    $instrumentId = dzn_pipeline_runtime_insert('instruments', array(
        'uid' => Identifier::uid(),
        'slug' => 'pipeline-runtime-' . $suffix,
        'name_en' => 'Pipeline Runtime Instrument ' . $suffix,
        'name_fa' => 'Pipeline Runtime Instrument ' . $suffix,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ));
    $created['instruments'][] = $instrumentId;

    $booking = static function (string $lifecycle, string $resolution, ?string $erasedAt = null) use ($studentId, $instrumentId, $now, $suffix): int {
        return dzn_pipeline_runtime_insert('booking_requests', array(
            'uid' => Identifier::uid(),
            'reference_code' => 'DZN-BRK-PIPE-' . strtoupper(substr(hash('sha256', $lifecycle . $resolution . $suffix . microtime(true)), 0, 8)),
            'student_id' => $studentId,
            'requested_instrument_id' => $instrumentId,
            'selected_intro_course_id' => null,
            'lifecycle_status' => $lifecycle,
            'resolution_state' => $resolution,
            'retention_due_at' => gmdate('Y-m-d H:i:s', time() + 86400),
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'privacy_erased_at' => $erasedAt,
        ));
    };

    // Eligible: closed/teacher_confirmed with coordination and identity, still awaiting final acceptance.
    $closedBookingId = $booking('closed', 'teacher_confirmed');
    $created['bookings'][] = $closedBookingId;
    $created['coordination'][] = dzn_pipeline_runtime_insert('coordination_cases', array(
        'uid' => Identifier::uid(),
        'booking_request_id' => $closedBookingId,
        'state' => 'active',
        'version' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ));

    // Ineligible closed booking: same shape, but a non-authoritative resolution.
    $cancelledBookingId = $booking('closed', 'cancelled');
    $created['bookings'][] = $cancelledBookingId;
    $created['coordination'][] = dzn_pipeline_runtime_insert('coordination_cases', array(
        'uid' => Identifier::uid(),
        'booking_request_id' => $cancelledBookingId,
        'state' => 'active',
        'version' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ));

    // Ineligible privacy-erased booking, otherwise submitted/unresolved.
    $erasedBookingId = $booking('submitted', 'unresolved', $now);
    $created['bookings'][] = $erasedBookingId;

    $service = new AcademyJourneyReadService();
    $counts = $service->stageCounts();

    $stages = array('needs_coordination', 'needs_identity', 'needs_acceptance', 'needs_enrolment', 'needs_assignment', 'complete');
    $queues = array();
    $stagesForClosed = array();
    foreach ($stages as $stage) {
        $queues[$stage] = $service->search('', $stage, 1);
        foreach ($queues[$stage]['rows'] as $row) {
            if ((int) $row->request_id !== $closedBookingId) continue;
            $stagesForClosed[] = $stage;
        }
        foreach ($queues[$stage]['rows'] as $row) {
            dzn_pipeline_runtime_assert((int) $row->request_id !== $cancelledBookingId, 'Ineligible closed booking appeared in queue stage ' . $stage);
            dzn_pipeline_runtime_assert((int) $row->request_id !== $erasedBookingId, 'Privacy-erased booking appeared in queue stage ' . $stage);
        }
        dzn_pipeline_runtime_assert(
            (int) $counts[$stage] === (int) $queues[$stage]['total'],
            'Pipeline count does not agree with the queue total for ' . $stage
        );
    }

    dzn_pipeline_runtime_assert($stagesForClosed === array('needs_acceptance'), 'Closed/teacher_confirmed booking must appear in exactly the needs_acceptance stage; got ' . implode(',', $stagesForClosed));
    dzn_pipeline_runtime_assert((int) $queues['needs_acceptance']['total'] >= 1, 'Matching needs_acceptance queue did not return the closed booking');
    dzn_pipeline_runtime_assert((int) $counts['needs_acceptance'] === (int) $queues['needs_acceptance']['total'], 'Displayed count must equal the matching queue total');

    echo 'Academy pipeline closed booking runtime passed: booking=' . $closedBookingId . ' stage=needs_acceptance count=' . (int) $counts['needs_acceptance'] . "\n";
} finally {
    foreach (array_reverse($created['coordination']) as $id) $wpdb->delete($p . 'coordination_cases', array('id' => (int) $id));
    foreach (array_reverse($created['bookings']) as $id) $wpdb->delete($p . 'booking_requests', array('id' => (int) $id));
    foreach (array_reverse($created['instruments']) as $id) $wpdb->delete($p . 'instruments', array('id' => (int) $id));
    foreach (array_reverse($created['students']) as $id) $wpdb->delete($p . 'students', array('id' => (int) $id));
}
