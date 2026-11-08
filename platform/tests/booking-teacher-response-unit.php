<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository {
    final class BookingTeacherResponseRepository {
        public object $teacher;
        public object $delivery;
        public ?object $connection;
        public ?object $request = null;
        public array $requestedTimes = array();
        public ?object $offer = null;
        public ?object $confirmationRequest = null;
        public array $commands = array();
        public array $confirmations = array();
        public array $notifications = array();
        public array $audits = array();
        public array $slotConflicts = array();
        public array $teacherConflicts = array();
        private int $nextConfirmation = 70;

        public function __construct() {
            $this->teacher = (object) array('id'=>9,'status'=>'active','archived_at'=>null,'timezone'=>'Australia/Brisbane','onboarding_state'=>'active','readiness_state'=>'ready');
            $this->delivery = (object) array('id'=>41,'uid'=>'01ARZ3NDEKTSV4RRFFQ69G5FAV','teacher_id'=>9,'booking_request_id'=>12,'requested_time_id'=>13,'match_strength'=>'possible','selected_intro_course_id'=>77,'state'=>'pending_request','lifecycle_status'=>'submitted','resolution_state'=>'unresolved','privacy_erased_at'=>null,'starts_at_utc'=>'2026-11-02 08:00:00','instructional_ends_at_utc'=>'2026-11-02 08:45:00','occupied_ends_at_utc'=>'2026-11-02 09:00:00','student_id'=>55);
            $this->connection = (object) array('id'=>5);
        }
        public function begin(): void {}
        public function commit(): void {}
        public function rollback(): void {}
        public function command(string $digest): ?object { return $this->commands[$digest] ?? null; }
        public function teacherForUser(int $userId,bool $lock=false): ?object { return $userId === 27 ? $this->teacher : null; }
        public function deliveryByUid(string $uid,bool $lock=false): ?object { return $uid === $this->delivery->uid ? $this->delivery : null; }
        public function requestByUid(string $uid,bool $lock=false): ?object { return $this->request && $uid === (string) $this->request->uid ? $this->request : null; }
        public function requestedTime(int $requestId,int $timeId,bool $lock=false): ?object { return $this->requestedTimes[$timeId] ?? null; }
        public function deliveryForRequest(int $requestId,bool $lock=false): ?object { return (int) $this->delivery->booking_request_id === $requestId ? $this->delivery : null; }
        public function offerForTeacher(int $requestId,int $teacherId,bool $lock=false): ?object { return $this->offer; }
        public function confirmationForRequest(int $requestId,bool $lock=false): ?object { return $this->confirmationRequest; }
        public function activeAllocationOffer(int $requestId,bool $lock=false): ?object { return $this->offer; }
        public function createDelivery(array $data): int { $this->delivery=(object) array_merge((array) $this->delivery,$data,array('id'=>41)); return 41; }
        public function repointDelivery(int $id,int $teacherId,int $requestedTimeId,string $uid,string $matchStrength,string $now): void { $this->delivery->uid=$uid;$this->delivery->teacher_id=$teacherId;$this->delivery->requested_time_id=$requestedTimeId;$this->delivery->match_strength=$matchStrength;$this->delivery->state='pending_request';$this->selectTimeFields($requestedTimeId); }
        public function switchOfferedTime(int $deliveryId,int $offerId,int $requestedTimeId,string $now): void { $this->delivery->requested_time_id=$requestedTimeId;if($this->offer)$this->offer->requested_time_id=$requestedTimeId;$this->selectTimeFields($requestedTimeId); }
        public function insertClaimOffer(int $requestId,int $deliveryId,int $teacherId,int $requestedTimeId,string $now,string $deadline): int { $this->offer=(object) array('id'=>88,'booking_request_id'=>$requestId,'delivery_id'=>$deliveryId,'teacher_id'=>$teacherId,'requested_time_id'=>$requestedTimeId,'state'=>'pending','active_slot'=>1,'deadline_at'=>$deadline); return 88; }
        public function auditByDigest(string $digest): ?object { return $this->audits[$digest] ?? null; }
        public function insertAudit(array $data): int { $this->audits[$data['idempotency_key']]=(object) $data; return count($this->audits); }
        public function activeConnection(int $teacherId,bool $lock=false): ?object { return $teacherId === 9 ? $this->connection : null; }
        public function transitionDelivery(int $id,string $from,string $to,string $now): void { if ($id !== 41 || $this->delivery->state !== $from) throw new \RuntimeException('transition mismatch'); $this->delivery->state = $to; }
        public function closeRequest(int $id,string $resolution,string $now,int $actor): void { $this->delivery->lifecycle_status='closed';$this->delivery->resolution_state=$resolution; }
        public function insertConfirmation(array $data): int { $id=$this->nextConfirmation++;$this->confirmations[$id]=(object)($data+array('id'=>$id));return $id; }
        public function insertCommand(array $data): int { $this->commands[$data['command_key_digest']] = (object) $data; return count($this->commands); }
        public function confirmation(int $id,bool $lock=false): ?object { return $this->confirmations[$id] ?? null; }
        public function confirmationForDelivery(int $deliveryId,bool $lock=false): ?object { foreach($this->confirmations as $row)if((int)$row->delivery_id===$deliveryId)return $row;return null; }
        public function ensureAndLockStudentRoot(int $studentId,string $now,int $actor): void {}
        public function ensureAndLockTeacherRoot(int $teacherId,string $now,int $actor): void {}
        public function teacherOccupancyConflicts(int $teacherId,string $start,string $end,int $exclude=0): array { return $this->teacherConflicts; }
        public function studentOccupancyConflicts(int $studentId,array $times,array $options=array()): array { return $this->slotConflicts; }
        public function updateConfirmation(int $id,int $version,array $data): void { $row=$this->confirmations[$id]??null;if(!$row||(int)$row->version!==$version)throw new \RuntimeException('confirmation version mismatch');foreach($data as $key=>$value)$row->{$key}=$value;$row->version=$version+1; }
        public function notificationForDeliveryKind(int $deliveryId,string $kind): ?object { return $this->notifications[$deliveryId.'|'.$kind] ?? null; }
        public function insertNotification(array $data): int { $this->notifications[$data['delivery_id'].'|'.$data['notification_kind']] = (object) $data; return count($this->notifications); }
        private function selectTimeFields(int $requestedTimeId): void { $time=$this->requestedTimes[$requestedTimeId]??null;if(!$time)return;foreach(array('starts_at_utc','instructional_ends_at_utc','occupied_ends_at_utc') as $field)if(isset($time->{$field}))$this->delivery->{$field}=$time->{$field}; }
    }
}
namespace Delnavazan\Platform\Core\Support {
    final class Identifier { public static function uid(): string { return '01ARZ3NDEKTSV4RRFFQ69G5FAA'; } }
}
namespace Delnavazan\Platform\Core\Application {
    final class IdempotencyConflictException extends \RuntimeException {}
    final class BookingAllocationService {
        public const RESPONSE_SECONDS = 86400;
        public static array $acceptableCalls = array();
        public static bool $acceptableResult = false;
        public static function resetAcceptableCalls(): void { self::$acceptableCalls = array(); self::$acceptableResult = false; }
        public static function addSeconds(string $utc,int $seconds): string { return (new \DateTimeImmutable($utc,new \DateTimeZone('UTC')))->modify(($seconds>=0?'+':'').$seconds.' seconds')->format('Y-m-d H:i:s'); }
        public function assertPending(object $delivery,string $now):void{}
        public function assertAcceptable(object $delivery,string $now,?int $requestedTimeId=null,bool $allowOutsideAvailability=false):bool{
            self::$acceptableCalls[]=array('delivery_id'=>(int)$delivery->id,'requested_time_id'=>$requestedTimeId,'allow_outside'=>$allowOutsideAvailability);
            return self::$acceptableResult;
        }
        public function acceptedLocked(object $delivery,string $now):void{}
        public function administratorAcceptedLocked(object $delivery,string $now):void{}
        public function advanceLocked(object $delivery,string $outcome,string $now):?array{$delivery->state=$outcome;return null;}
    }
}
namespace {
    function get_current_user_id(): int { return 27; }
    function wp_salt(string $scheme='auth'): string { return 'booking-response-unit-test-salt-material'; }
    function do_action(string $hook, ...$args): void {}

