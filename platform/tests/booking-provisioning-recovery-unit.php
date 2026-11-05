<?php
/**
 * Teacher acceptance survives provider failure and remains visible while provisioning is pending.
 */
namespace Delnavazan\Platform\Portals {
    final class PortalPrincipalResolver {
        public function resolve(string $kind): array { return array('id' => 10, 'kind' => $kind); }
    }
}
namespace Delnavazan\Platform\Core\Infrastructure\Repository {
    final class BookingTeacherDeliveryRepository {
        public array $rows = array();
        public function acceptedProvisioningForTeacher(int $teacherId, int $limit = 20): array {
            return array_values(array_filter($this->rows, static fn(object $row): bool => (int) $row->teacher_id === $teacherId));
        }
    }
}
namespace {
    function current_user_can(string $capability): bool { return $capability === 'dzn_view_own_portal_schedule'; }

    require_once dirname(__DIR__) . '/src/Portals/TeacherBookingProvisioningReadService.php';

    use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository;
    use Delnavazan\Platform\Portals\TeacherBookingProvisioningReadService;

    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
    $start = gmdate('Y-m-d H:i:s', time() + 86400);
    $end = gmdate('Y-m-d H:i:s', time() + 88200);
    $base = array(
        'teacher_id' => 10,
        'booking_request_id' => 7,
        'request_reference' => 'REQ-7',
        'confirmation_uid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'delivery_id' => 11,
        'delivery_state' => 'accepting',
        'student_name' => 'Student Seven',
        'instrument_name' => 'Setar',
        'course_name' => 'Introductory Setar',
        'starts_at_utc' => $start,
        'ends_at_utc' => $end,
        'schedule_timezone' => 'Asia/Tehran',
        'teacher_timezone' => 'Asia/Tehran',
        'lifecycle_status' => 'submitted',
        'resolution_state' => 'unresolved',
        'privacy_erased_at' => null,
        'confirmation_updated_at' => gmdate('Y-m-d H:i:s'),
        'next_attempt_at' => null,
        'attempt_count' => 2,
        'classroom_state' => null,
        'cohost_state' => null,
        'classroom_failure_reason_code' => null,
        'classroom_updated_at' => null,
    );

    $repository = new BookingTeacherDeliveryRepository();
    $repository->rows = array(
        (object) (array('confirmation_state' => 'failed', 'failure_reason_code' => 'meet_cohost_assignment_blocked') + $base),
        (object) (array('confirmation_state' => 'retryable', 'failure_reason_code' => 'meet_calendar_projection_retryable') + $base + array('confirmation_uid' => '01ARZ3NDEKTSV4RRFFQ69G5FAW')),
        (object) (array('confirmation_state' => 'awaiting_payment', 'failure_reason_code' => null) + $base + array('confirmation_uid' => '01ARZ3NDEKTSV4RRFFQ69G5FAX')),
        // Historical defect: confirmation exists but the old code moved the delivery back to pending_request.
        (object) (array('confirmation_state' => 'failed', 'failure_reason_code' => 'provider_conference_unavailable', 'delivery_state' => 'pending_request') + $base + array('confirmation_uid' => '01ARZ3NDEKTSV4RRFFQ69G5FAY')),
    );

    $rows = (new TeacherBookingProvisioningReadService($repository))->forTeacher(10);
    $assert(count($rows) === 4, 'Every accepted booking must remain visible regardless of offer consumption or delivery state.');
    $assert($rows[0]['acceptance_state'] === 'accepted', 'The confirmation must project the durable Teacher acceptance.');
    $assert($rows[0]['provisioning_state'] === 'failed', 'A terminal provider failure must be visible as a provisioning failure.');
    $assert($rows[0]['join_available'] === false, 'A failed provisioning record must not expose a broken Join action.');
    $assert($rows[1]['provisioning_state'] === 'provisioning', 'A retryable projection must remain visible while provisioning continues.');
    $assert($rows[2]['provisioning_state'] === 'payment_pending', 'A paid acceptance must remain visible while payment is pending.');
    $assert($rows[3]['provisioning_state'] === 'failed', 'A historical pending_request delivery with a confirmation must remain visible as accepted.');
    $assert($rows[0]['student_name'] === 'Student Seven' && $rows[0]['instrument_name'] === 'Setar', 'The Teacher must see the Student and instrument.');
    $assert($rows[0]['schedule_timezone'] === 'Asia/Tehran' && $rows[0]['starts_at_utc'] === $start, 'The Teacher must see the confirmed time and timezone.');
    $assert($rows[0]['failure_reason_code'] === 'meet_cohost_assignment_blocked', 'The failure reason must remain diagnosable.');
    $assert($rows[0]['attempt_count'] === 2 && $rows[0]['last_attempt_at'] !== '', 'Attempt count and last attempt must be exposed.');
    $foreignRejected = false;
    try { (new TeacherBookingProvisioningReadService($repository))->forTeacher(99); }
    catch (InvalidArgumentException $error) { $foreignRejected = $error->getMessage() === 'portal_object_not_owned'; }
    $assert($foreignRejected, 'Another Teacher must never see these accepted bookings.');

    echo "Booking provisioning recovery unit passed\n";
}
