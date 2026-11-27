<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;
use Delnavazan\Platform\Integrations\Notifications\DelnavazanEmailRenderer;
use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
use Delnavazan\Platform\Integrations\Notifications\NotificationChannelPolicy;
use Delnavazan\Platform\Integrations\Notifications\NotificationCopyResolver;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary;
use Delnavazan\Platform\Integrations\Notifications\PhoneNumberNormalizer;
use Delnavazan\Platform\Integrations\Notifications\TelnyxConfiguration;
use Delnavazan\Platform\Integrations\TelnyxWhatsAppAdapter;

/**
 * Renewal-automation notifications for the enrolled Student.
 *
 * One class owns the six canonical renewal events:
 *   - `renewal.automatic_charged.student`  — a collection intent committed `submitted → confirmed`
 *     for an `automatic_charge` intent (event-driven);
 *   - `renewal.automatic_failed.student`   — a collection intent committed `submitted → failed`
 *     for an `automatic_charge` intent (event-driven);
 *   - `renewal.automatic_upcoming.student` — a `pending` automatic cycle announces its charge before
 *     `automatic_charge_at` (clock-driven);
 *   - `renewal.guarantee_deadline_approaching.student` — a `guarantee_protected` cycle warns before
 *     `guarantee_deadline_at` (clock-driven);
 *   - `renewal.manual_payment_required.student` — a `payment_required` manual cycle requests payment
 *     before `guarantee_deadline_at` (clock-driven);
 *   - `renewal.term_lapsed.student`        — a cycle committed to `lapsed` (event-driven).
 *
 * The three event-driven notices are produced after the authoritative R2 aggregate fact commits and
 * defer out of band (`wp_schedule_single_event`); the three clock-driven notices are found by the
 * bounded `RenewalAutomationWorker` scheduler and produced through this same dispatch path. Every
 * notice is idempotent per subject + event (the portal inbox row is the durable record,
 * `source_kind=renewal_automation`, `source_id` = the collection intent / renewal cycle id), resolves
 * the recipient to the beneficiary Student only, and re-verifies the committed aggregate state at
 * dispatch time so a rolled-back or re-transitioned aggregate can never leak a wrong notice.
 *
 * WhatsApp is delivered strictly through the approved registry-mapped provider template (resolved and
 * enforced by the Telnyx WhatsApp adapter). An unmapped or unconfigured route skips with a stable
 * reason; free-form WhatsApp text is never sent. The message body is never logged. No leg mutates a
 * renewal or finance decision; reads are SELECT-only.
 */
final class RenewalAutomationNotificationService {
    public const RENEWAL_AUTOMATIC_CHARGED_EVENT='renewal.automatic_charged.student';
    public const RENEWAL_AUTOMATIC_FAILED_EVENT='renewal.automatic_failed.student';
    public const RENEWAL_AUTOMATIC_UPCOMING_EVENT='renewal.automatic_upcoming.student';
    public const RENEWAL_GUARANTEE_DEADLINE_EVENT='renewal.guarantee_deadline_approaching.student';
    public const RENEWAL_MANUAL_PAYMENT_REQUIRED_EVENT='renewal.manual_payment_required.student';
    public const RENEWAL_TERM_LAPSED_EVENT='renewal.term_lapsed.student';
    public const SOURCE_KIND='renewal_automation';
    public const DISPATCH_HOOK='dzn_renewal_automation_notification';
    public const AUDIENCE='student';

