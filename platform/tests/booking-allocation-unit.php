<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository {
    final class BookingAllocationRepository {
        public ?object $deliveryRow = null;
        public ?object $active = null;
        public array $offers = array();
        public array $referrals = array();
        public array $alerts = array();
        public static array $pendingAlerts = array();
        public int $cursorId = 0;
        public function begin(): void {}
        public function commit(): void {}
        public function rollback(): void {}
        public function delivery(int $requestId, bool $lock = false): ?object { return $this->deliveryRow; }
        public function request(int $requestId, bool $lock = false): ?object { return (object) array('id'=>$requestId,'selected_intro_course_id'=>4,'lifecycle_status'=>'submitted','resolution_state'=>'unresolved','privacy_erased_at'=>null,'created_at'=>'2026-11-02 00:00:00'); }
        public function confirmationExists(int $requestId): bool { return false; }
        public function times(int $requestId): array { return array((object) array('id'=>101,'sequence_number'=>1,'starts_at_utc'=>'2026-11-10 09:00:00','occupied_ends_at_utc'=>'2026-11-10 10:00:00')); }
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
            $this->active=null;
        }
        public function rotateDelivery(object $delivery, array $candidate, string $now): int {
            $delivery->teacher_id=$candidate['teacher_id'];
            $delivery->requested_time_id=$candidate['requested_time_id'];
            return (int)$delivery->id;
        }
        public function referral(int $requestId, string $reason, string $now): void { $this->referrals[]=$reason; $this->alert($requestId,'referral',$reason,'normal',$now); }
        public function alert(int $requestId, string $kind, string $reason, string $priority, string $now): void {
            $this->alerts[]=$kind;
            self::$pendingAlerts[$kind]??=(object)array('id'=>count(self::$pendingAlerts)+1,'booking_request_id'=>$requestId,'kind'=>$kind,'reason_code'=>$reason);
        }
        public function dueRequests(string $now, int $limit=25): array { return array(); }
        public function unnotifiedAlerts(int $limit=25): array { return array_values(self::$pendingAlerts); }
        public function markAlertNotified(int $id, string $now): void { foreach(self::$pendingAlerts as $kind=>$row)if($row->id===$id)unset(self::$pendingAlerts[$kind]); }
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
namespace Delnavazan\Platform\Core\Application {
    final class TeacherAvailabilityService {
        public function effective(int $teacherId, string $start, string $end): array { return array(array('starts_at_utc'=>$start,'ends_at_utc'=>$end,'state'=>'preferred')); }
    }
    final class CanonicalTeacherOccupancyReadService {
        public function overlapping(int $teacherId, string $start, string $end): array { return array(); }
    }
    final class CanonicalContinuationCapacityAuthority {
        public static function assertNoActiveHold(int $teacherId, string $start, string $end): void {}
    }
    final class PortalNotificationRecorder {
        public static array $calls=array();
        public static function admin(string $event,string $kind,int $id,array $variables=array(),?string $url=null):?int { self::$calls[]=compact('event','kind','id','url');return $id; }
    }
}
namespace Delnavazan\Platform\Core\Support {
    final class Identifier { public static function uid(): string { return '01TESTBOOKINGALLOCATION01'; } }
}
namespace {
    function admin_url(string $path=''): string { return 'https://academy.example/wp-admin/'.$path; }
    function do_action(string $hook, mixed ...$args): void {}
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
    $next=$service->advanceLocked($repo->deliveryRow,'expired','2026-11-03 01:00:00');
    $assert($next===null && $repo->deliveryRow->state==='expired' && $repo->referrals===array('candidates_exhausted'),'Exhaustion must refer the request to an administrator.');
    $service->process(9,'2026-11-05 00:00:00');
    $assert(in_array('escalation',$repo->alerts,true) && in_array('unaccepted_after_72h',$repo->referrals,true),'72-hour escalation must use original submission time.');
    \Delnavazan\Platform\Integrations\BookingAllocationWorker::run();
    \Delnavazan\Platform\Integrations\BookingAllocationWorker::run();
    $notices=\Delnavazan\Platform\Core\Application\PortalNotificationRecorder::$calls;
    $assert(count($notices)===2 && $notices[0]['event']==='booking.referral.admin' && $notices[1]['event']==='booking.escalation.admin','Referral and escalation must create one durable administrator inbox notice each.');
    echo "Booking allocation unit passed\n";
}
