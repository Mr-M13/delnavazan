<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Integrations\Notifications\DelnavazanEmailRenderer;
use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
use Delnavazan\Platform\Integrations\Notifications\NotificationChannelPolicy;
use Delnavazan\Platform\Integrations\Notifications\NotificationCopyResolver;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary;
use Delnavazan\Platform\Integrations\Notifications\PhoneNumberNormalizer;
use Delnavazan\Platform\Integrations\Notifications\TelnyxConfiguration;
use Delnavazan\Platform\Integrations\SmsIrAdapter;
use Delnavazan\Platform\Integrations\TelnyxSmsAdapter;
use Delnavazan\Platform\Integrations\TelnyxWhatsAppAdapter;

/**
 * Lesson-lifecycle notifications for the Teacher and the enrolled Student: access-ready, reminder and
 * cancellation.
 *
 * One class owns the six canonical lesson-lifecycle events (three per audience). It produces after the
 * authoritative lifecycle fact has committed, is idempotent per lesson + event + audience, and resolves
 * the recipient to the lesson's assigned Teacher or its enrolled Student only. Three or four independent
 * delivery legs — the portal inbox (website), email, SMS and (student only) WhatsApp — are produced from
 * one durable record; no leg changes another's outcome and none rolls back a lesson decision.
 *
 * The portal inbox row is the durable idempotency record (`source_kind=lesson_lifecycle`,
 * `source_id=lesson_id`, `audience=teacher|student`), so a re-run converges on the existing row and never
 * duplicates any leg. SMS is routed through the existing provider policy (SMS.ir for `+98`, Telnyx
 * otherwise) and never logs the message body; its outcome is reported as a state + stable reason, never
 * the copy. WhatsApp is the student-only leg and is delivered strictly through the approved
 * registry-mapped provider template; when no approved template is mapped or the provider is not
 * configured it skips with a stable reason, and free-form WhatsApp text is never sent.
 *
 * The canonical lifecycle hooks fire inside the schedule/authority transaction, so the producer defers
 * each notice out of band (`wp_schedule_single_event`) and re-verifies the lifecycle state at dispatch
 * time: a rolled-back schedule or cancellation can never leak a wrong notice.
 */
final class LessonLifecycleNotificationService {
    public const ACCESS_EVENT='lesson.access.teacher';
    public const REMINDER_EVENT='lesson.reminder.teacher';
    public const CANCELLED_EVENT='lesson.cancelled.teacher';
    public const STUDENT_ACCESS_EVENT='lesson.access.student';
    public const STUDENT_REMINDER_EVENT='lesson.reminder.student';
    public const STUDENT_CANCELLED_EVENT='lesson.cancelled.student';
    public const SOURCE_KIND='lesson_lifecycle';
    public const DISPATCH_HOOK='dzn_lesson_lifecycle_notification';

    /** @var callable(string,string,string,string):bool */
    private $transport;
    /** @var callable(int):?array */
    private $resolve;
    /** @var callable(int,string,string,int,array,?string,string):?int */
    private $record;
    /** @var callable(string,int,string):bool */
    private $seen;
    /** @var callable(string,string,int,string):array */
    private $smsSender;
    /** @var callable(string):bool */
    private $smsAllowed;
    /** @var callable(string):bool */
    private $smsProviderReady;
    /** @var callable(string,int,string,array):array */
    private $whatsappSender;
    /** @var callable(string):bool */
    private $whatsappAllowed;
    /** @var callable():bool */
    private $whatsappProviderReady;

