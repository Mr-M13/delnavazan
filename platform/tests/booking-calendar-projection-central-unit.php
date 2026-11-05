<?php
namespace Delnavazan\Platform\Core\Application {
    final class IntegrationSecretService { public function open(int $connectionId,string $providerCode,object|array $row): string { return '{"access_token":"test"}'; } }
    final class ProviderReferenceVault {
        public function seal(string $kind,int $mappingId,array $references): array { return array('key_version'=>'test','cipher_version'=>'test','nonce'=>'test','ciphertext'=>'test','references'=>$references); }
        public function open(string $kind,int $mappingId,object|array $row): array { return (array)($row->references ?? array()); }
    }
    final class ProviderIntegrationIdempotency { public static function subject(string $reference,string $purpose): string { return hash('sha256',$purpose.':'.$reference); } }
}
namespace Delnavazan\Platform\Core\Support {
    final class Identifier { public static function uid(): string { return '01ARZ3NDEKTSV4RRFFQ69G5FAA'; } }
}
namespace Delnavazan\Platform\Integrations\GoogleWorkspace {
    final class GoogleWorkspaceClassroomService {
        public static bool $configured=true;
        public static bool $enabled=true;
        public static ?string $throws=null;
        public static array $calls=array();
        public static function enabled(): bool { return self::$enabled; }
        public static function organizerConnected(): bool { return self::$configured; }
        public static function configured(): bool { return self::$enabled && self::$configured; }
        public function ensureForBooking(int $id): array {
            self::$calls[]=array('ensureForBooking',$id);
            if(self::$throws!==null)throw new \RuntimeException(self::$throws);
            return array('classroom_id'=>9,'classroom_uid'=>'CLASS-9','state'=>'ready','provider_object_reference'=>str_repeat('a',32),'join_uri_reference'=>'https://meet.google.com/abc-defg-hij','cohost_state'=>'verified','idempotent'=>false);
        }
        public function rescheduleForBooking(int $id,string $start,string $end,string $tz): array {
            self::$calls[]=array('rescheduleForBooking',$id,$start,$end,$tz);
            return array('provider_object_reference'=>str_repeat('a',32),'join_uri_reference'=>'https://meet.google.com/abc-defg-hij');
        }
        public function cancelForBooking(int $id): array { self::$calls[]=array('cancelForBooking',$id);return array('cancelled'=>true,'classroom_id'=>9); }
    }
}
namespace Delnavazan\Platform\Core\Infrastructure\Repository {
    final class BookingTeacherResponseRepository {
        public object $confirmationRow;
        public object $deliveryRow;
        public object $credentialRow;
        public ?object $secretRow=null;
        public ?object $activeOffer=null;
        public array $notifications=array();
        public int $secrets=0;
        public function __construct(){
            $this->confirmationRow=(object)array('id'=>70,'uid'=>'01ARZ3NDEKTSV4RRFFQ69G5FAV','booking_request_id'=>12,'delivery_id'=>41,'teacher_id'=>10,'requested_time_id'=>5,'connection_id'=>0,'state'=>'pending_projection','version'=>1,'starts_at_utc'=>gmdate('Y-m-d H:i:s',time()+86400),'ends_at_utc'=>gmdate('Y-m-d H:i:s',time()+88200),'schedule_timezone'=>'Australia/Brisbane','attempt_count'=>0,'failure_reason_code'=>null);
            $this->deliveryRow=(object)array('id'=>41,'booking_request_id'=>12,'teacher_id'=>10,'requested_time_id'=>5,'state'=>'accepting','lifecycle_status'=>'submitted','resolution_state'=>'unresolved','privacy_erased_at'=>null);
            $this->credentialRow=(object)array('valid_until_utc'=>null);
        }
        public function confirmation(int $id,bool $lock=false): ?object { return $id===70?$this->confirmationRow:null; }
        public function activeAllocationOffer(int $requestId,bool $lock=false): ?object { return $this->activeOffer; }
        public function credential(int $connectionId): ?object { return null; }
        public function projectionSecret(int $confirmationId): ?object { return $confirmationId===70?$this->secretRow:null; }
        public function begin(): void {}
        public function commit(): void {}
        public function rollback(): void {}
        public function insertSecret(array $data): int { $this->secrets++;$this->secretRow=(object)($data+array('id'=>91));return 91; }
        public function retireSecret(int $id,int $actor,string $now): void {}
        public function updateConfirmation(int $id,int $version,array $data): void { if($id!==70||$version!==(int)$this->confirmationRow->version)throw new \RuntimeException('confirmation version mismatch');foreach($data as $key=>$value)$this->confirmationRow->{$key}=$value;$this->confirmationRow->version=$version+1; }
        public function delivery(int $id,bool $lock=false): ?object { return $id===41?$this->deliveryRow:null; }
        public function transitionDelivery(int $id,string $from,string $to,string $now): void { if($id!==41||$this->deliveryRow->state!==$from)throw new \RuntimeException('delivery transition mismatch');$this->deliveryRow->state=$to; }
        public function closeRequest(int $id,string $resolution,string $now,int $actor): void { $this->deliveryRow->lifecycle_status='closed';$this->deliveryRow->resolution_state=$resolution; }
        public function notificationForDeliveryKind(int $deliveryId,string $kind): ?object { return $this->notifications[$deliveryId.'|'.$kind]??null; }
        public function insertNotification(array $data): int { $this->notifications[$data['delivery_id'].'|'.$data['notification_kind']]=(object)$data;return count($this->notifications); }
    }
    final class BookingAllocationRepository {
        public static array $referrals=array();
        public function referral(int $requestId,string $reason,string $now): void { self::$referrals[]=array($requestId,$reason,$now); }
    }
}
namespace Delnavazan\Platform\Integrations {
    final class GoogleCalendarProjectionTransport {
        public function dispatch(array $request,string $credentialMaterial): array { throw new \RuntimeException('Legacy transport must not be used for the central path.'); }
        public function inspect(string $eventId,string $credentialMaterial): array { throw new \RuntimeException('Legacy transport must not be used for the central path.'); }
        public function revise(string $eventId,string $start,string $end,string $tz,string $material): array { throw new \RuntimeException('Legacy transport must not be used for the central path.'); }
        public function cancel(string $eventId,string $material): void { throw new \RuntimeException('Legacy transport must not be used for the central path.'); }
    }
    final class BookingGoogleCredentialRefresher { public function refresh(int $connectionId,int $confirmationId,int $attempt): bool { throw new \RuntimeException('Legacy credential refresh must not run for the central path.'); } }
}
namespace {
    function get_current_user_id(): int { return 27; }
    require_once dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/GoogleWorkspaceClassroomRule.php';
    require_once dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/MeetCoHostRule.php';
    require_once dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/MeetProvisioningRule.php';
    require_once dirname(__DIR__) . '/src/Core/Application/BookingProvisioningRecoveryRule.php';
    require_once dirname(__DIR__) . '/src/Integrations/BookingCalendarProjectionService.php';

