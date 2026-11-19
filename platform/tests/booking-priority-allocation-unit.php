<?php
/**
 * Pure unit coverage for the Teacher booking priority: first-offer selection,
 * normal-channel fall-through, cursor neutrality, the 8h/T−12h cap and the
 * teacher-wide vs instrument-course scope.
 */
namespace Delnavazan\Platform\Core\Infrastructure\Repository {
    final class BookingAllocationRepository {
        public ?object $deliveryRow = null;
        public ?object $active = null;
        public array $offers = array();
        public array $referrals = array();
        public array $alerts = array();
        public array $cursorCalls = array();
        public int $cursorId = 0;
        public array $timeRows = array();
        public function begin(): void {}
        public function commit(): void {}
        public function rollback(): void {}
        public function ensureAndLockTeacherRoot(int $teacherId, string $now, int $actor): void {}
        public function delivery(int $requestId, bool $lock = false): ?object { return $this->deliveryRow; }
        public function request(int $requestId, bool $lock = false): ?object {
            return (object) array('id'=>$requestId,'selected_intro_course_id'=>4,'lifecycle_status'=>'submitted','resolution_state'=>'unresolved','privacy_erased_at'=>null,'created_at'=>'2026-11-02 00:00:00');
        }
        public function confirmationExists(int $requestId): bool { return false; }
        public function times(int $requestId): array { return $this->timeRows; }
        public function cursor(int $courseId, string $now): int { $this->cursorCalls[]=$courseId; return $this->cursorId; }
        public function advanceCursor(int $courseId, int $teacherId, string $now): void { $this->cursorCalls[]='advance:'.$teacherId; $this->cursorId=$teacherId; }
        public function addOffer(int $requestId, int $deliveryId, array $candidate, int $sequence, string $now, string $deadline, int $timeSetVersion = 1): void {
            $this->active=(object) array('id'=>$sequence,'delivery_id'=>$deliveryId,'teacher_id'=>$candidate['teacher_id'],'requested_time_id'=>$candidate['requested_time_id'],'offer_sequence'=>$sequence,'deadline_at'=>$deadline,'state'=>'pending','time_set_version'=>$timeSetVersion);
            $this->offers[]=$this->active;
        }
        public int $timeSetVersion = 1;
        public array $stickyDeclined = array();
        public function currentTimeSetVersion(int $requestId): int { return $this->timeSetVersion; }
        public function stickyDeclinedTeacherIds(int $requestId, int $version): array { return $this->stickyDeclined; }
        public function offer(int $requestId, bool $lock = false): ?object { return $this->active; }
        public function history(int $requestId): array { return $this->offers; }
        public function historyForVersion(int $requestId, int $version): array { return array_values(array_filter($this->offers, static fn(object $row): bool => (int)($row->time_set_version ?? 1) === $version)); }
        public function transitionOffer(int $offerId, string $outcome, string $now, string $reason): void { if($this->active)$this->active->state=$outcome; $this->active=null; }
        public function rotateDelivery(object $delivery, array $candidate, string $now): int { $delivery->teacher_id=$candidate['teacher_id']; $delivery->requested_time_id=$candidate['requested_time_id']; return (int)$delivery->id; }
        public function referral(int $requestId, string $reason, string $now): void { $this->referrals[]=$reason; }
        public function alert(int $requestId, string $kind, string $reason, string $priority, string $now): void { $this->alerts[]=$kind; }
        public function hasAlert(int $requestId, string $kind): bool { return in_array($kind,$this->alerts,true); }
        public function queueBookingNotice(int $deliveryId, int $offerId, string $kind, string $now): void {}
        public function extendOffer(int $offerId, string $deadline, string $now): void {}
        public function resolveAlerts(int $requestId, string $now): void {}
    }
    final class BookingRequestMatchAssessmentRepository {
        public function eligibleTeachers(int $courseId, string $now): array {
            return array(
                (object) array('teacher_id'=>11,'accepting_state'=>'accepting'),
                (object) array('teacher_id'=>22,'accepting_state'=>'accepting'),
                (object) array('teacher_id'=>33,'accepting_state'=>'accepting'),
            );
        }
    }
    final class BookingTeacherDeliveryRepository {
        public function __construct(private ?BookingAllocationRepository $repo=null) {}
        public function create(array $data): int { $this->repo->deliveryRow=(object)(array('id'=>77)+$data); return 77; }
        public function transitionForAllocation(int $id, string $from, string $to, string $now): void { $this->repo->deliveryRow->state=$to; }
    }
    final class TeacherBookingPriorityRepository {
        public array $rows = array();
        public array $audits = array();
        public array $eligibility = array();
        public function begin(): void {}
        public function commit(): void {}
        public function rollback(): void {}
        public function lockTeacherRoot(int $teacherId, string $now, int $actor): void {}
        public function activeForTeacher(int $teacherId): array {
            $out=array();
            foreach($this->rows as $row)if((int)$row->teacher_id===$teacherId&&$row->active_slot===1)$out[]=$row;
            return $out;
        }
        public function activeForScope(int $teacherId, string $scopeKind, int $courseScopeId, bool $lock = false): ?object {
            foreach($this->rows as $row)if((int)$row->teacher_id===$teacherId&&(string)$row->scope_kind===$scopeKind&&(int)$row->course_scope_id===$courseScopeId&&$row->active_slot===1&&(string)$row->state==='active')return $row;
            return null;
        }
        public function prioritizedTeacherIds(array $teacherIds, int $courseId, string $now): array {
            $out=array();
            foreach($this->rows as $row){
                if((string)$row->state!=='active'||$row->active_slot!==1)continue;
                if((string)$row->effective_from>$now)continue;
                if($row->effective_until!==null&&(string)$row->effective_until<=$now)continue;
                if(!in_array((int)$row->teacher_id,$teacherIds,true))continue;
                if((string)$row->scope_kind!=='teacher'&&!((string)$row->scope_kind==='teacher_course'&&(int)$row->course_scope_id===$courseId))continue;
                $out[]=(int)$row->teacher_id;
            }
            return array_values(array_unique($out));
        }
        public function eligibleForCourse(int $teacherId, int $courseId): bool { return !empty($this->eligibility[$teacherId.':'.$courseId]); }
        public function insertPriority(array $data): int { $id=count($this->rows)+1; $data['id']=$id; $this->rows[$id]=(object)$data; return $id; }
        public function closePriority(int $id, string $now, int $actor): void { if(isset($this->rows[$id])){$this->rows[$id]->state='inactive';$this->rows[$id]->active_slot=null;$this->rows[$id]->effective_until=$now;} }
        public function auditByDigest(string $digest): ?object { foreach($this->audits as $audit)if(hash_equals((string)$audit['idempotency_key'],$digest))return (object)$audit; return null; }
        public function insertAudit(array $data): int { $this->audits[]=$data; return count($this->audits); }
    }
}
namespace Delnavazan\Platform\Core\Application {
    final class TeacherAvailabilityService {
        public function effective(int $teacherId, string $start, string $end, ?int $instrumentId = null): array { return array(array('starts_at_utc'=>$start,'ends_at_utc'=>$end,'state'=>'preferred')); }
        public function instrumentIdForCourse(int $courseId): ?int { return 1; }
    }
    final class BookingTeacherOccupancyReadService {
        public static array $blockedTeachers = array();
        public function overlapping(int $teacherId, string $start, string $end, int $excludeLessonId = 0, int $excludeBookingRequestId = 0, bool $includePendingOffers = true): array { return in_array($teacherId,self::$blockedTeachers,true)?array(array('lesson_id'=>1)):array(); }
    }
    final class CanonicalContinuationCapacityAuthority {
        public static function assertNoActiveHold(int $teacherId, string $start, string $end): void {}
    }
    final class BookingTeacherWorkloadService {
        public function __construct(mixed $repository = null) {}
        public function scores(array $teacherIds, string $now): array {
            $out=array();foreach($teacherIds as $id)$out[(int)$id]=array('weighted'=>0.0,'pending'=>0,'accepted'=>0,'confirmed'=>0,'completed'=>0);return $out;
        }
    }
}
namespace {
    function current_user_can(string $capability): bool { return true; }
    function get_current_user_id(): int { return 9; }
    require_once dirname(__DIR__) . '/src/Core/Support/Identifier.php';
    require_once dirname(__DIR__) . '/src/Core/Application/Booking/TeacherBookingPriorityService.php';
    require_once dirname(__DIR__) . '/src/Core/Application/BookingLeadTimePolicy.php';
    require_once dirname(__DIR__) . '/src/Core/Application/BookingAllocationService.php';

