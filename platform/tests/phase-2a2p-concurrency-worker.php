<?php
/** One gated Phase-P race worker; worker 1 holds its locks until the runner releases the gate. */
if(getenv('DZN_PHASE_2A2P_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI){fwrite(STDERR,"Phase 2A.2-P concurrency worker refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\CanonicalAttendanceIntakeService;

global $wpdb;
$state=get_option('dzn_phase_2a2p_concurrency_state');
$worker=(string)getenv('DZN_PHASE_2A2P_WORKER');
$gate=(string)getenv('DZN_PHASE_2A2P_GATE_DIR');
if(!is_array($state)||!in_array($worker,array('w1','w2'),true)||$gate==='')throw new RuntimeException('Phase 2A.2-P concurrency state required');
$entry=(array)($state['actions'][$worker]??array());
$action=(string)($entry['action']??'');
$mark=static fn(string $name,string $value='')=>file_put_contents($gate.'/'.$name,$value);
$hook=(string)($state['hooks'][$worker]??'');
if($worker==='w1'&&$hook!==''){
    add_action($hook,static function() use($mark,$gate,$worker,$hook):void{
        $mark($worker.'.locked',$hook);
        for($i=0;$i<1200&&!is_file($gate.'/release');$i++)usleep(100000);
        if(!is_file($gate.'/release'))throw new RuntimeException('gate timeout');
    });
}
$mark($worker.'.connection',(string)$GLOBALS['wpdb']->get_var('SELECT CONNECTION_ID()'));
$mark($worker.'.started');
$occurrence=(array)($state[$entry['target']??'first']??$state['first']);
$key=(string)($state['keys'][$worker]??wp_generate_uuid4());
$reference=(string)($state['references'][$worker]??('race-'.$worker));
$at=(string)$state['at'];
try{
    $intake=new CanonicalAttendanceIntakeService();
    $result=match($action){
        'ingest'=>(function() use($intake,$occurrence,$state,$worker,$key,$reference,$at):array{
            $eventKey=(string)($state['event_keys'][$worker]??('race-event-'.$worker));
            $payloadKey=(string)($state['payload_keys'][$worker]??('race-payload-'.$worker));
            $accountKey=(string)($state['accounts'][$worker]??('race-acct-'.$worker));
            $input=array(
                'provider_code'=>'google_meet','provider_account_key'=>$accountKey,
                'provider_event_key'=>$eventKey,'provider_payload_key'=>$payloadKey,
                'participant_role'=>'teacher',
                'join_at_utc'=>$occurrence['start'],'leave_at_utc'=>gmdate('Y-m-d H:i:s',strtotime($occurrence['start'].' UTC')+60),
                'observed_at'=>$at,'provenance_reference'=>'prov-'.$reference,'evidence_reference'=>'ref-'.$reference,
            );
            if(isset($state['overrides'][$worker])&&is_array($state['overrides'][$worker]))$input=array_merge($input,$state['overrides'][$worker]);
            return $intake->ingestProviderEvidence((int)$occurrence['lesson_id'],(int)$occurrence['version_id'],$input,$key);
        })(),
        'claim'=>$intake->submitClaim((int)$occurrence['lesson_id'],(int)$occurrence['version_id'],array('claim_kind'=>'review_request','reason_code'=>'race_claim','observed_at'=>$at,'evidence_reference'=>'claim-'.$reference),$key),
        'adjudicate'=>$intake->adjudicate((int)$state['case_id'],array('adjudication'=>(string)($entry['adjudication']??'record_no_change'),'expected_case_version'=>(int)($state['case_version']??0)),$key),
        default=>throw new RuntimeException('Unknown race action: '.$action),
    };
    $mark($worker.'.result',wp_json_encode(array('ok'=>true,'action'=>$action,'result'=>is_array($result)?$result:array('value'=>$result)))."\n");
}catch(Throwable$exception){
    $mark($worker.'.result',wp_json_encode(array('ok'=>false,'action'=>$action,'class'=>$exception::class,'message'=>$exception->getMessage()))."\n");
}
$mark($worker.'.finished');
