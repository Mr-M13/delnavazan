<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestMatchAssessmentRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Chooses one deterministic, currently-covered Teacher for a new request.
 * This creates inbox delivery only. It never reserves capacity, confirms a class,
 * creates a Lesson, contacts a provider, or creates Calendar/Meet projections.
 */
final class BookingTeacherDeliveryService {
    private CanonicalTeacherOccupancyReadService $occupancy;

    public function __construct(
        private ?BookingRequestMatchAssessmentRepository $matches = null,
        private ?BookingTeacherDeliveryRepository $deliveries = null,
        private ?TeacherAvailabilityService $availability = null
    ) {
        $this->matches ??= new BookingRequestMatchAssessmentRepository();
        $this->deliveries ??= new BookingTeacherDeliveryRepository();
        $this->availability ??= new TeacherAvailabilityService();
        $this->occupancy = new CanonicalTeacherOccupancyReadService();
    }

    /**
     * @param list<array{id:int,sequence:int,starts_at_utc:string,occupied_ends_at_utc:string}> $times
     * @return array{delivery_id:int,teacher_id:int,requested_time_id:int,state:string,match_strength:string}|null
     */
    public function deliver(int $requestId, int $courseId, array $times, string $now): ?array {
        $teachers = $this->matches->eligibleTeachers($courseId, $now);
        $candidates = array();
        foreach ($times as $time) {
            foreach ($teachers as $teacher) {
                $coverage = $this->coverageState((int) $teacher->teacher_id, $time['starts_at_utc'], $time['occupied_ends_at_utc']);
                if ($coverage === null) continue;
                if ($this->occupancy->overlapping((int) $teacher->teacher_id, $time['starts_at_utc'], $time['occupied_ends_at_utc'])) continue;
                try { CanonicalContinuationCapacityAuthority::assertNoActiveHold((int) $teacher->teacher_id, $time['starts_at_utc'], $time['occupied_ends_at_utc']); }
                catch (\InvalidArgumentException $e) { if ($e->getMessage() === 'teacher_slot_conflict') continue; throw $e; }
                $strength = $coverage === 'preferred' && (string) $teacher->accepting_state === 'accepting' ? 'strong' : 'possible';
                $candidates[] = array(
                    'teacher_id' => (int) $teacher->teacher_id,
                    'requested_time_id' => (int) $time['id'],
                    'sequence' => (int) $time['sequence'],
                    'match_strength' => $strength,
                );
            }
        }
        if (!$candidates) return null;
        usort($candidates, static fn(array $a, array $b): int =>
            array($a['match_strength'] === 'strong' ? 0 : 1, $a['sequence'], $a['teacher_id'])
            <=> array($b['match_strength'] === 'strong' ? 0 : 1, $b['sequence'], $b['teacher_id'])
        );
        $winner = $candidates[0];
        $id = $this->deliveries->create(array(
            'uid' => Identifier::uid(),
            'booking_request_id' => $requestId,
            'requested_time_id' => $winner['requested_time_id'],
            'teacher_id' => $winner['teacher_id'],
            'state' => 'pending_request',
            'match_strength' => $winner['match_strength'],
            'created_at' => $now,
            'updated_at' => $now,
        ));
        return array('delivery_id' => $id, 'teacher_id' => $winner['teacher_id'], 'requested_time_id' => $winner['requested_time_id'], 'state' => 'pending_request', 'match_strength' => $winner['match_strength']);
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
        }
        return $deliveries;
    }

    private function coverageState(int $teacherId, string $start, string $end): ?string {
        $cursor = $start;
        $state = 'preferred';
        foreach ($this->availability->effective($teacherId, $start, $end) as $segment) {
            if ($segment['starts_at_utc'] > $cursor || !in_array($segment['state'], array('preferred', 'requestable'), true)) return null;
            if ($segment['state'] === 'requestable') $state = 'requestable';
            if ($segment['ends_at_utc'] > $cursor) $cursor = min($end, $segment['ends_at_utc']);
            if ($cursor === $end) return $state;
        }
        return null;
    }
}