    use Delnavazan\Platform\Core\Application\Booking\TeacherBookingPriorityService;
    use Delnavazan\Platform\Core\Application\BookingAllocationService;
    use Delnavazan\Platform\Core\Application\BookingTeacherOccupancyReadService;
    use Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository;
    use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestMatchAssessmentRepository;
    use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherBookingPriorityRepository;

    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
    $now = '2026-11-02 00:00:00';
    $times = array((object) array('id'=>101,'sequence_number'=>1,'starts_at_utc'=>'2026-11-10 09:00:00','occupied_ends_at_utc'=>'2026-11-10 10:00:00'));

    $boot = static function (TeacherBookingPriorityRepository $priorities, array $times, string $now): array {
        $repo = new BookingAllocationRepository();
        $repo->timeRows = $times;
        $service = new BookingAllocationService(
            $repo,
            new BookingRequestMatchAssessmentRepository(),
            new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository($repo),
            null,
            null,
            new TeacherBookingPriorityService($priorities)
        );
        return array($repo, $service, $priorities);
    };
    $priorityRow = static function (int $teacherId, string $scope, int $courseId, string $from = '2026-11-01 00:00:00'): object {
        return (object) array('id'=>0,'teacher_id'=>$teacherId,'scope_kind'=>$scope,'course_scope_id'=>$courseId,'state'=>'active','active_slot'=>1,'effective_from'=>$from,'effective_until'=>null,'reason_code'=>'operator_priority');
    };

