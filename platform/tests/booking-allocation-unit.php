<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository {
    final class BookingAllocationRepository {
        public ?object $deliveryRow = null;
        public ?object $active = null;
        public array $offers = array();
        public array $referrals = array();
        public array $alerts = array();
        public static array $pendingAlerts = array();
        public static array $pendingExpirations = array();
        public array $lockedTeacherRoots = array();
        public int $cursorId = 0;
        /** Optional requested-time rows; the default is a start far beyond the notice window. */
        public ?array $timeRows = null;
        public function begin(): void {}
        public function commit(): void {}
        public function rollback(): void {}
        public function ensureAndLockTeacherRoot(int $teacherId,string $now,int $actor):void{$this->lockedTeacherRoots[]=$teacherId;}
        public function delivery(int $requestId, bool $lock = false): ?object { return $this->deliveryRow; }
        public function request(int $requestId, bool $lock = false): ?object { return (object) array('id'=>$requestId,'selected_intro_course_id'=>4,'lifecycle_status'=>'submitted','resolution_state'=>'unresolved','privacy_erased_at'=>null,'created_at'=>'2026-11-02 00:00:00'); }
        public function confirmationExists(int $requestId): bool { return false; }
        public function times(int $requestId): array { return $this->timeRows ?? array((object) array('id'=>101,'sequence_number'=>1,'starts_at_utc'=>'2026-11-10 09:00:00','occupied_ends_at_utc'=>'2026-11-10 10:00:00')); }
        public function cursor(int $courseId, string $now): int { return $this->cursorId; }
        public function advanceCursor(int $courseId, int $teacherId, string $now): void { $this->cursorId=$teacherId; }
        public function addOffer(int $requestId, int $deliveryId, array $candidate, int $sequence, string $now, string $deadline): void {
            $this->active=(object) array('id'=>$sequence,'delivery_id'=>$deliveryId,'teacher_id'=>$candidate['teacher_id'],'requested_time_id'=>$candidate['requested_time_id'],'offer_sequence'=>$sequence,'deadline_at'=>$deadline,'state'=>'pending');
            $this->offers[]=$this->active;
        }
        public function offer(int $requestId, bool $lock = false): ?object { return $this->active; }
        public function history(int $requestId): array { return $this->offers; }
        public function transitionOffer(int $offerId, string $outcome, string $now, string $reason): void {
            if (!$this->active || $this->active->id!==$offerId) throw new \RuntimeException('Wrong active offer');
            $this->active->state=$outcome;
            if($outcome==='expired')self::$pendingExpirations[$offerId]=(object)array('id'=>$offerId,'teacher_id'=>$this->active->teacher_id);
            $this->active=null;
        }
        public array $extends = array();
        public function extendOffer(int $offerId, string $deadline, string $now): void { $this->extends[]=array($offerId,$deadline); if($this->active)$this->active->deadline_at=$deadline; }
        public function hasAlert(int $requestId, string $kind): bool { return in_array($kind,$this->alerts,true); }
        public array $bookingNotices = array();
        public function queueBookingNotice(int $deliveryId, int $offerId, string $kind, string $now): void { $this->bookingNotices[]=$kind; }
        public function rotateDelivery(object $delivery, array $candidate, string $now): int {
            $delivery->teacher_id=$candidate['teacher_id'];
            $delivery->requested_time_id=$candidate['requested_time_id'];
            return (int)$delivery->id;
        }
        public function referral(int $requestId, string $reason, string $now): void { $this->referrals[]=$reason; $this->alert($requestId,'referral',$reason,'normal',$now); }
        public function resolveAlerts(int $requestId, string $now): void { foreach($this->alerts as $index=>$kind)if($kind==='referral'||$kind==='escalation')unset($this->alerts[$index]); $this->alerts=array_values($this->alerts); }
        public function alert(int $requestId, string $kind, string $reason, string $priority, string $now): void {
            $this->alerts[]=$kind;
            self::$pendingAlerts[$kind]??=(object)array('id'=>count(self::$pendingAlerts)+1,'booking_request_id'=>$requestId,'kind'=>$kind,'reason_code'=>$reason);
        }
        public function dueRequests(string $now, int $limit=25): array { return array(); }
        public function unnotifiedAlerts(int $limit=25): array { return array_values(self::$pendingAlerts); }
        public function markAlertNotified(int $id, string $now): void { foreach(self::$pendingAlerts as $kind=>$row)if($row->id===$id)unset(self::$pendingAlerts[$kind]); }
        public function unnotifiedExpirations(int $limit=25):array{return array_values(self::$pendingExpirations);}
        public function markExpiryNotified(int $id,string $now):void{unset(self::$pendingExpirations[$id]);}
        public array $retireCalls = array();
        public array $retireRows = array();
        public function retireCompetingOffers(int $teacherId,string $startsAtUtc,string $occupiedEndUtc,int $excludeBookingRequestId,string $now):array{
            $this->retireCalls[]=array($teacherId,$startsAtUtc,$occupiedEndUtc,$excludeBookingRequestId);
            return $this->retireRows;
        }
    }
    final class BookingRequestMatchAssessmentRepository {
        public function eligibleTeachers(int $courseId, string $now): array {
            return array((object) array('teacher_id'=>11,'accepting_state'=>'accepting'),(object) array('teacher_id'=>22,'accepting_state'=>'accepting'));
        }
    }
    final class BookingTeacherDeliveryRepository {
        public function __construct(private ?BookingAllocationRepository $repo=null) {}
        public function create(array $data): int { $this->repo->deliveryRow=(object)(array('id'=>77)+$data); return 77; }
        public function transitionForAllocation(int $id, string $from, string $to, string $now): void { $this->repo->deliveryRow->state=$to; }
    }
}
namespace Delnavazan\Platform\Core\Application\Booking {
    final class TeacherBookingPriorityService {
        public static array $priorityIds = array();
        public function __construct(mixed $repository = null) {}
        public function prioritizedTeachers(array $teacherIds, int $courseId, string $now): array {
            return array_values(array_intersect(self::$priorityIds, array_map('intval', $teacherIds)));
        }
    }
}
namespace Delnavazan\Platform\Core\Application {
    final class TeacherAvailabilityService {
        public static array $requestable = array();
        public function effective(int $teacherId, string $start, string $end): array { return array(array('starts_at_utc'=>$start,'ends_at_utc'=>$end,'state'=>in_array($teacherId,self::$requestable,true)?'requestable':'preferred')); }
        public function instrumentIdForCourse(int $courseId): ?int { return 1; }
    }
    final class BookingTeacherOccupancyReadService {
        public static array $blockedTeachers = array();
        public function overlapping(int $teacherId, string $start, string $end): array { return in_array($teacherId,self::$blockedTeachers,true)?array(array('lesson_id'=>1)):array(); }
    }
    final class CanonicalContinuationCapacityAuthority {
        public static function assertNoActiveHold(int $teacherId, string $start, string $end): void {}
    }
    /** Controllable weighted workload; zero by default so the round-robin assertions stay exact. */
    final class BookingTeacherWorkloadService {
        public static array $weighted = array();
        public function __construct(mixed $repository = null) {}
        public function scores(array $teacherIds, string $now): array {
            $out = array();
            foreach ($teacherIds as $id) $out[(int) $id] = array('weighted'=>(float) (self::$weighted[(int) $id] ?? 0.0),'pending'=>0,'accepted'=>0,'confirmed'=>0,'completed'=>0);
            return $out;
        }
    }
    final class PortalNotificationRecorder {
        public static array $calls=array();
        public static function admin(string $event,string $kind,int $id,array $variables=array(),?string $url=null):?int { self::$calls[]=compact('event','kind','id','url');return $id; }
        public static function teacher(int $teacherId,string $event,string $kind,int $id,array $variables=array(),?string $url=null):?int { self::$calls[]=compact('teacherId','event','kind','id','url');return $id; }
    }
}
namespace Delnavazan\Platform\Core\Support {
    final class Identifier { public static function uid(): string { return '01TESTBOOKINGALLOCATION01'; } }
}
namespace {
    function admin_url(string $path=''): string { return 'https://academy.example/wp-admin/'.$path; }
    function home_url(string $path=''): string { return 'https://academy.example'.$path; }
    function do_action(string $hook, mixed ...$args): void {}
    require_once dirname(__DIR__).'/src/Core/Application/BookingLeadTimePolicy.php';
    require_once dirname(__DIR__).'/src/Core/Application/BookingLeadTimeException.php';
    require_once dirname(__DIR__).'/src/Core/Application/BookingContention.php';
    require_once dirname(__DIR__).'/src/Core/Application/BookingContentionException.php';
    require_once dirname(__DIR__).'/src/Core/Application/BookingOfferRetirementService.php';
    require_once dirname(__DIR__).'/src/Core/Application/BookingAllocationService.php';
    require_once dirname(__DIR__).'/src/Integrations/BookingAllocationWorker.php';
    $repo=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository();
    $service=new \Delnavazan\Platform\Core\Application\BookingAllocationService($repo,new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestMatchAssessmentRepository(),new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository($repo));
    $assert=static function(bool $ok,string $message): void { if(!$ok)throw new \RuntimeException($message); };
    $now='2026-11-02 00:00:00';
    $first=$service->startWithinTransaction(9,4,$repo->times(9),$now);
    $assert($first['teacher_id']===11 && $first['deadline_at']==='2026-11-03 00:00:00','First offer must have a 24-hour deadline.');
    $service->assertPending($repo->deliveryRow,'2026-11-02 23:59:59');
    try { $service->assertPending($repo->deliveryRow,'2026-11-03 00:00:00'); throw new \RuntimeException('Deadline was inclusive.'); }
    catch(\InvalidArgumentException) {}
    $second=$service->advanceLocked($repo->deliveryRow,'declined','2026-11-02 01:00:00');
    $assert($second['teacher_id']===22 && count($repo->offers)===2,'Decline must offer the next Teacher without closing the request.');
    $assert($repo->cursorId===11,'Only a new request advances the course cursor.');
    $assert($repo->lockedTeacherRoots===array(11,22,11,22),'Allocation must lock every eligible Teacher in a stable order before each offer decision.');
    \Delnavazan\Platform\Core\Application\BookingTeacherOccupancyReadService::$blockedTeachers=array(11,22);
    $next=$service->advanceLocked($repo->deliveryRow,'expired','2026-11-03 01:00:00');
    \Delnavazan\Platform\Core\Application\BookingTeacherOccupancyReadService::$blockedTeachers=array();
    $assert($next===null && $repo->deliveryRow->state==='expired' && $repo->referrals===array('teacher_became_unavailable'),'An expired offer to a Teacher who is no longer viable must refer with the unavailable reason instead of holding.');
    $service->process(9,'2026-11-09 22:00:00');
    $assert(in_array('escalation',$repo->alerts,true),'T−12h must raise the terminal escalation alert.');
    $assert(!in_array('unaccepted_after_72h',$repo->referrals,true),'The retired 72-hour referral branch must never run.');
    \Delnavazan\Platform\Integrations\BookingAllocationWorker::run();
    \Delnavazan\Platform\Integrations\BookingAllocationWorker::run();
    $notices=\Delnavazan\Platform\Core\Application\PortalNotificationRecorder::$calls;
    $assert(count($notices)===3 && $notices[0]['event']==='booking.referral.admin' && $notices[1]['event']==='booking.escalation.admin' && $notices[2]['event']==='booking.expired.teacher','Referral, escalation, and expiration must create one durable portal notice each.');
    $blockedRepo=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository();
    \Delnavazan\Platform\Core\Application\BookingTeacherOccupancyReadService::$blockedTeachers=array(11);
    $blockedService=new \Delnavazan\Platform\Core\Application\BookingAllocationService($blockedRepo,new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestMatchAssessmentRepository(),new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository($blockedRepo));
    $blocked=$blockedService->startWithinTransaction(10,4,$blockedRepo->times(10),$now);
    $assert($blocked['teacher_id']===22,'A pending or confirmed Teacher occupancy must exclude that Teacher across instruments.');

