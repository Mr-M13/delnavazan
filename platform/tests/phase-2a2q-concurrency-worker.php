<?php
/** One gated Phase-Q continuation race worker; worker 1 holds its locks until the runner releases the gate. */
if(getenv('DZN_PHASE_2A2Q_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI){fwrite(STDERR,"Phase 2A.2-Q concurrency worker refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalContinuationService,CanonicalContinuationValidator};

global $wpdb;
$state=get_option('dzn_phase_2a2q_concurrency_state');
$worker=(string)getenv('DZN_PHASE_2A2Q_WORKER');
$gate=(string)getenv('DZN_PHASE_2A2Q_GATE_DIR');
if(!is_array($state)||!in_array($worker,array('w1','w2'),true)||$gate==='')throw new RuntimeException('Phase 2A.2-Q concurrency state required');
$entry=(array)($state['actions'][$worker]??array());
$action=(string)($entry['action']??'');
$mark=static fn(string $name,string $value='')=>file_put_contents($gate.'/'.$name,$value);
$hook=(string)($state['hooks'][$worker]??'');
if($worker==='w1'&&$hook!==''){
    add_action($hook,static function() use($mark,$gate,$worker,$hook):void{
        $mark($worker.'.locked',$hook);
        for($i=0;$i<1200&&!is_file($gate.'/release');$i++)usleep(100000);
        if(!is_file($gate.'/release'))throw new RuntimeException('gate timeout');
    },10,3);
}
$mark($worker.'.connection',(string)$GLOBALS['wpdb']->get_var('SELECT CONNECTION_ID()'));
$mark($worker.'.started');
$occurrence=(array)($state[$entry['target']??'first']??$state['first']);
$key=(string)($state['keys'][$worker]??wp_generate_uuid4());
if($key==='')$key=(string)$state['keys']['w1'];
$reference=(string)($state['references'][$worker]??('race-'.$worker));
$at=(string)$state['at'];
try{
    $svc=new CanonicalContinuationService();
    if($action==='teacher_exception')wp_set_current_user((int)$state['teacher_user']);
    elseif(isset($state['actors'][$worker]))wp_set_current_user((int)$state['actors'][$worker]);
    else wp_set_current_user((int)$occurrence['principal']);
    if(in_array($action,array('revoke_guardian','revoke_principal','lesson_schedule','record_slot'),true))wp_set_current_user(1);
    $evidence=array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'race-'.$action.'-'.$reference,'evidence_at'=>$at);
    $result=match($action){
        'continue'=>$svc->continueWithTeacher((int)$occurrence['lesson_id'],$evidence,$key),
        'contact'=>$svc->requestContact((int)$occurrence['lesson_id'],$evidence,$key),
        'teacher_exception'=>$svc->markMatchNeedsAdmin((int)$occurrence['lesson_id'],$evidence,$key),
        'stop'=>$svc->stopContinuation((int)$occurrence['lesson_id'],$evidence,$key),
        'record_slot'=>(function() use($svc,$occurrence,$state,$key):array{
            $wall=(string)($state['slot_wall']??gmdate('Y-m-d H:i:s',time()+7200));
            return $svc->recordFirstRegularSlot((int)$occurrence['lesson_id'],array(
                'schedule_timezone'=>'UTC','local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11,8),
                'authority_basis'=>'administrator_attestation','reason_code'=>'agreed_regular_slot',
                'evidence_channel'=>'staff_record','evidence_reference'=>'race-slot-'.substr(hash('sha256',$key),0,12),'evidence_at'=>gmdate('Y-m-d H:i:s'),
            ),$key);
        })(),
        'lesson_schedule'=>(function() use($state,$at,$reference,$key):array{
            $chain=(array)($state['chain']??array());
            $wall=(string)($state['schedule_wall']??$at);
            return (new \Delnavazan\Platform\Core\Application\CanonicalLessonScheduleService())->schedule((int)$chain['lesson_id'],(int)$chain['assignment_id'],array(
                'schedule_timezone'=>'UTC','local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11,8),
                'duration_minutes'=>30,'reason_code'=>'synthetic_race','evidence_channel'=>'staff_record','evidence_reference'=>'race-schedule-'.$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'),
            ),'dzn-2a2q-race-schedule-'.$key);
        })(),
        'revoke_guardian'=>(function() use($state):array{
            $grant=(array)($state['guardian_grant']??array());
            (new \Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService())->revokeGuardian((int)$grant['id'],(int)$grant['version'],'synthetic_race_revocation',1);
            return array('revoked_grant_id'=>(int)$grant['id']);
        })(),
        'revoke_principal'=>(function() use($state):array{
            $link=(array)($state['principal_link']??array());
            (new \Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService())->revokePrincipal((int)$link['id'],(int)$link['version'],'synthetic_race_revocation',1);
            return array('revoked_link_id'=>(int)$link['id']);
        })(),
        default=>throw new RuntimeException('Unknown Phase-Q race action: '.$action),
    };
    $mark($worker.'.result',wp_json_encode(array('ok'=>true,'action'=>$action,'result'=>is_array($result)?$result:array('value'=>$result)))."\n");
}catch(Throwable$exception){
    $mark($worker.'.result',wp_json_encode(array('ok'=>false,'action'=>$action,'class'=>$exception::class,'message'=>$exception->getMessage()))."\n");
}
$mark($worker.'.finished');
