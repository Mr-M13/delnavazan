<?php
namespace Delnavazan\Platform\Integrations\Notifications;

use Delnavazan\Platform\Core\Application\LessonRescheduleStore;
use Delnavazan\Platform\Core\Infrastructure\Repository\LessonRescheduleRepository;

/**
 * Recoverable, idempotent reschedule notifications.
 *
 * A notification row is created in the same transaction as the proposal/decision that caused it.
 * Dispatch only transitions an existing row; a repeated worker run converges on `sent` and never
 * emits a second message for the same idempotency key.
 */
final class LessonRescheduleNotificationService {
    private const MAX_ATTEMPTS=6;
    /** @var callable(string,string,string):bool */
    private $transport;
    /** @var callable(object,string):?array */
    private $recipient;
    /** @var callable():int */
    private $clock;

    public function __construct(
        private ?LessonRescheduleStore $store=null,
        ?callable $transport=null,
        ?callable $recipient=null,
        ?callable $clock=null
    ){
        $this->store??=new LessonRescheduleRepository();
        $this->transport=$transport??static fn(string $email,string $subject,string $body):bool=>wp_mail($email,$subject,$body);
        $this->recipient=$recipient??array(__CLASS__,'defaultRecipient');
        $this->clock=$clock??static fn():int=>time();
    }

    public function dispatchPending(int $limit=20):array{
        $sent=0;$failed=0;$skipped=0;
        foreach($this->store->pendingNotifications($limit) as $row){
            $this->store->begin();
            try{
                $locked=$this->store->notification((int)$row->id,true);
                if(!$locked||!in_array((string)$locked->state,array('queued','retry'),true)){$this->store->commit();$skipped++;continue;}
                $proposal=$this->store->proposal((int)$locked->proposal_id);
                if(!$proposal)throw new \RuntimeException('reschedule_proposal_not_found');
                $recipient=($this->recipient)($proposal,(string)$locked->audience);
                if(!$recipient||empty($recipient['email']))throw new \RuntimeException('reschedule_recipient_unresolved');
                $rendered=$this->render($proposal,(string)$locked->event_type,(string)$locked->audience,(string)($recipient['name']??''));
                $ok=(bool)($this->transport)((string)$recipient['email'],$rendered['subject'],$rendered['body']);
                $now=gmdate('Y-m-d H:i:s',(int)($this->clock)());
                if($ok){
                    $this->store->updateNotification((int)$locked->id,array('state'=>'sent','attempt_count'=>(int)$locked->attempt_count+1,'sent_at'=>$now,'last_error_code'=>null,'updated_at'=>$now));
                    $sent++;
                }else{
                    $attempt=(int)$locked->attempt_count+1;
                    $state=$attempt>=self::MAX_ATTEMPTS?'failed':'retry';
                    $this->store->updateNotification((int)$locked->id,array('state'=>$state,'attempt_count'=>$attempt,'next_attempt_at'=>gmdate('Y-m-d H:i:s',(int)($this->clock)()+min(3600,60*(2**min(5,$attempt)))),'last_error_code'=>'transport_failed','updated_at'=>$now));
                    $failed++;
                }
                $this->store->commit();
            }catch(\Throwable $e){
                $this->store->rollback();
                $failed++;
            }
        }
        return array('sent'=>$sent,'failed'=>$failed,'skipped'=>$skipped);
    }

    private function render(object $proposal,string $eventType,string $audience,string $name):array{
        $timezone=(string)$proposal->schedule_timezone;
        $when=$this->format((string)$proposal->proposed_starts_at_utc,$timezone);
        $role='student'===$audience?'هنرجو':'مدرس';
        $subject='دلنوازان · تغییر زمان کلاس';
        $messages=array(
            'proposed'=>'درخواست تغییر زمان کلاس برای شما ثبت شد. زمان پیشنهادی: '.$when.' ('.$timezone.'). لطفاً در پرتال هنرجو آن را بپذیرید یا رد کنید.',
            'accepted'=>'زمان جدید کلاس تأیید شد. زمان: '.$when.' ('.$timezone.').',
            'rejected'=>'پیشنهاد تغییر زمان کلاس رد شد. زمان فعلی کلاس بدون تغییر باقی می‌ماند.',
            'failed'=>'تغییر زمان کلاس ثبت نشد. زمان فعلی بدون تغییر باقی می‌ماند؛ لطفاً زمان تازه‌ای پیشنهاد کنید.',
        );
        $body='سلام'.(''!==trim($name)?' '.trim($name):'')."،\n\n".($messages[$eventType]??$messages['failed'])."\n\nدلنوازان";
        return array('subject'=>$subject,'body'=>$body);
    }

    private function format(string $utc,string $timezone):string{
        $timestamp=strtotime($utc.' UTC');
        if(!$timestamp)return $utc;
        if(class_exists('IntlDateFormatter')){
            try{
                $formatter=new \IntlDateFormatter('fa_IR@calendar=persian',\IntlDateFormatter::FULL,\IntlDateFormatter::SHORT,$timezone,\IntlDateFormatter::TRADITIONAL);
                $formatter->setPattern('EEEE d MMMM y، H:mm');
                $formatted=$formatter->format((new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone($timezone)));
                if(is_string($formatted)&&''!==$formatted)return $formatted;
            }catch(\Throwable){}
        }
        return wp_date('Y-m-d H:i',$timestamp,new \DateTimeZone($timezone));
    }

    /** Default recipient lookup stays on the owning domain row; no contact data enters the job. */
    public static function defaultRecipient(object $proposal,string $audience):?array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        if('student'===$audience){
            $row=$wpdb->get_row($wpdb->prepare("SELECT email,display_name name FROM {$p}students WHERE id=%d AND status='active' AND archived_at IS NULL LIMIT 1",(int)$proposal->student_id));
        }else{
            $row=$wpdb->get_row($wpdb->prepare("SELECT email,COALESCE(NULLIF(display_name,''),NULLIF(english_name,''),persian_name) name FROM {$p}teachers WHERE id=%d AND status='active' AND archived_at IS NULL LIMIT 1",(int)$proposal->teacher_id));
        }
        if(!$row||trim((string)($row->email??''))==='')return null;
        return array('email'=>(string)$row->email,'name'=>(string)($row->name??''));
    }
}
