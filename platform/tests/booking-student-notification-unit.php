<?php
namespace Delnavazan\Platform\Core\Application {
    final class ProviderReferenceVault { public function open(string $kind,int $mappingId,object|array $row): array { return array(); } }
    final class PortalNotificationRecorder {
        public static array $calls=array();
        public static function student(int $id,string $event,string $kind,int $source,array $variables=array(),?string $link=null):?int{self::$calls[]=compact('id','event','kind','source','variables','link')+array('audience'=>'student');return 1;}
        public static function teacher(int $id,string $event,string $kind,int $source,array $variables=array(),?string $link=null):?int{self::$calls[]=compact('id','event','kind','source','variables','link')+array('audience'=>'teacher');return 1;}
    }
}
namespace Delnavazan\Platform\Integrations {
    final class BookingNotificationMobileDispatcher {
        public static array $calls=array();
        public function dispatch(object $row,string $eventKey,array $variables):array{
            self::$calls[]=array('notification_id'=>(int)$row->id,'event_key'=>$eventKey,'variables'=>$variables);
            return array('state'=>'skipped','reason'=>'test');
        }
    }
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
                'delivery_state'=>'declined','lifecycle_status'=>'closed','resolution_state'=>'teacher_declined','confirmation_state'=>'',
                'email'=>'student@example.test','student_email'=>'student@example.test','student_id'=>55,'full_name'=>'Student','teacher_name'=>'Teacher','course_name'=>'Course',
                'confirmation_record_id'=>null,'student_timezone'=>'Australia/Brisbane','schedule_timezone'=>'Australia/Brisbane',
                'starts_at_utc'=>null,'ends_at_utc'=>null,
            );
        }
        public function begin(): void {}
        public function commit(): void {}
        public function rollback(): void {}
        public function notificationPayload(int $id,bool $lock=false): ?object { return in_array($id,array(81,82),true)?$this->row:null; }
        public function updateNotification(int $id,string $from,array $data,?string $leaseTokenDigest=null): void {
            if(!in_array($id,array(81,82),true)||(string)$this->row->state!==$from)throw new \RuntimeException('state mismatch');
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

    require_once dirname(__DIR__).'/src/Core/Application/BookingNotificationRecipient.php';
    require_once dirname(__DIR__).'/src/Core/Application/BookingNotificationRelevance.php';
    require_once dirname(__DIR__).'/src/Integrations/BookingStudentNotificationWorker.php';
    $assert=static function(bool $ok,string $message):void{if(!$ok)throw new \RuntimeException($message);};

    $repository=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $result=\Delnavazan\Platform\Integrations\BookingStudentNotificationWorker::dispatch(81,$repository);
    $assert($result['state']==='handed_off'&&$result['transport_accepted']===true&&$result['recipient_delivery_confirmed']===false,'wp_mail success must record transport handoff without claiming recipient delivery.');
    $assert($repository->row->handed_off_at!==null&&$repository->row->lease_token_digest===null,'A completed handoff must clear its lease and retain explicit handoff evidence.');
    $messageIds=array_values(array_filter($mailCalls[0]['headers'],static fn(string $header):bool=>str_starts_with($header,'Message-ID:')));
    $assert(count($messageIds)===1&&str_contains($messageIds[0],strtolower((string)$repository->row->uid)),'Each retry must carry a stable notification Message-ID.');
    $mobile=\Delnavazan\Platform\Integrations\BookingNotificationMobileDispatcher::$calls;
    $assert(count($mobile)===1&&$mobile[0]['notification_id']===81&&$mobile[0]['event_key']==='booking.declined.student','Every booking notification must reach the mobile dispatcher with its event key.');
    $studentPortal=\Delnavazan\Platform\Core\Application\PortalNotificationRecorder::$calls;
    $assert(count($studentPortal)===1&&$studentPortal[0]['audience']==='student'&&$studentPortal[0]['event']==='booking.declined.student'&&$studentPortal[0]['id']===55,'A resolved student must receive a portal notification for a booking update.');
    try{\Delnavazan\Platform\Integrations\BookingStudentNotificationWorker::dispatch(81,$repository);throw new \RuntimeException('A completed handoff was dispatched again.');}catch(\InvalidArgumentException){}
    $assert(count($mailCalls)===1,'A completed handoff must be idempotent.');

    $stale=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository('sending',1);
    $recovered=\Delnavazan\Platform\Integrations\BookingStudentNotificationWorker::dispatch(81,$stale);
    $assert($recovered['state']==='handed_off'&&(int)$stale->row->attempt_count===2,'An expired sending lease must be safely reclaimed and retried.');

    $exhausted=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository('sending',3);
    $failed=\Delnavazan\Platform\Integrations\BookingStudentNotificationWorker::dispatch(81,$exhausted);
    $assert($failed['state']==='failed'&&$exhausted->row->last_error_code==='handoff_outcome_unknown'&&count($mailCalls)===2,'An ambiguous crashed handoff must stop at the bounded retry limit.');

    $confirmed=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $confirmed->row->id=82;
    $confirmed->row->notification_kind='confirmed';$confirmed->row->confirmation_state='confirmed';
    $confirmed->row->confirmation_record_id=44;
    $confirmed->row->course_name='سه تار';
    $confirmed->row->teacher_name='نیما';
    $confirmed->row->full_name='Hamed';
    $confirmed->row->starts_at_utc='2026-11-03 05:45:00';
    $confirmed->row->ends_at_utc='2026-11-03 06:15:00';
    $confirmed->row->student_timezone='Australia/Brisbane';
    $confirmed->row->student_locale='fa_IR';
    $confirmed->row->student_calendar='persian';
    $confirmedResult=\Delnavazan\Platform\Integrations\BookingStudentNotificationWorker::dispatch(82,$confirmed);
    $assert($confirmedResult['state']==='handed_off'&&count($mailCalls)===3,'A confirmed booking must hand off without a Meet secret.');
    $assert(str_contains($mailCalls[2]['subject'],'کلاس معارفه')&&str_contains($mailCalls[2]['body'],'آبان')&&str_contains($mailCalls[2]['body'],'حساب کاربری'),'The confirmation must use the Persian calendar and account wording.');
    $assert(!str_contains($mailCalls[2]['body'],'meet.google.com')&&!str_contains($mailCalls[2]['body'],'Google Meet'),'The confirmation email must not expose the Google Meet backup link.');

    $teacher=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $teacher->row->notification_kind='requested_teacher';$teacher->row->delivery_state='pending_request';$teacher->row->lifecycle_status='submitted';$teacher->row->resolution_state='unresolved';$teacher->row->teacher_id=12;$teacher->row->teacher_email='teacher@example.test';$teacher->row->teacher_timezone='Australia/Brisbane';$teacher->row->starts_at_utc='2026-11-04 08:00:00';$teacher->row->ends_at_utc='2026-11-04 08:30:00';
    $teacherResult=\Delnavazan\Platform\Integrations\BookingStudentNotificationWorker::dispatch(81,$teacher);
    $assert($teacherResult['state']==='handed_off'&&end($mailCalls)['to']==='teacher@example.test','A requested booking must hand off the teacher alert to the Teacher address.');
    $portal=array_values(array_filter(\Delnavazan\Platform\Core\Application\PortalNotificationRecorder::$calls,static fn(array $call):bool=>$call['audience']==='teacher'));
    $assert(count($portal)===1&&$portal[0]['event']==='booking.new.teacher'&&$portal[0]['id']===12,'A teacher booking alert must create a separate portal notification before email handoff is recorded.');
    $aliasFree=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    unset($aliasFree->row->email);
    $aliasResult=\Delnavazan\Platform\Integrations\BookingStudentNotificationWorker::dispatch(81,$aliasFree);
    $assert($aliasResult['state']==='handed_off'&&$aliasFree->row->last_error_code===null,'A payload without the legacy email alias must use student_email and remain deliverable.');
    $assert($mailCalls[1]['to']==='student@example.test','The Student recipient must come from student_email.');
    $accountRecipient=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $accountRecipient->row->student_account_email='account@example.test';
    $accountRecipient->row->student_email='snapshot@example.test';
    $accountResult=\Delnavazan\Platform\Integrations\BookingStudentNotificationWorker::dispatch(81,$accountRecipient);
    $assert($accountResult['state']==='handed_off'&&end($mailCalls)['to']==='account@example.test','The current canonical Student account email must win over the legacy snapshot address.');
    $stale=new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository();
    $stale->row->notification_kind='requested_teacher';$stale->row->delivery_state='cancelled';$stale->row->lifecycle_status='closed';$stale->row->resolution_state='cancelled';
    $staleResult=\Delnavazan\Platform\Integrations\BookingStudentNotificationWorker::dispatch(81,$stale);
    $assert($staleResult['state']==='failed'&&$stale->row->last_error_code==='invitation_stale','A cancelled invitation must be terminally refused, not replayed.');
    echo "Booking student notification unit passed\n";
}