    public function __construct(
        ?callable $transport=null,
        ?callable $resolve=null,
        ?callable $record=null,
        ?callable $seen=null,
        ?callable $smsSender=null,
        ?callable $smsAllowed=null,
        ?callable $smsProviderReady=null,
        ?callable $whatsappSender=null,
        ?callable $whatsappAllowed=null,
        ?callable $whatsappProviderReady=null
    ){
        $this->transport=$transport??static function(string $email,string $subject,string $body,string $audience='teacher'):bool{
            if(class_exists(DelnavazanEmailRenderer::class))return DelnavazanEmailRenderer::send($email,$subject,$body,array('locale'=>NotificationCatalogue::PRIMARY_LOCALE,'audience'=>$audience));
            return function_exists('wp_mail')?(bool)wp_mail($email,$subject,$body):false;
        };
        $this->resolve=$resolve??array(__CLASS__,'resolveLesson');
        $this->record=$record??static function(int $recipientId,string $eventKey,string $sourceKind,int $sourceId,array $variables,?string $linkUrl,string $audience='teacher'):?int{
            if(!class_exists(PortalNotificationRecorder::class))return null;
            if($audience==='student')return PortalNotificationRecorder::student($recipientId,$eventKey,$sourceKind,$sourceId,$variables,$linkUrl);
            return PortalNotificationRecorder::teacher($recipientId,$eventKey,$sourceKind,$sourceId,$variables,$linkUrl);
        };
        $this->seen=$seen??array(__CLASS__,'alreadyRecorded');
        $this->smsSender=$smsSender??static function(string $provider,string $to,int $notificationId,string $body):array{
            $settings=new IntegrationSettingsService();
            if($provider==='sms_ir')return (new SmsIrAdapter($settings))->sendBookingNotification($to,$notificationId,$body);
            return (new TelnyxSmsAdapter($settings))->sendBookingNotification($to,$notificationId,$body);
        };
        $this->smsAllowed=$smsAllowed??static function(string $eventKey):bool{
            return class_exists(NotificationChannelPolicy::class)&&NotificationChannelPolicy::allows($eventKey,'sms');
        };
        $this->smsProviderReady=$smsProviderReady??static function(string $provider):bool{
            $settings=new IntegrationSettingsService();
            if($provider==='sms_ir')return $settings->verified('sms_ir');
            return (new TelnyxConfiguration($settings))->smsReady();
        };
        $this->whatsappSender=$whatsappSender??static function(string $to,int $notificationId,string $eventKey,array $parameters):array{
            return (new TelnyxWhatsAppAdapter(new IntegrationSettingsService()))->sendBookingNotification($to,$notificationId,$eventKey,$parameters);
        };
        $this->whatsappAllowed=$whatsappAllowed??static function(string $eventKey):bool{
            return class_exists(NotificationChannelPolicy::class)&&NotificationChannelPolicy::allows($eventKey,'whatsapp');
        };
        $this->whatsappProviderReady=$whatsappProviderReady??static function():bool{
            return (new TelnyxConfiguration(new IntegrationSettingsService()))->whatsappReady();
        };
    }

    public static function register():void{
        add_action('dzn_phase_2a2n_after_command_insert',array(__CLASS__,'onScheduleCommand'),10,2);
        add_action('dzn_phase_2a2m_after_lesson_transition',array(__CLASS__,'onLessonTransition'),10,2);
        add_action(self::DISPATCH_HOOK,array(__CLASS__,'onDispatch'),10,2);
    }

    /** Access is ready the moment the canonical schedule is first created, for both audiences. */
    public static function onScheduleCommand(string $operation,int $lessonId):void{
        if($operation==='schedule_initial'){
            self::queue(self::ACCESS_EVENT,$lessonId);
            self::queue(self::STUDENT_ACCESS_EVENT,$lessonId);
        }
    }

    /** Cancellation is the canonical lesson `authorised → cancelled` transition, for both audiences. */
    public static function onLessonTransition(string $operation,int $lessonId):void{
        if($operation==='cancel'){
            self::queue(self::CANCELLED_EVENT,$lessonId);
            self::queue(self::STUDENT_CANCELLED_EVENT,$lessonId);
        }
    }

    /** Out-of-band dispatch: the canonical transaction is never blocked by a transport. */
    public static function onDispatch(string $eventKey,int $lessonId):void{
        try{$result=(new self())->dispatch($eventKey,$lessonId);}
        catch(\Throwable $error){do_action('dzn_lesson_lifecycle_notification_failed',$eventKey,$lessonId,get_class($error));return;}
        // The outcome (including any SMS/WhatsApp skip reason) is reported without the message body.
        do_action('dzn_lesson_lifecycle_notification_dispatched',$eventKey,$lessonId,$result);
    }

    private static function queue(string $eventKey,int $lessonId):void{
        if($lessonId<1)return;
        if(!function_exists('wp_schedule_single_event'))return;
        if(function_exists('wp_next_scheduled')&&wp_next_scheduled(self::DISPATCH_HOOK,array($eventKey,$lessonId)))return;
        wp_schedule_single_event(time()+5,self::DISPATCH_HOOK,array($eventKey,$lessonId));
    }