    use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository;
    use Delnavazan\Platform\Integrations\BookingCalendarProjectionService;
    use Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomService as Central;

    $assert=static function(bool $ok,string $message):void{if(!$ok)throw new \RuntimeException($message);};

    $repo=new BookingTeacherResponseRepository();
    $service=new BookingCalendarProjectionService($repo);
    $confirmed=$service->dispatch(70);
    $assert($confirmed['state']==='confirmed'&&$repo->deliveryRow->state==='confirmed','Central provider result must confirm the booking.');
    $assert(Central::$calls===array(array('ensureForBooking',70)),'Central ensureForBooking must run exactly once.');
    $assert($repo->secrets===0,'A central booking must not create a duplicate legacy teacher-calendar secret.');
    $assert(isset($repo->notifications['41|confirmed']),'Central confirmation must queue exactly one student notification.');

    $rescheduleRepo=new BookingTeacherResponseRepository();$rescheduleRepo->confirmationRow->state='confirmed';
    $rescheduled=(new BookingCalendarProjectionService($rescheduleRepo))->reschedule(70,'2026-11-03 08:00:00','2026-11-03 08:45:00','Australia/Brisbane');
    $assert($rescheduled['state']==='confirmed'&&in_array(array('rescheduleForBooking',70,'2026-11-03 08:00:00','2026-11-03 08:45:00','Australia/Brisbane'),Central::$calls,true),'Central reschedule must patch the organizer event without a legacy vault.');
    $cancelRepo=new BookingTeacherResponseRepository();$cancelRepo->confirmationRow->state='confirmed';
    $cancelled=(new BookingCalendarProjectionService($cancelRepo))->cancel(70);
    $assert($cancelled['state']==='cancelled'&&in_array(array('cancelForBooking',70),Central::$calls,true),'Central cancellation must cancel the organizer event without a legacy vault.');