    // 1. A teacher-wide priority wins the first offer and leaves the cursor untouched.
    $priorities = new TeacherBookingPriorityRepository();
    $priorities->rows[] = $priorityRow(22, 'teacher', 0);
    [$repo, $service] = $boot($priorities, $times, $now);
    $first = $service->startWithinTransaction(1, 4, $times, $now);
    $assert($first !== null && (int)$first['teacher_id'] === 22, 'The prioritized Teacher must receive the first offer.');
    $assert($first['deadline_at'] === '2026-11-02 08:00:00', 'A priority offer must carry an eight-hour deadline.');
    $assert($repo->cursorCalls === array(), 'A priority offer must not read or advance the round-robin cursor.');

    // 2. The priority Teacher is unavailable: the normal channel runs untouched.
    BookingTeacherOccupancyReadService::$blockedTeachers = array(22);
    [$repo, $service] = $boot($priorities, $times, $now);
    $fallback = $service->startWithinTransaction(2, 4, $times, $now);
    BookingTeacherOccupancyReadService::$blockedTeachers = array();
    $assert($fallback !== null && (int)$fallback['teacher_id'] === 11, 'An unavailable priority Teacher must fall through to normal allocation.');
    $assert($repo->cursorCalls === array(4, 'advance:11'), 'The normal fallback must use the round-robin cursor.');

    // 3. Multiple priorities use the normal order (strength, workload, id) and ignore the cursor.
    $priorities->rows[] = $priorityRow(11, 'teacher', 0);
    [$repo, $service] = $boot($priorities, $times, $now);
    $multi = $service->startWithinTransaction(3, 4, $times, $now);
    $assert($multi !== null && (int)$multi['teacher_id'] === 11, 'Two equally ranked priorities must use the Teacher id as the final order.');
    $assert($repo->cursorCalls === array(), 'Multiple priorities must not advance the cursor.');

    // 4. The eight-hour deadline is capped at T−12h.
    $cappedTimes = array((object) array('id'=>101,'sequence_number'=>1,'starts_at_utc'=>'2026-11-02 17:00:00','occupied_ends_at_utc'=>'2026-11-02 18:00:00'));
    [$repo, $service] = $boot($priorities, $cappedTimes, $now);
    $capped = $service->startWithinTransaction(4, 4, $cappedTimes, $now);
    $assert($capped !== null && $capped['deadline_at'] === '2026-11-02 05:00:00', 'A priority offer 17h out must be capped at T−12h, not 8h.');

    // 5. Inside the offer lead even a priority cannot offer; the request is referred.
    $insideTimes = array((object) array('id'=>101,'sequence_number'=>1,'starts_at_utc'=>'2026-11-02 15:00:00','occupied_ends_at_utc'=>'2026-11-02 16:00:00'));
    [$repo, $service] = $boot($priorities, $insideTimes, $now);
    $inside = $service->startWithinTransaction(5, 4, $insideTimes, $now);
    $assert($inside === null && $repo->offers === array(), 'A priority must never bypass the offer lead.');

