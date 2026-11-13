<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestMatchAssessmentRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository;

/**
 * Chooses one deterministic, currently-covered Teacher for a new request.
 * This creates inbox delivery and a short-lived Teacher-wide offer reservation.
 * It never confirms a class, creates a Lesson, contacts a provider, or creates
 * Calendar/Meet projections.
 */
final class BookingTeacherDeliveryService {

    public function __construct(
        private ?BookingRequestMatchAssessmentRepository $matches = null,
        private ?BookingTeacherDeliveryRepository $deliveries = null,
        private ?TeacherAvailabilityService $availability = null
    ) {
        $this->matches ??= new BookingRequestMatchAssessmentRepository();
        $this->deliveries ??= new BookingTeacherDeliveryRepository();
        $this->availability ??= new TeacherAvailabilityService();
    }

    /**
     * @param list<array{id:int,sequence:int,starts_at_utc:string,occupied_ends_at_utc:string}> $times
     * @return array{delivery_id:int,teacher_id:int,requested_time_id:int,state:string,match_strength:string}|null
     */
    public function deliver(int $requestId, int $courseId, array $times, string $now): ?array {
        return (new BookingAllocationService(null,$this->matches,$this->deliveries,$this->availability))
            ->startWithinTransaction($requestId,$courseId,$times,$now);
    }

    public function ownPending(): array {
        $user = (int) get_current_user_id();
        if ($user < 1) throw new \RuntimeException('Unauthorized');
        $teacher = (new \Delnavazan\Platform\Core\Infrastructure\Repository\TeacherAvailabilityRepository())->activeTeacherIdForUser($user);
        if (!$teacher) throw new \RuntimeException('Active ready Teacher required');
        $deliveries = $this->deliveries->pendingForTeacher($teacher);
        foreach ($deliveries as $delivery) {
            // The delivery retains one matchable time for the authoritative
            // response action, while the read projection exposes every ranked
            // preference for the teacher's informed choice.
            $delivery->requested_times = $this->deliveries->requestedTimes((int) $delivery->booking_request_id);
            foreach ($delivery->requested_times as &$preference) {
                // The Teacher must know which Student preference is an explicit
                // availability exception before accepting it.
                $preference['coverage'] = $this->coverageState(
                    $teacher,
                    (string) ($preference['starts_at_utc'] ?? ''),
                    (string) ($preference['occupied_ends_at_utc'] ?? ''), (int) ($delivery->course_id ?? 0)
                );
            }
            unset($preference);
            $deadline = trim((string) ($delivery->response_deadline_at ?? ''));
            if ($deadline !== '') {
                $delivery->response_deadline_utc = $deadline;
                $delivery->response_remaining_seconds = max(0, (new \DateTimeImmutable($deadline, new \DateTimeZone('UTC')))->getTimestamp() - time());
                $delivery->response_expired = $delivery->response_remaining_seconds <= 0;
            }
        }
        return $deliveries;
    }

    /** Exact declared-availability state for one preference, or `outside` when it is not covered. */
    private function coverageState(int $teacherId, string $startsAtUtc, string $occupiedEndUtc, ?int $courseId = null): string {
        if ($startsAtUtc === '' || $occupiedEndUtc === '' || $occupiedEndUtc <= $startsAtUtc) {
            return 'outside';
        }
        $cursor = $startsAtUtc;
        $state = 'preferred';
        foreach ($this->availability->effective($teacherId, $startsAtUtc, $occupiedEndUtc, $courseId ? $this->availability->instrumentIdForCourse($courseId) : null) as $segment) {
            if ((string) $segment['starts_at_utc'] > $cursor) { return 'outside'; }
            if (!in_array((string) $segment['state'], array('preferred', 'requestable'), true)) { return 'outside'; }
            if ((string) $segment['state'] === 'requestable') { $state = 'requestable'; }
            if ((string) $segment['ends_at_utc'] > $cursor) {
                $cursor = min($occupiedEndUtc, (string) $segment['ends_at_utc']);
            }
            if ($cursor === $occupiedEndUtc) { return $state; }
        }
        return 'outside';
    }
}
