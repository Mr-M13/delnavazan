<?php
namespace Delnavazan\Platform\Integrations\Notifications;

use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Application\SmsProviderRoutingPolicy;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationManualSendRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationSuppressionRepository;
use Delnavazan\Platform\Integrations\SmsIrAdapter;
use Delnavazan\Platform\Integrations\TelnyxSmsAdapter;
use Delnavazan\Platform\Integrations\TelnyxWhatsAppAdapter;

/**
 * Administrator-invoked, operational manual send.
 *
 * This is a deliberate, narrow delivery authority — a second one does not exist. It composes one
 * message for one audience, resolves the recipients from the academy roster or from typed numbers,
 * honours the recipient STOP/opt-out register and the administrator SMS routing, and sends through
 * the existing adapters. Teacher recipients default to SMS and students to WhatsApp, either of which
 * the operator may override.
 *
 * Reliability model (see §2 of the manual-send review):
 * - The batch is persisted in the database *before* any message is dispatched, with an atomic,
 *   database-enforced unique `command_key_digest`, so two identical or concurrent requests can never
 *   create two batches.
 * - Each recipient is persisted up front and atomically claimed from `pending` with a single-row
 *   UPDATE, so the same recipient is never handed to two workers.
 * - A recipient marked `sent` is never sent again; a recipient left `sending` by an interrupted run
 *   is moved to `uncertain` (never blindly resent), and a permanent failure is never retried.
 * - Provider responses and outcomes are written back to the recipient row.
 *
 * WhatsApp is template-only (Meta requires an approved template). The announcement template is
 * registered here but its Meta approval is separate: until the provider approves it the WhatsApp
 * route fails closed, exactly like every other unmapped route.
 *
 * This is an operational service notice on the teaching relationship, so it never consults marketing
 * consent — but it always honours an explicit STOP and a channel-neutral suppression.
 */
final class NotificationManualSendService {
    public const OPTION_AUDIT='dzn_notification_manual_send_audit';
    public const CAPABILITY='dzn_operate_notification_dispatch';
    public const WHATSAPP_TEMPLATE='delnavazan_announcement_fa';
    public const WHATSAPP_LANGUAGE='fa';
    /** Meta body parameter order for the announcement template. */
    public const WHATSAPP_ORDER=array('name','message');
    public const MAX_RECIPIENTS=500;
    public const AUDIT_LIMIT=100;
    public const PURPOSE='manual_service_notice';

    /** @param object|null $suppressions @param object|null $lookup @param object|null $settings @param callable|null $http @param object|null $repository */
    public function __construct(
        private $suppressions=null,
        private $lookup=null,
        private $settings=null,
        private $http=null,
        private $repository=null
    ){
        $this->suppressions??=new NotificationSuppressionRepository();
        $this->lookup??=new StudentNotificationContactLookup();
        $this->settings??=new IntegrationSettingsService();
        $this->repository??=new NotificationManualSendRepository();
    }

    /** @return array<string,array{label:string,audience:string,count:int,channels:string[]}> */
    public function segments():array{
        $rows=$this->segmentRows();
        $out=array();
        foreach($rows as $key=>$definitions)$out[$key]=array(
            'label'=>(string)$definitions['label'],
            'audience'=>(string)$definitions['audience'],
            'count'=>count($definitions['rows']),
            'channels'=>$definitions['audience']==='teacher'?array('sms'):array('whatsapp','sms'),
        );
        return $out;
    }

    /**
     * Event templates an administrator may cover in a manual send. Only events with a real mobile
     * producer are offered; the event decides which channel can carry it.
     *
     * @return array<string,array{label:string,audience:string,channels:string[]}>
     */
    public function events():array{
        $out=array();
        foreach(NotificationCatalogue::events() as $eventKey=>$event){
            $channels=NotificationCatalogue::mobileProducerChannels((string)$eventKey);
            if($channels===array())continue;
            $out[(string)$eventKey]=array(
                'label'=>(string)($event['label']??$eventKey),
                'audience'=>(string)($event['audience']??''),
                'channels'=>array_values($channels),
            );
        }
        return $out;
    }

