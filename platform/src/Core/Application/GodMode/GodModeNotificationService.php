<?php
namespace Delnavazan\Platform\Core\Application\GodMode;

use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Integrations\Notifications\{DelnavazanEmailRenderer,TelnyxTemplateRegistry};
use Delnavazan\Platform\Integrations\{TelnyxSmsAdapter,TelnyxWhatsAppAdapter};

/**
 * Notification testing over the real transport bindings where safe.
 *
 * Recipients are constrained to the approved testing list or a participating teacher. Portal test
 * rows are isolated by source kind `god_mode_test`; no payment reminder, renewal campaign or
 * enrolment message can be triggered from here.
 */
final class GodModeNotificationService {
    public function __construct(private ?GodModeRepository $repository=null){$this->repository??=new GodModeRepository();}

    /** @return array<string,mixed> */
    public function sendTest(int $sessionId,string $channel,string $recipientKind='owner',?int $runId=null):array{
        $actor=GodModeAuthority::require();
        if(!in_array($channel,GodModeRule::CHANNELS,true))throw new \InvalidArgumentException('god_mode_notification_channel_invalid');
        if(!in_array($recipientKind,array('owner','teacher'),true))throw new \InvalidArgumentException('god_mode_notification_recipient_kind_invalid');
        $session=$sessionId>0?$this->repository->session($sessionId):null;
        if($session&&(string)$session->activity_type!=='test')throw new \InvalidArgumentException('god_mode_test_session_required');
        $recipient=$this->resolveRecipient($recipientKind,$session,$actor);
        $template='god_mode_test_'.$channel;
        $state='failed';$provider=null;$outcome=null;$failure=null;$fallbackUsed=false;$fallbackVerified=false;
        if($channel==='portal'){
            $portalId=$this->recordPortal($recipientKind,$session,$actor,$sessionId>0?$sessionId:($runId??0));
            $state=$portalId>0?'accepted':'failed';$provider='portal';$outcome=$portalId>0?'portal_notification_recorded':'portal_notification_refused';
            if($portalId<1)$failure='portal_record_failed';
        }elseif($channel==='email'){
            $to=(string)($recipient['email']??'');
            if(!is_email($to)){$state='failed';$failure='approved_email_recipient_missing';}
            else{
                $subject='[Delnavazan TEST] God Mode email notification';
                $body='<p>This is an explicit God Mode notification test. It is not a production student or finance message.</p><p>Test session: '.htmlspecialchars((string)($session->reference_code??('run-'.$runId)),ENT_QUOTES,'UTF-8').'</p>';
                $accepted=DelnavazanEmailRenderer::send($to,$subject,$body,array('locale'=>'en'));
                $state=$accepted?'accepted':'failed';$provider='wordpress_mail';$outcome=$accepted?'wp_mail_handoff_accepted':'wp_mail_handoff_refused';
                if(!$accepted)$failure='wp_mail_refused';
            }
        }elseif($channel==='sms'){
            $to=(string)($recipient['phone']??'');
            try{
                $adapter=new TelnyxSmsAdapter(new IntegrationSettingsService());
                $result=$adapter->sendTest($to);
                $state=((string)($result['state']??'')==='success')?'accepted':'failed';
                $provider='telnyx_sms';$outcome=(string)($result['code']??'unknown');
                if($state!=='accepted')$failure='sms_transport_failed';
            }catch(\Throwable $error){$state='failed';$provider='telnyx_sms';$outcome='transport_exception';$failure='sms_transport_exception';}
        }elseif($channel==='whatsapp'){
            $to=(string)($recipient['phone']??'');
            $name='delnavazan_class_access_fa';
            try{
                $adapter=new TelnyxWhatsAppAdapter(new IntegrationSettingsService());
                $result=$adapter->sendTest($to,$name,TelnyxTemplateRegistry::LANGUAGE,TelnyxTemplateRegistry::sampleParameters());
                $state=((string)($result['state']??'')==='success')?'accepted':'failed';
                $provider='telnyx_whatsapp';$outcome=(string)($result['code']??'unknown');
                if($state!=='accepted')$failure='whatsapp_transport_failed';
            }catch(\Throwable $error){$state='failed';$provider='telnyx_whatsapp';$outcome='transport_exception';$failure='whatsapp_transport_exception';}
        }
        $id=$this->repository->recordNotification($sessionId,$runId,$channel,$recipientKind,(string)($recipient['digest_source']??'unknown'),$template,$state,$provider,$outcome,$failure,$fallbackUsed,$fallbackVerified);
        if($sessionId>0){
            $this->repository->appendEvent($sessionId,GodModeRule::STAGE_INVITATION_SENT,$state==='accepted'?GodModeRule::EVENT_CONFIRMED:($state==='warning'?GodModeRule::EVENT_UNKNOWN:GodModeRule::EVENT_FAILED),array(
                'channel'=>$channel,'notification_test_id'=>$id,'recipient_kind'=>$recipientKind,
                'outcome'=>$outcome,'fallback_used'=>$fallbackUsed,'fallback_condition_verified'=>$fallbackVerified,
                'delivery_confirmation'=>'transport_handoff_only',
            ),'notification:'.$id,null,null,$failure,($failure!==null?'Transport did not accept the test message':null),true);
        }
        GodModeAudit::record($actor,'notification_test','notification_test',$id,$channel.'_'.$state,wp_json_encode(array('recipient_kind'=>$recipientKind,'outcome'=>$outcome,'fallback_verified'=>$fallbackVerified),JSON_UNESCAPED_SLASHES)?:null);
        return array('notification_test_id'=>$id,'channel'=>$channel,'state'=>$state,'provider'=>$provider,'outcome'=>$outcome,'failure'=>$failure,'fallback_used'=>$fallbackUsed,'fallback_condition_verified'=>$fallbackVerified,'delivery_confirmed'=>false,'recipient_kind'=>$recipientKind);
    }