    // 6. Scope: teacher-wide covers any course; teacher_course only its exact course.
    $scoped = new TeacherBookingPriorityRepository();
    $scoped->rows[] = $priorityRow(22, 'teacher', 0);
    $scoped->rows[] = $priorityRow(11, 'teacher_course', 4);
    $scopeService = new TeacherBookingPriorityService($scoped);
    $assert($scopeService->prioritizedTeachers(array(11,22,33), 4, $now) === array(22,11), 'Both scopes must cover the exact course.');
    $assert($scopeService->prioritizedTeachers(array(11,22,33), 5, $now) === array(22), 'A course-scoped priority must not leak to another course.');

    // 7. Enable/disable is reversible, capability-gated and audited with one replay.
    $admin = new TeacherBookingPriorityRepository();
    $admin->eligibility['33:4'] = true;
    $adminService = new TeacherBookingPriorityService($admin);
    $enabled = $adminService->set(33, 'teacher', 0, true, 'operator_priority', 'priority-command-1');
    $assert($enabled['state'] === 'active' && $enabled['replayed'] === false, 'Enabling a priority must create one live row.');
    $replay = $adminService->set(33, 'teacher', 0, true, 'operator_priority', 'priority-command-1');
    $assert($replay['replayed'] === true && count($admin->rows) === 1, 'A repeated command must replay without a second row.');
    $course = $adminService->set(33, 'teacher_course', 4, true, 'bulk_priority', 'priority-command-2');
    $assert($course['state'] === 'active' && $course['course_scope_id'] === 4, 'An eligible course scope must be recorded.');
    $removed = $adminService->set(33, 'teacher', 0, false, 'operator_removed', 'priority-command-3');
    $assert($removed['state'] === 'inactive' && $admin->activeForScope(33, 'teacher', 0) === null, 'Disabling must close the live row.');
    $assert($admin->activeForScope(33, 'teacher_course', 4) !== null, 'Disabling one scope must leave the other scope intact.');
    $assert(count($admin->audits) === 3, 'Every transition must leave one append-only audit event, and a replay must not duplicate it.');
    $refused = false;
    try { $adminService->set(33, 'teacher_course', 9, true, 'operator_priority', 'priority-command-4'); }
    catch (\Throwable) { $refused = true; }
    $assert($refused, 'A course scope without an active eligibility must be refused.');

    // 8. After a priority decline the normal rotation moves on, never back to the priority Teacher.
    $priorities = new TeacherBookingPriorityRepository();
    $priorities->rows[] = $priorityRow(22, 'teacher', 0);
    [$repo, $service] = $boot($priorities, $times, $now);
    $first = $service->startWithinTransaction(6, 4, $times, $now);
    $advanced = $service->advanceLocked($repo->deliveryRow, 'declined', $now);
    $assert($first !== null && (int)$first['teacher_id'] === 22, 'The priority Teacher must be offered first.');
    $assert($advanced !== null && (int)$advanced['teacher_id'] !== 22, 'A declined priority offer must advance to a different Teacher.');
    $assert($advanced['deadline_at'] === '2026-11-03 00:00:00', 'The normal rotation must use the dynamic window, not the 8h priority window.');

    // 9. A sticky decline excludes the prioritized Teacher; a lifted times_unsuitable does not.
    $priorities = new TeacherBookingPriorityRepository();
    $priorities->rows[] = $priorityRow(22, 'teacher', 0);
    [$repo, $service] = $boot($priorities, $times, $now);
    $repo->stickyDeclined = array(22);
    $sticky = $service->startWithinTransaction(7, 4, $times, $now);
    $assert($sticky !== null && (int)$sticky['teacher_id'] === 11, 'A priority Teacher with a sticky decline must fall through to the normal channel.');
    [$repo, $service] = $boot($priorities, $times, $now);
    $repo->timeSetVersion = 2;
    $lifted = $service->startWithinTransaction(8, 4, $times, $now);
    $assert($lifted !== null && (int)$lifted['teacher_id'] === 22 && (int)$repo->offers[0]->time_set_version === 2, 'A times_unsuitable decline from an older set must not block a priority offer at the new version.');

    echo "Booking priority allocation unit passed\n";
}
