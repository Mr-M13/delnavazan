<?php
namespace Delnavazan\Platform\Integrations\Notifications;

use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Application\SmsProviderRoutingPolicy;
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
 * WhatsApp is template-only (Meta requires an approved template). The announcement template is
 * registered here but its Meta approval is separate: until the provider approves it the WhatsApp
 * route fails closed with a clear reason, exactly like every other unmapped route.
 *
 * Every send is confirmed, capped, audited and recorded as an immutable batch. Nothing here is a
 * marketing channel: it is an operational service notice on the teaching relationship, so it never
 * consults marketing consent — but it always honours an explicit STOP and a channel-neutral
 * suppression.
 */
final class NotificationManualSendService {
    public const OPTION_BATCHES='dzn_notification_manual_send_batches';
    public const OPTION_AUDIT='dzn_notification_manual_send_audit';
    public const CAPABILITY='dzn_operate_notification_dispatch';
    public const WHATSAPP_TEMPLATE='delnavazan_announcement_fa';
    public const WHATSAPP_LANGUAGE='fa';
    /** Meta body parameter order for the announcement template. */
    public const WHATSAPP_ORDER=array('name','message');
    public const MAX_RECIPIENTS=500;
    public const BATCH_LIMIT=100;
    public const PURPOSE='manual_service_notice';

    /** @param object|null $suppressions @param object|null $lookup @param object|null $settings @param callable|null $http */
    public function __construct(
        private $suppressions=null,
        private $lookup=null,
        private $settings=null,
        private $http=null
    ){
        $this->suppressions??=new NotificationSuppressionRepository();
        $this->lookup??=new StudentNotificationContactLookup();
        $this->settings??=new IntegrationSettingsService();
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

    /** A safe, read-only recipient preview: how many will be reached and how many are skipped. */
    public function preview(array $input):array{
        $resolution=$this->resolve($input);
        $channel=(string)($resolution['channel']??'');
        if($channel==='sms'&&trim((string)($input['message']??''))==='')$resolution['errors'][]='A message is required for SMS.';
        if($channel==='whatsapp'&&trim((string)($input['parameters']['message']??''))==='')$resolution['errors'][]='A message is required for WhatsApp.';
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
            'whatsapp_template'=>self::WHATSAPP_TEMPLATE,
        );
    }

