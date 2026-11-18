<?php
/**
 * Pure unit coverage for the sole-Teacher hold, terminal expiry and the
 * decline-is-never-a-hold rule. The repository is a recording fake.
 */
namespace Delnavazan\Platform\Core\Infrastructure\Repository {
    final class BookingAllocationRepository {
        public object $deliveryRow;
        public ?object $active = null;
        public array $offers = array();
        public array $referrals = array();
        public array $alerts = array();
        public array $extends = array();
        public array $notices = array();
        public ?array $timeRows = null;
        public function begin(): void {}
        public function commit(): void {}
        public function rollback(): void {}
        public function ensureAndLockTeacherRoot(int $teacherId, string $now, int $actor): void {}
        public function delivery(int $requestId, bool $lock = false): ?object { return $this->deliveryRow; }
        public function request(int $requestId, bool $lock = false): ?object {
            return (object) array('id'=>$requestId,'selected_intro_course_id'=>4,'lifecycle_status'=>'submitted','resolution_state'=>'unresolved','privacy_erased_at'=>null,'created_at'=>'2026-11-19 00:00:00');
        }
        public function confirmationExists(int $requestId): bool { return false; }
        public function times(int $requestId): array { return $this->timeRows ?? array(); }
        public function offer(int $requestId, bool $lock = false): ?object { return $this->active; }
        public function history(int $requestId): array { return $this->offers; }
        public function transitionOffer(int $offerId, string $outcome, string $now, string $reason): void {
            if (!$this->active || $this->active->id !== $offerId) throw new \RuntimeException('Wrong active offer');
            $this->active->state = $outcome;
            $this->active = null;
        }
        public function rotateDelivery(object $delivery, array $candidate, string $now): int {
            $delivery->teacher_id = $candidate['teacher_id'];
            $delivery->requested_time_id = $candidate['requested_time_id'];
            return (int) $delivery->id;
        }
        public function addOffer(int $requestId, int $deliveryId, array $candidate, int $sequence, string $now, string $deadline): void {
            $this->active = (object) array('id'=>$sequence,'delivery_id'=>$deliveryId,'teacher_id'=>$candidate['teacher_id'],'requested_time_id'=>$candidate['requested_time_id'],'offer_sequence'=>$sequence,'deadline_at'=>$deadline,'state'=>'pending');
            $this->offers[] = $this->active;
        }
        public function extendOffer(int $offerId, string $deadline, string $now): void {
            if (!$this->active || $this->active->id !== $offerId) throw new \RuntimeException('Wrong active offer');
            $this->extends[] = array($offerId, $deadline);
            $this->active->deadline_at = $deadline;
        }
        public function alert(int $requestId, string $kind, string $reason, string $priority, string $now): void {
            foreach ($this->alerts as $index => $alert) if ($alert['kind'] === $kind) { $this->alerts[$index]['reason'] = $reason; return; }
            $this->alerts[] = array('kind'=>$kind,'reason'=>$reason);
        }
        public function hasAlert(int $requestId, string $kind): bool {
            foreach ($this->alerts as $alert) if ($alert['kind'] === $kind) return true;
            return false;
        }
        public function referral(int $requestId, string $reason, string $now): void {
            $this->referrals[] = $reason;
            $this->alert($requestId, 'referral', $reason, 'normal', $now);
        }
        public function resolveAlerts(int $requestId, string $now): void {}
        public function queueBookingNotice(int $deliveryId, int $offerId, string $kind, string $now): void {
            foreach ($this->notices as $notice) if ($notice['kind'] === $kind) return;
            $this->notices[] = array('kind'=>$kind,'deadline'=>$this->active ? $this->active->deadline_at : null);
        }
        public function cursor(int $courseId, string $now): int { return 0; }
        public function advanceCursor(int $courseId, int $teacherId, string $now): void {}
    }
    final class BookingRequestMatchAssessmentRepository {
        public static array $teachers = array(11);
        public function eligibleTeachers(int $courseId, string $now): array {
            $out = array();
            foreach (self::$teachers as $id) $out[] = (object) array('teacher_id'=>$id,'accepting_state'=>'accepting');
            return $out;
        }
    }
    final class BookingTeacherDeliveryRepository {
        public function __construct(private ?BookingAllocationRepository $repo = null) {}
        public function create(array $data): int { $this->repo->deliveryRow = (object) (array('id'=>77) + $data); return 77; }
        public function transitionForAllocation(int $id, string $from, string $to, string $now): void { $this->repo->deliveryRow->state = $to; }
    }
}
namespace Delnavazan\Platform\Core\Application {
    final class TeacherAvailabilityService {
        public function effective(int $teacherId, string $start, string $end, ?int $instrumentId = null): array {
            return array(array('starts_at_utc'=>$start,'ends_at_utc'=>$end,'state'=>'preferred'));
        }
        public function instrumentIdForCourse(int $courseId): ?int { return 1; }
    }
    final class BookingTeacherOccupancyReadService {
        public static array $blockedTeachers = array();
        public function overlapping(int $teacherId, string $start, string $end, int $excludeLessonId = 0, int $excludeBookingRequestId = 0, bool $includePendingOffers = true): array {
            return in_array($teacherId, self::$blockedTeachers, true) ? array(array('lesson_id'=>1)) : array();
        }
    }
    final class CanonicalContinuationCapacityAuthority {
        public static function assertNoActiveHold(int $teacherId, string $start, string $end): void {}
    }
    final class BookingTeacherWorkloadService {
        public function __construct(mixed $repository = null) {}
        public function scores(array $teacherIds, string $now): array {
            $out = array();
            foreach ($teacherIds as $id) $out[(int) $id] = array('weighted'=>0.0,'pending'=>0,'accepted'=>0,'confirmed'=>0,'completed'=>0);
            return $out;
        }
    }
}
namespace {
    require_once dirname(__DIR__) . '/src/Core/Application/BookingLeadTimePolicy.php';
    require_once dirname(__DIR__) . '/src/Core/Application/BookingAllocationService.php';

