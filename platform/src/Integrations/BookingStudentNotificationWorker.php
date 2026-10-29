<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\PortalNotificationRecorder;
use Delnavazan\Platform\Core\Application\ProviderReferenceVault;
use Delnavazan\Platform\Integrations\Notifications\DelnavazanEmailRenderer;
use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
use Delnavazan\Platform\Integrations\Notifications\NotificationCopyResolver;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository;

/** Durable, leased, at-least-once mail hand-off for confirmed or declined introductory requests. */
final class BookingStudentNotificationWorker {
    public const HOOK='dzn_booking_student_notification_dispatch';
    private const MAX_ATTEMPTS=3;
    private const LEASE_SECONDS=600;
    public static function register():void{add_action(self::HOOK,array(__CLASS__,'run'));add_action('init',array(__CLASS__,'ensureScheduled'));}
    public static function ensureScheduled():void{if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+90,'dzn_two_minutes',self::HOOK);}
    public static function run():void{$repository=new BookingTeacherResponseRepository();foreach($repository->queuedNotifications(10)as $row){try{self::dispatch((int)$row->id,$repository);}catch(\Throwable $error){do_action('dzn_booking_student_notification_failed',(int)$row->id,get_class($error));}}}

    public static function dispatch(int $notificationId,?BookingTeacherResponseRepository $repository=null):array{
        $repository??=new BookingTeacherResponseRepository();$repository->begin();
        try{
            $row=$repository->notificationPayload($notificationId,true);if(!$row)throw new \InvalidArgumentException('Booking notification is not dispatchable');$now=gmdate('Y-m-d H:i:s');$state=(string)$row->state;
            $stale=$state==='sending'&&$row->lease_expires_at!==null&&strtotime((string)$row->lease_expires_at.' UTC')<=time();
            $due=in_array($state,array('queued','retryable'),true)&&($row->next_attempt_at===null||strtotime((string)$row->next_attempt_at.' UTC')<=time());
            if(!$due&&!$stale)throw new \InvalidArgumentException('Booking notification is not dispatchable');
            if(!is_email((string)$row->email)){$repository->updateNotification($notificationId,$state,array('state'=>'failed','attempt_count'=>(int)$row->attempt_count+1,'next_attempt_at'=>null,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>'contact_unusable','updated_at'=>$now));$repository->commit();return array('notification_id'=>$notificationId,'state'=>'failed','recipient_delivery_confirmed'=>false);}
            if($stale&&(int)$row->attempt_count>=self::MAX_ATTEMPTS){$repository->updateNotification($notificationId,'sending',array('state'=>'failed','next_attempt_at'=>null,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>'handoff_outcome_unknown','updated_at'=>$now),(string)$row->lease_token_digest);$repository->commit();return array('notification_id'=>$notificationId,'state'=>'failed','recipient_delivery_confirmed'=>false);}
            $lease=bin2hex(random_bytes(32));$leaseDigest=hash('sha256',$lease);$attempt=(int)$row->attempt_count+1;
            $repository->updateNotification($notificationId,$state,array('state'=>'sending','attempt_count'=>$attempt,'next_attempt_at'=>null,'lease_token_digest'=>$leaseDigest,'lease_expires_at'=>gmdate('Y-m-d H:i:s',time()+self::LEASE_SECONDS),'last_error_code'=>$stale?'previous_handoff_outcome_unknown':null,'updated_at'=>$now),$stale?(string)$row->lease_token_digest:null);$repository->commit();
        }catch(\Throwable $error){$repository->rollback();throw $error;}
        try{
            [$subject,$body,$variables]=self::message($row,$repository);
            $eventKey='booking.'.(string)$row->notification_kind.'.student';
            if((string)$row->notification_kind==='post_intro_invitation')$eventKey='post_intro.invitation.student';
            if((string)$row->notification_kind==='post_intro_activated')$eventKey='post_intro.activated.student';
            $copy=array('subject'=>$subject,'body'=>$body);
            if(class_exists(NotificationCopyResolver::class)){
                $copy=NotificationCopyResolver::email($eventKey,NotificationCatalogue::PRIMARY_LOCALE,$variables,$copy);
                self::recordInbox($row,$eventKey,$variables);
            }
            $accepted=class_exists(DelnavazanEmailRenderer::class)
                ?DelnavazanEmailRenderer::send((string)$row->email,$copy['subject'],$copy['body'],array('locale'=>NotificationCatalogue::PRIMARY_LOCALE,'cta'=>array('url'=>(string)($copy['cta_url']??''),'label'=>'رفتن به پرتال هنرجو')),self::headers($row))
                :wp_mail((string)$row->email,$copy['subject'],$copy['body'],self::headers($row));
        }catch(\Throwable){$accepted=false;}
        $repository->begin();
        try{
            $locked=$repository->notificationPayload($notificationId,true);if(!$locked||(string)$locked->state!=='sending'||!hash_equals($leaseDigest,(string)$locked->lease_token_digest))throw new \RuntimeException('Booking notification lease changed concurrently');$now=gmdate('Y-m-d H:i:s');
            $clear=array('lease_token_digest'=>null,'lease_expires_at'=>null,'updated_at'=>$now);
            if($accepted){$state='handed_off';$repository->updateNotification($notificationId,'sending',$clear+array('state'=>$state,'handed_off_at'=>$now,'last_error_code'=>null),$leaseDigest);}
            elseif((int)$locked->attempt_count>=self::MAX_ATTEMPTS){$state='failed';$repository->updateNotification($notificationId,'sending',$clear+array('state'=>$state,'next_attempt_at'=>null,'last_error_code'=>'send_refused'),$leaseDigest);}
            else{$state='retryable';$repository->updateNotification($notificationId,'sending',$clear+array('state'=>$state,'next_attempt_at'=>gmdate('Y-m-d H:i:s',time()+300),'last_error_code'=>'send_refused'),$leaseDigest);}
            $repository->commit();return array('notification_id'=>$notificationId,'state'=>$state,'transport_accepted'=>$accepted,'recipient_delivery_confirmed'=>false);
        }catch(\Throwable $error){$repository->rollback();throw $error;}
    }

    /** The student inbox is recorded even when the mail transport refuses the hand-off. */
    private static function recordInbox(object $row,string $eventKey,array $variables):void{
        $studentId=(int)($row->student_id??0);
        if($studentId<1||!class_exists(PortalNotificationRecorder::class))return;
        PortalNotificationRecorder::student($studentId,$eventKey,'booking_notification',(int)$row->id,$variables,(string)($variables['portal_url']??''));
    }

    private static function headers(object $row):array{
        $host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        if(!preg_match('/^[a-z0-9.-]+$/D',$host))$host='delnavazan.invalid';
        $identity='booking-'.strtolower((string)$row->uid).'@'.$host;
        return array('Content-Type: text/plain; charset=UTF-8','Message-ID: <'.$identity.'>','X-Delnavazan-Notification-ID: '.(string)$row->uid);
    }

    private static function message(object $row,BookingTeacherResponseRepository $repository):array{
        $name=trim((string)$row->full_name);$teacher=trim((string)$row->teacher_name);$course=trim((string)$row->course_name);
        $portal=home_url('/student-portal/');
        $base=array('name'=>$name,'teacher'=>$teacher,'course'=>$course,'portal_url'=>$portal);
        if((string)$row->notification_kind==='post_intro_invitation')return array('Your Delnavazan enrolment is ready',"Hello {$name},\n\n{$teacher} confirmed that your introductory {$course} lesson was delivered. You can now complete payment and confirm your enrolment with the same Teacher.\n\nOpen your Student Portal: {$portal}\n\nYour enrolment, weekly schedule and future lessons are activated automatically after the payment is verified. No further Teacher approval is required.\n\nDelnavazan",$base);
        if((string)$row->notification_kind==='post_intro_activated')return array('Your Delnavazan enrolment is active',"Hello {$name},\n\nYour payment was verified and your {$course} enrolment with {$teacher} is now active. Your weekly schedule and future lessons are available in your Student Portal.\n\nOpen your Student Portal: {$portal}\n\nDelnavazan",$base);
        if((string)$row->notification_kind==='declined')return array('Delnavazan booking request update',"Hello {$name},\n\n{$teacher} is unable to accept your requested introductory lesson. The request has not been confirmed and no Calendar event or Meet link was created.\n\nDelnavazan",$base);
        if((string)$row->notification_kind!=='confirmed'||(int)$row->confirmation_record_id<1)throw new \RuntimeException('Booking confirmation notification incomplete');
        $secret=$repository->projectionSecret((int)$row->confirmation_record_id);if(!$secret)throw new \RuntimeException('provider_reference_required');$refs=(new ProviderReferenceVault())->open('booking_calendar',(int)$row->confirmation_record_id,$secret);$join=trim((string)($refs['join_uri_reference']??''));if($join==='')throw new \RuntimeException('provider_reference_required');
        $timezone=in_array((string)$row->student_timezone,timezone_identifiers_list(),true)?(string)$row->student_timezone:(string)$row->schedule_timezone;$zone=new \DateTimeZone($timezone);$start=(new \DateTimeImmutable((string)$row->starts_at_utc,new \DateTimeZone('UTC')))->setTimezone($zone);$end=(new \DateTimeImmutable((string)$row->ends_at_utc,new \DateTimeZone('UTC')))->setTimezone($zone);$when=$start->format('l j F Y, H:i').'–'.$end->format('H:i').' '.$timezone;
        return array('Your Delnavazan lesson is confirmed',"Hello {$name},\n\nYour introductory {$course} lesson with {$teacher} is confirmed.\n\nTime: {$when}\nGoogle Meet: {$join}\n\nThe Calendar event and Meet link were created before this confirmation was issued.\n\nDelnavazan",$base+array('when'=>$when,'join_url'=>$join));
    }
}