    /** Active Students an administrator can select by name instead of a whole audience segment. */
    public function students():array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=$wpdb->get_results("SELECT id,COALESCE(NULLIF(display_name,''),NULLIF(reference_code,''),CONCAT('Student #',id)) label,phone,whatsapp_phone FROM {$p}students WHERE archived_at IS NULL AND status='active' ORDER BY label");
        if(!is_array($rows))return array();
        return array_map(static fn(object $row):array=>array(
            'id'=>(int)$row->id,
            'label'=>(string)($row->label??('Student #'.(int)$row->id)),
            'phone'=>(string)($row->phone??''),
            'whatsapp_phone'=>(string)($row->whatsapp_phone??''),
        ),$rows);
    }

    /** A safe, read-only recipient preview: how many will be reached and how many are skipped. */
    public function preview(array $input):array{
        $resolution=$this->resolve($input);
        $channel=(string)($resolution['channel']??'');
        $eventKey=(string)($resolution['event']??'');
        $template=null;
        if($channel==='sms'&&$eventKey===''&&trim((string)($input['message']??''))==='')$resolution['errors'][]='A message is required for SMS.';
        if($channel==='whatsapp'&&$eventKey===''){
            if(trim((string)($input['parameters']['message']??''))==='')$resolution['errors'][]='A message is required for WhatsApp.';
        }elseif($channel==='whatsapp'){
            $template=TelnyxTemplateRegistry::nameForEvent($eventKey);
            if($template===null)$resolution['errors'][]='No approved WhatsApp template is mapped for this event.';
            else foreach(TelnyxTemplateRegistry::variableOrderForName($template) as $code){
                if(in_array($code,array('name','teacher','student'),true))continue;
                if(trim((string)($input['variables'][$code]??''))==='')$resolution['errors'][]='Template variable '.$code.' is required.';
            }
        }
        $reached=0;$skipped=array();
        foreach($resolution['recipients'] as $recipient){
            $blocker=$this->blocker($recipient);
            if($blocker!==null){@$skipped[$blocker]++;continue;}
            $reached++;
        }
        return array(
            'total'=>(int)($resolution['total']??count($resolution['recipients'])),
            'reached'=>$reached,
            'skipped'=>$skipped,
            'errors'=>$resolution['errors'],
            'channel'=>$channel,
            'event'=>$eventKey,
            'event_label'=>$eventKey===''?'Announcement':(string)($this->events()[$eventKey]['label']??$eventKey),
            'whatsapp_template'=>$eventKey===''?self::WHATSAPP_TEMPLATE:(string)($template??''),
        );
    }

    /**
     * Send one manual notification.
     *
     * @return array{batch_id:string,idempotent:bool,sent:int,failed:int,uncertain:int,skipped:int,summary:array<string,int>}
     */
    public function send(array $input,string $commandKey):array{
        if(!self::canSend())throw new \InvalidArgumentException('notification_manual_send_forbidden');
        $resolution=$this->resolve($input);
        if($resolution['errors']!==array())throw new \InvalidArgumentException(implode(' ',$resolution['errors']));
        $channel=(string)$resolution['channel'];
        if($channel!=='whatsapp'&&trim((string)($input['event']??''))===''&&trim((string)($input['message']??''))==='')throw new \InvalidArgumentException('A message is required for SMS.');

        $commandDigest=NotificationSupport::keyDigest($commandKey);
        $now=NotificationSupport::now();
        $actor=NotificationSupport::actor();
        $audience=implode(',',array_values(array_unique(array_map(static fn(array $r):string=>(string)($r['audience']??''),$resolution['recipients']))));

        // 1. Persist the batch and every recipient before dispatching anything. The unique
        //    `command_key_digest` makes two identical/concurrent requests converge on one batch; the
        //    unique `(batch_id, contact_digest)` makes the recipient set idempotent too.
        $this->repository->begin();
        try{
            $inserted=$this->repository->insertBatch(array(
                'uid'=>NotificationSupport::uid(),'command_key_digest'=>$commandDigest,'channel'=>$channel,
                'audience'=>$audience,'state'=>'created','created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
            ));
            $batchId=$inserted['id'];
            if($inserted['created']){
                foreach($resolution['recipients'] as $index=>$recipient){
                    if($index>=self::MAX_RECIPIENTS)break;
                    $blocked=$this->blocker($recipient);
                    $this->repository->insertRecipient(array(
                        'uid'=>NotificationSupport::uid(),'batch_id'=>$batchId,'contact'=>(string)$recipient['to'],
                        'contact_digest'=>hash_hmac('sha256','contact:'.(string)$recipient['to'],NotificationSupport::salt()),
                        'audience'=>(string)$recipient['audience'],
                        'subject_id'=>($recipient['id']??null)===null?null:(int)$recipient['id'],
                        'state'=>$blocked===null?'pending':'skipped',
                        'failure_reason'=>$blocked,
                        'created_at'=>$now,'updated_at'=>$now,
                    ));
                }
            }
            $this->repository->commit();
        }catch(\Throwable $error){
            $this->repository->rollback();
            throw $error;
        }

        // 2. Claim and dispatch only pending recipients. Already `sent`, `failed` or `uncertain`
        //    recipients are counted, never re-sent; a `sending` row left by an interrupted run is
        //    marked `uncertain` rather than blindly resent.
        $sent=0;$failed=0;$uncertain=0;$skipped=0;
        $recipientNames=array();
        foreach($resolution['recipients'] as $resolved)$recipientNames[(string)$resolved['to']]=(string)($resolved['name']??'');
        foreach($this->repository->recipients($batchId) as $row){
            $state=(string)$row->state;
            if($state==='sent'){$sent++;continue;}
            if($state==='skipped'){$skipped++;continue;}
            if($state==='failed'){$failed++;continue;}
            if($state==='uncertain'){$uncertain++;continue;}
            if($state==='sending'){
                $this->repository->markRecipient((int)$row->id,'uncertain',array('failure_reason'=>'interrupted','updated_at'=>gmdate('Y-m-d H:i:s')));
                $uncertain++;continue;
            }
            // pending
            if(!$this->repository->claimRecipient((int)$row->id,$now))continue;
            $idempotencyKey='dzn-manual:'.hash('sha256',$batchId.':'.(int)$row->id.':'.$commandDigest);
            $result=$this->dispatch($channel,(string)$row->contact,$input,$idempotencyKey,$row,(string)($recipientNames[(string)$row->contact]??''));
            if(!empty($result['acknowledged'])){
                $this->repository->markRecipient((int)$row->id,'sent',array('failure_reason'=>null,'finished_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')));
                $sent++;
            }elseif(($result['permanent_failure']??null)!==null){
                $this->repository->markRecipient((int)$row->id,'failed',array('failure_reason'=>(string)$result['permanent_failure'],'finished_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')));
                $failed++;
            }else{
                $this->repository->markRecipient((int)$row->id,'uncertain',array('failure_reason'=>(string)($result['reason']??'ambiguous'),'finished_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')));
                $uncertain++;
            }
        }
        $this->repository->updateBatchState($batchId,'completed',$now);
        $summary=array('sent'=>$sent,'failed'=>$failed,'uncertain'=>$uncertain,'skipped'=>$skipped);
        $this->audit('manual_send',(string)$batchId,$channel,$summary);
        return array('batch_id'=>(string)$batchId,'idempotent'=>!$inserted['created'],'sent'=>$sent,'failed'=>$failed,'uncertain'=>$uncertain,'skipped'=>$skipped,'summary'=>$summary);
    }

    // ------------------------------------------------------------------ resolution

    /**
     * Resolve the audience and channel of one send. Returns normalised, deduplicated recipients and a
     * single effective channel.
     *
     * @return array{channel:string,recipients:array<int,array{to:string,audience:string,id:int|null,phone:string,whatsapp:string}>,errors:string[],total:int}
     */
    private function resolve(array $input):array{
        $errors=array();
        $audience=(string)($input['audience']??'');
        $channel=(string)($input['channel']??'');
        $manual=self::numbers((string)($input['numbers']??''));
        $segmentKeys=is_array($input['segments']??null)?array_values(array_filter(array_map('sanitize_key',(array)$input['segments']))):array();
        $eventKey=trim((string)($input['event']??''));
        $event=$eventKey===''?null:($this->events()[$eventKey]??null);
        if($eventKey!==''&&$event===null)$errors[]='Unknown notification event template.';
        $selectedStudents=is_array($input['students']??null)?array_values(array_unique(array_filter(array_map('intval',(array)$input['students']),static fn(int $id):bool=>$id>0))):array();

        // The audience is implied by the chosen segments; an explicit value overrides it. A single
        // audience sets the default channel (teacher -> SMS, student -> WhatsApp).
        $definitions=$this->segmentRows();
        $segmentAudiences=array();
        foreach($segmentKeys as $segmentKey)if(isset($definitions[$segmentKey]))$segmentAudiences[(string)$definitions[$segmentKey]['audience']]=true;
        $segmentAudience=count($segmentAudiences)===1?(string)array_key_first($segmentAudiences):'';
        $effectiveAudience=$audience!==''?$audience:$segmentAudience;

        if($event!==null){
            $allowed=$event['channels'];
            if(!in_array($channel,$allowed,true))$channel=in_array('whatsapp',$allowed,true)?'whatsapp':(string)($allowed[0]??'sms');
        }else{
            if($channel===''&&$effectiveAudience==='teacher')$channel='sms';
            if($channel===''&&$effectiveAudience==='student')$channel='whatsapp';
            if($channel===''&&$effectiveAudience===''&&$manual!==array()&&$segmentKeys===array()&&$selectedStudents===array())$channel='whatsapp';
            if(!in_array($channel,array('sms','whatsapp'),true)){
                if(($manual!==array()||$selectedStudents!==array())&&$segmentKeys!==array())$errors[]='Choose a channel when sending to both teachers and students.';
                else$channel=$effectiveAudience==='teacher'?'sms':'whatsapp';
            }
        }

        $recipients=array();$seen=array();
        $add=static function(array $row)use(&$recipients,&$seen){
            $phone=self::e164((string)($row['phone']??''));
            $whatsapp=self::e164((string)($row['whatsapp']??''));
            $contact=$whatsapp!==''?$whatsapp:$phone;
            if($contact==='')return;
            if(isset($seen[$contact]))return;
            $seen[$contact]=true;
            $recipients[]=array('to'=>$contact,'audience'=>(string)($row['audience']??''),'id'=>isset($row['id'])?(int)$row['id']:null,'name'=>(string)($row['name']??''),'phone'=>$phone,'whatsapp'=>$whatsapp);
        };

        foreach($segmentKeys as $segmentKey){
            $definition=$definitions[$segmentKey]??null;
            if($definition===null){$errors[]='Unknown audience segment.';continue;}
            foreach($definition['rows'] as $row)$add(array('id'=>(int)$row['id'],'name'=>(string)($row['name']??''),'phone'=>(string)($row['phone']??''),'whatsapp'=>(string)($row['whatsapp_phone']??''),'audience'=>(string)$definition['audience']));
        }
        if($selectedStudents!==array()){
            $active=$definitions['students_active']['rows']??array();
            foreach($active as $row){
                if(!in_array((int)$row['id'],$selectedStudents,true))continue;
                $add(array('id'=>(int)$row['id'],'name'=>(string)($row['name']??''),'phone'=>(string)($row['phone']??''),'whatsapp'=>(string)($row['whatsapp_phone']??''),'audience'=>'student'));
            }
        }
        foreach($manual as $number){
            $phone=self::e164($number);
            if($phone===''){$errors[]='One of the entered numbers is not a valid phone number.';continue;}
            // A typed number that matches the roster adopts that identity for STOP/dedupe.
            $studentId=$this->lookup->studentIdForPhone($phone);
            $teacherId=$this->lookup->teacherIdForPhone($phone);
            if($studentId!==null)$add(array('id'=>$studentId,'phone'=>$phone,'whatsapp'=>$phone,'audience'=>'student'));
            elseif($teacherId!==null)$add(array('id'=>$teacherId,'phone'=>$phone,'whatsapp'=>$phone,'audience'=>'teacher'));
            else$add(array('id'=>null,'phone'=>$phone,'whatsapp'=>$phone,'audience'=>$audience!==''?$audience:'student'));
        }
        if($event!==null&&in_array((string)$event['audience'],array('student','teacher'),true)){
            foreach($recipients as $recipient)if((string)($recipient['audience']??'')!==(string)$event['audience']){
                $errors[]='The selected event is for '.$event['audience'].'s; remove recipients from the other audience.';
                break;
            }
        }
        if($recipients===array()&&$errors===array())$errors[]='No recipients matched. Choose a student, an audience or enter numbers.';
        return array('channel'=>$channel,'event'=>$eventKey,'variables'=>is_array($input['variables']??null)?$input['variables']:array(),'recipients'=>$recipients,'errors'=>array_values(array_unique($errors)),'total'=>count($recipients));
    }

    /** Why a recipient may not be sent to, or null when reachable. */
    private function blocker(array $recipient):?string{
        if(($recipient['id']??null)===null)return null;
        $digest=(string)$recipient['audience']==='teacher'?$this->lookup->teacherRecipientDigest((int)$recipient['id']):$this->lookup->recipientDigest((int)$recipient['id']);
        if($this->suppressions->active($digest,self::PURPOSE,NotificationSupport::now(),true)!==null)return 'stopped';
        return null;
    }

    /**
     * @return array{acknowledged:bool,permanent_failure:?string,reason:string}
     */
    private function dispatch(string $channel,string $to,array $input,string $idempotencyKey,object $recipientRow,string $name=''):array{
        $eventKey=trim((string)($input['event']??''));
        $variables=is_array($input['variables']??null)?$input['variables']:array();
        foreach(array('name','teacher','student') as $code)if(array_key_exists($code,$variables)||$code==='name')if($name!==''&&trim((string)($variables[$code]??''))==='')$variables[$code]=$name;
        if($channel==='sms'){
            $provider=SmsProviderRoutingPolicy::select($to);
            if($eventKey!==''){
                if($provider==='sms_ir'){
                    $adapter=new SmsIrAdapter($this->settings,$this->http);
                    $result=$adapter->sendBookingNotification($to,(int)$recipientRow->id,'',$eventKey,$variables);
                }else{
                    $adapter=new TelnyxSmsAdapter($this->settings,$this->http);
                    $body=trim((string)($input['message']??''));
                    if($body==='')$body='Delnavazan: '.($this->events()[$eventKey]['label']??$eventKey);
                    $result=$adapter->send(array('to'=>$to,'body'=>$body,'idempotency_key'=>$idempotencyKey));
                }
            }else{
                $body=trim(self::messageText($input,'sms'));
                $adapter=$provider==='sms_ir'?new SmsIrAdapter($this->settings,$this->http):new TelnyxSmsAdapter($this->settings,$this->http);
                $result=$adapter->send(array('to'=>$to,'body'=>$body,'idempotency_key'=>$idempotencyKey));
            }
            return array('acknowledged'=>(bool)($result['acknowledged']??false),'permanent_failure'=>isset($result['permanent_failure'])?$result['permanent_failure']:null,'reason'=>(string)($result['reason']??'sms_failed'));
        }
        $adapter=new TelnyxWhatsAppAdapter($this->settings,$this->http);
        if($eventKey!==''){
            $template=TelnyxTemplateRegistry::nameForEvent($eventKey);
            if($template===null)return array('acknowledged'=>false,'permanent_failure'=>'send_refused','reason'=>'template_not_mapped');
            $order=TelnyxTemplateRegistry::variableOrderForName($template);
            $result=$adapter->sendManual($to,$template,self::WHATSAPP_LANGUAGE,$order,$variables,(int)$recipientRow->id,$idempotencyKey);
        }else{
            $result=$adapter->sendManual($to,self::WHATSAPP_TEMPLATE,self::WHATSAPP_LANGUAGE,self::WHATSAPP_ORDER,(array)($input['parameters']??array()),0,$idempotencyKey);
        }
        return array('acknowledged'=>(bool)($result['acknowledged']??false),'permanent_failure'=>isset($result['permanent_failure'])?$result['permanent_failure']:null,'reason'=>(string)($result['reason']??'whatsapp_failed'));
    }

    private static function messageText(array $input,string $channel):string{
        if($channel==='whatsapp')return implode(' ',array_values((array)($input['parameters']??array())));
        return (string)($input['message']??'');
    }

    private static function numbers(string $text):array{
        $text=str_replace(array(',',"\n","\r","\t",';'),' ',trim($text));
        return array_values(array_unique(array_filter(array_map('trim',preg_split('/\s+/',$text)?:array()))));
    }
    private static function e164(string $phone):string{return PhoneNumberNormalizer::toE164($phone);}
    public static function canSend():bool{return current_user_can(self::CAPABILITY)||current_user_can('manage_options');}

    // ------------------------------------------------------------------ segments

    /** @return array<string,array{label:string,audience:string,rows:array<int,array{id:int,phone:string,whatsapp_phone:string}}>} */
    private function segmentRows():array{
        global $wpdb;
        $p=$wpdb->prefix.'dzn_';
        $teachers=$this->query("SELECT id,COALESCE(NULLIF(display_name,''),NULLIF(english_name,''),persian_name,'') name,phone,whatsapp_phone FROM {$p}teachers WHERE archived_at IS NULL ORDER BY id");
        $studentsActive=$this->query("SELECT id,COALESCE(NULLIF(display_name,''),NULLIF(reference_code,''),'') name,phone,whatsapp_phone FROM {$p}students WHERE archived_at IS NULL AND status='active' ORDER BY id");
        $studentsFormer=$this->query("SELECT id,COALESCE(NULLIF(display_name,''),NULLIF(reference_code,''),'') name,phone,whatsapp_phone FROM {$p}students WHERE archived_at IS NOT NULL OR status IN ('archived','completed','cancelled') ORDER BY id");
        $studentsAll=$this->query("SELECT id,COALESCE(NULLIF(display_name,''),NULLIF(reference_code,''),'') name,phone,whatsapp_phone FROM {$p}students WHERE archived_at IS NULL ORDER BY id");
        return array(
            'teachers'=>array('label'=>'All teachers','audience'=>'teacher','rows'=>$teachers),
            'students_active'=>array('label'=>'Active students','audience'=>'student','rows'=>$studentsActive),
            'students_former'=>array('label'=>'Former students','audience'=>'student','rows'=>$studentsFormer),
            'students_all'=>array('label'=>'All students','audience'=>'student','rows'=>$studentsAll),
        );
    }
    /** @return array<int,array{id:int,phone:string,whatsapp_phone:string}> */
    private function query(string $sql):array{
        global $wpdb;
        $rows=$wpdb->get_results($sql);
        if(!is_array($rows))return array();
        return array_map(static fn(object $row):array=>array('id'=>(int)$row->id,'name'=>(string)($row->name??''),'phone'=>(string)($row->phone??''),'whatsapp_phone'=>(string)($row->whatsapp_phone??'')),$rows);
    }

    // ------------------------------------------------------------------ audit

    private function audit(string $action,string $batchId,string $channel,array $summary):void{
        if(!function_exists('get_option'))return;
        $log=is_array(get_option(self::OPTION_AUDIT,array()))?get_option(self::OPTION_AUDIT,array()):array();
        $log[]=array('at'=>NotificationSupport::now(),'actor'=>NotificationSupport::actor(),'action'=>$action,'batch_id'=>$batchId,'channel'=>$channel,'summary'=>$summary);
        if(count($log)>self::AUDIT_LIMIT)$log=array_slice($log,-self::AUDIT_LIMIT);
        update_option(self::OPTION_AUDIT,$log,false);
    }
}