    // One lead-time authority: strictly later than 24h response + 1h projection.
    $assert(!\Delnavazan\Platform\Core\Application\BookingLeadTimePolicy::isStartAllowed('2026-11-03 00:59:00',$now),'24h59m notice must be refused.');
    $assert(!\Delnavazan\Platform\Core\Application\BookingLeadTimePolicy::isStartAllowed('2026-11-03 01:00:00',$now),'Exactly 25h notice must remain unavailable.');
    $assert(\Delnavazan\Platform\Core\Application\BookingLeadTimePolicy::isStartAllowed('2026-11-03 01:00:01',$now),'25h1s notice must be allowed.');
    $refusal=\Delnavazan\Platform\Core\Application\BookingLeadTimePolicy::refusal('2026-11-02 10:00:00',$now,'Asia/Tehran');
    $assert(is_array($refusal)&&$refusal['reason_code']==='lead_time_too_short'&&$refusal['timezone']==='Asia/Tehran'&&$refusal['minimum_start_local']==='2026-11-03 04:30','A short-notice refusal must explain the boundary in the Student timezone.');
    $assert(\Delnavazan\Platform\Core\Application\BookingLeadTimePolicy::isStartAllowed('2026-11-02 02:00:01',$now,false),'An existing offer acceptance only needs the projection buffer.');