    require_once dirname(__DIR__) . '/src/Core/Application/BookingTeacherResponseService.php';
    $assert = static function(bool $ok,string $message): void { if(!$ok)throw new \RuntimeException($message); };
    $repository = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $freePricing = static fn(int $id): array => array('configured'=>false,'mode'=>'free','amount_minor'=>0,'currency'=>null,'region_code'=>null);
    $service = new \Delnavazan\Platform\Core\Application\BookingTeacherResponseService($repository,null,$freePricing);
    $accepted = $service->respond($repository->delivery->uid,'accept','accept-once');
    $assert($accepted['state']==='accepting' && $repository->delivery->state==='accepting','Acceptance must enter provider projection before confirmation.');
    $confirmationId=(int)$accepted['confirmation_id'];
    $repository->confirmations[$confirmationId]->state='confirmed';
    $replay=$service->respond($repository->delivery->uid,'accept','accept-once');
    $assert($replay['idempotent']===true && $replay['state']==='confirmed','Replay must report the live confirmed state.');
    try{$service->respond($repository->delivery->uid,'decline','accept-once');throw new \RuntimeException('Conflicting replay was accepted.');}catch(\Delnavazan\Platform\Core\Application\IdempotencyConflictException){}

    $declineRepository = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $declineRepository->teacher->onboarding_state='in_progress';$declineRepository->teacher->readiness_state='not_ready';$declineRepository->connection=null;
    $declined=(new \Delnavazan\Platform\Core\Application\BookingTeacherResponseService($declineRepository,null,$freePricing))->respond($declineRepository->delivery->uid,'decline','decline-once');
    $assert($declined['state']==='declined' && $declineRepository->delivery->resolution_state==='unresolved','A decline must leave the request open for the next Teacher or administrator.');
    $assert(!isset($declineRepository->notifications['41|declined']),'A decline must not tell the Student that the entire request failed.');

