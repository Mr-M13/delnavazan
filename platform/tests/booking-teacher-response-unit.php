<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository {
    final class BookingTeacherResponseRepository {
        public object $teacher;
        public object $delivery;
        public ?object $connection;
        public array $commands = array();
        public array $confirmations = array();
        public array $notifications = array();
        private int $nextConfirmation = 70;

        public function __construct() {
            $this->teacher = (object) array('id'=>9,'status'=>'active','archived_at'=>null,'timezone'=>'Australia/Brisbane','onboarding_state'=>'active','readiness_state'=>'ready');
            $this->delivery = (object) array('id'=>41,'uid'=>'01ARZ3NDEKTSV4RRFFQ69G5FAV','teacher_id'=>9,'booking_request_id'=>12,'requested_time_id'=>13,'state'=>'pending_request','lifecycle_status'=>'submitted','resolution_state'=>'unresolved','privacy_erased_at'=>null,'starts_at_utc'=>'2026-11-02 08:00:00','instructional_ends_at_utc'=>'2026-11-02 08:45:00');
            $this->connection = (object) array('id'=>5);
        }
        public function begin(): void {}
        public function commit(): void {}
        public function rollback(): void {}
        public function command(string $digest): ?object { return $this->commands[$digest] ?? null; }
        public function teacherForUser(int $userId,bool $lock=false): ?object { return $userId === 27 ? $this->teacher : null; }
        public function deliveryByUid(string $uid,bool $lock=false): ?object { return $uid === $this->delivery->uid ? $this->delivery : null; }
        public function activeConnection(int $teacherId,bool $lock=false): ?object { return $teacherId === 9 ? $this->connection : null; }
        public function transitionDelivery(int $id,string $from,string $to,string $now): void { if ($id !== 41 || $this->delivery->state !== $from) throw new \RuntimeException('transition mismatch'); $this->delivery->state = $to; }
        public function closeRequest(int $id,string $resolution,string $now,int $actor): void { $this->delivery->lifecycle_status='closed';$this->delivery->resolution_state=$resolution; }
        public function insertConfirmation(array $data): int { $id=$this->nextConfirmation++;$this->confirmations[$id]=(object)($data+array('id'=>$id));return $id; }
        public function insertCommand(array $data): int { $this->commands[$data['command_key_digest']] = (object) $data; return count($this->commands); }
        public function confirmation(int $id,bool $lock=false): ?object { return $this->confirmations[$id] ?? null; }
        public function notificationForDeliveryKind(int $deliveryId,string $kind): ?object { return $this->notifications[$deliveryId.'|'.$kind] ?? null; }
        public function insertNotification(array $data): int { $this->notifications[$data['delivery_id'].'|'.$data['notification_kind']] = (object) $data; return count($this->notifications); }
    }
}
namespace Delnavazan\Platform\Core\Support {
    final class Identifier { public static function uid(): string { return '01ARZ3NDEKTSV4RRFFQ69G5FAA'; } }
}
namespace Delnavazan\Platform\Core\Application {
    final class IdempotencyConflictException extends \RuntimeException {}
}
namespace {
    function get_current_user_id(): int { return 27; }
    function wp_salt(string $scheme='auth'): string { return 'booking-response-unit-test-salt-material'; }

    require_once dirname(__DIR__) . '/src/Core/Application/BookingTeacherResponseService.php';
    $assert = static function(bool $ok,string $message): void { if(!$ok)throw new \RuntimeException($message); };
    $repository = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $service = new \Delnavazan\Platform\Core\Application\BookingTeacherResponseService($repository);
    $accepted = $service->respond($repository->delivery->uid,'accept','accept-once');
    $assert($accepted['state']==='accepting' && $repository->delivery->state==='accepting','Acceptance must enter provider projection before confirmation.');
    $confirmationId=(int)$accepted['confirmation_id'];
    $repository->confirmations[$confirmationId]->state='confirmed';
    $replay=$service->respond($repository->delivery->uid,'accept','accept-once');
    $assert($replay['idempotent']===true && $replay['state']==='confirmed','Replay must report the live confirmed state.');
    try{$service->respond($repository->delivery->uid,'decline','accept-once');throw new \RuntimeException('Conflicting replay was accepted.');}catch(\Delnavazan\Platform\Core\Application\IdempotencyConflictException){}

    $declineRepository = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $declineRepository->teacher->onboarding_state='in_progress';$declineRepository->teacher->readiness_state='not_ready';$declineRepository->connection=null;
    $declined=(new \Delnavazan\Platform\Core\Application\BookingTeacherResponseService($declineRepository))->respond($declineRepository->delivery->uid,'decline','decline-once');
    $assert($declined['state']==='declined' && $declineRepository->delivery->resolution_state==='teacher_declined','A linked active Teacher must be able to decline without provider readiness.');
    $assert(isset($declineRepository->notifications['41|declined']),'Decline must queue the student update atomically.');
    echo "Booking teacher response unit passed\n";
}
