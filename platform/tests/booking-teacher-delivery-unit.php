<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository {
    final class BookingRequestMatchAssessmentRepository {
        public array $teachers;
        public function __construct() {
            $this->teachers = array(
                (object) array('teacher_id' => 22, 'accepting_state' => 'limited'),
                (object) array('teacher_id' => 11, 'accepting_state' => 'accepting'),
            );
        }
        public function eligibleTeachers(int $courseId, string $now): array { return $this->teachers; }
    }
    final class BookingTeacherDeliveryRepository {
        public array $created = array();
        public function create(array $data): int { $this->created[] = $data; return 77; }
    }
}
namespace Delnavazan\Platform\Core\Application {
    final class TeacherAvailabilityService {
        public function effective(int $teacherId, string $start, string $end): array {
            if ($teacherId === 11 && $start === '2026-11-01 09:00:00') return array();
            $state = $teacherId === 11 ? 'preferred' : 'requestable';
            return array(array('starts_at_utc' => $start, 'ends_at_utc' => $end, 'state' => $state));
        }
    }
    final class CanonicalTeacherOccupancyReadService {
        public function overlapping(int $teacherId, string $start, string $end): array { return array(); }
    }
    final class CanonicalContinuationCapacityAuthority {
        public static function assertNoActiveHold(int $teacherId, string $start, string $end): void {}
    }
}
namespace Delnavazan\Platform\Core\Support {
    final class Identifier { public static function uid(): string { return '01TESTBOOKINGDELIVERY00001'; } }
}
namespace {
    require_once dirname(__DIR__) . '/src/Core/Application/BookingTeacherDeliveryService.php';
    $matches = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestMatchAssessmentRepository();
    $deliveries = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository();
    $availability = new \Delnavazan\Platform\Core\Application\TeacherAvailabilityService();
    $service = new \Delnavazan\Platform\Core\Application\BookingTeacherDeliveryService($matches, $deliveries, $availability);
    $times = array(
        array('id' => 101, 'sequence' => 1, 'starts_at_utc' => '2026-11-01 09:00:00', 'occupied_ends_at_utc' => '2026-11-01 10:00:00'),
        array('id' => 202, 'sequence' => 2, 'starts_at_utc' => '2026-11-02 09:00:00', 'occupied_ends_at_utc' => '2026-11-02 10:00:00'),
    );
    $result = $service->deliver(9, 4, $times, '2026-10-14 00:00:00');
    if (($result['delivery_id'] ?? null) !== 77) throw new RuntimeException('Delivery was not persisted.');
    $row = $deliveries->created[0] ?? array();
    if (($row['teacher_id'] ?? null) !== 11 || ($row['requested_time_id'] ?? null) !== 202) throw new RuntimeException('The strongest eligible Teacher/time pair was not selected.');
    if (($row['state'] ?? null) !== 'pending_request' || ($row['match_strength'] ?? null) !== 'strong') throw new RuntimeException('Delivery must remain a pending request.');
    $matches->teachers = array();
    if ($service->deliver(10, 4, $times, '2026-10-14 00:00:00') !== null) throw new RuntimeException('No eligible Teacher must produce no delivery.');
    if (count($deliveries->created) !== 1) throw new RuntimeException('No-candidate intake must not persist a delivery.');
    echo "Booking teacher delivery unit passed\n";
}