    Central::$calls=array();Central::$throws='meet_cohost_assignment_blocked';
    $permanentRepo=new BookingTeacherResponseRepository();
    $permanent=(new BookingCalendarProjectionService($permanentRepo))->dispatch(70);
    $assert($permanent['state']==='failed'&&$permanentRepo->confirmationRow->state==='failed','A permanent co-host refusal must fail immediately, not loop six times.');
    $assert($permanentRepo->deliveryRow->state==='accepting','A provisioning failure must preserve the Teacher acceptance in the accepting state.');
    $assert($permanentRepo->deliveryRow->lifecycle_status==='submitted'&&$permanentRepo->deliveryRow->resolution_state==='unresolved','A provisioning failure must not reopen or resolve the booking request.');

    Central::$throws='meet_conference_pending';
    $transientRepo=new BookingTeacherResponseRepository();
    $transient=(new BookingCalendarProjectionService($transientRepo))->dispatch(70);
    $assert($transient['state']==='retryable'&&$transientRepo->confirmationRow->state==='retryable','A pending Meet conference must remain retryable.');
    $assert(!empty($transientRepo->confirmationRow->next_attempt_at),'A retryable failure must carry a durable next-attempt instant.');
    $assert($transientRepo->deliveryRow->state==='accepting','A transient failure must preserve the accepted delivery.');

    Central::$throws=null;Central::$calls=array();Central::$enabled=false;
    $pausedRepo=new BookingTeacherResponseRepository();
    $paused=(new BookingCalendarProjectionService($pausedRepo))->dispatch(70);
    $assert($paused['state']==='retryable'&&$pausedRepo->confirmationRow->state==='retryable','A paused organizer must keep the accepted booking retryable.');
    $assert($pausedRepo->deliveryRow->state==='accepting','A paused organizer must not reverse Teacher acceptance.');
    $assert(Central::$calls===array()&&$pausedRepo->secrets===0,'A paused organizer must not call Google or create a legacy vault secret.');

    Central::$enabled=true;Central::$configured=false;
    $offlineRepo=new BookingTeacherResponseRepository();
    $offline=(new BookingCalendarProjectionService($offlineRepo))->dispatch(70);
    $assert($offline['state']==='retryable'&&$offlineRepo->confirmationRow->state==='retryable','A re-authorising organizer must keep the accepted booking retryable.');

    Central::$configured=true;Central::$calls=array();Central::$throws=null;
    $recoveryRepo=new BookingTeacherResponseRepository();
    $recoveryRepo->confirmationRow->state='failed';$recoveryRepo->confirmationRow->attempt_count=6;
    $recoveryRepo->deliveryRow->state='pending_request';
    $recovered=(new BookingCalendarProjectionService($recoveryRepo))->retryFailed(70);
    $assert($recovered['state']==='confirmed'&&$recoveryRepo->deliveryRow->state==='confirmed','Administrator retry must resume a terminal booking without another Teacher acceptance.');
    $assert(Central::$calls===array(array('ensureForBooking',70)),'Administrator retry must use the same central provisioning path.');

