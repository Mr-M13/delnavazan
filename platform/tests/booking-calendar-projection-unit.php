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
namespace Delnavazan\Platform\Core\Infrastructure\Repository {
    final class BookingTeacherResponseRepository {
        public object $confirmationRow;
        public object $deliveryRow;
        public object $credentialRow;
        public ?object $secretRow = null;
        public array $notifications = array();
        public function __construct() {
            $this->confirmationRow=(object)array('id'=>70,'uid'=>'01ARZ3NDEKTSV4RRFFQ69G5FAV','delivery_id'=>41,'connection_id'=>5,'state'=>'pending_projection','version'=>1,'starts_at_utc'=>'2026-11-02 08:00:00','ends_at_utc'=>'2026-11-02 08:45:00','schedule_timezone'=>'Australia/Brisbane','attempt_count'=>0);
            $this->deliveryRow=(object)array('id'=>41,'booking_request_id'=>12,'state'=>'accepting','lifecycle_status'=>'submitted','resolution_state'=>'unresolved','privacy_erased_at'=>null);
            $this->credentialRow=(object)array('valid_until_utc'=>null);
        }
        public function confirmation(int $id,bool $lock=false): ?object { return $id===70?$this->confirmationRow:null; }
        public function credential(int $connectionId): ?object { return $connectionId===5?$this->credentialRow:null; }
        public function projectionSecret(int $confirmationId): ?object { return $confirmationId===70?$this->secretRow:null; }
        public function begin(): void {}
        public function commit(): void {}
        public function rollback(): void {}
        public function insertSecret(array $data): int { $this->secretRow=(object)($data+array('id'=>91));return 91; }
        public function updateConfirmation(int $id,int $version,array $data): void { if($id!==70||$version!==(int)$this->confirmationRow->version)throw new \RuntimeException('confirmation version mismatch');foreach($data as $key=>$value)$this->confirmationRow->{$key}=$value; }
        public function delivery(int $id,bool $lock=false): ?object { return $id===41?$this->deliveryRow:null; }
        public function retireSecret(int $id,int $actor,string $now): void { if(!$this->secretRow||$id!==(int)$this->secretRow->id)throw new \RuntimeException('secret mismatch');$this->secretRow=null; }
        public function transitionDelivery(int $id,string $from,string $to,string $now): void { if($id!==41||$this->deliveryRow->state!==$from)throw new \RuntimeException('delivery transition mismatch');$this->deliveryRow->state=$to; }
        public function closeRequest(int $id,string $resolution,string $now,int $actor): void { $this->deliveryRow->lifecycle_status='closed';$this->deliveryRow->resolution_state=$resolution; }
        public function notificationForDeliveryKind(int $deliveryId,string $kind): ?object { return $this->notifications[$deliveryId.'|'.$kind]??null; }
        public function insertNotification(array $data): int { $this->notifications[$data['delivery_id'].'|'.$data['notification_kind']]=(object)$data;return count($this->notifications); }
    }
}
namespace Delnavazan\Platform\Integrations {
    final class BookingGoogleCredentialRefresher {
        public int $calls=0;
        public function __construct(private ?\Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository $repository=null,public bool $succeeds=true) {}
        public function refresh(int $connectionId,int $confirmationId,int $attempt): bool { $this->calls++;if($this->succeeds&&$this->repository)$this->repository->credentialRow->valid_until_utc=gmdate('Y-m-d H:i:s',time()+3600);return $this->succeeds; }
    }
    final class GoogleCalendarProjectionTransport {
        public bool $conferenceReady=false;
        public int $dispatches=0;
        public int $inspections=0;
        public function dispatch(array $request,string $credentialMaterial): array { $this->dispatches++;return $this->result(); }
        public function inspect(string $eventId,string $credentialMaterial): array { $this->inspections++;return $this->result(); }
        private function result(): array { return array('provider_object_reference'=>str_repeat('a',32),'join_uri_reference'=>$this->conferenceReady?'https://meet.google.com/abc-defg-hij':null); }
    }
}
namespace {
    function get_current_user_id(): int { return 27; }
    require_once dirname(__DIR__) . '/src/Integrations/BookingCalendarProjectionService.php';
    $assert=static function(bool $ok,string $message):void{if(!$ok)throw new \RuntimeException($message);};
    $repository=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $transport=new \Delnavazan\Platform\Integrations\GoogleCalendarProjectionTransport();
    $service=new \Delnavazan\Platform\Integrations\BookingCalendarProjectionService($repository,null,null,$transport);
    $waiting=$service->dispatch(70);
    $assert($waiting['state']==='awaiting_conference'&&$repository->confirmationRow->state==='awaiting_conference','A Calendar event without a Meet URI must remain unconfirmed.');
    $assert($repository->deliveryRow->state==='accepting'&&$repository->notifications===array(),'Student-visible confirmation must wait for Meet.');
    $transport->conferenceReady=true;
    $confirmed=$service->dispatch(70);
    $assert($confirmed['state']==='confirmed'&&$repository->deliveryRow->state==='confirmed','A reinspection with Calendar and Meet must confirm the delivery.');
    $assert($repository->deliveryRow->resolution_state==='teacher_confirmed'&&isset($repository->notifications['41|confirmed']),'Confirmation must close the request and queue one student notification.');
    $replay=$service->dispatch(70);
    $assert($replay['idempotent']===true&&count($repository->notifications)===1,'Confirmed projection replay must be side-effect free.');
    $assert($transport->dispatches===1&&$transport->inspections===1,'Conference recovery must inspect the deterministic event instead of posting another event.');

    $refreshRepository=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $refreshRepository->credentialRow->valid_until_utc=gmdate('Y-m-d H:i:s',time()-60);
    $refreshTransport=new \Delnavazan\Platform\Integrations\GoogleCalendarProjectionTransport();$refreshTransport->conferenceReady=true;
    $refresher=new \Delnavazan\Platform\Integrations\BookingGoogleCredentialRefresher($refreshRepository);
    $refreshed=(new \Delnavazan\Platform\Integrations\BookingCalendarProjectionService($refreshRepository,null,null,$refreshTransport,$refresher))->dispatch(70);
    $assert($refresher->calls===1&&$refreshed['state']==='confirmed','An expired access token must rotate through the stored refresh credential before projection.');

    $failedRepository=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $failedRepository->credentialRow->valid_until_utc=gmdate('Y-m-d H:i:s',time()-60);$failedRepository->confirmationRow->attempt_count=5;
    $failedRefresher=new \Delnavazan\Platform\Integrations\BookingGoogleCredentialRefresher($failedRepository,false);
    $failed=(new \Delnavazan\Platform\Integrations\BookingCalendarProjectionService($failedRepository,null,null,new \Delnavazan\Platform\Integrations\GoogleCalendarProjectionTransport(),$failedRefresher))->dispatch(70);
    $assert($failed['state']==='failed'&&$failedRepository->deliveryRow->state==='pending_request','Exhausted projection attempts must terminate and reopen the Teacher request.');
    echo "Booking calendar projection unit passed\n";
}