    public function notifyAccessReady(int $lessonId):array{return $this->dispatch(self::ACCESS_EVENT,$lessonId);}
    public function notifyReminder(int $lessonId):array{return $this->dispatch(self::REMINDER_EVENT,$lessonId);}
    public function notifyCancelled(int $lessonId):array{return $this->dispatch(self::CANCELLED_EVENT,$lessonId);}
    public function notifyStudentAccessReady(int $lessonId):array{return $this->dispatch(self::STUDENT_ACCESS_EVENT,$lessonId);}
    public function notifyStudentReminder(int $lessonId):array{return $this->dispatch(self::STUDENT_REMINDER_EVENT,$lessonId);}
    public function notifyStudentCancelled(int $lessonId):array{return $this->dispatch(self::STUDENT_CANCELLED_EVENT,$lessonId);}

    /** The audience a lesson-lifecycle event targets, or null when it is not one of the six events. */
    public static function audienceFor(string $eventKey):?string{
        if(in_array($eventKey,array(self::ACCESS_EVENT,self::REMINDER_EVENT,self::CANCELLED_EVENT),true))return 'teacher';
        if(in_array($eventKey,array(self::STUDENT_ACCESS_EVENT,self::STUDENT_REMINDER_EVENT,self::STUDENT_CANCELLED_EVENT),true))return 'student';
        return null;
    }

    /**
     * Produce one notice for one lesson + event + audience, across the portal, email, SMS and (student
     * only) WhatsApp legs. Idempotent on re-run; the result never carries a message body.
     *
     * @return array{produced:bool,portal:array,email:array,sms:array,whatsapp:array}
     */
    public function dispatch(string $eventKey,int $lessonId):array{
        if($lessonId<1)return self::skipped('invalid_lesson');
        $audience=self::audienceFor($eventKey);
        if($audience===null)return self::skipped('unknown_event');
        $row=($this->resolve)($lessonId);
        if(!is_array($row))return self::skipped('recipient_unresolved',$audience);
        $recipientId=$audience==='student'?(int)($row['student_id']??0):(int)($row['teacher_id']??0);
        if($recipientId<1)return self::skipped('recipient_unresolved',$audience);
        if(!$this->applicable($eventKey,(string)($row['lifecycle_state']??'')))return self::skipped('lifecycle_not_applicable',$audience);
        if(($this->seen)($eventKey,$lessonId,$audience)){
            return array(
                'produced'=>false,
                'portal'=>array('recorded'=>false,'id'=>null,'idempotent'=>true),
                'email'=>array('state'=>'skipped','reason'=>'already_recorded'),
                'sms'=>array('state'=>'skipped','reason'=>'already_recorded'),
                'whatsapp'=>array('state'=>'skipped','reason'=>$audience==='student'?'already_recorded':'not_offered'),
            );
        }
        $variables=$this->variables($eventKey,$row);
        $linkUrl=$audience==='student'?(string)($variables['portal_url']??''):(string)($variables['teacher_portal_url']??'');
        $recorded=($this->record)($recipientId,$eventKey,self::SOURCE_KIND,$lessonId,$variables,$linkUrl,$audience);
        $email=$this->email($eventKey,$row,$variables,$audience);
        $sms=$this->sms($eventKey,$row,$variables,$recorded,$audience);
        $whatsapp=$audience==='student'?$this->whatsapp($eventKey,$row,$variables,$recorded):array('state'=>'skipped','reason'=>'not_offered');
        return array(
            'produced'=>$recorded!==null,
            'portal'=>array('recorded'=>$recorded!==null,'id'=>$recorded),
            'email'=>$email,
            'sms'=>$sms,
            'whatsapp'=>$whatsapp,
        );
    }

    /** Access and reminder require an active, scheduled lesson; cancellation requires a cancelled one. */
    private function applicable(string $eventKey,string $state):bool{
        if(in_array($eventKey,array(self::CANCELLED_EVENT,self::STUDENT_CANCELLED_EVENT),true))return $state==='cancelled';
        return $state==='authorised';
    }

    private function variables(string $eventKey,array $row):array{
        return self::audienceFor($eventKey)==='student'?$this->studentVariables($row):$this->teacherVariables($row);
    }