    // A still-refused co-host remains terminal and never invents a link.
    Central::$calls=array();Central::$throws='meet_cohost_assignment_blocked';
    $blockedRepo=new BookingTeacherResponseRepository();
    $blockedRepo->confirmationRow->state='failed';$blockedRepo->confirmationRow->attempt_count=6;
    $blockedRepo->deliveryRow->state='pending_request';
    $blocked=(new BookingCalendarProjectionService($blockedRepo))->retryFailed(70);
    $assert($blocked['state']==='failed'&&$blockedRepo->confirmationRow->state==='failed','A still-refused co-host must remain terminal.');
    $assert($blockedRepo->deliveryRow->state==='accepting','A still-refused booking must keep the accepted delivery recoverable.');
    $assert(Central::$calls===array(array('ensureForBooking',70)),'A refused retry must call the central provisioning path exactly once.');

    // After policy correction the same terminal record can recover once, idempotently.
    Central::$calls=array();Central::$throws=null;
    $recoveredBlocked=(new BookingCalendarProjectionService($blockedRepo))->retryFailed(70);
    $assert($recoveredBlocked['state']==='confirmed'&&$blockedRepo->confirmationRow->state==='confirmed','A corrected policy must recover the same terminal booking.');
    $assert(Central::$calls===array(array('ensureForBooking',70)),'Corrected-policy recovery must call provisioning exactly once.');

    // A duplicate/concurrent retry must not dispatch a second provisioning attempt.
    $concurrentRepo=new BookingTeacherResponseRepository();
    $concurrentRepo->confirmationRow->state='failed';$concurrentRepo->confirmationRow->attempt_count=6;
    $concurrentRepo->deliveryRow->state='pending_request';
    Central::$calls=array();Central::$throws=null;
    $first=(new BookingCalendarProjectionService($concurrentRepo))->retryFailed(70);
    $secondRejected=false;
    try{(new BookingCalendarProjectionService($concurrentRepo))->retryFailed(70);}
    catch(InvalidArgumentException $error){$secondRejected=$error->getMessage()==='Booking confirmation is not failed';}
    $assert($first['state']==='confirmed'&&$secondRejected,'A second retry after confirmation must be rejected.');
    $assert(Central::$calls===array(array('ensureForBooking',70)),'Concurrent/duplicate retries must not dispatch twice.');


    // A terminal space-lookup code must not be treated as a retryable booking failure.
    Central::$calls=array();Central::$throws='meet_space_lookup_refused';
    $spaceRepo=new BookingTeacherResponseRepository();
    $spaceRepo->confirmationRow->state='retryable';$spaceRepo->confirmationRow->attempt_count=7;
    $spaceRepo->deliveryRow->state='accepting';
    $spaceResult=(new BookingCalendarProjectionService($spaceRepo))->dispatch(70);
    $assert($spaceResult['state']==='failed'&&$spaceRepo->confirmationRow->state==='failed','A terminal space-lookup failure must leave the booking failed.');
    $assert($spaceRepo->confirmationRow->next_attempt_at===null,'A terminal space-lookup failure must not schedule another automatic retry.');
    $assert($spaceRepo->deliveryRow->state==='accepting','A terminal space-lookup failure must preserve the accepted delivery for administrator recovery.');
    $assert(Central::$calls===array(array('ensureForBooking',70)),'A terminal space-lookup failure must provision exactly once.');

    // Only the explicit administrator retry may reopen the booking after that terminal failure.
    Central::$calls=array();Central::$throws=null;
    $spaceRecovered=(new BookingCalendarProjectionService($spaceRepo))->retryFailed(70);
    $assert($spaceRecovered['state']==='confirmed'&&$spaceRepo->confirmationRow->state==='confirmed','Explicit retry-booking must recover the terminal space-lookup failure.');
    $assert(Central::$calls===array(array('ensureForBooking',70)),'Explicit recovery must use one central provisioning attempt.');