    $restartRepository = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $first=(new \Delnavazan\Platform\Core\Application\BookingTeacherResponseService($restartRepository,null,$freePricing))->respond($restartRepository->delivery->uid,'accept','restart-first');
    $restartRepository->confirmations[$first['confirmation_id']]->state='failed';
    $reacceptRefused=false;
    try{(new \Delnavazan\Platform\Core\Application\BookingTeacherResponseService($restartRepository,null,$freePricing))->respond($restartRepository->delivery->uid,'accept','restart-second');}
    catch(\InvalidArgumentException $error){$reacceptRefused=$error->getMessage()==='Booking request is no longer pending';}
    $assert($reacceptRefused&&count($restartRepository->confirmations)===1&&$restartRepository->delivery->state==='accepting','A provisioning failure must not reopen the accepted delivery or ask the Teacher to accept again.');
    $assert($restartRepository->delivery->resolution_state==='unresolved','The accepted request must remain unresolved until classroom recovery succeeds.');

    $paidRepository = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $paidRepository->delivery->student_id = 55;
    $paidRepository->delivery->selected_intro_course_id = 77;
    $paidPricing = static fn(int $id): array => array('configured'=>true,'mode'=>'paid','amount_minor'=>4000,'currency'=>'AUD','region_code'=>'AU');
    $paidPayment = static fn(array $context,string $key,bool $within): array => array('obligation_id'=>1,'uid'=>'01ARZ3NDEKTSV4RRFFQ69G5FAB','reference'=>'DZN-INTRO-01ARZ3NDEKTSV4RRFFQ69G5FAB','state'=>'pending','amount_minor'=>4000,'currency'=>'AUD','created'=>true);
    $paid = (new \Delnavazan\Platform\Core\Application\BookingTeacherResponseService($paidRepository,null,$paidPricing,$paidPayment))->respond($paidRepository->delivery->uid,'accept','paid-accept-once');
    $assert($paid['state']==='awaiting_payment' && ($paid['payment_required']??false)===true,'Paid acceptance must stop at the payment gate.');
    $assert($paidRepository->confirmations[$paid['confirmation_id']]->state==='awaiting_payment','Paid confirmation must not be projectable before payment.');
    $assert($paidRepository->delivery->state==='accepting','Paid acceptance must retain the accepted delivery while awaiting payment.');

    $conflictRepository = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $conflictRepository->slotConflicts = array(array('source'=>'booking_request','conflict_id'=>99));
    $conflictRefused = false;
    try { (new \Delnavazan\Platform\Core\Application\BookingTeacherResponseService($conflictRepository,null,$freePricing))->respond($conflictRepository->delivery->uid,'accept','conflict-accept-once'); }
    catch (\InvalidArgumentException $error) { $conflictRefused = $error->getMessage() === 'student_slot_conflict'; }
    $assert($conflictRefused,'An overlapping Student booking must refuse acceptance with student_slot_conflict.');
    $assert($conflictRepository->confirmations===array() && $conflictRepository->delivery->state==='pending_request','Refused overlap must not create a confirmation or consume the delivery.');
    $teacherConflictRepository = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $teacherConflictRepository->teacherConflicts = array(array('source'=>'booking_offer','conflict_id'=>98));
    $teacherConflictRefused = false;
    try { (new \Delnavazan\Platform\Core\Application\BookingTeacherResponseService($teacherConflictRepository,null,$freePricing))->respond($teacherConflictRepository->delivery->uid,'accept','teacher-conflict-accept-once'); }
    catch (\InvalidArgumentException $error) { $teacherConflictRefused = $error->getMessage() === 'teacher_slot_conflict'; }
    $assert($teacherConflictRefused,'An overlapping Teacher booking must refuse acceptance before creating a confirmation.');
    $assert($teacherConflictRepository->confirmations===array() && $teacherConflictRepository->delivery->state==='pending_request','Teacher conflict must retain the pending offer for manual resolution.');