    /**
     * Send one manual notification. The command key makes a double submit converge on the same batch.
     *
     * @return array{batch_id:string,idempotent:bool,sent:int,failed:int,skipped:int,summary:array<string,int>}
     */
    public function send(array $input,string $commandKey):array{
        if(!self::canSend())throw new \InvalidArgumentException('notification_manual_send_forbidden');
        $commandDigest=NotificationSupport::keyDigest($commandKey);
        if($existing=$this->batchByCommand($commandDigest))return array('batch_id'=>(string)$existing['id'],'idempotent'=>true,'sent'=>(int)$existing['sent'],'failed'=>(int)$existing['failed'],'skipped'=>(int)$existing['skipped'],'summary'=>(array)($existing['summary']??array()));
        $resolution=$this->resolve($input);
        if($resolution['errors']!==array())throw new \InvalidArgumentException(implode(' ',$resolution['errors']));
        $channel=(string)$resolution['channel'];
        $message=self::messageText($input,$channel);
        if(trim($message)===''&&$channel!=='whatsapp')throw new \InvalidArgumentException('A message is required for SMS.');
        $sent=0;$failed=0;$skipped=0;$recipients=array();$summary=array();
        foreach($resolution['recipients'] as $index=>$recipient){
            if($index>=self::MAX_RECIPIENTS)break;
            $blocker=$this->blocker($recipient);
            if($blocker!==null){$skipped++;@$summary['skipped_'.$blocker]++;$recipients[]=array('to'=>self::mask($recipient['to']),'audience'=>$recipient['audience'],'state'=>'skipped','reason'=>$blocker);continue;}
            $result=$this->dispatch($channel,$recipient,$input);
            $state=$result['acknowledged']?'sent':'failed';
            if($state==='sent')$sent++;else{$failed++;@$summary['failed_'.$result['reason']]++;}
            @$summary[$state]++;
            $recipients[]=array('to'=>self::mask((string)$recipient['to']),'audience'=>$recipient['audience'],'state'=>$state,'reason'=>$state==='failed'?(string)$result['reason']:null,'reference'=>$result['reference']??null);
        }
        $batch=array(
            'id'=>NotificationSupport::uid(),'command_digest'=>$commandDigest,
            'audience'=>implode(',',array_values(array_unique(array_map(static fn(array $r):string=>(string)($r['audience']??''),$resolution['recipients'])))),
            'channel'=>$channel,'message'=>$message,'whatsapp_template'=>self::WHATSAPP_TEMPLATE,
            'parameters'=>(array)($input['parameters']??array()),
            'sent'=>$sent,'failed'=>$failed,'skipped'=>$skipped,'summary'=>$summary,
            'created_at'=>NotificationSupport::now(),'created_by'=>NotificationSupport::actor(),
            'recipients'=>$recipients,
        );
        $this->storeBatch($batch);
        $this->audit('manual_send',$batch['id'],$channel,$sent,$failed,$skipped);
        return array('batch_id'=>$batch['id'],'idempotent'=>false,'sent'=>$sent,'failed'=>$failed,'skipped'=>$skipped,'summary'=>$summary);
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

        // The audience is implied by the chosen segments; an explicit value overrides it. A single
        // audience sets the default channel (teacher -> SMS, student -> WhatsApp).
        $definitions=$this->segmentRows();
        $segmentAudiences=array();
        foreach($segmentKeys as $segmentKey)if(isset($definitions[$segmentKey]))$segmentAudiences[(string)$definitions[$segmentKey]['audience']]=true;
        $segmentAudience=count($segmentAudiences)===1?(string)array_key_first($segmentAudiences):'';
        $effectiveAudience=$audience!==''?$audience:$segmentAudience;

        if($channel===''&&$effectiveAudience==='teacher')$channel='sms';
        if($channel===''&&$effectiveAudience==='student')$channel='whatsapp';
        if($channel===''&&$effectiveAudience===''&&$manual!==array()&&$segmentKeys===array())$channel='whatsapp';
        if(!in_array($channel,array('sms','whatsapp'),true)){
            if($manual!==array()&&$segmentKeys!==array())$errors[]='Choose a channel when sending to both teachers and students.';
            else$channel=$effectiveAudience==='teacher'?'sms':'whatsapp';
        }

        $recipients=array();$seen=array();
        $add=static function(array $row)use(&$recipients,&$seen){
            $phone=self::e164((string)($row['phone']??''));
            $whatsapp=self::e164((string)($row['whatsapp']??''));
            $contact=$whatsapp!==''?$whatsapp:$phone;
            if($contact==='')return;
            if(isset($seen[$contact]))return;
            $seen[$contact]=true;
            $recipients[]=array('to'=>$contact,'audience'=>(string)($row['audience']??''),'id'=>isset($row['id'])?(int)$row['id']:null,'phone'=>$phone,'whatsapp'=>$whatsapp);
        };

        foreach($segmentKeys as $segmentKey){
            $definition=$definitions[$segmentKey]??null;
            if($definition===null){$errors[]='Unknown audience segment.';continue;}
            foreach($definition['rows'] as $row)$add(array('id'=>(int)$row['id'],'phone'=>(string)($row['phone']??''),'whatsapp'=>(string)($row['whatsapp_phone']??''),'audience'=>(string)$definition['audience']));
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
        if($recipients===array()&&$errors===array())$errors[]='No recipients matched. Choose an audience or enter numbers.';
        return array('channel'=>$channel,'recipients'=>$recipients,'errors'=>array_values(array_unique($errors)),'total'=>count($recipients));
    }

    /** Why a recipient may not be sent to, or null when reachable. */
    private function blocker(array $recipient):?string{
        $channel=null; // blocked is channel-neutral: STOP/suppression are on the person, not the route.
        if(($recipient['id']??null)===null)return null;
        $digest=(string)$recipient['audience']==='teacher'?$this->lookup->teacherRecipientDigest((int)$recipient['id']):$this->lookup->recipientDigest((int)$recipient['id']);
        if($this->suppressions->active($digest,self::PURPOSE,NotificationSupport::now(),true)!==null)return 'stopped';
        return null;
    }

    private function dispatch(string $channel,array $recipient,array $input):array{
        if($channel==='sms'){
            $provider=SmsProviderRoutingPolicy::select((string)$recipient['to']);
            $body=trim(self::messageText($input,'sms'));
            if($provider==='sms_ir')$adapter=new SmsIrAdapter($this->settings,$this->http);
            else$adapter=new TelnyxSmsAdapter($this->settings,$this->http);
            $result=$adapter->send(array('to'=>(string)$recipient['to'],'body'=>$body));
            return array('acknowledged'=>(bool)($result['acknowledged']??false),'reason'=>(string)($result['permanent_failure']??($result['reason']??'sms_failed')),'reference'=>null);
        }
        $adapter=new TelnyxWhatsAppAdapter($this->settings,$this->http);
        $result=$adapter->sendManual((string)$recipient['to'],self::WHATSAPP_TEMPLATE,self::WHATSAPP_LANGUAGE,self::WHATSAPP_ORDER,(array)($input['parameters']??array()),0);
        return array('acknowledged'=>(bool)($result['acknowledged']??false),'reason'=>(string)($result['permanent_failure']??($result['reason']??'whatsapp_failed')),'reference'=>null);
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
    private static function mask(string $number):string{return preg_replace('/[0-9](?=[0-9]{4})/','*',substr($number,0,-4)).substr($number,-4);}
    public static function canSend():bool{return current_user_can(self::CAPABILITY)||current_user_can('manage_options');}

    // ------------------------------------------------------------------ segments

    /** @return array<string,array{label:string,audience:string,rows:array<int,array{id:int,phone:string,whatsapp_phone:string}}>} */
    private function segmentRows():array{
        global $wpdb;
        $p=$wpdb->prefix.'dzn_';
        $teachers=$this->query("SELECT id,phone,whatsapp_phone FROM {$p}teachers WHERE archived_at IS NULL ORDER BY id");
        $studentsActive=$this->query("SELECT id,phone,whatsapp_phone FROM {$p}students WHERE archived_at IS NULL AND status='active' ORDER BY id");
        $studentsFormer=$this->query("SELECT id,phone,whatsapp_phone FROM {$p}students WHERE archived_at IS NOT NULL OR status IN ('archived','completed','cancelled') ORDER BY id");
        $studentsAll=$this->query("SELECT id,phone,whatsapp_phone FROM {$p}students WHERE archived_at IS NULL ORDER BY id");
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
        return array_map(static fn(object $row):array=>array('id'=>(int)$row->id,'phone'=>(string)($row->phone??''),'whatsapp_phone'=>(string)($row->whatsapp_phone??'')),$rows);
    }

    // ------------------------------------------------------------------ persistence

    private function batchByCommand(string $digest):?array{
        foreach($this->batches() as $batch)if(($batch['command_digest']??'')===$digest)return $batch;
        return null;
    }
    private function storeBatch(array $batch):void{
        $batches=$this->batches();
        array_unshift($batches,$batch);
        if(count($batches)>self::BATCH_LIMIT)$batches=array_slice($batches,0,self::BATCH_LIMIT);
        update_option(self::OPTION_BATCHES,$batches,false);
    }
    private function batches():array{
        if(!function_exists('get_option'))return array();
        $value=get_option(self::OPTION_BATCHES,array());
        return is_array($value)?$value:array();
    }
    private function audit(string $action,string $batchId,string $channel,int $sent,int $failed,int $skipped):void{
        if(!function_exists('get_option'))return;
        $log=is_array(get_option(self::OPTION_AUDIT,array()))?get_option(self::OPTION_AUDIT,array()):array();
        $log[]=array('at'=>NotificationSupport::now(),'actor'=>NotificationSupport::actor(),'action'=>$action,'batch_id'=>$batchId,'channel'=>$channel,'sent'=>$sent,'failed'=>$failed,'skipped'=>$skipped);
        if(count($log)>self::BATCH_LIMIT)$log=array_slice($log,-self::BATCH_LIMIT);
        update_option(self::OPTION_AUDIT,$log,false);
    }
}
