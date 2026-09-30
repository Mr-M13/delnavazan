<?php
/** Disposable Phase-R1 concurrency worker: performs one contender action, optionally gated. */
if(getenv('DZN_PHASE_2A2R1_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-R1 concurrency worker refused.\n");exit(1);}
require __DIR__.'/phase-2a2r1-fixture.php';
use Delnavazan\Platform\Core\Application\{CanonicalLessonAuthorityService,CanonicalLessonScheduleService,CommercialCapacityService};
global $wpdb;$p=$wpdb->prefix.'dzn_';
$mode=(string)getenv('DZN_PHASE_2A2R1_MODE');
$worker=(string)getenv('DZN_PHASE_2A2R1_WORKER');
$gate=(string)getenv('DZN_PHASE_2A2R1_GATE_DIR');
$state=get_option('dzn_phase_2a2r1_concurrency');
if(!is_array($state)||(string)($state['mode']??'')!==$mode)throw new RuntimeException('Phase R1 concurrency setup required');
wp_set_current_user(1);
$result=array('ok'=>false,'worker'=>$worker,'mode'=>$mode,'action'=>'none');
/** Gate the holder inside its own transaction until the runner releases it. */
$hold=static function(string $hook) use($gate,$worker):void{
    if($gate==='')return;
    add_action($hook,static function() use($gate,$worker):void{
        if(!is_dir($gate))throw new RuntimeException('Gate directory missing');
        file_put_contents($gate.'/'.$worker.'.started','1');
        $waited=0.0;
        while(!file_exists($gate.'/release')&&$waited<60.0){usleep(100000);$waited+=0.1;}
        if(!file_exists($gate.'/release'))throw new RuntimeException('Gate release timed out');
    });
};
try{
    if($mode==='duplicate_evidence'){
        $offer=$state['offer'];
        $result['action']=$worker==='w1'?'settle_first':'settle_duplicate';
        if($worker==='w1')$hold('dzn_phase_2a2r1_after_evidence_insert');
        $outcome=dzn_r1_fix_settle($offer,1,(string)$state['provider_reference']);
        $result['ok']=true;$result['outcome']=$outcome;
    }elseif($mode==='handoff_vs_schedule'){
        if($worker==='w1'){
            $result['action']='handoff';
            $hold('dzn_phase_2a2r1_after_claim_insert');
            $result['outcome']=dzn_r1_fix_handoff((int)$state['entitlement_id'],'race-handoff');
            $result['ok']=true;
        }else{
            $result['action']='schedule_competing';
            $lesson=$state['free_lesson'];
            $target=$state['target_interval'];
            $outcome=(new CanonicalLessonScheduleService())->schedule((int)$lesson['lesson_id'],(int)$lesson['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>(string)$target['local_wall_date'],'local_wall_time'=>(string)$target['local_wall_time'],'reason_code'=>'competing_booking','evidence_channel'=>'staff_record','evidence_reference'=>'race-competing','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_fix_key('race-competing'));
            $result['ok']=true;$result['outcome']=$outcome;
        }
    }elseif($mode==='settlement_vs_lesson_seven'){
        if($worker==='w1'){
            $result['action']='settle_second_tranche';
            $hold('dzn_phase_2a2r1_after_settlement');
            $outcome=dzn_r1_fix_settle($state['offer'],2,'prov-race-2');
            $result['ok']=true;$result['outcome']=$outcome;
        }else{
            $result['action']='create_lesson_seven';
            $outcome=(new CanonicalLessonAuthorityService())->createStandard((int)$state['term_id'],(int)$state['assignment_id'],dzn_r1_fix_evidence('race-lesson-seven'),dzn_r1_fix_key('race-lesson-seven'));
            $result['ok']=true;$result['outcome']=$outcome;
        }
    }elseif($mode==='promotion_global_limit'){
        if($worker==='w1'){
            $result['action']='settle_first_offer';
            $hold('dzn_phase_2a2r1_after_evidence_insert');
            $result['outcome']=dzn_r1_fix_settle($state['offer_a'],1,'prov-limit-a');
            $result['ok']=true;
        }else{
            $result['action']='settle_second_offer';
            $result['outcome']=dzn_r1_fix_settle($state['offer_b'],1,'prov-limit-b');
            $result['ok']=true;
        }
    }elseif($mode==='conflicting_evidence_replay'){
        if($worker==='w1'){
            $result['action']='settle_original_fact';
            $hold('dzn_phase_2a2r1_after_settlement');
            $result['outcome']=dzn_r1_fix_settle($state['offer'],1,(string)$state['provider_reference']);
            $result['ok']=true;
        }else{
            $result['action']='replay_conflicting_fact';
            $offer=$state['offer'];
            $result['outcome']=(new \Delnavazan\Platform\Core\Application\CommercialPaymentService())->ingest(array(
                'provider_key'=>'synthetic_provider','provider_reference'=>(string)$state['provider_reference'],'evidence_kind'=>'success',
                'amount_minor'=>'1','currency'=>'AUD','obligation_reference'=>$offer['offer_uid'].':1',
                'provider_occurred_at'=>gmdate('Y-m-d H:i:s'),'evidence_channel'=>'provider_evidence',
                'evidence_reference'=>'evidence-conflicting-replay','evidence_at'=>gmdate('Y-m-d H:i:s'),
            ),dzn_r1_fix_key('evidence-conflicting-replay'));
            $result['ok']=true;
        }
    }elseif($mode==='release_vs_satisfaction'){
        if($worker==='w1'){
            $result['action']='release_claim';
            $hold('dzn_phase_2a2r1_teacher_root_held');
            $result['outcome']=dzn_r1_fix_release_claim((int)$state['claim_id'],'release-race');
            $result['ok']=true;
        }else{
            $result['action']='schedule_after_release';
            $target=$state['interval'];
            $result['outcome']=(new CanonicalLessonScheduleService())->schedule((int)$state['lesson_id'],(int)$state['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>(string)$target['local_wall_date'],'local_wall_time'=>(string)$target['local_wall_time'],'reason_code'=>'release_race','evidence_channel'=>'staff_record','evidence_reference'=>'release-race','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_fix_key('release-race'));
            $result['ok']=true;
        }
    }elseif($mode==='unrelated_commitments'){
        $entitlement=$worker==='w1'?(int)$state['entitlement_a']:(int)$state['entitlement_b'];
        $result['action']='handoff';
        if($worker==='w1')$hold('dzn_phase_2a2r1_after_claim_insert');
        $result['outcome']=dzn_r1_fix_handoff($entitlement,'race-'.$worker);
        $result['ok']=true;
    }elseif($mode==='unattributed_conflict'||$mode==='unattributed_convergence'){
        $result['action']='ingest_unattributed_fact';
        // The holder keeps its initially-unattributed evidence row uncommitted inside its own
        // transaction while the contender submits its own facts for the same provider reference.
        if($worker==='w1')$hold('dzn_phase_2a2r1_after_unattributed_evidence_insert');
        $amount=$worker==='w1'?(int)$state['amount_a']:(int)$state['amount_b'];
        $result['outcome']=(new \Delnavazan\Platform\Core\Application\CommercialPaymentService())->ingest(array(
            'provider_key'=>'synthetic_provider','provider_reference'=>(string)$state['provider_reference'],'evidence_kind'=>'success',
            'amount_minor'=>(string)$amount,'currency'=>'AUD','obligation_reference'=>(string)$state['obligation_reference'],
            'provider_occurred_at'=>(string)$state['provider_occurred_at'],'evidence_channel'=>'provider_evidence',
            'evidence_reference'=>'evidence-unattributed-'.$worker,'evidence_at'=>gmdate('Y-m-d H:i:s'),
        ),dzn_r1_fix_key('unattributed-'.$worker));
        $result['ok']=true;
    }else{
        throw new RuntimeException('Unknown Phase R1 concurrency mode');
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