    private function teacherVariables(array $row):array{
        $when=$this->formatWhen((string)($row['starts_at_utc']??''),trim((string)($row['teacher_timezone']??'')),trim((string)($row['schedule_timezone']??'')));
        $portal=trim((string)($row['teacher_portal_url']??''));
        if($portal===''&&function_exists('home_url'))$portal=home_url('/teacher-portal/');
        return array(
            'teacher'=>(string)($row['teacher_name']??''),
            'course'=>(string)($row['course_name']??''),
            'student'=>(string)($row['student_name']??''),
            'when'=>$when,
            'teacher_portal_url'=>$portal,
        );
    }

    private function studentVariables(array $row):array{
        $when=$this->formatWhen((string)($row['starts_at_utc']??''),trim((string)($row['student_timezone']??'')),trim((string)($row['schedule_timezone']??'')));
        $portal=trim((string)($row['student_portal_url']??''));
        if($portal===''&&function_exists('home_url'))$portal=home_url('/student-portal/');
        return array(
            'name'=>(string)($row['student_name']??''),
            'course'=>(string)($row['course_name']??''),
            'teacher'=>(string)($row['teacher_name']??''),
            'when'=>$when,
            'portal_url'=>$portal,
        );
    }

    /** Format one UTC instant in the recipient timezone, falling back to the schedule timezone then UTC. */
    private function formatWhen(string $starts,string $recipientTimezone,string $scheduleTimezone):string{
        $timezone=trim($recipientTimezone);
        if(!in_array($timezone,timezone_identifiers_list(),true))$timezone=trim($scheduleTimezone);
        if(!in_array($timezone,timezone_identifiers_list(),true))$timezone='UTC';
        try{$zone=new \DateTimeZone($timezone);}catch(\Throwable){$zone=new \DateTimeZone('UTC');}
        $when='';
        if($starts!==''){
            $ts=strtotime($starts.' UTC');
            if($ts!==false){
                if(function_exists('wp_date'))$when=wp_date('Y-m-d H:i',$ts,$zone);
                else $when=(new \DateTimeImmutable('@'.$ts))->setTimezone($zone)->format('Y-m-d H:i');
            }
        }
        return $when;
    }

    /** Render the reviewed Persian default copy for one channel; never leaves a placeholder raw. */
    private function renderedCopy(string $eventKey,string $channel,array $variables):array{
        $copy=NotificationCatalogue::defaultCopy($eventKey,$channel,NotificationCatalogue::PRIMARY_LOCALE);
        if(!is_array($copy))$copy=array('subject'=>'','body'=>'');
        $render=static function(string $text)use($variables):string{
            return (string)preg_replace_callback('/\{\{([a-z][a-z0-9_]{0,63})\}\}/',static function(array $m)use($variables):string{
                return array_key_exists($m[1],$variables)?(string)$variables[$m[1]]:'';
            },$text);
        };
        return array('subject'=>$render((string)($copy['subject']??'')),'body'=>$render((string)($copy['body']??'')));
    }

    /** @return array{state:string,reason?:string} */
    private function email(string $eventKey,array $row,array $variables,string $audience):array{
        $email=trim((string)($audience==='student'?($row['student_email']??''):($row['email']??'')));
        if($email===''||(function_exists('is_email')&&!is_email($email)))return array('state'=>'skipped','reason'=>'no_recipient');
        if(class_exists(NotificationTemplateLibrary::class)&&!(new NotificationTemplateLibrary())->eventChannelEnabled($eventKey,'email'))return array('state'=>'suppressed','reason'=>'email_disabled_by_administrator');
        $copy=$this->renderedCopy($eventKey,'email',$variables);
        if(class_exists(NotificationCopyResolver::class))$copy=NotificationCopyResolver::email($eventKey,NotificationCatalogue::PRIMARY_LOCALE,$variables,$copy);
        try{$sent=(bool)($this->transport)($email,(string)($copy['subject']??''),(string)($copy['body']??''),$audience);}
        catch(\Throwable){$sent=false;}
        return $sent?array('state'=>'sent'):array('state'=>'failed','reason'=>'transport_refused');
    }

