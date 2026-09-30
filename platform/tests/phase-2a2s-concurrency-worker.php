<?php
/**
 * Phase 2A.2-S concurrency worker.
 *
 * `w1` is the holder: it opens a transaction on the fixture's serialisation root (or performs the §6.2.4
 * post-publication drift inside it) and waits on the gate file before committing. `w2` is the contender: it
 * runs the real command against the same root, so the S lock order and the named-index arbitration are
 * exercised end to end rather than simulated.
 */
if(getenv('DZN_PHASE_2A2S_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI){fwrite(STDERR,"Phase 2A.2-S concurrency refused.\n");exit(1);}
require __DIR__.'/phase-2a2s-fixture.php';
use Delnavazan\Platform\Core\Application\NotificationDispatchService;
use Delnavazan\Platform\Core\Application\NotificationPrivacyService;
use Delnavazan\Platform\Core\Application\NotificationRule;
use Delnavazan\Platform\Core\Application\NotificationService;
use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Application\NotificationSuppressionService;
use Delnavazan\Platform\Core\Application\NotificationWorkflowService;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationDeliveryRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationSuppressionRepository;
use Delnavazan\Platform\Core\Support\Identifier;

$modes=array(
    'dispatch_vs_retry','lease_expiry_vs_handoff','retry_exhaustion_vs_recovery',
    'subject_transition_after_enqueue_vs_dispatch','policy_change_after_publication_vs_dispatch',
    'deferral_vs_claim','activation_vs_dispatch','competing_activation_same_intent',
    'rule_attach_vs_activation','suppress_vs_enqueue','cancel_vs_dispatch',
    'delivery_vs_attempt_close','erase_vs_dispatch','unrelated_notifications',
);
$worker=(string)getenv('DZN_PHASE_2A2S_WORKER');
$gate=(string)getenv('DZN_PHASE_2A2S_GATE_DIR');
$fixture=(array)get_option('dzn_phase_2a2s_concurrency_fixture',array());
$mode=(string)($fixture['mode']??'');
if(!in_array($mode,$modes,true)||!in_array($worker,array('w1','w2'),true)||$gate===''){fwrite(STDERR,"Phase 2A.2-S concurrency worker refused.\n");exit(1);}
global $wpdb;$p=$wpdb->prefix.'dzn_';
wp_set_current_user(1);

/**
 * The delivery intake double: S registers no real binding, so the race drives one already-normalised fact
 * through the same lock order and the same applied/retained decision a Phase-T adapter would use.
 */
final class DznSConcurrencyDeliveryIntake {
    public function __construct(private NotificationDeliveryRepository $repository){}
    public function submit(array $facts):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $notificationId=(int)$facts['notification_id'];$attemptId=(int)$facts['attempt_id'];
        $this->repository->begin();
        try{
            $notification=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notifications WHERE id=%d FOR UPDATE",$notificationId));
            if(!$notification)throw new \RuntimeException('notification_not_found');
            $attempt=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_attempts WHERE id=%d",$attemptId));
            if(!$attempt||(int)$attempt->notification_id!==$notificationId)throw new \RuntimeException('notification_not_found');
            $rank=(int)NotificationRule::deliveryRank((string)$facts['delivery_state']);
            $applied=($rank>0&&$rank>(int)$this->repository->appliedRank($notificationId)
                &&(string)$notification->state==='dispatched'&&(string)$attempt->state==='acknowledged')?1:0;
            $now=NotificationSupport::now();
            $this->repository->insertDelivery(array(
                'uid'=>NotificationSupport::uid(),'notification_id'=>$notificationId,'attempt_id'=>$attemptId,
                'delivery_sequence'=>$this->repository->nextSequence($notificationId),'delivery_state'=>(string)$facts['delivery_state'],
                'delivery_rank'=>$rank,'provider_fact_digest'=>hash('sha256','fact-'.$notificationId.'-'.$attemptId),
                'provider_event_reference_digest'=>hash('sha256','reference-'.$notificationId.'-'.$attemptId),
                'occurred_at'=>$now,'recorded_at'=>$now,'applied'=>$applied,
            ));
            if($applied===1){
                $wpdb->update($p.'notifications',array('state'=>'delivered','delivered_at'=>$now,'updated_at'=>$now),array('id'=>$notificationId,'state'=>'dispatched'));
                $wpdb->update($p.'platform_outbox',array('status'=>'delivered','processed_at'=>$now),array('notification_id'=>$notificationId));
            }
            $this->repository->commit();
            return array('notification_id'=>$notificationId,'applied'=>$applied);
        }catch(\Throwable $error){
            $this->repository->rollback();
            throw $error;
        }
    }
}

$GLOBALS['dzn_s_worker']=$worker;
$mark=static function(string $suffix)use($gate):void{if($gate!==''&&is_dir($gate))touch($gate.'/'.$GLOBALS['dzn_s_worker'].'.'.$suffix);};
$waitForRelease=static function()use($gate):void{$limit=0;while(!file_exists($gate.'/release')&&$limit<1200){usleep(100000);$limit++;}};
$evidence=dzn_s_fix_evidence('conc-'.$mode.'-'.$worker);
$recover=static function(array $ready,array $evidence,string $key):array{
    return (new NotificationDispatchService(null,null,null,null,new DznSConcurrencyTransport(),$ready['subjects'],$ready['recipients'],new NotificationSuppressionRepository()))->recoverExpiredLeases($evidence,$key);
};
$output=static function(string $line)use($worker):void{echo $worker.': '.$line."\n";};

if($mode==='competing_activation_same_intent'||$mode==='rule_attach_vs_activation'){
    $workflows=new NotificationWorkflowService();
    $versionId=(int)($mode==='competing_activation_same_intent'
        ?($worker==='w1'?(int)$fixture['first_version_id']:(int)$fixture['second_version_id'])
        :(int)$fixture['workflow_version_id']);
    if($worker==='w1'){
        $wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$wpdb->query('START TRANSACTION');
        $wpdb->query($wpdb->prepare("SELECT id FROM {$p}notification_workflow_versions WHERE id=%d FOR UPDATE",$versionId));
        $mark('started');
        $waitForRelease();
        $wpdb->query('COMMIT');
    }else{
        $mark('started');
    }
    try{
        if($mode==='competing_activation_same_intent'){
            $workflows->activateVersion($versionId,dzn_s_fix_key('conc-act-'.$worker));
            $output('activated');
        }elseif($worker==='w1'){
            $workflows->activateVersion($versionId,dzn_s_fix_key('conc-rule-act'));
            $output('activated');
        }else{
            $workflows->setEligibilityRule($versionId,array('rule_code'=>'not_suppressed'),dzn_s_fix_key('conc-rule-append'));
            $output('appended');
        }
    }catch(Throwable $error){
        $output($error->getMessage());
    }
    $mark('finished');
    exit(0);
}

$notificationId=(int)(($mode==='unrelated_notifications'&&$worker==='w2'&&isset($fixture['second_notification_id']))
    ?$fixture['second_notification_id']:(int)$fixture['notification_id']);
$attemptId=(int)($fixture['attempt_id']??0);
$ready=array('recipients'=>new DznSRecipientPort(),'subjects'=>new DznSSubjectPort());

if($worker==='w1'){
    $wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$wpdb->query('START TRANSACTION');
    $wpdb->query($wpdb->prepare("SELECT id FROM {$p}notifications WHERE id=%d FOR UPDATE",$notificationId));
    // §6.2.3/§6.2.4(b): the two drift modes mutate the *other* module's authoritative rows inside the
    // holder's locked window, so the contender's dispatch resolves them after they are committed.
    if($mode==='subject_transition_after_enqueue_vs_dispatch'){
        dzn_s_fix_cycle_transition((int)$fixture['cycle_id'],'payment_required','payment_required','conc-'.$mode);
    }
    if($mode==='policy_change_after_publication_vs_dispatch'){
        $now=gmdate('Y-m-d H:i:s');
        $wpdb->insert($p.'commercial_policies',array(
            'uid'=>Identifier::uid(),'policy_key'=>'AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME','policy_version'=>(int)$fixture['policy_version'],
            'policy_value'=>'30','value_type'=>'days','effective_from'=>$now,'status'=>'active','reason_code'=>'post_publication_change',
            'evidence_channel'=>'staff_record','evidence_reference_digest'=>hash('sha256','conc-policy'),'evidence_at'=>$now,
            'recorded_at'=>$now,'recorded_by'=>1,'created_at'=>$now,'created_by'=>1,
        ));
        $wpdb->update($p.'commercial_recurring_patterns',array(
            'weekday'=>5,'local_wall_time'=>'17:30','schedule_timezone'=>'Europe/Paris','duration_minutes'=>45,'buffer_minutes'=>15,
            'updated_at'=>$now,'updated_by'=>1,
        ),array('id'=>(int)$fixture['pattern_id']));
    }
    $mark('started');
    $waitForRelease();
    $wpdb->query('COMMIT');
}else{
    $mark('started');
}

try{
    if($mode==='dispatch_vs_retry'){
        $dispatch=dzn_s_fix_dispatch($ready,new DznSConcurrencyTransport());
        if($worker==='w1'){
            $result=$dispatch->handOff($attemptId,$evidence,dzn_s_fix_key('conc-handoff-'.$worker));
            $output('handed_off='.(int)!empty($result['handed_off']));
        }else{
            $result=$dispatch->recordOutcome($attemptId,array_merge($evidence,array('failure_class'=>'retryable','reason_code'=>'retryable')),dzn_s_fix_key('conc-outcome-'.$worker));
            $output('state='.((string)($result['state']??'')).' re_arm='.(int)!empty($result['re_arm']));
        }
    }elseif($mode==='lease_expiry_vs_handoff'){
        if($worker==='w1'){
            $result=$recover($ready,$evidence,dzn_s_fix_key('conc-recover-'.$worker));
            $output('recovered='.(int)($result['recovered']??0).' closed='.(int)($result['closed']??0));
        }else{
            $dispatch=dzn_s_fix_dispatch($ready,new DznSConcurrencyTransport());
            $result=$dispatch->handOff($attemptId,$evidence,dzn_s_fix_key('conc-handoff-'.$worker));
            $output('handed_off='.(int)!empty($result['handed_off']).' outcome='.(string)($result['outcome']??''));
        }
    }elseif($mode==='retry_exhaustion_vs_recovery'){
        if($worker==='w1'){
            $dispatch=dzn_s_fix_dispatch($ready,new DznSConcurrencyTransport());
            $result=$dispatch->recordOutcome($attemptId,array_merge($evidence,array('failure_class'=>'retryable','reason_code'=>'retryable')),dzn_s_fix_key('conc-outcome-'.$worker));
            $output('state='.((string)($result['state']??'')).' exhaustion='.((string)($result['exhaustion']??'')));
        }else{
            $result=$recover($ready,$evidence,dzn_s_fix_key('conc-recover-'.$worker));
            $output('recovered='.(int)($result['recovered']??0).' closed='.(int)($result['closed']??0));
        }
    }elseif($mode==='subject_transition_after_enqueue_vs_dispatch'||$mode==='policy_change_after_publication_vs_dispatch'){
        if($worker==='w1'){
            $output('drift_applied');
        }else{
            $dispatch=dzn_s_fix_dispatch($ready,new DznSConcurrencyTransport());
            $result=$dispatch->claimLease($evidence,dzn_s_fix_key('conc-claim-'.$worker));
            $output('claimed='.(int)!empty($result['claimed']).' state='.(string)($result['state']??''));
        }
    }elseif($mode==='deferral_vs_claim'){
        if($worker==='w1'){
            $service=new NotificationService(null,null,null,null,null,null,$ready['recipients'],$ready['subjects']);
            $result=$service->defer($notificationId,$evidence,dzn_s_fix_key('conc-defer-'.$worker));
            $output('state='.((string)($result['state']??'')).' deferral_count='.(int)($result['deferral_count']??0));
        }else{
            $dispatch=dzn_s_fix_dispatch($ready,new DznSConcurrencyTransport());
            $result=$dispatch->claimLease($evidence,dzn_s_fix_key('conc-claim-'.$worker));
            $output('claimed='.(int)!empty($result['claimed']).' reason='.(string)($result['reason']??''));
        }
    }elseif($mode==='activation_vs_dispatch'){
        if($worker==='w1'){
            $workflows=new NotificationWorkflowService();
            $result=$workflows->activateVersion((int)$fixture['successor_version_id'],dzn_s_fix_key('conc-activate-'.$worker));
            $output('activated='.(int)$result['workflow_version_id']);
        }else{
            $dispatch=dzn_s_fix_dispatch($ready,new DznSConcurrencyTransport());
            $result=$dispatch->claimLease($evidence,dzn_s_fix_key('conc-claim-'.$worker));
            $output('claimed='.(int)!empty($result['claimed']).' state='.(string)($result['state']??''));
        }
    }elseif($mode==='suppress_vs_enqueue'){
        if($worker==='w2'){
            $wpdb->update($p.'notifications',array('state'=>'scheduled'),array('id'=>$notificationId));
            $ready['recipients']->optedIn=false;
            $result=(new NotificationService(null,null,null,null,null,null,$ready['recipients'],$ready['subjects']))->enqueue($notificationId,$evidence,dzn_s_fix_key('conc-enqueue-'.$worker));
            $output('state='.((string)($result['state']??'')).' outcome='.(string)($result['outcome']??''));
        }else{
            $notification=$wpdb->get_row($wpdb->prepare("SELECT recipient_digest FROM {$p}notifications WHERE id=%d",$notificationId));
            $result=(new NotificationSuppressionService())->suppress(array_merge($evidence,array(
                'subject_kind'=>'student','subject_digest'=>(string)$notification->recipient_digest,'purpose'=>'TERM_LAPSED','reason_code'=>'conc',
            )),dzn_s_fix_key('conc-suppress-'.$worker));
            $output('suppression='.(int)$result['suppression_id']);
        }
    }elseif($mode==='cancel_vs_dispatch'){
        if($worker==='w2'){
            $result=(new NotificationService())->cancel($notificationId,array_merge($evidence,array('reason_code'=>'operator_cancel')),dzn_s_fix_key('conc-cancel-'.$worker));
            $output('state='.((string)($result['state']??'')));
        }else{
            $dispatch=dzn_s_fix_dispatch($ready,new DznSConcurrencyTransport());
            $result=$dispatch->claimLease($evidence,dzn_s_fix_key('conc-claim-'.$worker));
            $output('claimed='.(int)!empty($result['claimed']).' reason='.(string)($result['reason']??''));
        }
    }elseif($mode==='delivery_vs_attempt_close'){
        if($worker==='w1'){
            $dispatch=dzn_s_fix_dispatch($ready,new DznSConcurrencyTransport());
            $result=$dispatch->recordOutcome($attemptId,array_merge($evidence,array('acknowledged'=>true)),dzn_s_fix_key('conc-ack-'.$worker));
            $output('state='.((string)($result['state']??'')));
        }else{
            $result=(new DznSConcurrencyDeliveryIntake(new NotificationDeliveryRepository()))->submit(array(
                'notification_id'=>$notificationId,'attempt_id'=>$attemptId,'delivery_state'=>'delivered',
                'provider_fact_digest'=>hash('sha256','fact'),'provider_event_reference_digest'=>hash('sha256','reference'),
                'occurred_at'=>gmdate('Y-m-d H:i:s'),
            ));
            $output('applied='.(int)$result['applied']);
        }
    }elseif($mode==='erase_vs_dispatch'){
        if($worker==='w2'){
            $result=(new NotificationPrivacyService())->eraseRecipient($notificationId,array_merge($evidence,array('reason_code'=>'owner_request')),dzn_s_fix_key('conc-erase-'.$worker));
            $output('erased='.(int)!empty($result['erased']));
        }else{
            $dispatch=dzn_s_fix_dispatch($ready,new DznSConcurrencyTransport());
            $result=$dispatch->claimLease($evidence,dzn_s_fix_key('conc-claim-'.$worker));
            $output('claimed='.(int)!empty($result['claimed']).' reason='.(string)($result['reason']??''));
        }
    }elseif($mode==='unrelated_notifications'){
        $result=(new NotificationService())->cancel($notificationId,array_merge($evidence,array('reason_code'=>'operator_cancel')),dzn_s_fix_key('conc-unrelated-'.$worker));
        $output('state='.((string)($result['state']??'')));
    }
    $mark('finished');
}catch(Throwable $error){
    $output($error->getMessage());
    $mark('finished');
}