    // A request that passes intake can cross the boundary before allocation runs.
    // The outcome must name that cause (retryable: choose later times) instead of
    // claiming every Teacher was considered, and it must use the same instant and
    // strictness as the grid and intake (see booking-engine-scenario-date-unit).
    $boundaryService=static function(\Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository $repo): \Delnavazan\Platform\Core\Application\BookingAllocationService {
        return new \Delnavazan\Platform\Core\Application\BookingAllocationService($repo,new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestMatchAssessmentRepository(),new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository($repo));
    };
    \Delnavazan\Platform\Core\Application\BookingTeacherOccupancyReadService::$blockedTeachers=array();
    $crossedRepo=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository();
    $crossedRepo->timeRows=array((object) array('id'=>201,'sequence_number'=>1,'starts_at_utc'=>'2026-11-02 16:00:00','occupied_ends_at_utc'=>'2026-11-02 17:00:00'));
    $crossed=$boundaryService($crossedRepo)->startWithinTransaction(31,4,$crossedRepo->times(31),$now);
    $assert($crossed===null&&$crossedRepo->referrals===array(\Delnavazan\Platform\Core\Application\BookingAllocationService::LEAD_TIME_REFERRAL_REASON),'A request whose start is exactly 4h before T−12h must be referred with the retryable lead-time reason.');
    $assert(in_array('referral',$crossedRepo->alerts,true)&&$crossedRepo->offers===array(),'The crossed-boundary referral must alert coordination without creating an offer.');
    $openRepo=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository();
    $openRepo->timeRows=array((object) array('id'=>202,'sequence_number'=>1,'starts_at_utc'=>'2026-11-02 16:00:01','occupied_ends_at_utc'=>'2026-11-02 17:00:01'));
    $open=$boundaryService($openRepo)->startWithinTransaction(32,4,$openRepo->times(32),$now);
    $assert($open!==null&&$open['teacher_id']===11,'One second past the offer-window boundary must still be offered at the same instant.');
    \Delnavazan\Platform\Core\Application\BookingTeacherOccupancyReadService::$blockedTeachers=array(11,22);
    $unavailableRepo=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository();
    $unavailable=$boundaryService($unavailableRepo)->startWithinTransaction(33,4,$unavailableRepo->times(33),$now);
    \Delnavazan\Platform\Core\Application\BookingTeacherOccupancyReadService::$blockedTeachers=array();
    $assert($unavailable===null&&$unavailableRepo->referrals===array('no_viable_teacher'),'A genuine availability failure must keep the existing no_viable_teacher reason.');
    $rotationRepo=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository();
    $rotationService=$boundaryService($rotationRepo);
    $rotationFirst=$rotationService->startWithinTransaction(34,4,$rotationRepo->times(34),$now);
    $rotation=$rotationService->advanceLocked($rotationRepo->deliveryRow,'declined','2026-11-09 20:00:00');
    $assert($rotationFirst!==null&&$rotation===null&&$rotationRepo->referrals===array(\Delnavazan\Platform\Core\Application\BookingAllocationService::LEAD_TIME_REFERRAL_REASON),'A decline whose requested time crossed the offer window must explain the lead time instead of reporting an exhausted candidate set.');