    /**
     * SMS is independent and fail-soft. Destination policy is SMS.ir for Iranian `+98` numbers and
     * Telnyx otherwise; a missing, invalid or unconfigured route skips with a stable reason and never
     * reaches a provider. The message body is passed only to the provider, never recorded or logged.
     *
     * @return array{state:string,provider?:string,reason?:string}
     */
    private function sms(string $eventKey,array $row,array $variables,?int $notificationId,string $audience):array{
        $phone=trim((string)($audience==='student'?($row['student_phone']??''):($row['phone']??'')));
        if($phone==='')return array('state'=>'skipped','reason'=>'no_phone');
        $country=strtoupper(trim((string)($audience==='student'?($row['student_country_code']??''):($row['country_code']??''))));
        $e164=PhoneNumberNormalizer::toE164($phone,$country!==''?$country:'IR');
        if($e164==='')return array('state'=>'skipped','reason'=>'phone_invalid');
        if(!($this->smsAllowed)($eventKey))return array('state'=>'skipped','reason'=>'channel_disabled');
        $provider=SmsProviderRoutingPolicy::select($e164);
        if($provider==='off')return array('state'=>'skipped','reason'=>'provider_disabled');
        if(!($this->smsProviderReady)($provider))return array('state'=>'skipped','reason'=>'provider_not_configured','provider'=>$provider);
        if($notificationId===null)return array('state'=>'skipped','reason'=>'notification_not_recorded');
        $copy=$this->renderedCopy($eventKey,'sms',$variables);
        if(class_exists(NotificationCopyResolver::class))$copy=NotificationCopyResolver::message($eventKey,'sms',NotificationCatalogue::PRIMARY_LOCALE,$variables,$copy);
        $body=trim((string)($copy['body']??''));
        if($body==='')return array('state'=>'skipped','reason'=>'sms_copy_unavailable');
        try{$outcome=($this->smsSender)($provider,$e164,$notificationId,$body);}
        catch(\Throwable){return array('state'=>'failed','provider'=>$provider,'reason'=>'sms_exception');}
        if(!empty($outcome['acknowledged']))return array('state'=>'accepted','provider'=>$provider);
        $failure=(string)($outcome['permanent_failure']??'');
        if($failure==='')return array('state'=>'failed','provider'=>$provider,'reason'=>'transport_retryable');
        return array('state'=>'failed','provider'=>$provider,'reason'=>$failure);
    }

    /**
     * WhatsApp is the student-only leg, independent and fail-soft. It delivers only through the approved
     * registry-mapped provider template (resolved and enforced by the Telnyx WhatsApp adapter); the body
     * is never rendered as free-form text and is never recorded or logged. A missing WhatsApp number, an
     * unmapped template or an unconfigured provider skips with a stable reason.
     *
     * @return array{state:string,reason?:string}
     */
    private function whatsapp(string $eventKey,array $row,array $variables,?int $notificationId):array{
        $phone=trim((string)($row['student_whatsapp']??''));
        if($phone==='')return array('state'=>'skipped','reason'=>'no_whatsapp_phone');
        $country=strtoupper(trim((string)($row['student_country_code']??'')));
        $e164=PhoneNumberNormalizer::toE164($phone,$country!==''?$country:'IR');
        if($e164==='')return array('state'=>'skipped','reason'=>'phone_invalid');
        if(!($this->whatsappAllowed)($eventKey))return array('state'=>'skipped','reason'=>'channel_disabled');
        if(!($this->whatsappProviderReady)())return array('state'=>'skipped','reason'=>'provider_not_configured');
        if($notificationId===null)return array('state'=>'skipped','reason'=>'notification_not_recorded');
        // Only the catalogue-declared variables are handed to the adapter, which maps them onto the
        // approved template's variable order. A missing or non-scalar variable refuses the send.
        $parameters=array();
        foreach(NotificationCatalogue::variables($eventKey) as $code){
            if(!array_key_exists($code,$variables))return array('state'=>'skipped','reason'=>'variable_contract_mismatch');
            $value=$variables[$code];
            if(is_array($value)||is_object($value))return array('state'=>'skipped','reason'=>'variable_contract_mismatch');
            $parameters[$code]=trim((string)$value);
        }
        try{$outcome=($this->whatsappSender)($e164,$notificationId,$eventKey,$parameters);}
        catch(\Throwable){return array('state'=>'failed','reason'=>'whatsapp_exception');}
        if(!empty($outcome['acknowledged']))return array('state'=>'accepted');
        $reason=(string)($outcome['reason']??'');
        if($reason==='template_not_mapped')return array('state'=>'skipped','reason'=>'provider_template_not_mapped');
        if($reason==='sender_not_configured')return array('state'=>'skipped','reason'=>'provider_not_configured');
        if($reason==='variable_contract_mismatch')return array('state'=>'skipped','reason'=>'variable_contract_mismatch');
        $failure=(string)($outcome['permanent_failure']??'');
        if($failure==='contact_unusable')return array('state'=>'skipped','reason'=>'phone_invalid');
        if($failure==='')return array('state'=>'failed','reason'=>'transport_retryable');
        return array('state'=>'failed','reason'=>$failure);
    }

