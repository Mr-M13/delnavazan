<?php
/** Disposable Phase-R2 concurrency worker: performs one contender action, optionally gated. */
if(getenv('DZN_PHASE_2A2R2_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-R2 concurrency worker refused.\n");exit(1);}
require __DIR__.'/phase-2a2r1-fixture.php';
require __DIR__.'/phase-2a2r2-fixture.php';
use Delnavazan\Platform\Core\Application\{CanonicalLessonScheduleService,CollectionIntentService,CommercialPaymentService,RecurringEnrolmentService,RecurringProtectionService,RecoveryService,RefundReviewService,RenewalCycleService};
global $wpdb;$p=$wpdb->prefix.'dzn_';
$mode=(string)getenv('DZN_PHASE_2A2R2_MODE');
$worker=(string)getenv('DZN_PHASE_2A2R2_WORKER');
$gate=(string)getenv('DZN_PHASE_2A2R2_GATE_DIR');
$state=get_option('dzn_phase_2a2r2_concurrency');
if(!is_array($state)||(string)($state['mode']??'')!==$mode)throw new RuntimeException('Phase R2 concurrency setup required');
wp_set_current_user(1);
$result=array('ok'=>false,'worker'=>$worker,'mode'=>$mode,'action'=>'none');
/**
 * Gate the holder inside its own open transaction: the hook is dispatched by the owning service
 * after its aggregate rows are written and before the commit.
 */
$hold=static function(string $hook) use($gate,$worker):void{
    if($gate==='')return;
    add_action($hook,static function() use($gate,$worker):void{
        if(!is_dir($gate))throw new RuntimeException('Gate directory missing');
        file_put_contents($gate.'/'.$worker.'.started','1');
        $waited=0.0;
        while(!file_exists($gate.'/release')&&$waited<120.0){usleep(100000);$waited+=0.1;}
        if(!file_exists($gate.'/release'))throw new RuntimeException('Gate release timed out');
    },1);
};
$competingSchedule=static function(array $free,array $target) use($worker):array{
    return (new CanonicalLessonScheduleService())->schedule((int)$free['lesson_id'],(int)$free['assignment_id'],array('schedule_timezone'=>(string)$target['schedule_timezone'],'local_wall_date'=>(string)$target['local_wall_date'],'local_wall_time'=>(string)$target['local_wall_time'],'reason_code'=>'competing_renewal','evidence_channel'=>'staff_record','evidence_reference'=>'race-competing-'.$worker,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('race-competing-'.$worker));
};
try{
    if($mode==='renewal_vs_schedule'){
        if($worker==='w1'){
            $result['action']='activate_manual_guarantee';
            $hold('dzn_phase_2a2r2_after_cycle_event_insert');
            $cycle=(new RenewalCycleService())->openCycle((int)$state['recurring'],array('source_term_id'=>(int)$state['funded']['term_id'],'collection_mode'=>'manual','evidence_channel'=>'staff_record','evidence_reference'=>'race-cycle','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('race-cycle'));
            $result['outcome']=(new RenewalCycleService())->activateManualGuarantee((int)$cycle['renewal_cycle_id'],dzn_r2_fix_evidence('race-guarantee'),dzn_r2_fix_key('race-guarantee'));
        }else{
            $result['action']='schedule_competing_interval';
            $result['outcome']=$competingSchedule($state['free'],$state['target']);
        }
        $result['ok']=true;
    }elseif($mode==='guarantee_vs_close'){
        if($worker==='w1'){
            $result['action']='activate_manual_guarantee';
            $hold('dzn_phase_2a2r2_after_cycle_event_insert');
            $cycle=(new RenewalCycleService())->openCycle((int)$state['recurring'],array('source_term_id'=>(int)$state['funded']['term_id'],'collection_mode'=>'manual','evidence_channel'=>'staff_record','evidence_reference'=>'race-cycle','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('race-cycle'));
            $result['outcome']=(new RenewalCycleService())->activateManualGuarantee((int)$cycle['renewal_cycle_id'],dzn_r2_fix_evidence('race-guarantee'),dzn_r2_fix_key('race-guarantee'));
        }else{
            $result['action']='close_recurring_enrolment';
            $result['outcome']=(new RecurringEnrolmentService())->close((int)$state['recurring'],dzn_r2_fix_evidence('race-close'),dzn_r2_fix_key('race-close'));
        }
        $result['ok']=true;
    }elseif($mode==='recovery_vs_satisfaction'){
        if($worker==='w1'){
            $result['action']='mark_recovery_recovered';
            $hold('dzn_phase_2a2r2_after_recovery_case_event_insert');
            $result['outcome']=(new RecoveryService())->markRecovered((int)$state['recovery_id'],dzn_r2_fix_evidence('race-recovered'),dzn_r2_fix_key('race-recovered'));
        }else{
            $result['action']='schedule_competing_interval';
            $result['outcome']=$competingSchedule($state['free'],$state['target']);
        }
        $result['ok']=true;
    }elseif($mode==='release_vs_succession'){
        if($worker==='w1'){
            $result['action']='release_recurring_protection';
            $hold('dzn_phase_2a2r2_after_protection_event_insert');
            $result['outcome']=(new RecurringProtectionService())->releaseProtection((int)$state['protection_id'],array('evidence_channel'=>'staff_record','evidence_reference'=>'race-release','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('race-release'));
        }else{
            $result['action']='establish_competing_protection';
            $result['outcome']=(new RecurringProtectionService())->establishProtection((int)$state['cycle']['cycle_id'],(int)$state['funded']['claim_id'],dzn_r2_fix_evidence('race-succession'),dzn_r2_fix_key('race-succession'));
        }
        $result['ok']=true;
    }elseif($mode==='mode_change_vs_cycle'){
        if($worker==='w1'){
            $result['action']='change_collection_mode';
            $hold('dzn_phase_2a2r2_after_recurring_command_insert');
            $result['outcome']=(new RecurringEnrolmentService())->setCollectionMode((int)$state['recurring'],array('collection_mode'=>'automatic','evidence_channel'=>'staff_record','evidence_reference'=>'race-mode','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('race-mode'));
        }else{
            $result['action']='open_renewal_cycle';
            // No `collection_mode` is supplied: the cycle derives it from the locked recurring
            // enrolment, which the serialised mode change has already rewritten to `automatic`.
            $result['outcome']=(new RenewalCycleService())->openCycle((int)$state['recurring'],array('source_term_id'=>(int)$state['funded']['term_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'race-cycle','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('race-cycle'));
        }
        $result['ok']=true;
    }elseif($mode==='refund_vs_settlement'){
        if($worker==='w1'){
            $result['action']='record_refund_evidence';
            $hold('dzn_phase_2a2r2_after_refund_review_event_insert');
            $result['outcome']=(new RefundReviewService())->recordRefundEvidence(array('purchase_id'=>(int)$state['purchase_id'],'obligation_id'=>(int)$state['funded']['obligation_id'],'evidence_id'=>(int)$state['evidence_id'],'kind'=>'refund','amount_minor'=>25000,'currency'=>'AUD','evidence_channel'=>'staff_record','evidence_reference'=>'race-refund','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('race-refund'));
        }else{
            $result['action']='ingest_settlement_evidence';
            $result['outcome']=(new CommercialPaymentService())->ingest(array('provider_key'=>'synthetic_provider','provider_reference'=>'race-provider-'.wp_generate_uuid4(),'evidence_kind'=>'success','amount_minor'=>(string)$state['funded']['amount_minor'],'currency'=>(string)$state['funded']['currency'],'obligation_reference'=>((string)$state['offer_uid']).':1','provider_occurred_at'=>gmdate('Y-m-d H:i:s'),'evidence_channel'=>'provider_evidence','evidence_reference'=>'race-evidence','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('race-evidence'));
        }
        $result['ok']=true;
    }elseif($mode==='unrelated_recurring_enrolments'){
        $recurring=$worker==='w1'?(int)$state['recurring_a']:(int)$state['recurring_b'];
        $result['action']='suspend_unrelated_recurring_enrolment';
        if($worker==='w1')$hold('dzn_phase_2a2r2_after_recurring_command_insert');
        $result['outcome']=(new RecurringEnrolmentService())->suspend($recurring,dzn_r2_fix_evidence('race-unrelated-'.$worker),dzn_r2_fix_key('race-unrelated-'.$worker));
        $result['ok']=true;
    }else{
        throw new RuntimeException('Unknown Phase R2 concurrency mode');
    }
}catch(Throwable$e){
    $result['ok']=false;
    $result['class']=get_class($e);
    $result['message']=$e->getMessage();
}
if($gate!==''){
    file_put_contents($gate.'/'.$worker.'.result',json_encode($result));
    file_put_contents($gate.'/'.$worker.'.finished','1');
}
echo json_encode($result)."\n";