    // A Teacher may accept a different explicit Student preference. The selected preference is
    // switched onto the delivery and offer, and the availability override is audited.
    $altRepository = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $altRepository->requestedTimes[13]=(object)array('id'=>13,'starts_at_utc'=>'2026-11-02 08:00:00','instructional_ends_at_utc'=>'2026-11-02 08:45:00','occupied_ends_at_utc'=>'2026-11-02 09:00:00');
    $altRepository->requestedTimes[14]=(object)array('id'=>14,'starts_at_utc'=>'2026-11-04 08:00:00','instructional_ends_at_utc'=>'2026-11-04 08:45:00','occupied_ends_at_utc'=>'2026-11-04 09:00:00');
    $altRepository->offer=(object)array('id'=>88,'booking_request_id'=>12,'delivery_id'=>41,'teacher_id'=>9,'requested_time_id'=>13,'state'=>'pending','active_slot'=>1,'deadline_at'=>'2026-12-01 00:00:00');
    \Delnavazan\Platform\Core\Application\BookingAllocationService::resetAcceptableCalls();
    \Delnavazan\Platform\Core\Application\BookingAllocationService::$acceptableResult=true;
    $alt=(new \Delnavazan\Platform\Core\Application\BookingTeacherResponseService($altRepository,null,$freePricing))->respond($altRepository->delivery->uid,'accept','alt-preference-once',14);
    $assert((int)$altRepository->delivery->requested_time_id===14 && (int)$altRepository->offer->requested_time_id===14,'Accepting an alternative preference must switch the offered time.');
    $altConfirmation=$altRepository->confirmations[(int)$alt['confirmation_id']];
    $assert((int)$altConfirmation->requested_time_id===14 && (string)$altConfirmation->starts_at_utc==='2026-11-04 08:00:00','The confirmation must use the explicitly selected preference.');
    $altCalls=\Delnavazan\Platform\Core\Application\BookingAllocationService::$acceptableCalls;
    $assert(count($altCalls)===1 && $altCalls[0]['requested_time_id']===14 && $altCalls[0]['allow_outside']===true,'An alternative preference must request an explicit availability override.');
    $altAudit=array_values(array_filter($altRepository->audits,static fn($row)=>(string)$row->event_type==='teacher_booking_response_availability_override' && (string)$row->reason_code==='teacher_selected_preference'));
    $assert(count($altAudit)===1,'An alternative preference accepted outside availability must be audited.');
    \Delnavazan\Platform\Core\Application\BookingAllocationService::resetAcceptableCalls();

    // Offering the originally offered time keeps strict availability: no override is requested.
    $strictRepository = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $strictRepository->offer=(object)array('id'=>89,'booking_request_id'=>12,'delivery_id'=>41,'teacher_id'=>9,'requested_time_id'=>13,'state'=>'pending','active_slot'=>1,'deadline_at'=>'2026-12-01 00:00:00');
    \Delnavazan\Platform\Core\Application\BookingAllocationService::resetAcceptableCalls();
    (new \Delnavazan\Platform\Core\Application\BookingTeacherResponseService($strictRepository,null,$freePricing))->respond($strictRepository->delivery->uid,'accept','strict-preference-once');
    $strictCalls=\Delnavazan\Platform\Core\Application\BookingAllocationService::$acceptableCalls;
    $assert(count($strictCalls)===1 && $strictCalls[0]['requested_time_id']===13 && $strictCalls[0]['allow_outside']===false,'The originally offered time must keep strict availability.');
    $assert($strictRepository->audits===array(),'No availability override audit is written for the originally offered time.');
    \Delnavazan\Platform\Core\Application\BookingAllocationService::resetAcceptableCalls();