    /** @var callable(string,string,string,string):bool */
    private $transport;
    /** @var callable(string,int):?array */
    private $resolve;
    /** @var callable(int,string,string,int,array,?string):?int */
    private $record;
    /** @var callable(string,int,string):bool */
    private $seen;
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
        ?callable $whatsappSender=null,
        ?callable $whatsappAllowed=null,
        ?callable $whatsappProviderReady=null
    ){
        $this->transport=$transport??static function(string $email,string $subject,string $body,string $audience='student'):bool{
            if(class_exists(DelnavazanEmailRenderer::class))return DelnavazanEmailRenderer::send($email,$subject,$body,array('locale'=>NotificationCatalogue::PRIMARY_LOCALE,'audience'=>$audience));
            return function_exists('wp_mail')?(bool)wp_mail($email,$subject,$body):false;
        };
        $this->resolve=$resolve??array(__CLASS__,'resolveSubject');
        $this->record=$record??static function(int $recipientId,string $eventKey,string $sourceKind,int $sourceId,array $variables,?string $linkUrl):?int{
            if(!class_exists(PortalNotificationRecorder::class))return null;
            return PortalNotificationRecorder::student($recipientId,$eventKey,$sourceKind,$sourceId,$variables,$linkUrl);
        };
        $this->seen=$seen??array(__CLASS__,'alreadyRecorded');
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
        add_action('dzn_phase_2a2r2_after_collection_intent_event_insert',array(__CLASS__,'onCollectionIntentEvent'),10,2);
        add_action('dzn_phase_2a2r2_after_cycle_event_insert',array(__CLASS__,'onCycleEvent'),10,2);
        add_action(self::DISPATCH_HOOK,array(__CLASS__,'onDispatch'),10,2);
    }

    /**
     * The two automatic-charge notices follow the committed `confirm` and `record_failure` transitions
     * of a collection intent. The manual collection intent is owned by PaymentRefundNotificationService,
     * so the kind is re-verified at dispatch and only an `automatic_charge` intent produces here.
     */
    public static function onCollectionIntentEvent(string $operation,int $intentId):void{
        if($operation==='confirm')self::queue(self::RENEWAL_AUTOMATIC_CHARGED_EVENT,$intentId);
        elseif($operation==='record_failure')self::queue(self::RENEWAL_AUTOMATIC_FAILED_EVENT,$intentId);
    }

    /** `renewal.term_lapsed.student` follows the committed `lapse` transition of a renewal cycle. */
    public static function onCycleEvent(string $operation,int $cycleId):void{
        if($operation==='lapse')self::queue(self::RENEWAL_TERM_LAPSED_EVENT,$cycleId);
    }

    /** Out-of-band dispatch: the canonical R2 transaction is never blocked by a transport. */
    public static function onDispatch(string $eventKey,int $subjectId):void{
        try{$result=(new self())->dispatch($eventKey,$subjectId);}
        catch(\Throwable $error){do_action('dzn_renewal_automation_notification_failed',$eventKey,$subjectId,get_class($error));return;}
        do_action('dzn_renewal_automation_notification_dispatched',$eventKey,$subjectId,$result);
    }

    private static function queue(string $eventKey,int $subjectId):void{
        if($subjectId<1)return;
        if(!function_exists('wp_schedule_single_event'))return;
        if(function_exists('wp_next_scheduled')&&wp_next_scheduled(self::DISPATCH_HOOK,array($eventKey,$subjectId)))return;
        wp_schedule_single_event(time()+5,self::DISPATCH_HOOK,array($eventKey,$subjectId));
    }

    /** The one event-driven event each hook operation owns, or null when the operation is not a producer fact. */
    public static function eventFor(string $operation):?string{
        return match($operation){
            'confirm'=>self::RENEWAL_AUTOMATIC_CHARGED_EVENT,
            'record_failure'=>self::RENEWAL_AUTOMATIC_FAILED_EVENT,
            'lapse'=>self::RENEWAL_TERM_LAPSED_EVENT,
            default=>null,
        };
    }

    /**
     * Produce one notice for one subject + event across the portal, email and WhatsApp legs.
     * Idempotent on re-run; the result never carries a message body.
     *
     * @return array{produced:bool,portal:array,email:array,whatsapp:array}
     */
    public function dispatch(string $eventKey,int $subjectId):array{
        if($subjectId<1)return self::skipped('invalid_subject');
        if(!self::knownEvent($eventKey))return self::skipped('unknown_event');
        $row=($this->resolve)($eventKey,$subjectId);
        if(!is_array($row))return self::skipped('recipient_unresolved');
        $recipientId=(int)($row['student_id']??0);
        if($recipientId<1)return self::skipped('recipient_unresolved');
        if(!$this->applicable($eventKey,$row))return self::skipped('fact_not_applicable');
        if(($this->seen)($eventKey,$subjectId,self::AUDIENCE)){
            return array(
                'produced'=>false,
                'portal'=>array('recorded'=>false,'id'=>null,'idempotent'=>true),
                'email'=>array('state'=>'skipped','reason'=>'already_recorded'),
                'whatsapp'=>array('state'=>'skipped','reason'=>'already_recorded'),
            );
        }
        $variables=$this->variables($eventKey,$row);
        $linkUrl=(string)($variables['portal_url']??'');
        $recorded=($this->record)($recipientId,$eventKey,self::SOURCE_KIND,$subjectId,$variables,$linkUrl);
        $email=$this->email($eventKey,$row,$variables);
        $whatsapp=$this->whatsapp($eventKey,$row,$variables,$recorded);
        return array(
            'produced'=>$recorded!==null,
            'portal'=>array('recorded'=>$recorded!==null,'id'=>$recorded),
            'email'=>$email,
            'whatsapp'=>$whatsapp,
        );
    }

    /** The re-verified committed state (and aggregate kind/mode) each notice requires. */
    private function applicable(string $eventKey,array $row):bool{
        $state=(string)($row['state']??'');
        $kind=(string)($row['kind']??'');
        $mode=(string)($row['collection_mode']??'');
        return match($eventKey){
            self::RENEWAL_AUTOMATIC_CHARGED_EVENT=>$state==='confirmed'&&$kind==='automatic_charge',
            self::RENEWAL_AUTOMATIC_FAILED_EVENT=>$state==='failed'&&$kind==='automatic_charge',
            self::RENEWAL_AUTOMATIC_UPCOMING_EVENT=>$state==='pending'&&$mode==='automatic'&&trim((string)($row['automatic_charge_at']??''))!=='',
            self::RENEWAL_GUARANTEE_DEADLINE_EVENT=>$state==='guarantee_protected',
            self::RENEWAL_MANUAL_PAYMENT_REQUIRED_EVENT=>$state==='payment_required'&&$mode==='manual',
            self::RENEWAL_TERM_LAPSED_EVENT=>$state==='lapsed',
            default=>false,
        };
    }

    private function variables(string $eventKey,array $row):array{
        $portal=(string)($row['student_portal_url']??'');
        if($portal===''&&function_exists('home_url'))$portal=home_url('/student-portal/');
        $variables=array(
            'name'=>(string)($row['student_name']??''),
            'course'=>(string)($row['course_name']??''),
            'portal_url'=>$portal,
        );
        if($eventKey===self::RENEWAL_TERM_LAPSED_EVENT)return $variables;
        $variables['amount']=PricingMoney::format((int)($row['amount_minor']??0),(string)($row['currency']??''),true);
        if($eventKey===self::RENEWAL_AUTOMATIC_CHARGED_EVENT){
            $variables['paid_date']=$this->formatDate((string)($row['paid_date']??''));
        }elseif($eventKey===self::RENEWAL_AUTOMATIC_UPCOMING_EVENT){
            $variables['due_date']=$this->formatDate((string)($row['automatic_charge_at']??''));
        }else{
            $variables['due_date']=$this->formatDate((string)($row['guarantee_deadline_at']??''));
        }
        return $variables;
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
    private function email(string $eventKey,array $row,array $variables):array{
        $email=trim((string)($row['student_email']??''));
        if($email===''||(function_exists('is_email')&&!is_email($email)))return array('state'=>'skipped','reason'=>'no_recipient');
        if(class_exists(NotificationTemplateLibrary::class)&&!(new NotificationTemplateLibrary())->eventChannelEnabled($eventKey,'email'))return array('state'=>'suppressed','reason'=>'email_disabled_by_administrator');
        $copy=$this->renderedCopy($eventKey,'email',$variables);
        if(class_exists(NotificationCopyResolver::class))$copy=NotificationCopyResolver::email($eventKey,NotificationCatalogue::PRIMARY_LOCALE,$variables,$copy);
        try{$sent=(bool)($this->transport)($email,(string)($copy['subject']??''),(string)($copy['body']??''),self::AUDIENCE);}
        catch(\Throwable){$sent=false;}
        return $sent?array('state'=>'sent'):array('state'=>'failed','reason'=>'transport_refused');
    }

    /**
     * WhatsApp is independent and fail-soft. It delivers only through the approved registry-mapped
     * provider template (resolved and enforced by the Telnyx WhatsApp adapter); the body is never
     * rendered as free-form text and is never recorded or logged. A missing number, an unmapped
     * template or an unconfigured provider skips with a stable reason.
     *
     * @return array{state:string,reason?:string}
     */
    private function whatsapp(string $eventKey,array $row,array $variables,?int $notificationId):array{
        if((new NotificationSuppressionGuard())->suppressed('student',(int)($row['student_id']??0),$eventKey))return array('state'=>'skipped','reason'=>'suppressed');
        $contact=PhoneNumberNormalizer::pair($row['student_phone']??'',$row['student_whatsapp']??'');
        $phone=$contact['whatsapp'];
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

    /** @return array{produced:bool,portal:array,email:array,whatsapp:array} */
    private static function skipped(string $reason):array{
        return array(
            'produced'=>false,
            'portal'=>array('recorded'=>false,'id'=>null),
            'email'=>array('state'=>'skipped','reason'=>$reason),
            'whatsapp'=>array('state'=>'skipped','reason'=>$reason),
        );
    }

    /** Whether the event key is one of the six renewal notices this producer owns. */
    private static function knownEvent(string $eventKey):bool{
        return in_array($eventKey,array(
            self::RENEWAL_AUTOMATIC_CHARGED_EVENT,self::RENEWAL_AUTOMATIC_FAILED_EVENT,
            self::RENEWAL_AUTOMATIC_UPCOMING_EVENT,self::RENEWAL_GUARANTEE_DEADLINE_EVENT,
            self::RENEWAL_MANUAL_PAYMENT_REQUIRED_EVENT,self::RENEWAL_TERM_LAPSED_EVENT,
        ),true);
    }

    /** One renewal subject's beneficiary Student and its committed finance facts. */
    public static function resolveSubject(string $eventKey,int $subjectId):?array{
        if($eventKey===self::RENEWAL_AUTOMATIC_CHARGED_EVENT||$eventKey===self::RENEWAL_AUTOMATIC_FAILED_EVENT)return self::resolveCollectionIntent($subjectId);
        if($eventKey===self::RENEWAL_AUTOMATIC_UPCOMING_EVENT||$eventKey===self::RENEWAL_GUARANTEE_DEADLINE_EVENT||$eventKey===self::RENEWAL_MANUAL_PAYMENT_REQUIRED_EVENT||$eventKey===self::RENEWAL_TERM_LAPSED_EVENT)return self::resolveCycle($subjectId);
        return null;
    }

    private static function resolveCollectionIntent(int $intentId):?array{
        global $wpdb;
        if(!is_object($wpdb))return null;
        $p=$wpdb->prefix.'dzn_';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT i.state,i.kind,
                    r.student_id,r.course_id,
                    s.email student_email,s.phone student_phone,s.whatsapp_phone student_whatsapp,s.country_code student_country_code,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.reference_code,''),'هنرجو') student_name,
                    COALESCE(NULLIF(c.name_fa,''),NULLIF(c.name_en,''),'کلاس') course_name,
                    o.amount_minor,o.currency,
                    rc.guarantee_deadline_at,st.settled_at paid_date
             FROM {$p}collection_intents i
             INNER JOIN {$p}renewal_cycles rc ON rc.id=i.renewal_cycle_id
             INNER JOIN {$p}recurring_enrolments r ON r.id=rc.recurring_enrolment_id
             INNER JOIN {$p}students s ON s.id=r.student_id AND s.status='active' AND s.archived_at IS NULL
             LEFT JOIN {$p}courses c ON c.id=r.course_id
             LEFT JOIN {$p}commercial_offer_obligations o ON o.id=i.obligation_id
             LEFT JOIN {$p}commercial_obligation_settlements st ON st.obligation_id=i.obligation_id
             WHERE i.id=%d LIMIT 1",
            $intentId
        ));
        if(!$row)return null;
        return self::shape($row,array('kind'=>(string)($row->kind??''),'guarantee_deadline_at'=>(string)($row->guarantee_deadline_at??''),'paid_date'=>(string)($row->paid_date??'')));
    }

    private static function resolveCycle(int $cycleId):?array{
        global $wpdb;
        if(!is_object($wpdb))return null;
        $p=$wpdb->prefix.'dzn_';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT cy.state,cy.collection_mode,cy.amount_minor,cy.currency,cy.automatic_charge_at,cy.guarantee_deadline_at,
                    r.student_id,r.course_id,
                    s.email student_email,s.phone student_phone,s.whatsapp_phone student_whatsapp,s.country_code student_country_code,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.reference_code,''),'هنرجو') student_name,
                    COALESCE(NULLIF(c.name_fa,''),NULLIF(c.name_en,''),'کلاس') course_name
             FROM {$p}renewal_cycles cy
             INNER JOIN {$p}recurring_enrolments r ON r.id=cy.recurring_enrolment_id
             INNER JOIN {$p}students s ON s.id=r.student_id AND s.status='active' AND s.archived_at IS NULL
             LEFT JOIN {$p}courses c ON c.id=r.course_id
             WHERE cy.id=%d LIMIT 1",
            $cycleId
        ));
        if(!$row)return null;
        return self::shape($row,array('collection_mode'=>(string)($row->collection_mode??''),'automatic_charge_at'=>(string)($row->automatic_charge_at??''),'guarantee_deadline_at'=>(string)($row->guarantee_deadline_at??'')));
    }

    /** @return array<string,mixed> */
    private static function shape(object $row,array $extra=array()):array{
        return array_merge(array(
            'student_id'=>(int)($row->student_id??0),
            'state'=>(string)($row->state??''),
            'student_email'=>trim((string)($row->student_email??'')),
            'student_phone'=>trim((string)($row->student_phone??'')),
            'student_whatsapp'=>trim((string)($row->student_whatsapp??'')),
            'student_country_code'=>trim((string)($row->student_country_code??'')),
            'student_name'=>trim((string)($row->student_name??'')),
            'course_name'=>trim((string)($row->course_name??'')),
            'amount_minor'=>(int)($row->amount_minor??0),
            'currency'=>(string)($row->currency??''),
        ),$extra);
    }

    /** One portal inbox row per subject + event; the durable idempotency record. */
    public static function alreadyRecorded(string $eventKey,int $subjectId,string $audience='student'):bool{
        global $wpdb;
        if(!is_object($wpdb))return false;
        $value=$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dzn_portal_notifications WHERE event_key=%s AND audience=%s AND source_kind=%s AND source_id=%d LIMIT 1",
            $eventKey,$audience,self::SOURCE_KIND,$subjectId
        ));
        return $value!==null&&$value!==false;
    }

    /** Present a stored UTC instant as a short, locale-neutral date. */
    private function formatDate(string $datetime):string{
        $datetime=trim($datetime);
        if($datetime==='')return '';
        $ts=strtotime($datetime.' UTC');
        if($ts===false)return $datetime;
        if(function_exists('wp_date'))return wp_date('Y-m-d',$ts);
        return gmdate('Y-m-d',$ts);
    }
}