    // Transient InnoDB contention is retryable, never an authorization refusal.
    $assert(\Delnavazan\Platform\Core\Application\BookingContention::isLockFailure('Lock wait timeout exceeded; try restarting transaction'),'A lock-wait timeout must be classified as contention.');
    $assert(\Delnavazan\Platform\Core\Application\BookingContention::isLockFailure('Deadlock found when trying to get lock; try restarting transaction'),'A deadlock must be classified as contention.');
    $assert(!\Delnavazan\Platform\Core\Application\BookingContention::isLockFailure('Duplicate entry for key'),'A duplicate key must not be misclassified as contention.');
    $GLOBALS['wpdb']=(object)array('last_error'=>'Lock wait timeout exceeded');
    $captured=\Delnavazan\Platform\Core\Application\BookingContention::capture(new \RuntimeException('Allocation offer changed concurrently'),'teacher_busy');
    $assert($captured instanceof \Delnavazan\Platform\Core\Application\BookingContentionException&&$captured->errorCode()==='teacher_busy','A captured lock timeout must become a typed retryable error.');
    unset($GLOBALS['wpdb']);

    // A winning acceptance retires every overlapping pending offer to the same Teacher.
    $retireRepo=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository();
    \Delnavazan\Platform\Core\Application\BookingTeacherOccupancyReadService::$blockedTeachers=array();
    $retireService=new \Delnavazan\Platform\Core\Application\BookingAllocationService($retireRepo,new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestMatchAssessmentRepository(),new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository($retireRepo));
    $retireService->startWithinTransaction(11,4,$retireRepo->times(11),$now);
    $retireRepo->deliveryRow->starts_at_utc='2026-11-10 09:00:00';
    $retireRepo->deliveryRow->occupied_ends_at_utc='2026-11-10 10:00:00';
    $retireRepo->retireRows=array(array('offer_id'=>55,'booking_request_id'=>77,'delivery_id'=>88));
    $retireService->acceptedLocked($retireRepo->deliveryRow,$now);
    $assert($retireRepo->retireCalls===array(array(11,'2026-11-10 09:00:00','2026-11-10 10:00:00',11)),'Acceptance must retire competing offers for the exact winning Teacher and interval.');
    $assert(in_array('capacity_lost',$retireRepo->alerts,true),'Every retired competing offer must leave one capacity_lost alert.');

