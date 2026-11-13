<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\BookingNotificationRecipient;
use Delnavazan\Platform\Core\Application\BookingNotificationRelevance;

require_once __DIR__ . '/PostIntroSurveyWorker.php';
use Delnavazan\Platform\Core\Application\CanonicalContinuationRule;
use Delnavazan\Platform\Core\Application\PortalNotificationRecorder;
use Delnavazan\Platform\Integrations\Notifications\DelnavazanEmailRenderer;
use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
use Delnavazan\Platform\Integrations\Notifications\NotificationCopyResolver;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary;
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
            if((string)$row->notification_kind===PostIntroSurveyWorker::NOTIFICATION_KIND&&!PostIntroSurveyWorker::eligibleForDispatch($row)){
                $repository->updateNotification($notificationId,$state,array('state'=>'cancelled','next_attempt_at'=>null,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>'survey_not_eligible','updated_at'=>$now),$stale?(string)$row->lease_token_digest:null);
                $repository->commit();
                return array('notification_id'=>$notificationId,'state'=>'cancelled','recipient_delivery_confirmed'=>false);
            }
            if(!BookingNotificationRelevance::isStillRelevant($row)){
                $repository->updateNotification($notificationId,$state,array('state'=>'failed','next_attempt_at'=>null,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>'invitation_stale','updated_at'=>$now));
                $repository->commit();
                return array('notification_id'=>$notificationId,'state'=>'failed','recipient_delivery_confirmed'=>false);
            }
            if(!is_email(self::recipientEmail($row))){$repository->updateNotification($notificationId,$state,array('state'=>'failed','attempt_count'=>(int)$row->attempt_count+1,'next_attempt_at'=>null,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>'contact_unusable','updated_at'=>$now));$repository->commit();return array('notification_id'=>$notificationId,'state'=>'failed','recipient_delivery_confirmed'=>false);}
            if($stale&&(int)$row->attempt_count>=self::MAX_ATTEMPTS){$repository->updateNotification($notificationId,'sending',array('state'=>'failed','next_attempt_at'=>null,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>'handoff_outcome_unknown','updated_at'=>$now),(string)$row->lease_token_digest);$repository->commit();return array('notification_id'=>$notificationId,'state'=>'failed','recipient_delivery_confirmed'=>false);}
            $lease=bin2hex(random_bytes(32));$leaseDigest=hash('sha256',$lease);$attempt=(int)$row->attempt_count+1;
            $repository->updateNotification($notificationId,$state,array('state'=>'sending','attempt_count'=>$attempt,'next_attempt_at'=>null,'lease_token_digest'=>$leaseDigest,'lease_expires_at'=>gmdate('Y-m-d H:i:s',time()+self::LEASE_SECONDS),'last_error_code'=>$stale?'previous_handoff_outcome_unknown':null,'updated_at'=>$now),$stale?(string)$row->lease_token_digest:null);$repository->commit();
        }catch(\Throwable $error){$repository->rollback();throw $error;}
        $mobile=array('state'=>'skipped','reason'=>'not_attempted');
        $emailSuppressed=false;
        try{
            [$subject,$body,$variables]=self::message($row);
            $eventKey='booking.'.(string)$row->notification_kind.'.student';
            if((string)$row->notification_kind==='requested_student')$eventKey='booking.requested.student';
            if((string)$row->notification_kind==='requested_teacher')$eventKey='booking.new.teacher';
            if((string)$row->notification_kind==='cancelled')$eventKey='booking.cancelled.student';
            if((string)$row->notification_kind==='cancelled_teacher')$eventKey='booking.cancelled.teacher';
            if((string)$row->notification_kind==='rescheduled')$eventKey='booking.rescheduled.student';
            if((string)$row->notification_kind==='rescheduled_teacher')$eventKey='booking.rescheduled.teacher';
            if((string)$row->notification_kind==='post_intro_invitation'||(string)$row->notification_kind==='post_intro_reminder')$eventKey='post_intro.invitation.student';
            if((string)$row->notification_kind==='post_intro_activated')$eventKey='post_intro.activated.student';
            if((string)$row->notification_kind===PostIntroSurveyWorker::NOTIFICATION_KIND)$eventKey=PostIntroSurveyWorker::EVENT_KEY;
            $copy=array('subject'=>$subject,'body'=>$body);
            if(class_exists(NotificationCopyResolver::class)){
                $copy=NotificationCopyResolver::email($eventKey,NotificationCatalogue::PRIMARY_LOCALE,$variables,$copy);
            }
            if($eventKey==='booking.new.teacher'&&!str_contains((string)$copy['body'],'مهلت پاسخ')){
                $copy['body']=rtrim((string)$copy['body'])."\n\n".'لطفاً حداکثر تا '.$variables['response_window'].' ('.$variables['response_deadline'].') پاسخ دهید. اگر در این بازه پاسخی ثبت نشود، درخواست به مدرس در دسترس بعدی ارجاع می‌شود.';
            }
            // The portal is an independent first-party notice.  A missing or invalid editable
            // email template must not hide the operational booking alert from the Teacher.
            self::recordInbox($row,$eventKey,$variables);
            // The admin switch is an event/channel authority, not presentation state. Portal and
            // mobile delivery remain independent, but a disabled email must never reach wp_mail.
            if(!(new NotificationTemplateLibrary())->eventChannelEnabled($eventKey,'email')){
                $emailSuppressed=true;
            }else{
                try{
                    $accepted=class_exists(DelnavazanEmailRenderer::class)
                        ?DelnavazanEmailRenderer::send(self::recipientEmail($row),$copy['subject'],$copy['body'],array('locale'=>NotificationCatalogue::PRIMARY_LOCALE,'cta'=>array('url'=>(string)($copy['cta_url']??''),'label'=>self::portalLabel($row))),self::headers($row))
                        :wp_mail(self::recipientEmail($row),$copy['subject'],$copy['body'],self::headers($row));
                }catch(\Throwable){$accepted=false;}
            }
            // WhatsApp is the mobile primary.  It is coordinated with the same leased booking
            // notification so an email retry cannot duplicate an accepted provider hand-off.
            $mobile=self::mobile($row,$eventKey,$variables);
        }catch(\Throwable){$accepted=false;}
        $repository->begin();
        try{
            $locked=$repository->notificationPayload($notificationId,true);if(!$locked||(string)$locked->state!=='sending'||!hash_equals($leaseDigest,(string)$locked->lease_token_digest))throw new \RuntimeException('Booking notification lease changed concurrently');$now=gmdate('Y-m-d H:i:s');
            $clear=array('lease_token_digest'=>null,'lease_expires_at'=>null,'updated_at'=>$now);
            // Email/portal hand-off remains independent from mobile delivery, but a mobile refusal must
            // stay visible on the durable queue row. Previously the worker discarded this result and
            // an email success made an unmapped WhatsApp event indistinguishable from a fully-routed
            // notification.
            $mobileDiagnostic=self::mobileDiagnostic($mobile);
            if($emailSuppressed){$state='cancelled';$repository->updateNotification($notificationId,'sending',$clear+array('state'=>$state,'next_attempt_at'=>null,'last_error_code'=>'email_disabled_by_administrator'),$leaseDigest);}
            elseif($accepted){$state='handed_off';$repository->updateNotification($notificationId,'sending',$clear+array('state'=>$state,'handed_off_at'=>$now,'last_error_code'=>$mobileDiagnostic),$leaseDigest);}
            elseif((int)$locked->attempt_count>=self::MAX_ATTEMPTS){$state='failed';$repository->updateNotification($notificationId,'sending',$clear+array('state'=>$state,'next_attempt_at'=>null,'last_error_code'=>'send_refused'),$leaseDigest);}
            else{$state='retryable';$repository->updateNotification($notificationId,'sending',$clear+array('state'=>$state,'next_attempt_at'=>gmdate('Y-m-d H:i:s',time()+300),'last_error_code'=>'send_refused'),$leaseDigest);}
            $repository->commit();return array('notification_id'=>$notificationId,'state'=>$state,'transport_accepted'=>$accepted,'recipient_delivery_confirmed'=>false,'mobile'=>$mobile);
        }catch(\Throwable $error){$repository->rollback();throw $error;}
    }

    /** The student inbox is recorded even when the mail transport refuses the hand-off. */
    private static function recordInbox(object $row,string $eventKey,array $variables):void{
        if(!class_exists(PortalNotificationRecorder::class))return;
        if(str_ends_with((string)$row->notification_kind,'_teacher')){
            PortalNotificationRecorder::teacher((int)$row->teacher_id,$eventKey,'booking_notification',(int)$row->id,$variables,(string)($variables['teacher_portal_url']??''));
            return;
        }
        $studentId=(int)($row->student_id??0);
        if($studentId<1)return;
        if($eventKey===PostIntroSurveyWorker::EVENT_KEY){
            $preparationId=(int)($row->preparation_id??0);
            if($preparationId<1)return;
            PortalNotificationRecorder::student($studentId,$eventKey,'post_intro_survey',$preparationId,$variables,(string)($variables['survey_url']??''));
            return;
        }
        PortalNotificationRecorder::student($studentId,$eventKey,'booking_notification',(int)$row->id,$variables,(string)($variables['portal_url']??''));
    }

    /** Mobile is fail-soft: it can never roll back the durable booking or the email hand-off. */
    private static function mobile(object $row,string $eventKey,array $variables):array{
        if($eventKey===PostIntroSurveyWorker::EVENT_KEY)return array('state'=>'skipped','reason'=>'survey_email_portal_only');
        if(!class_exists(BookingNotificationMobileDispatcher::class))return array('state'=>'skipped','reason'=>'runtime_unavailable');
        try{return (new BookingNotificationMobileDispatcher())->dispatch($row,$eventKey,$variables);}
        catch(\Throwable $error){do_action('dzn_booking_mobile_notification_failed',(int)$row->id,get_class($error));return array('state'=>'failed','reason'=>'runtime_exception');}
    }

    /** A bounded, contact-free mobile outcome for the existing booking queue diagnostic field. */
    private static function mobileDiagnostic(array $mobile):?string{
        if((string)($mobile['state']??'')==='accepted')return null;
        $reason=(string)($mobile['reason']??'');
        if($reason==='')$reason=(string)($mobile['state']??'unknown');
        $reason=preg_replace('/[^a-z0-9_]+/','_',strtolower($reason))??'unknown';
        return substr('mobile_'.trim($reason,'_'),0,64);
    }

    private static function headers(object $row):array{
        $host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        if(!preg_match('/^[a-z0-9.-]+$/D',$host))$host='delnavazan.invalid';
        $identity='booking-'.strtolower((string)$row->uid).'@'.$host;
        return array('Content-Type: text/plain; charset=UTF-8','Message-ID: <'.$identity.'>','X-Delnavazan-Notification-ID: '.(string)$row->uid);
    }

    private static function recipientEmail(object $row):string{return BookingNotificationRecipient::email($row);}
    private static function portalLabel(object $row):string{return (string)(str_ends_with((string)$row->notification_kind,'_teacher')?'رفتن به پرتال مدرس':'رفتن به حساب کاربری');}

    private static function message(object $row):array{
        $name=trim((string)$row->full_name);$teacher=trim((string)$row->teacher_name);$course=trim((string)$row->course_name);
        $portal=home_url('/student-portal/');$teacherPortal=home_url('/teacher-portal/');
        $base=array('name'=>$name,'teacher'=>$teacher,'course'=>$course,'portal_url'=>$portal,'teacher_portal_url'=>$teacherPortal,'student'=>$name);
        if((string)$row->notification_kind===PostIntroSurveyWorker::NOTIFICATION_KIND){
            $course=trim((string)($row->preparation_course_name??$course));
            $survey=add_query_arg('post_intro_survey','1',$portal).'#dzn-post-intro-survey';
            $holdNote='مهلت نگه‌داشتن این جایگاه در حال پایان است.';
            try{if(strtotime(CanonicalContinuationRule::expiresAt((string)$row->ends_at_utc,(string)$row->slot_start_utc).' UTC')<=time())$holdNote='مهلت نگه‌داشتن این جایگاه به پایان رسیده است.';}catch(\Throwable){}
            $variables=array('name'=>$name,'teacher'=>$teacher,'course'=>$course,'hold_status_note'=>$holdNote,'survey_url'=>$survey,'portal_url'=>$portal);
            return array('دلنوازان · فرصت ثبت‌نام شما',"درود {$name}،\n\n{$holdNote} ثبت‌نام کلاس «{$course}» با {$teacher} تکمیل نشده است.\n\nاگر تصمیم به ادامه نگرفته‌اید، لطفاً به ما بگویید چه چیزی مانع شد تا بتوانیم کمک کنیم.\n\nبرای ثبت پاسخ به حساب کاربری بروید:\n{$survey}\n\nبا احترام، دلنوازان",$variables);
        }
        if((string)$row->notification_kind==='requested_student'){
            $timezone=in_array((string)$row->student_timezone,timezone_identifiers_list(),true)?(string)$row->student_timezone:(string)$row->schedule_timezone;
            $when=self::formatWhen((string)$row->starts_at_utc,(string)$row->ends_at_utc,$timezone,self::calendarPreference($row));
            return array('Delnavazan booking request received',"Hello {$name},\n\nYour introductory {$course} lesson request for {$when} was received. We will send the final class details after the Teacher confirms.\n\nOpen your account: {$portal}\n\nDelnavazan",$base+array('when'=>$when));
        }
        if((string)$row->notification_kind==='requested_teacher'){
            $timezone=in_array((string)$row->teacher_timezone,timezone_identifiers_list(),true)?(string)$row->teacher_timezone:(string)$row->schedule_timezone;
            $when=self::formatWhen((string)$row->starts_at_utc,(string)$row->ends_at_utc,$timezone,'gregorian');
            $createdAt=trim((string)($row->delivery_created_at??($row->created_at??'')));
            $deadlineUtc=$createdAt!==''?gmdate('Y-m-d H:i:s',strtotime($createdAt.' UTC')+86400):gmdate('Y-m-d H:i:s',time()+86400);
            $deadline=self::formatInstant($deadlineUtc,$timezone);
            return array('New Delnavazan booking request',"Hello {$teacher},\n\nA new introductory {$course} booking request from {$name} was received for {$when}.\n\nPlease respond within 24 hours, by {$deadline}. If no response is received, the request will be offered to the next available Teacher.\n\nReview it in your Teacher Portal: {$teacherPortal}\n\nDelnavazan",$base+array('when'=>$when,'response_window'=>'۲۴ ساعت','response_deadline'=>$deadline));
        }
        if((string)$row->notification_kind==='cancelled'){
            $timezone=in_array((string)$row->student_timezone,timezone_identifiers_list(),true)?(string)$row->student_timezone:(string)$row->schedule_timezone;
            $when=self::formatWhen((string)$row->starts_at_utc,(string)$row->ends_at_utc,$timezone,self::calendarPreference($row));
            return array('Delnavazan introductory lesson cancelled',"Hello {$name},\n\nYour introductory {$course} lesson with {$teacher} for {$when} has been cancelled. No attendance or payment decision was inferred from this cancellation.\n\nOpen your account: {$portal}\n\nDelnavazan",$base+array('when'=>$when));
        }
        if((string)$row->notification_kind==='cancelled_teacher'){
            $timezone=in_array((string)$row->teacher_timezone,timezone_identifiers_list(),true)?(string)$row->teacher_timezone:(string)$row->schedule_timezone;
            $when=self::formatWhen((string)$row->starts_at_utc,(string)$row->ends_at_utc,$timezone,'gregorian');
            return array('Delnavazan introductory lesson cancelled',"Hello {$teacher},\n\nThe introductory {$course} lesson with {$name} for {$when} has been cancelled. No attendance or payment decision was inferred from this cancellation.\n\nOpen your Teacher Portal: {$teacherPortal}\n\nDelnavazan",$base+array('when'=>$when));
        }
        if((string)$row->notification_kind==='rescheduled'){
            $timezone=in_array((string)$row->student_timezone,timezone_identifiers_list(),true)?(string)$row->student_timezone:(string)$row->schedule_timezone;
            $when=self::formatWhen((string)$row->starts_at_utc,(string)$row->ends_at_utc,$timezone,self::calendarPreference($row));
            return array('Delnavazan introductory lesson rescheduled',"Hello {$name},\n\nYour introductory {$course} lesson with {$teacher} has been rescheduled to {$when}.\n\nOpen your account: {$portal}\n\nDelnavazan",$base+array('when'=>$when));
        }
        if((string)$row->notification_kind==='rescheduled_teacher'){
            $timezone=in_array((string)$row->teacher_timezone,timezone_identifiers_list(),true)?(string)$row->teacher_timezone:(string)$row->schedule_timezone;
            $when=self::formatWhen((string)$row->starts_at_utc,(string)$row->ends_at_utc,$timezone,'gregorian');
            return array('Delnavazan introductory lesson rescheduled',"Hello {$teacher},\n\nThe introductory {$course} lesson with {$name} has been rescheduled to {$when}.\n\nOpen your Teacher Portal: {$teacherPortal}\n\nDelnavazan",$base+array('when'=>$when));
        }
        if((string)$row->notification_kind==='post_intro_invitation')return array('Your Delnavazan enrolment is ready',"Hello {$name},\n\n{$teacher} confirmed that your introductory {$course} lesson was delivered. You can now complete payment and confirm your enrolment with the same Teacher.\n\nOpen your account: {$portal}\n\nYour enrolment, weekly schedule and future lessons are activated automatically after the payment is verified. No further Teacher approval is required.\n\nDelnavazan",$base);
        if((string)$row->notification_kind==='post_intro_reminder')return array('Reminder: complete your Delnavazan enrolment',"Hello {$name},\n\nThis is a reminder that your introductory {$course} lesson with {$teacher} is complete and your continuation decision and payment are still outstanding. The available reservation expires at the deadline shown in your account.\n\nOpen your account: {$portal}\n\nIf you have already paid, no further action is required and the payment evidence will be reviewed.\n\nDelnavazan",$base);
        if((string)$row->notification_kind==='post_intro_activated')return array('Your Delnavazan enrolment is active',"Hello {$name},\n\nYour payment was verified and your {$course} enrolment with {$teacher} is now active. Your weekly schedule and future lessons are available in your account.\n\nOpen your account: {$portal}\n\nDelnavazan",$base);
        if((string)$row->notification_kind==='declined')return array('Delnavazan booking request update',"Hello {$name},\n\n{$teacher} is unable to accept your requested introductory lesson. The request has not been confirmed and no calendar event was created.\n\nDelnavazan",$base);
        if((string)$row->notification_kind!=='confirmed'||(int)$row->confirmation_record_id<1)throw new \RuntimeException('Booking confirmation notification incomplete');
        $timezone=in_array((string)$row->student_timezone,timezone_identifiers_list(),true)?(string)$row->student_timezone:(string)$row->schedule_timezone;
        $when=self::formatWhen((string)$row->starts_at_utc,(string)$row->ends_at_utc,$timezone,self::calendarPreference($row));
        return array('دلنوازان · کلاس معارفه شما تأیید شد',"درود {$name}،\n\nکلاس معارفهٔ «{$course}» با {$teacher} تأیید شد.\n\nزمان کلاس: {$when}\n\nاین کلاس در محیط کلاس آنلاین دلنوازان برگزار می‌شود. کمی پیش از زمان کلاس وارد حساب کاربری خود شوید و کلاس را از همان‌جا آغاز کنید. دسترسی کلاس را در اختیار دیگران قرار ندهید.\n\nبرای دیدن برنامهٔ کلاس‌ها وارد حساب کاربری خود شوید:\n{$portal}\n\nبا احترام، دلنوازان",$base+array('when'=>$when));
    }

    /**
     * The recipient's explicit calendar preference wins. `auto` (or an older blank value) follows
     * the recipient's language: Persian readers see the Persian calendar and everyone else Gregorian.
     */
    private static function calendarPreference(object $row):string{
        $preference=strtolower(trim((string)($row->student_calendar??'')));
        if(in_array($preference,array('persian','gregorian'),true))return $preference;
        $locale=trim((string)($row->student_locale??''));
        if($locale==='')$locale=trim((string)($row->student_language??''));
        return str_starts_with(strtolower($locale),'fa')?'persian':'gregorian';
    }

    private static function formatWhen(string $startsAtUtc,string $endsAtUtc,string $timezone,string $calendar):string{
        try{$zone=new \DateTimeZone($timezone);}catch(\Throwable){$zone=new \DateTimeZone('UTC');$timezone='UTC';}
        try{
            $start=(new \DateTimeImmutable($startsAtUtc,new \DateTimeZone('UTC')))->setTimezone($zone);
            $end=(new \DateTimeImmutable($endsAtUtc,new \DateTimeZone('UTC')))->setTimezone($zone);
        }catch(\Throwable){return trim($startsAtUtc.' '.$timezone);}
        if($calendar==='persian'&&class_exists('IntlCalendar')){
            try{
                $persian=\IntlCalendar::createInstance($zone,'@calendar=persian');
                $persian->setTime($start->getTimestamp()*1000);
                $months=array(1=>'فروردین',2=>'اردیبهشت',3=>'خرداد',4=>'تیر',5=>'مرداد',6=>'شهریور',7=>'مهر',8=>'آبان',9=>'آذر',10=>'دی',11=>'بهمن',12=>'اسفند');
                $weekdays=array(1=>'یکشنبه',2=>'دوشنبه',3=>'سه‌شنبه',4=>'چهارشنبه',5=>'پنجشنبه',6=>'جمعه',7=>'شنبه');
                $digits=static fn($value):string=>strtr((string)$value,array('0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹'));
                $month=(int)$persian->get(\IntlCalendar::FIELD_MONTH)+1;
                $weekday=(int)$persian->get(\IntlCalendar::FIELD_DAY_OF_WEEK);
                return ($weekdays[$weekday]??'').' '.$digits((int)$persian->get(\IntlCalendar::FIELD_DAY_OF_MONTH)).' '.($months[$month]??'').' '.$digits((int)$persian->get(\IntlCalendar::FIELD_YEAR)).'، ساعت '.$digits($start->format('H:i')).' تا '.$digits($end->format('H:i')).' به وقت '.$timezone;
            }catch(\Throwable){}
        }
        return $start->format('l j F Y, H:i').'–'.$end->format('H:i').' '.$timezone;
    }

    private static function formatInstant(string $utc,string $timezone):string{
        try{
            $zone=new \DateTimeZone($timezone);
            $at=(new \DateTimeImmutable($utc,new \DateTimeZone('UTC')))->setTimezone($zone);
            return $at->format('l j F Y, H:i').' '.$timezone;
        }catch(\Throwable){return trim($utc.' '.$timezone);}
    }
}
