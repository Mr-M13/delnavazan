<?php
namespace Delnavazan\Platform\Core\Application {
    final class ProviderReferenceVault { public function open(string $kind,int $mappingId,object|array $row): array { return array(); } }
}
namespace Delnavazan\Platform\Core\Infrastructure\Repository {
    final class BookingTeacherResponseRepository {
        public object $row;
        public function __construct(string $state='queued',int $attempts=0) {
            $this->row=(object)array(
                'id'=>81,'uid'=>'01ARZ3NDEKTSV4RRFFQ69G5FAX','delivery_id'=>41,'confirmation_id'=>null,
                'notification_kind'=>'declined','state'=>$state,'attempt_count'=>$attempts,'next_attempt_at'=>null,
                'lease_token_digest'=>$state==='sending'?hash('sha256','old-lease'):null,
                'lease_expires_at'=>$state==='sending'?gmdate('Y-m-d H:i:s',time()-60):null,
                'last_error_code'=>null,'handed_off_at'=>null,'updated_at'=>gmdate('Y-m-d H:i:s'),
                'email'=>'student@example.test','full_name'=>'Student','teacher_name'=>'Teacher','course_name'=>'Course',
                'confirmation_record_id'=>null,'student_timezone'=>'Australia/Brisbane','schedule_timezone'=>'Australia/Brisbane',
                'starts_at_utc'=>null,'ends_at_utc'=>null,
            );
        }
        public function begin(): void {}
        public function commit(): void {}
        public function rollback(): void {}
        public function notificationPayload(int $id,bool $lock=false): ?object { return $id===81?$this->row:null; }
        public function updateNotification(int $id,string $from,array $data,?string $leaseTokenDigest=null): void {
            if($id!==81||(string)$this->row->state!==$from)throw new \RuntimeException('state mismatch');
            if($leaseTokenDigest!==null&&!hash_equals($leaseTokenDigest,(string)$this->row->lease_token_digest))throw new \RuntimeException('lease mismatch');
            foreach($data as $key=>$value)$this->row->{$key}=$value;
        }
        public function projectionSecret(int $confirmationId): ?object { return null; }
    }
}
namespace {
    $mailCalls=array();
    function is_email(string $email): bool { return filter_var($email,FILTER_VALIDATE_EMAIL)!==false; }
    function home_url(string $path=''): string { return 'https://academy.example'.$path; }
    function wp_parse_url(string $url,int $component=-1): mixed { return parse_url($url,$component); }
    function wp_mail(string $to,string $subject,string $body,array $headers=array()): bool { global $mailCalls;$mailCalls[]=compact('to','subject','body','headers');return true; }

    require_once dirname(__DIR__).'/src/Integrations/BookingStudentNotificationWorker.php';
    $assert=static function(bool $ok,string $message):void{if(!$ok)throw new \RuntimeException($message);};

    $repository=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $result=\Delnavazan\Platform\Integrations\BookingStudentNotificationWorker::dispatch(81,$repository);
    $assert($result['state']==='handed_off'&&$result['transport_accepted']===true&&$result['recipient_delivery_confirmed']===false,'wp_mail success must record transport handoff without claiming recipient delivery.');
    $assert($repository->row->handed_off_at!==null&&$repository->row->lease_token_digest===null,'A completed handoff must clear its lease and retain explicit handoff evidence.');
    $messageIds=array_values(array_filter($mailCalls[0]['headers'],static fn(string $header):bool=>str_starts_with($header,'Message-ID:')));
    $assert(count($messageIds)===1&&str_contains($messageIds[0],strtolower((string)$repository->row->uid)),'Each retry must carry a stable notification Message-ID.');
    try{\Delnavazan\Platform\Integrations\BookingStudentNotificationWorker::dispatch(81,$repository);throw new \RuntimeException('A completed handoff was dispatched again.');}catch(\InvalidArgumentException){}
    $assert(count($mailCalls)===1,'A completed handoff must be idempotent.');

    $stale=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository('sending',1);
    $recovered=\Delnavazan\Platform\Integrations\BookingStudentNotificationWorker::dispatch(81,$stale);
    $assert($recovered['state']==='handed_off'&&(int)$stale->row->attempt_count===2,'An expired sending lease must be safely reclaimed and retried.');

    $exhausted=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository('sending',3);
    $failed=\Delnavazan\Platform\Integrations\BookingStudentNotificationWorker::dispatch(81,$exhausted);
    $assert($failed['state']==='failed'&&$exhausted->row->last_error_code==='handoff_outcome_unknown'&&count($mailCalls)===2,'An ambiguous crashed handoff must stop at the bounded retry limit.');
    echo "Booking student notification unit passed\n";
}