    // A Teacher can claim an unmatched request for an eligible course. The request appears in the
    // unmatched section precisely because no available Teacher matched, so the chosen preference
    // is an explicit availability exception and the claim is audited as such.
    $claimRepository = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $claimStart=time()+172800;
    $claimRepository->request=(object)array('id'=>12,'uid'=>'01ARZ3NDEKTSV4RRFFQ69G5FAW','lifecycle_status'=>'submitted','resolution_state'=>'unresolved','privacy_erased_at'=>null,'student_id'=>55,'selected_intro_course_id'=>77);
    $claimRepository->requestedTimes[14]=(object)array('id'=>14,'starts_at_utc'=>gmdate('Y-m-d H:i:s',$claimStart),'instructional_ends_at_utc'=>gmdate('Y-m-d H:i:s',$claimStart+2700),'occupied_ends_at_utc'=>gmdate('Y-m-d H:i:s',$claimStart+3600));
    \Delnavazan\Platform\Core\Application\BookingAllocationService::resetAcceptableCalls();
    \Delnavazan\Platform\Core\Application\BookingAllocationService::$acceptableResult=true;
    $claim=(new \Delnavazan\Platform\Core\Application\BookingTeacherResponseService($claimRepository,null,$freePricing))->claim($claimRepository->request->uid,14,'claim-once');
    $assert($claimRepository->delivery->state==='accepting' && (int)$claimRepository->delivery->teacher_id===9,'A claim must create the delivery and enter provider projection.');
    $assert((int)$claimRepository->delivery->requested_time_id===14 && (int)$claimRepository->delivery->booking_request_id===12,'A claim must bind the request to the selected preference.');
    $assert($claimRepository->offer!==null && (int)$claimRepository->offer->requested_time_id===14 && (string)$claimRepository->offer->state==='pending','A claim must insert the active claim offer.');
    $claimConfirmation=$claimRepository->confirmations[(int)$claim['confirmation_id']];
    $assert((int)$claimConfirmation->requested_time_id===14 && (int)$claimConfirmation->teacher_id===9,'A claim must create the confirmation for the claiming Teacher.');
    $claimCalls=\Delnavazan\Platform\Core\Application\BookingAllocationService::$acceptableCalls;
    $assert(count($claimCalls)===1 && $claimCalls[0]['allow_outside']===true && $claimCalls[0]['requested_time_id']===14,'A claim must allow outside availability while still revalidating occupancy.');
    $claimAudit=array_values(array_filter($claimRepository->audits,static fn($row)=>(string)$row->event_type==='teacher_booking_claim'));
    $assert(count($claimAudit)===1 && (string)$claimAudit[0]->reason_code==='teacher_claim_availability_override','A claim outside availability must be audited as an override.');
    \Delnavazan\Platform\Core\Application\BookingAllocationService::resetAcceptableCalls();

    // A claim whose chosen preference happens to be inside availability records the ordinary claim
    // reason while still using the explicit override-capable claim path.
    $insideClaimRepository = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $insideStart=time()+259200;
    $insideClaimRepository->request=$claimRepository->request;
    $insideClaimRepository->requestedTimes[14]=(object)array('id'=>14,'starts_at_utc'=>gmdate('Y-m-d H:i:s',$insideStart),'instructional_ends_at_utc'=>gmdate('Y-m-d H:i:s',$insideStart+2700),'occupied_ends_at_utc'=>gmdate('Y-m-d H:i:s',$insideStart+3600));
    \Delnavazan\Platform\Core\Application\BookingAllocationService::resetAcceptableCalls();
    \Delnavazan\Platform\Core\Application\BookingAllocationService::$acceptableResult=false;
    (new \Delnavazan\Platform\Core\Application\BookingTeacherResponseService($insideClaimRepository,null,$freePricing))->claim($insideClaimRepository->request->uid,14,'claim-inside-once');
    $insideAudit=array_values(array_filter($insideClaimRepository->audits,static fn($row)=>(string)$row->event_type==='teacher_booking_claim'));
    $assert(count($insideAudit)===1 && (string)$insideAudit[0]->reason_code==='teacher_claim','A claim inside availability must record the ordinary claim reason.');
    \Delnavazan\Platform\Core\Application\BookingAllocationService::resetAcceptableCalls();

    // A request that already has a live offer cannot be claimed a second time.
    $claimBlockedRepository = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $claimBlockedRepository->request=$claimRepository->request;
    $claimBlockedRepository->requestedTimes=$claimRepository->requestedTimes;
    $claimBlockedRepository->offer=(object)array('id'=>90,'booking_request_id'=>12,'teacher_id'=>8,'requested_time_id'=>14,'state'=>'pending','active_slot'=>1,'deadline_at'=>'2026-12-01 00:00:00');
    $claimBlocked=false;
    try{(new \Delnavazan\Platform\Core\Application\BookingTeacherResponseService($claimBlockedRepository,null,$freePricing))->claim($claimBlockedRepository->request->uid,14,'claim-blocked-once');}
    catch(\InvalidArgumentException $error){$claimBlocked=$error->getMessage()==='Booking request is no longer available';}
    $assert($claimBlocked && $claimBlockedRepository->confirmations===array(),'An already-offered request must not be claimed again.');
    echo "Booking teacher response unit passed\n";
}