    /** @return array{produced:bool,portal:array,email:array,sms:array,whatsapp:array} */
    private static function skipped(string $reason,string $audience='teacher'):array{
        return array(
            'produced'=>false,
            'portal'=>array('recorded'=>false,'id'=>null),
            'email'=>array('state'=>'skipped','reason'=>$reason),
            'sms'=>array('state'=>'skipped','reason'=>$reason),
            'whatsapp'=>array('state'=>'skipped','reason'=>$audience==='student'?$reason:'not_offered'),
        );
    }

    /**
     * Resolve the lesson's assigned Teacher (email + phone + country) and enrolled Student (email, phone,
     * WhatsApp and country) together with the canonical schedule. Both recipient projections come from the
     * existing identity tables; no new recipient model is introduced.
     */
    public static function resolveLesson(int $lessonId):?array{
        global $wpdb;
        if(!is_object($wpdb))return null;
        $p=$wpdb->prefix.'dzn_';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT l.teacher_id,l.student_id,l.lifecycle_state,
                    t.email,t.phone,t.country_code,t.timezone teacher_timezone,
                    COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name,'') teacher_name,
                    s.email student_email,s.phone student_phone,s.whatsapp_phone student_whatsapp,
                    s.country_code student_country_code,s.timezone student_timezone,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.reference_code,''),'هنرجو') student_name,
                    v.starts_at_utc,v.schedule_timezone,
                    COALESCE(NULLIF(i.name_fa,''),NULLIF(c.name_fa,''),NULLIF(c.name_en,''),'کلاس') course_name
             FROM {$p}lessons l
             INNER JOIN {$p}teachers t ON t.id=l.teacher_id AND t.status='active' AND t.archived_at IS NULL
             INNER JOIN {$p}students s ON s.id=l.student_id
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             LEFT JOIN {$p}courses c ON c.id=l.course_id
             LEFT JOIN {$p}instruments i ON i.id=c.instrument_id
             WHERE l.id=%d AND l.archived_at IS NULL AND l.activity_type<>'test' LIMIT 1",
            $lessonId
        ));
        if(!$row)return null;
        return array(
            'teacher_id'=>(int)$row->teacher_id,
            'student_id'=>(int)$row->student_id,
            'lifecycle_state'=>(string)$row->lifecycle_state,
            'email'=>trim((string)($row->email??'')),
            'phone'=>trim((string)($row->phone??'')),
            'country_code'=>trim((string)($row->country_code??'')),
            'teacher_name'=>trim((string)($row->teacher_name??'')),
            'student_name'=>trim((string)($row->student_name??'')),
            'course_name'=>trim((string)($row->course_name??'')),
            'starts_at_utc'=>(string)($row->starts_at_utc??''),
            'teacher_timezone'=>(string)($row->teacher_timezone??''),
            'schedule_timezone'=>(string)($row->schedule_timezone??''),
            'student_email'=>trim((string)($row->student_email??'')),
            'student_phone'=>trim((string)($row->student_phone??'')),
            'student_whatsapp'=>trim((string)($row->student_whatsapp??'')),
            'student_country_code'=>trim((string)($row->student_country_code??'')),
            'student_timezone'=>(string)($row->student_timezone??''),
        );
    }

    /** One portal inbox row per lesson + event + audience; the durable idempotency record. */
    public static function alreadyRecorded(string $eventKey,int $lessonId,string $audience='teacher'):bool{
        global $wpdb;
        if(!is_object($wpdb))return false;
        $value=$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dzn_portal_notifications WHERE event_key=%s AND audience=%s AND source_kind=%s AND source_id=%d LIMIT 1",
            $eventKey,$audience,self::SOURCE_KIND,$lessonId
        ));
        return $value!==null&&$value!==false;
    }
}