    // Public intake consumes the same policy: a 25h31m start is allowed, a 24h59m start is not.
    require_once dirname(__DIR__).'/src/Core/Application/AvailabilityLocalTime.php';
    require_once dirname(__DIR__).'/src/Core/Application/Normalizer.php';
    require_once dirname(__DIR__).'/src/Core/Application/RequestedTimeNormalizer.php';
    require_once dirname(__DIR__).'/src/Core/Application/BookingRequestValidationService.php';
    $validator=new \Delnavazan\Platform\Core\Application\BookingRequestValidationService();
    $instrument=(object)array('id'=>1,'status'=>'active','archived_at'=>null);
    $course=(object)array('id'=>2,'status'=>'active','archived_at'=>null,'course_type'=>'introductory','instrument_id'=>1,'default_duration_minutes'=>30,'default_buffer_minutes'=>15);
    $start=(new \DateTimeImmutable('tomorrow',new \DateTimeZone('UTC')))->setTime(12,0);
    $intake=array('course_id'=>2,'contact'=>array('timezone'=>'UTC'),'requested_times'=>array(array('local_date'=>$start->format('Y-m-d'),'local_start_time'=>'12:00','timezone'=>'UTC')));
    $accepted=$validator->resolveLockedCatalogue($intake,$instrument,$course,$start->modify('-26 hours')->format('Y-m-d H:i:s'));
    $assert(count($accepted['times'])===1,'Intake must accept a start beyond the shared minimum notice.');
    $shortNotice=false;
    try{$validator->resolveLockedCatalogue($intake,$instrument,$course,$start->modify('-1 hour')->format('Y-m-d H:i:s'));}
    catch(\Delnavazan\Platform\Core\Application\BookingLeadTimeException $error){$shortNotice=$error->reasonCode()==='lead_time_too_short';}
    $assert($shortNotice,'Intake must refuse a start inside the shared minimum notice.');

