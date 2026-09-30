<?php
/** Disposable Phase-V concurrency worker: performs one contender action, optionally gated. */
if(getenv('DZN_PHASE_2A2V_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-V concurrency worker refused.\n");exit(1);}
require __DIR__.'/phase-2a2r1-fixture.php';
require __DIR__.'/phase-2a2r2-fixture.php';
use Delnavazan\Platform\Core\Application\{CanonicalLessonAuthorityService,CanonicalLessonScheduleService,ProviderEventIngestService,ProviderIntegrationService};
use Delnavazan\Platform\Integrations\ContractProviderAdapters;
global $wpdb;$p=$wpdb->prefix.'dzn_';
$mode=(string)getenv('DZN_PHASE_2A2V_MODE');
$worker=(string)getenv('DZN_PHASE_2A2V_WORKER');
$gate=(string)getenv('DZN_PHASE_2A2V_GATE_DIR');
$state=get_option('dzn_phase_2a2v_concurrency');
if(!is_array($state)||(string)($state['mode']??'')!==$mode)throw new RuntimeException('Phase V concurrency setup required');
wp_set_current_user(1);
$ports=new ContractProviderAdapters(array());
$service=new ProviderIntegrationService(null,null,$ports,$ports,$ports);
$ingest=new ProviderEventIngestService(null,new ContractProviderAdapters(array()),null);
$scope='https://www.googleapis.com/auth/calendar.events';
$evidence=static fn(string $reference):array=>array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));
$key=static fn(string $label):string=>'dzn-2a2v-'.$label.'-'.wp_generate_uuid4();
$result=array('ok'=>false,'worker'=>$worker,'mode'=>$mode,'action'=>'none');
/**
 * Gate the holder inside its own open Phase-V transaction: the hook is dispatched by the owning
 * service after its aggregate rows are written and before the commit.
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
$connect=static function(int $teacher,string $label) use($service,$scope,$evidence,$key,$mode):array{
    $begin=$service->beginAuthorization(array('provider_code'=>'google_calendar','teacher_id'=>$teacher,'client_reference'=>'client-1','redirect_uri'=>'https://academy.example/cb','scope_snapshot'=>$scope)+$evidence($mode.'-'.$label.'-begin'),$key($mode.'-'.$label.'-begin'));
    return $service->completeAuthorization(array('state'=>(string)$begin['state'],'code'=>$label.'-'.$mode,'code_verifier'=>(string)$begin['code_verifier'],'redirect_uri'=>'https://academy.example/cb')+$evidence($mode.'-'.$label.'-complete'),$key($mode.'-'.$label.'-complete'));
};
$given=is_array($state['delivery']??null)?$state['delivery']:array('event_key'=>$mode.'-event-'.substr(str_replace('-','',wp_generate_uuid4()),0,8),'observed_at'=>gmdate('Y-m-d H:i:s'),'leave_at_utc'=>gmdate('Y-m-d H:i:s'),'join_at_utc'=>gmdate('Y-m-d H:i:s'));
$eventKey=(string)$given['event_key'];
/** One authenticated transport envelope over the exact body the fixture fixed for one occurrence. */
$envelope=static function(int $lessonId,int $scheduleVersionId,string $eventKey,string $joinAt,string $observedAt,string $leaveAt):array{
    $facts=array('provider_code'=>'google_meet','provider_event_key'=>$eventKey,'provider_payload_key'=>$eventKey,'participant_role'=>'teacher','provider_account_key'=>'acct-'.$eventKey,'observed_at'=>$observedAt,'join_at_utc'=>$joinAt,'leave_at_utc'=>$leaveAt);
    $body=(string)wp_json_encode($facts);
    return array('provider_code'=>'google_meet','lesson_id'=>$lessonId,'schedule_version_id'=>$scheduleVersionId,'transport'=>'deployment_gateway','authenticated'=>true,'authenticated_at'=>gmdate('Y-m-d H:i:s'),'raw_body'=>$body,'body_digest'=>hash('sha256',$body),'proof_reference'=>'proof-'.$eventKey.'-0000','facts'=>$facts);
};
$delivery=static function(array $state,string $joinAt) use($given,$eventKey,$envelope):array{
    return $envelope((int)$state['lesson_id'],(int)$state['schedule_version_id'],$eventKey,$joinAt,(string)$given['observed_at'],(string)$given['leave_at_utc']);
};
try{
    if($mode==='connect_vs_revoke'){
        if($worker==='w1'){
            $result['action']='complete_new_consent';
            $hold('dzn_phase_2a2v_after_connection_write');
            $result['outcome']=$connect((int)$state['teacher_id'],'race');
        }else{
            $result['action']='revoke_existing_connection';
            $result['outcome']=$service->revokeConnection((int)$state['connection_id'],$evidence('race-revoke'),$key('race-revoke'));
        }
        $result['ok']=true;
    }elseif($mode==='authorization_replay'){
        $pending=$state['pending_authorization']??null;
        if(!is_array($pending))throw new RuntimeException('authorization replay fixture missing');
        // Both processes attempt the identical completion of the identical state with the identical
        // command key, so exactly one lifecycle may be consumed and the other must converge on the
        // recorded command instead of minting a second consent or reviving the consumed state.
        $result['action']=$worker==='w1'?'complete_authorization':'replay_completed_authorization';
        if($worker==='w1')$hold('dzn_phase_2a2v_after_connection_write');
        $result['outcome']=$service->completeAuthorization(array('state'=>(string)$pending['state'],'code'=>'race-'.$mode,'code_verifier'=>(string)$pending['code_verifier'],'redirect_uri'=>'https://academy.example/cb')+$evidence('race-complete'),'dzn-2a2v-race-complete-'.$mode);
        $result['ok']=true;
    }elseif($mode==='projection_vs_release'){
        if($worker==='w1'){
            $result['action']='project_calendar_event';
            $hold('dzn_phase_2a2v_after_projection_write');
            $result['outcome']=$service->projectCalendarEvent(array('lesson_id'=>(int)$state['lesson_id'],'schedule_version_id'=>(int)$state['schedule_version_id'],'projection_reference'=>'race-calendar')+$evidence('race-project'),$key('race-project'));
        }else{
            $result['action']='release_canonical_schedule';
            $result['outcome']=(new CanonicalLessonScheduleService())->release((int)$state['lesson_id'],array('expected_schedule_version_id'=>(int)$state['schedule_version_id'],'reason_code'=>'race_release')+$evidence('race-release'),$key('race-release'));
        }
        $result['ok']=true;
    }elseif($mode==='projection_vs_completion'){
        if($worker==='w1'){
            $result['action']='project_calendar_event';
            $hold('dzn_phase_2a2v_after_projection_write');
            $result['outcome']=$service->projectCalendarEvent(array('lesson_id'=>(int)$state['lesson_id'],'schedule_version_id'=>(int)$state['schedule_version_id'],'projection_reference'=>'race-calendar')+$evidence('race-project'),$key('race-project'));
        }else{
            $result['action']='complete_canonical_lesson';
            $current=(string)$wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}lessons WHERE id=%d",(int)$state['lesson_id']));
            $result['outcome']=(new CanonicalLessonAuthorityService())->complete((int)$state['lesson_id'],$current,$evidence('race-complete'),$key('race-complete'));
        }
        $result['ok']=true;
    }elseif($mode==='duplicate_vs_conflicting_event'){
        if($worker==='w1'){
            $result['action']='ingest_provider_event';
            $hold('dzn_phase_2a2v_after_ingest_write');
            $result['outcome']=$ingest->ingest($delivery($state,(string)$given['join_at_utc']),$key('race-ingest'));
        }else{
            $result['action']='ingest_duplicate_or_conflicting_provider_event';
            $join=(string)$given['join_at_utc'];
            if((string)getenv('DZN_PHASE_2A2V_VARIANT')==='conflict')$join=gmdate('Y-m-d H:i:s',strtotime((string)$given['join_at_utc'].' UTC')+900);
            $result['outcome']=$ingest->ingest($delivery($state,$join),$key('race-ingest-2'));
        }
        $result['ok']=true;
    }elseif($mode==='cross_lesson_event_key_race'){
        // One provider event key delivered twice at the same instant with a materially changed context:
        // the holder writes the receipt for its Lesson and keeps that transaction open, while the
        // contender names a *different* Lesson with the same key. The canonical chains are disjoint, so
        // the two only meet on the provider-scoped lock: the loser must re-read the committed winner and
        // leave the durable conflict receipt its changed context requires rather than being rejected.
        $other=is_array($state['occurrence_b']??null)?$state['occurrence_b']:null;
        if($other===null)throw new RuntimeException('cross-lesson provider event key race fixture missing');
        if($worker==='w1'){
            $result['action']='ingest_provider_event';
            $hold('dzn_phase_2a2v_after_ingest_write');
            $result['outcome']=$ingest->ingest($delivery($state,(string)$given['join_at_utc']),$key('race-cross-lesson-a'));
        }else{
            $result['action']='ingest_same_provider_event_key_other_lesson';
            $result['outcome']=$ingest->ingest($envelope((int)$other['lesson_id'],(int)$other['schedule_version_id'],$eventKey,(string)$given['join_at_utc'],(string)$given['observed_at'],(string)$given['leave_at_utc']),$key('race-cross-lesson-b'));
        }
        $result['ok']=true;
    }elseif($mode==='provider_event_sequence_race'){
        // Two distinct event keys for two different Lessons arrive together. The holder keeps its receipt
        // transaction open, so the contender has to take a sequence that is distinct from the one the
        // holder is still holding — neither may be answered with a duplicate-key persistence failure.
        $other=is_array($state['occurrence_b']??null)?$state['occurrence_b']:null;
        if($other===null)throw new RuntimeException('provider event sequence race fixture missing');
        if($worker==='w1'){
            $result['action']='ingest_provider_event';
            $hold('dzn_phase_2a2v_after_ingest_write');
            $result['outcome']=$ingest->ingest($delivery($state,(string)$given['join_at_utc']),$key('race-sequence-a'));
        }else{
            $givenB=is_array($state['delivery_b']??null)?$state['delivery_b']:$given;
            $eventKeyB=(string)$givenB['event_key'];
            $result['action']='ingest_distinct_provider_event_other_lesson';
            $result['outcome']=$ingest->ingest($envelope((int)$other['lesson_id'],(int)$other['schedule_version_id'],$eventKeyB,(string)$givenB['join_at_utc'],(string)$givenB['observed_at'],(string)$givenB['leave_at_utc']),$key('race-sequence-b'));
        }
        $result['ok']=true;
    }elseif($mode==='ingest_vs_canonical_authority'){
        // The canonical schedule authority holds the complete canonical chain — root, Enrolment, Term and
        // Lesson — while the provider ingest takes the same chain in the same declared order. The release
        // names a version that is not applicable, so the canonical operation fails closed after it holds
        // the locks; the ingest must still complete and the canonical aggregate must stay untouched.
        if($worker==='w1'){
            $result['action']='release_canonical_schedule_stale';
            $hold('dzn_phase_2a2n_schedule_locks_held');
            $result['outcome']=(new CanonicalLessonScheduleService())->release((int)$state['lesson_id'],array('expected_schedule_version_id'=>(int)$state['schedule_version_id']+1000,'reason_code'=>'race_release')+$evidence('race-release'),$key('race-release'));
        }else{
            $result['action']='ingest_provider_event';
            $result['outcome']=$ingest->ingest($delivery($state,(string)$given['join_at_utc']),$key('race-ingest'));
        }
        $result['ok']=true;
    }elseif($mode==='mapping_revoke_vs_ingest'){
        if($worker==='w1'){
            $result['action']='ingest_provider_event';
            $hold('dzn_phase_2a2v_after_ingest_write');
            $result['outcome']=$ingest->ingest($delivery($state,(string)$given['join_at_utc']),$key('race-ingest'));
        }else{
            $result['action']='revoke_identity_mapping';
            $result['outcome']=$service->revokeIdentityMapping((int)$state['identity_mapping_id'],array('reason_code'=>'race_revoke')+$evidence('race-mapping-revoke'),$key('race-mapping-revoke'));
        }
        $result['ok']=true;
    }elseif($mode==='teacher_archival_vs_connection'){
        if($worker==='w1'){
            $result['action']='complete_new_consent';
            $hold('dzn_phase_2a2v_after_connection_write');
            $result['outcome']=$connect((int)$state['teacher_id'],'archival');
        }else{
            $result['action']='archive_teacher';
            $result['outcome']=(new \Delnavazan\Platform\Admin\Service\ArchiveService())->archive('teacher',(int)$state['teacher_id']);
            $result['instant']=gmdate('Y-m-d H:i:s');
        }
        $result['ok']=true;
    }elseif($mode==='unrelated_teacher'){
        if($worker==='w1'){
            $result['action']='complete_consent_teacher_a';
            $hold('dzn_phase_2a2v_after_connection_write');
            $result['outcome']=$connect((int)$state['teacher_id'],'unrelated-a');
        }else{
            $result['action']='complete_consent_teacher_b';
            $result['outcome']=$connect((int)$state['other_teacher_id'],'unrelated-b');
        }
        $result['ok']=true;
    }else{
        throw new RuntimeException('Unknown Phase V concurrency mode');
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
