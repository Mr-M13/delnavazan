<?php
/** Verify the committed database state of one gated Phase-P attendance intake race. */
if(getenv('DZN_PHASE_2A2P_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI){fwrite(STDERR,"Phase 2A.2-P concurrency verifier refused.\n");exit(1);}
global $wpdb;$p=$wpdb->prefix.'dzn_';
$mode=(string)getenv('DZN_PHASE_2A2P_MODE');
$state=get_option('dzn_phase_2a2p_concurrency_state');
if(!is_array($state)||$mode==='')throw new RuntimeException('Phase 2A.2-P concurrency state required');
function dzn_pv_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$evidenceCount=static function(int $lessonId) use($wpdb,$p):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_evidence WHERE lesson_id=%d",$lessonId));};
$providerCount=static function(int $lessonId) use($wpdb,$p):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_evidence WHERE lesson_id=%d AND evidence_kind='provider_interval'",$lessonId));};
$caseFor=static function(int $lessonId) use($wpdb,$p):?object{return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_cases WHERE lesson_id=%d ORDER BY id LIMIT 1",$lessonId));};
$outcome=static function(int $lessonId) use($wpdb,$p):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d",$lessonId));};
$gate=(string)getenv('DZN_PHASE_2A2P_GATE_DIR');
$workerResult=static function(string $worker) use($gate):array{
    $raw=trim((string)@file_get_contents($gate.'/'.$worker.'.result'));
    if($raw==='')throw new RuntimeException('Phase-P race worker result unavailable: '.$worker);
    $decoded=json_decode($raw,true);
    if(!is_array($decoded))throw new RuntimeException('Phase-P race worker result malformed: '.$worker);
    return $decoded;
};
$first=(int)$state['first']['lesson_id'];$second=(int)$state['second']['lesson_id'];
switch($mode){
    case 'same_event_same_payload':
        dzn_pv_assert($providerCount($first)===1,'identical provider event race must retain exactly one evidence row');
        dzn_pv_assert($outcome($first)===0,'a race on an unproven occurrence must not create canonical truth');
        break;
    case 'same_event_changed_payload':
        dzn_pv_assert($providerCount($first)===1,'changed-payload race must retain exactly the first provider event');
        break;
    case 'claim_vs_adjudication':
        $case=$caseFor($first);
        dzn_pv_assert($case&&in_array((string)$case->state,array('ready_for_review','closed_no_change','adjudicated'),true),'the concurrent claim must leave a reviewable case');
        dzn_pv_assert($evidenceCount($first)>=1,'claim evidence must be retained after adjudication');
        dzn_pv_assert($outcome($first)===0,'a stale adjudication must never create canonical truth');
        dzn_pv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_decisions WHERE case_id=%d AND decision_kind='admin_adjudication'",(int)$case->id))===0,'a stale adjudication must never record an adjudication decision');
        break;
    case 'adjudication_vs_adjudication':
        $case=$caseFor($first);
        dzn_pv_assert($case&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_decisions WHERE case_id=%d AND decision_kind='admin_adjudication'",(int)$case->id))>=1,'concurrent adjudication must record a decision');
        dzn_pv_assert($outcome($first)===0,'record_no_change adjudication must not create canonical truth');
        break;
    case 'command_key_cross_lesson':
        dzn_pv_assert($providerCount($first)+$providerCount($second)===1,'exactly one command key reuse may write provider evidence');
        dzn_pv_assert($providerCount($first)===0||$providerCount($second)===0,'a losing cross-Lesson command must never write evidence into the other Lesson');
        dzn_pv_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_attendance_commands")>=1,'the winning command must be durably recorded');
        break;
    case 'command_key_exact':
        $w1=$workerResult('w1');$w2=$workerResult('w2');
        dzn_pv_assert($w1['ok']===true&&$w2['ok']===true,'an exact concurrent command replay must be accepted by both workers');
        $idempotent1=!empty($w1['result']['idempotent']);$idempotent2=!empty($w2['result']['idempotent']);
        dzn_pv_assert($idempotent1!==$idempotent2,'exactly one exact concurrent response must be the non-idempotent creation');
        dzn_pv_assert((int)$w1['result']['case_id']===(int)$w2['result']['case_id'],'both exact responses must resolve to the same attendance case');
        $evidence1=$w1['result']['evidence_id']??null;$evidence2=$w2['result']['evidence_id']??null;
        dzn_pv_assert($evidence1!==null&&$evidence2!==null&&(int)$evidence1===(int)$evidence2,'both exact responses must reference the same durable evidence identifier');
        dzn_pv_assert($providerCount($first)===1,'an exact concurrent command replay must never add a second evidence row');
        dzn_pv_assert($outcome($first)===0,'an exact concurrent command replay must not create canonical truth');
        break;
    case 'command_key_changed_payload':
    case 'command_key_changed_event':
    case 'command_key_changed_account':
    case 'command_key_changed_interval':
    case 'command_key_changed_observed':
    case 'command_key_changed_provenance':
        dzn_pv_assert($providerCount($first)===1,'a same-Lesson/schedule duplicate command must never add a second evidence row');
        dzn_pv_assert($outcome($first)===0,'a same-Lesson/schedule duplicate command must not create canonical truth');
        break;
    case 'unrelated_lessons':
        dzn_pv_assert($evidenceCount($first)===1&&$evidenceCount($second)===1,'unrelated Lessons must each record exactly one claim');
        break;
    default:
        throw new RuntimeException('Unknown Phase-P race mode: '.$mode);
}
echo 'verified='.$mode."\n";