    use Delnavazan\Platform\Core\Application\BookingAllocationService;
    use Delnavazan\Platform\Core\Application\BookingTeacherOccupancyReadService;
    use Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository;
    use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestMatchAssessmentRepository;

    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

    $boot = static function (string $start, string $now, string $deadline, array $teachers = array(11)): array {
        BookingRequestMatchAssessmentRepository::$teachers = $teachers;
        BookingTeacherOccupancyReadService::$blockedTeachers = array();
        $repo = new BookingAllocationRepository();
        $repo->deliveryRow = (object) array('id'=>77,'booking_request_id'=>9,'teacher_id'=>11,'requested_time_id'=>101,'state'=>'pending_request');
        $repo->timeRows = array((object) array('id'=>101,'sequence_number'=>1,'starts_at_utc'=>$start,'occupied_ends_at_utc'=>(new DateTimeImmutable($start,new DateTimeZone('UTC')))->modify('+1 hour')->format('Y-m-d H:i:s')));
        $repo->active = (object) array('id'=>5,'delivery_id'=>77,'teacher_id'=>11,'requested_time_id'=>101,'offer_sequence'=>1,'deadline_at'=>$deadline,'state'=>'pending');
        $repo->offers = array($repo->active);
        $service = new BookingAllocationService($repo, new BookingRequestMatchAssessmentRepository(), new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository($repo));
        return array($repo, $service);
    };

    // A silent, still-viable sole Teacher is extended, not expired.
    [$repo, $service] = $boot('2026-11-20 12:00:00', '2026-11-19 16:00:00', '2026-11-19 16:00:00');
    $result = $service->advanceLocked($repo->deliveryRow, 'expired', '2026-11-19 16:00:00');
    $assert($result === null && $repo->active !== null && $repo->active->state === 'pending', 'A sole viable Teacher must keep the pending offer.');
    $assert($repo->extends === array(array(5, '2026-11-19 20:00:00')), 'The first hold must extend the same offer by its next window.');
    $assert($repo->referrals === array(), 'A hold must not refer the request.');
    $assert(count(array_filter($repo->alerts, static fn(array $a): bool => $a['kind'] === 'hold' && $a['reason'] === 'sole_candidate_unanswered')) === 1, 'A hold must raise one sole_candidate_unanswered alert.');
    $assert($repo->notices === array(array('kind'=>'hold_teacher','deadline'=>'2026-11-19 20:00:00')), 'A hold must queue one Teacher reminder with the extended deadline.');

    // A second extension past T−12h keeps the same alert and notice.
    $service->advanceLocked($repo->deliveryRow, 'expired', '2026-11-19 20:00:00');
    $assert($repo->extends === array(array(5, '2026-11-19 20:00:00'), array(5, '2026-11-20 00:00:00')), 'The hold may extend past T−12h.');
    $assert(count($repo->alerts) === 1 && $repo->alerts[0]['kind'] === 'hold', 'Repeated holds must not duplicate the open alert.');
    $assert(count($repo->notices) === 1, 'Repeated holds must not duplicate the Teacher reminder.');

    // At T−1h the same offer expires terminally and the Student is told to rebook.
    [$repo, $service] = $boot('2026-11-20 12:00:00', '2026-11-20 11:00:00', '2026-11-20 11:00:00');
    $service->advanceLocked($repo->deliveryRow, 'expired', '2026-11-20 11:00:00');
    $assert($repo->active === null && $repo->deliveryRow->state === 'expired', 'T−1h must expire the pending offer and close the delivery.');
    $assert($repo->referrals === array(BookingAllocationService::TERMINAL_REFERRAL_REASON), 'The terminal expiry must refer with the terminal reason.');
    $assert($repo->notices === array(array('kind'=>'terminal','deadline'=>null)), 'The terminal expiry must queue the Student rebook notice.');
    $assert(count(array_filter($repo->alerts, static fn(array $a): bool => $a['kind'] === 'hold')) === 0, 'A terminal expiry must not hold.');

    // A decline is never a hold, even with a sole, viable Teacher.
    [$repo, $service] = $boot('2026-11-20 12:00:00', '2026-11-19 16:00:00', '2026-11-19 16:00:00');
    $service->advanceLocked($repo->deliveryRow, 'declined', '2026-11-19 16:00:00');
    $assert($repo->extends === array() && count(array_filter($repo->alerts, static fn(array $a): bool => $a['kind'] === 'hold')) === 0, 'A decline must never hold the request.');
    $assert($repo->referrals === array('candidates_exhausted'), 'A declined sole Teacher must refer immediately.');
    $assert($repo->deliveryRow->state === 'declined', 'A decline must close the delivery.');

    // A Teacher who became unavailable expires normally, not as a hold.
    [$repo, $service] = $boot('2026-11-20 12:00:00', '2026-11-19 16:00:00', '2026-11-19 16:00:00');
    BookingTeacherOccupancyReadService::$blockedTeachers = array(11);
    $service->advanceLocked($repo->deliveryRow, 'expired', '2026-11-19 16:00:00');
    $assert($repo->extends === array() && $repo->active === null, 'An unavailable Teacher must not be held.');
    $assert($repo->referrals === array('teacher_became_unavailable'), 'An unavailable Teacher must refer with the explicit reason.');

    echo "Booking sole-teacher hold unit passed\n";
}