    /** @return array{email:string,phone:string,digest_source:string,wp_user_id:int} */
    private function resolveRecipient(string $kind,?object $session,int $actor):array{
        if($kind==='teacher'){
            if(!$session)throw new \InvalidArgumentException('god_mode_teacher_recipient_requires_session');
            global $wpdb;$p=$wpdb->prefix.'dzn_';
            $teacher=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}teachers WHERE id=%d AND archived_at IS NULL",(int)$session->teacher_id));
            if(!$teacher)throw new \InvalidArgumentException('god_mode_participating_teacher_required');
            return array('email'=>strtolower(trim((string)($teacher->email??''))),'phone'=>trim((string)($teacher->phone??'')),'digest_source'=>'teacher:'.(int)$teacher->id,'wp_user_id'=>(int)($teacher->wordpress_user_id??0));
        }
        $user=get_userdata($actor);
        $email=$user&&is_object($user)?strtolower(trim((string)($user->user_email??''))):'';
        $approved=(array)GodModeSettings::get('approved_recipients',array());
        if($email!==''&&!in_array($email,$approved,true))$approved[]=$email;
        $phone='';
        foreach($approved as $candidate){if(preg_match('/^\+[1-9][0-9]{7,14}$/',$candidate)){$phone=$candidate;break;}}
        if($email===''&&$phone==='')throw new \InvalidArgumentException('god_mode_approved_recipient_required');
        return array('email'=>$email,'phone'=>$phone,'digest_source'=>'owner:'.$actor,'wp_user_id'=>$actor);
    }

    private function recordPortal(string $kind,?object $session,int $actor,int $sourceId):int{
        global $wpdb;$now=gmdate('Y-m-d H:i:s');
        $audience=$kind==='teacher'&&$session?'teacher':'admin';
        $recipientId=$audience==='teacher'?(int)$session->teacher_id:0;
        $wpUserId=(int)($this->resolveRecipient($kind,$session,$actor)['wp_user_id']??0);
        if($audience==='admin')$wpUserId=$actor;
        $reference=$session?(string)$session->reference_code:'run-'.$sourceId;
        $dedupe=hash('sha256','god-mode-portal-test:'.$sourceId.':'.$kind.':'.$audience);
        $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}portal_notifications WHERE dedupe_key=%s LIMIT 1",$dedupe));
        if($existing)return (int)$existing;
        $ok=$wpdb->insert($wpdb->prefix.'portal_notifications',array(
            'uid'=>\Delnavazan\Platform\Core\Support\Identifier::uid(),'event_key'=>'god_mode.test.portal','category'=>'test','audience'=>$audience,
            'recipient_id'=>$recipientId,'wp_user_id'=>$wpUserId,'title'=>'God Mode portal notification test',
            'body'=>'This is a test portal notification for '.$reference.'. It is not a production message.','link_url'=>admin_url('admin.php?page=dzn-god-mode&tab=inspector&session='.$sourceId),
            'source_kind'=>'god_mode_test','source_id'=>$sourceId,'dedupe_key'=>$dedupe,'read_at'=>null,'created_at'=>$now,
            'expires_at'=>gmdate('Y-m-d H:i:s',time()+7*86400),
        ));
        return $ok===false?0:(int)$wpdb->insert_id;
    }
}