    // A cancelled request can never be resurrected by a delayed or administrator retry.
    $cancelledRepo=new BookingTeacherResponseRepository();
    $cancelledRepo->confirmationRow->state='failed';
    $cancelledRepo->deliveryRow->lifecycle_status='closed';
    $cancelledRepo->deliveryRow->resolution_state='cancelled';
    Central::$calls=array();Central::$throws=null;
    $cancelRejected=false;
    try{(new BookingCalendarProjectionService($cancelledRepo))->retryFailed(70);}
    catch(InvalidArgumentException $error){$cancelRejected=$error->getMessage()==='Booking request is no longer active';}
    $assert($cancelRejected&&$cancelledRepo->deliveryRow->state==='accepting','A cancelled request must be refused without changing the delivery.');
    $assert(Central::$calls===array(),'A cancelled request must never call Google.');

    // A newer allocation offer means the accepted delivery no longer owns the request.
    $supersededRepo=new BookingTeacherResponseRepository();
    $supersededRepo->confirmationRow->state='failed';
    $supersededRepo->activeOffer=(object)array('id'=>8,'delivery_id'=>41,'teacher_id'=>99,'state'=>'pending');
    Central::$calls=array();
    $supersededRejected=false;
    try{(new BookingCalendarProjectionService($supersededRepo))->retryFailed(70);}
    catch(InvalidArgumentException $error){$supersededRejected=$error->getMessage()==='Booking allocation has been superseded';}
    $assert($supersededRejected&&Central::$calls===array(),'A superseded allocation must be refused before any provider call.');

    // Stale reviewed versions are rejected before mutation.
    $staleRepo=new BookingTeacherResponseRepository();
    $staleRepo->confirmationRow->state='failed';
    Central::$calls=array();
    $staleRejected=false;
    try{(new BookingCalendarProjectionService($staleRepo))->retryFailed(70,99);}
    catch(RuntimeException $error){$staleRejected=$error->getMessage()==='Booking confirmation changed concurrently';}
    $assert($staleRejected&&$staleRepo->confirmationRow->state==='failed'&&Central::$calls===array(),'A stale confirmation version must be refused without a provider call.');

    // An ended booking cannot be resurrected by retry.
    $expiredRepo=new BookingTeacherResponseRepository();
    $expiredRepo->confirmationRow->state='failed';
    $expiredRepo->confirmationRow->starts_at_utc=gmdate('Y-m-d H:i:s',time()-3600);
    $expiredRepo->confirmationRow->ends_at_utc=gmdate('Y-m-d H:i:s',time()-1800);
    Central::$calls=array();
    $expiredRejected=false;
    try{(new BookingCalendarProjectionService($expiredRepo))->retryFailed(70);}
    catch(InvalidArgumentException $error){$expiredRejected=$error->getMessage()==='meet_booking_ended';}
    $assert($expiredRejected&&$expiredRepo->confirmationRow->state==='failed'&&Central::$calls===array(),'An ended booking must never be retried or call Google.');

    // Legacy records already moved to pending_request by the old defect are repaired in place.
    $legacyRepo=new BookingTeacherResponseRepository();
    $legacyRepo->confirmationRow->state='failed';$legacyRepo->confirmationRow->attempt_count=6;
    $legacyRepo->deliveryRow->state='pending_request';
    Central::$calls=array();Central::$throws=null;
    $legacy=(new BookingCalendarProjectionService($legacyRepo))->retryFailed(70);
    $assert($legacy['state']==='confirmed'&&$legacyRepo->deliveryRow->state==='confirmed','A historical pending_request delivery must recover without a new acceptance.');
    $assert(Central::$calls===array(array('ensureForBooking',70)),'Historical repair must reuse the same confirmation and provider path.');

    echo "Booking calendar projection central unit passed\n";
}