    // --- Approved allocation order: strength, then weighted workload, then round-robin ---
    $ordered = static function (array $workload, array $requestable = array()) use ($now): array {
        \Delnavazan\Platform\Core\Application\BookingTeacherWorkloadService::$weighted = $workload;
        \Delnavazan\Platform\Core\Application\TeacherAvailabilityService::$requestable = $requestable;
        $repo = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository();
        $service = new \Delnavazan\Platform\Core\Application\BookingAllocationService($repo, new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestMatchAssessmentRepository(), new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository($repo));
        return $service->startWithinTransaction(90, 4, $repo->times(90), $now);
    };

    $first = $ordered(array());
    $assert($first['teacher_id'] === 11, 'With equal strength and equal workload the course cursor selects the lowest eligible id.');

    $lighter = $ordered(array(11 => 2.0, 22 => 0.0));
    $assert($lighter['teacher_id'] === 22, 'The lower weighted workload must win over the lower Teacher id.');

    $stronger = $ordered(array(11 => 0.0, 22 => 9.0), array(11));
    $assert($stronger['teacher_id'] === 22, 'A strong match must win even when its weighted workload is higher than a possible match.');
    $assert($stronger['match_strength'] === 'strong', 'The selected candidate must report the strong match it was chosen for.');

    $weak = $ordered(array(11 => 0.0, 22 => 9.0));
    $assert($weak['teacher_id'] === 11 && $weak['match_strength'] === 'strong', 'Two strong matches fall back to weighted workload, not Teacher id.');

    // Determinism: identical inputs must always select the same Teacher.
    $repeatA = $ordered(array(11 => 1.25, 22 => 1.25));
    $repeatB = $ordered(array(11 => 1.25, 22 => 1.25));
    $assert($repeatA['teacher_id'] === $repeatB['teacher_id'], 'Equal strength and equal workload must resolve deterministically.');
    $assert($repeatA['teacher_id'] === 11, 'Equal strength and equal workload must fall through to the deterministic round-robin tie-break.');

    \Delnavazan\Platform\Core\Application\BookingTeacherWorkloadService::$weighted = array();
    \Delnavazan\Platform\Core\Application\TeacherAvailabilityService::$requestable = array();

    echo "Booking allocation unit passed\n";
}
