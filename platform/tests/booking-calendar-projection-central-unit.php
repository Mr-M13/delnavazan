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
        public array $notifications=array();
        public int $secrets=0;
        public function __construct(){
            $this->confirmationRow=(object)array('id'=>70,'uid'=>'01ARZ3NDEKTSV4RRFFQ69G5FAV','delivery_id'=>41,'connection_id'=>0,'state'=>'pending_projection','version'=>1,'starts_at_utc'=>'2026-11-02 08:00:00','ends_at_utc'=>'2026-11-02 08:45:00','schedule_timezone'=>'Australia/Brisbane','attempt_count'=>0);
            $this->deliveryRow=(object)array('id'=>41,'booking_request_id'=>12,'state'=>'accepting','lifecycle_status'=>'submitted','resolution_state'=>'unresolved','privacy_erased_at'=>null);
            $this->credentialRow=(object)array('valid_until_utc'=>null);
        }
        public function confirmation(int $id,bool $lock=false): ?object { return $id===70?$this->confirmationRow:null; }
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
    $assert($permanentRepo->deliveryRow->state==='pending_request','A permanent failure must reopen the Teacher request for administrator recovery.');

    Central::$throws='meet_conference_pending';
    $transientRepo=new BookingTeacherResponseRepository();
    $transient=(new BookingCalendarProjectionService($transientRepo))->dispatch(70);
    $assert($transient['state']==='retryable'&&$transientRepo->confirmationRow->state==='retryable','A pending Meet conference must remain retryable.');
    $assert(!empty($transientRepo->confirmationRow->next_attempt_at),'A retryable failure must carry a durable next-attempt instant.');

    Central::$throws=null;Central::$calls=array();Central::$enabled=false;
    $pausedRepo=new BookingTeacherResponseRepository();
    $paused=(new BookingCalendarProjectionService($pausedRepo))->dispatch(70);
    $assert($paused['state']==='retryable'&&$pausedRepo->confirmationRow->state==='retryable','A paused organizer must keep the accepted booking retryable.');
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

    echo "Booking calendar projection central unit passed\n";
}
