<?php
/** Verify the committed database state of one gated Phase-Q continuation race. */
use Delnavazan\Platform\Core\Application\CanonicalContinuationRule;

if(getenv('DZN_PHASE_2A2Q_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI){fwrite(STDERR,"Phase 2A.2-Q concurrency verifier refused.\n");exit(1);}
global $wpdb;$p=$wpdb->prefix.'dzn_';
$mode=(string)getenv('DZN_PHASE_2A2Q_MODE');
$state=get_option('dzn_phase_2a2q_concurrency_state');
if(!is_array($state)||$mode==='')throw new RuntimeException('Phase 2A.2-Q concurrency state required');
function dzn_qv_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$gate=(string)getenv('DZN_PHASE_2A2Q_GATE_DIR');
$workerResult=static function(string $worker) use($gate):array{
    $raw=trim((string)@file_get_contents($gate.'/'.$worker.'.result'));
    if($raw==='')throw new RuntimeException('Phase-Q race worker result unavailable: '.$worker);
    $decoded=json_decode($raw,true);
    if(!is_array($decoded))throw new RuntimeException('Phase-Q race worker result malformed: '.$worker);
    return $decoded;
};
$caseOf=static function(int $lessonId) use($wpdb,$p):?object{return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_cases WHERE intro_lesson_id=%d",$lessonId));};
$reservationOf=static function(int $caseId) use($wpdb,$p):?object{return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_reservations WHERE continuation_case_id=%d",$caseId));};
$decisionCount=static function(int $caseId) use($wpdb,$p):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_decisions WHERE continuation_case_id=%d",$caseId));};
$activeHoldCount=static function(int $teacherId) use($wpdb,$p):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_reservations WHERE teacher_id=%d AND state='active' AND expires_at>%s",$teacherId,gmdate('Y-m-d H:i:s')));};
$firstLesson=(int)$state['first']['lesson_id'];$secondLesson=(int)$state['second']['lesson_id'];
$firstTeacher=(int)$state['first']['teacher_id'];
switch($mode){
    case 'continue_exact_replay':
        $w1=$workerResult('w1');$w2=$workerResult('w2');
        dzn_qv_assert($w1['ok']===true&&$w2['ok']===true,'an exact concurrent continuation replay must be accepted by both workers');
        $case=$caseOf($firstLesson);
        dzn_qv_assert($case&&$decisionCount((int)$case->id)===1,'an exact concurrent replay must record exactly one decision');
        $reservation=$reservationOf((int)$case->id);
        dzn_qv_assert($reservation&&$activeHoldCount($firstTeacher)===1,'an exact concurrent replay must hold capacity exactly once');
        $reservationId=(int)$reservation->id;
        $ids=array();
        foreach(array($w1,$w2)as$payload){
            $value=$payload['result']['reservation_id']??$payload['result']['reservation']['reservation_id']??null;
            if($value!==null)$ids[]=(int)$value;
        }
        dzn_qv_assert(count($ids)===2,'both exact results must expose a reservation identifier');
        dzn_qv_assert($ids[0]===$ids[1]&&$ids[0]===$reservationId,'both exact concurrent responses must reference the same durable reservation');
        break;
    case 'command_key_changed_decision':
        $w1=$workerResult('w1');$w2=$workerResult('w2');
        dzn_qv_assert($w1['ok']===true&&$w2['ok']===false&&$w2['message']==='Idempotency conflict','a changed decision under one command key must fail idempotency conflict');
        $case=$caseOf($firstLesson);
        dzn_qv_assert($case&&$decisionCount((int)$case->id)===1,'a rejected changed-decision replay must leave exactly one decision');
        break;
    case 'two_student_decisions':
        $w1=$workerResult('w1');$w2=$workerResult('w2');
        dzn_qv_assert($w1['ok']===true&&$w2['ok']===true,'two Student decisions with distinct command keys must both be recorded');
        $case=$caseOf($firstLesson);
        dzn_qv_assert($case&&$decisionCount((int)$case->id)===2,'both Student decisions must be retained append-only');
        dzn_qv_assert((string)$case->current_decision==='contact_me','the later serialized decision must be the current continuation decision');
        $reservation=$reservationOf((int)$case->id);
        dzn_qv_assert($reservation&&(string)$reservation->state==='released','a later non-continuing decision must release the earlier hold');
        dzn_qv_assert($activeHoldCount($firstTeacher)===0,'a released hold must stop blocking capacity');
        break;
    case 'continue_vs_teacher_exception':
        $w1=$workerResult('w1');$w2=$workerResult('w2');
        dzn_qv_assert($w1['ok']===true,'the Student continuation must succeed');
        dzn_qv_assert($w2['ok']===true,'the Teacher match exception must succeed on its own principal');
        $case=$caseOf($firstLesson);
        dzn_qv_assert($case&&(string)$case->current_decision==='teacher_unsuitable','the Teacher exception must end the ordinary continuation state');
        $reservation=$reservationOf((int)$case->id);
        dzn_qv_assert($reservation&&(string)$reservation->state==='released','the Teacher exception must stop holding capacity');
        dzn_qv_assert($activeHoldCount($firstTeacher)===0,'no capacity may leak after a Teacher match exception');
        dzn_qv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_interventions WHERE continuation_case_id=%d AND reason_code='teacher_match_unsuitable'",(int)$case->id))===1,'the Teacher exception must record exactly one administrator intervention');
        break;
    case 'competing_hold_same_slot':
        $ok=0;$losers=0;
        foreach(array('w1','w2')as$worker){$r=json_decode(trim((string)@file_get_contents($gate.'/'.$worker.'.result')),true);if(!is_array($r))continue;if($r['ok']===true)$ok++;elseif($r['message']==='teacher_slot_conflict')$losers++;}
        dzn_qv_assert($ok===1&&$losers===1,'exactly one Student may hold the same exclusive Teacher slot');
        dzn_qv_assert($activeHoldCount($firstTeacher)===1,'exactly one active hold may remain after the race');
        dzn_qv_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_continuation_reservations")===1,'a losing hold attempt must leave no partial reservation');
        break;
    case 'unrelated_teachers':
        $w1=$workerResult('w1');$w2=$workerResult('w2');
        dzn_qv_assert($w1['ok']===true&&$w2['ok']===true,'unrelated Teachers must not block one another');
        $caseOne=$caseOf($firstLesson);$caseTwo=$caseOf($secondLesson);
        dzn_qv_assert($caseOne&&$caseTwo,'both unrelated continuation cases must exist');
        dzn_qv_assert($reservationOf((int)$caseOne->id)!==null&&$reservationOf((int)$caseTwo->id)!==null,'both unrelated holds must be recorded');
        break;
    case 'hold_vs_lesson_schedule_q_first':
    case 'hold_vs_lesson_schedule_n_first':
        $chain=(array)($state['chain']??array());
        $chainLesson=(int)($chain['lesson_id']??0);
        dzn_qv_assert($chainLesson>0,'the cross-service race needs its canonical Lesson chain');
        $schedules=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d",$chainLesson));
        $holds=$activeHoldCount($firstTeacher);
        if($mode==='hold_vs_lesson_schedule_q_first'){
            dzn_qv_assert($holds===1&&$schedules===0,'the Phase-Q hold must win with no conflicting Lesson schedule and no double occupancy');
        }else{
            dzn_qv_assert($schedules===1&&$holds===0,'the Phase-N schedule must win with no surviving hold and no double occupancy');
        }
        break;
    case 'expiry_vs_new_claim':
        $w1=$workerResult('w1');$w2=$workerResult('w2');
        dzn_qv_assert($w1['ok']===false&&$w1['message']==='teacher_slot_conflict','a claim while the hold is still effective must fail closed');
        dzn_qv_assert($w2['ok']===true,'a claim after the frozen expiry must succeed');
        $chain=(array)($state['chain']??array());
        dzn_qv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d",(int)$chain['lesson_id']))===1,'exactly one post-expiry schedule may exist');
        $expiringCase=$caseOf((int)$state['expiring']['lesson_id']);
        $expiringReservation=$expiringCase?$reservationOf((int)$expiringCase->id):null;
        dzn_qv_assert($expiringReservation&&CanonicalContinuationRule::capacityEffective((string)$expiringReservation->state,(string)$expiringReservation->expires_at,gmdate('Y-m-d H:i:s'))===false,'an expired hold must no longer be capacity-effective');
        break;
    case 'principal_decision_first':
        $w1=$workerResult('w1');$w2=$workerResult('w2');
        dzn_qv_assert($w1['ok']===true&&$w2['ok']===true,'a committed decision must survive a later principal revocation');
        $case=$caseOf($firstLesson);
        dzn_qv_assert($case&&$decisionCount((int)$case->id)===1,'the committed decision must remain historical');
        dzn_qv_assert((string)$wpdb->get_var($wpdb->prepare("SELECT actor_basis FROM {$p}canonical_continuation_decisions WHERE continuation_case_id=%d",(int)$case->id))==='adult_principal','the decision must record its original authority basis');
        dzn_qv_assert((string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}student_principal_links WHERE id=%d",(int)$state['principal_link']['id']))==='revoked','the later revocation must be durably recorded');
        break;
    case 'principal_revocation_first':
        $w1=$workerResult('w1');$w2=$workerResult('w2');
        dzn_qv_assert($w1['ok']===true,'the principal revocation must succeed');
        dzn_qv_assert($w2['ok']===false&&$w2['message']==='Unauthorized','a stale principal must not commit a continuation decision');
        dzn_qv_assert($caseOf($firstLesson)===null,'a refused stale-principal command must leave no continuation case');
        break;
    case 'guardian_decision_first':
        $w1=$workerResult('w1');$w2=$workerResult('w2');
        dzn_qv_assert($w1['ok']===true&&$w2['ok']===true,'a guardian decision must survive a later guardian revocation');
        $case=$caseOf($firstLesson);
        dzn_qv_assert($case&&(string)$wpdb->get_var($wpdb->prepare("SELECT actor_basis FROM {$p}canonical_continuation_decisions WHERE continuation_case_id=%d",(int)$case->id))==='guardian_representative','the guardian decision must record its authority basis');
        dzn_qv_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}student_acceptance_authority_grants WHERE id=%d",(int)$state['guardian_grant']['id']))==='revoked','the later revocation must be durably recorded');
        break;
    case 'guardian_revocation_first':
        $w1=$workerResult('w1');$w2=$workerResult('w2');
        dzn_qv_assert($w1['ok']===true,'the guardian revocation must succeed');
        dzn_qv_assert($w2['ok']===false&&$w2['message']==='Unauthorized','a revoked guardian must not commit a continuation decision');
        dzn_qv_assert($caseOf($firstLesson)===null,'a refused revoked-guardian command must leave no continuation case');
        break;
    case 'slot_vs_lesson_schedule':
        $ok=0;$losers=0;
        foreach(array('w1','w2')as$worker){$r=json_decode(trim((string)@file_get_contents($gate.'/'.$worker.'.result')),true);if(!is_array($r))continue;if($r['ok']===true)$ok++;elseif($r['message']==='teacher_slot_conflict')$losers++;}
        dzn_qv_assert($ok===1&&$losers===1,'exactly one of the delayed hold and the conflicting Lesson schedule may win');
        $chain=(array)($state['chain']??array());
        dzn_qv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d",(int)$chain['lesson_id']))<=1,'no double occupancy may survive the race');
        dzn_qv_assert($activeHoldCount($firstTeacher)<=1,'no orphan hold may survive the race');
        dzn_qv_assert((int)$decisionCount((int)$state['delayed_case_id'])===1,'the delayed race must not duplicate the Student decision');
        break;
    case 'slot_vs_teacher_unsuitable':
        $case=$caseOf($firstLesson);
        dzn_qv_assert($case!==null&&(int)$case->id===(int)$state['delayed_case_id'],'the delayed race must keep the original continuation case');
        dzn_qv_assert((int)$decisionCount((int)$case->id)>=1&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_decisions WHERE continuation_case_id=%d AND decision='continue_with_teacher'",(int)$case->id))===1,'the original continue decision must be preserved exactly once');
        if((string)$case->current_decision==='teacher_unsuitable'){
            $reservation=$reservationOf((int)$case->id);
            dzn_qv_assert($reservation===null||(string)$reservation->state!=='active','a suppressed match must not keep holding capacity');
        }
        dzn_qv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_slot_authorities WHERE intro_lesson_id=%d",$firstLesson))===1,'exactly one slot authority may exist');
        break;
    case 'slot_vs_terminal_decision':
        $case=$caseOf($firstLesson);
        dzn_qv_assert($case!==null&&(int)$case->id===(int)$state['delayed_case_id'],'the delayed race must keep the original continuation case');
        dzn_qv_assert((int)$decisionCount((int)$case->id)===2,'the terminal decision must be appended without duplicating the original');
        dzn_qv_assert((string)$case->current_decision==='not_continuing','the later terminal decision must be current');
        $reservation=$reservationOf((int)$case->id);
        dzn_qv_assert($reservation===null||(string)$reservation->state!=='active','a terminal case must never keep holding capacity');
        dzn_qv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_slot_authorities WHERE intro_lesson_id=%d",$firstLesson))===1,'exactly one slot authority may exist');
        break;
    default:
        throw new RuntimeException('Unknown Phase-Q race mode: '.$mode);
}
echo 'verified='.$mode."\n";
