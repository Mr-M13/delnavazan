<?php
/** Disposable Phase-Q corruption regressions: corrupted continuation authority must fail closed. */
if(getenv('DZN_PHASE_2A2Q_RUNTIME_TEST')!=='corruption'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-Q corruption runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalContinuationRule,CanonicalContinuationService,CanonicalContinuationReadService,LessonScheduleService,LessonService};

global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_qc_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_qc_key(string $label):string{return 'dzn-2a2qc-'.$label.'-'.wp_generate_uuid4();}
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_qc_assert(is_array($fixture)&&count($fixture['sources']??array())>=4,'Phase-J production fixture required');
$sources=$fixture['sources'];
foreach(array('canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities')as$t)dzn_qc_assert($wpdb->query("DELETE FROM {$p}{$t}")!==false,'Failed to reset disposable Phase Q storage');
$svc=new CanonicalContinuationService();$read=new CanonicalContinuationReadService();
$previous=get_current_user_id();wp_set_current_user(1);
$principalOf=static function(int $studentId) use($wpdb,$p):int{$id=(int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 LIMIT 1",$studentId));if($id<1)throw new RuntimeException('principal fixture required');return $id;};
$introOf=function(int $index,string $label,int $sequence) use($sources,$wpdb,$p):array{
    $src=$sources[$index%count($sources)];
    $lessonId=(int)(new LessonService())->create(array('student_id'=>(int)$src['student_id'],'teacher_id'=>(int)$src['teacher_id'],'course_id'=>(int)$src['course_id'],'lesson_type'=>'introductory','status'=>'draft'));
    $wall=gmdate('Y-m-d H:i:s',strtotime('-3 days')-($sequence*3600));
    (new LessonScheduleService())->initial($lessonId,array('schedule_timezone'=>'UTC','local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11,8),'reason'=>$label));
    $occurrence=$wpdb->get_row($wpdb->prepare("SELECT v.* FROM {$p}lesson_schedule_versions v INNER JOIN {$p}lessons l ON l.id=v.lesson_id AND l.current_schedule_version_id=v.id WHERE v.lesson_id=%d AND v.superseded_at IS NULL",$lessonId));
    return array('lesson_id'=>$lessonId,'student_id'=>(int)$src['student_id'],'teacher_id'=>(int)$src['teacher_id'],'course_id'=>(int)$src['course_id'],'occurrence'=>$occurrence);
};
// Fixtures: one continuing case with an active hold, one non-continuing case with an intervention.
$continueFixture=$introOf(0,'corrupt-continue',1);
$continueFixture['slot']=$svc->recordFirstRegularSlot((int)$continueFixture['lesson_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>gmdate('Y-m-d',time()+7200),'local_wall_time'=>gmdate('H:i:s',time()+7200),'authority_basis'=>'administrator_attestation','reason_code'=>'agreed_regular_slot','evidence_channel'=>'staff_record','evidence_reference'=>'corrupt-slot','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_qc_key('corrupt-slot'));
wp_set_current_user($principalOf((int)$continueFixture['student_id']));
$svc->continueWithTeacher((int)$continueFixture['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'corrupt-continue'),dzn_qc_key('corrupt-continue'));
wp_set_current_user(1);
$stopFixture=$introOf(1,'corrupt-stop',2);
wp_set_current_user($principalOf((int)$stopFixture['student_id']));
$svc->requestContact((int)$stopFixture['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'corrupt-stop'),dzn_qc_key('corrupt-stop'));
wp_set_current_user(1);
$caseOf=static function(int $lessonId) use($wpdb,$p):object{$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_cases WHERE intro_lesson_id=%d",$lessonId));if(!$row)throw new RuntimeException('Phase Q case fixture missing');return $row;};
$continueCase=$caseOf((int)$continueFixture['lesson_id']);
$stopCase=$caseOf((int)$stopFixture['lesson_id']);
$reservationId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_continuation_reservations WHERE continuation_case_id=%d",(int)$continueCase->id));
$decisionId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_continuation_decisions WHERE continuation_case_id=%d ORDER BY decision_sequence LIMIT 1",(int)$continueCase->id));
$interventionId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_continuation_interventions WHERE continuation_case_id=%d LIMIT 1",(int)$stopCase->id));
dzn_qc_assert($reservationId>0&&$decisionId>0&&$interventionId>0,'Phase Q corruption fixture incomplete');
$otherLesson=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}lessons WHERE lesson_type='introductory' AND id<>%d LIMIT 1",(int)$continueFixture['lesson_id']));
$otherStudent=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}students WHERE id<>%d LIMIT 1",(int)$continueCase->student_id));
$otherTeacher=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}teachers WHERE id<>%d LIMIT 1",(int)$continueCase->teacher_id));
$otherCourse=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}courses WHERE id<>%d LIMIT 1",(int)$continueCase->course_id));
$failClosed=function(string $label,object $case,string $damage,string $repair,array $expected) use($wpdb,$read):void{
    dzn_qc_assert($wpdb->query($damage)!==false,'Failed to damage Phase Q authority: '.$label);
    $observed=null;
    try{$read->forIntroLesson((int)$case->intro_lesson_id);}catch(Throwable$e){$observed=$e->getMessage();}
    dzn_qc_assert(in_array($observed,$expected,true),'Corrupted Phase Q authority ('.$label.') was not rejected (observed: '.var_export($observed,true).')');
    dzn_qc_assert($wpdb->query($repair)!==false,'Failed to repair Phase Q authority: '.$label);
    $restored=$read->forIntroLesson((int)$case->intro_lesson_id);
    dzn_qc_assert((string)$restored['case']['continuation_case_id']===(string)$case->id,'Repaired Phase Q authority is still unreadable: '.$label);
};
$integrity=array('canonical_continuation_integrity_conflict');
$cases=0;
// 1-4: stored source selectors must agree with the authoritative introductory source.
$failClosed('wrong Student',$continueCase,"UPDATE {$p}canonical_continuation_cases SET student_id={$otherStudent} WHERE id=".(int)$continueCase->id,"UPDATE {$p}canonical_continuation_cases SET student_id=".(int)$continueCase->student_id." WHERE id=".(int)$continueCase->id,$integrity);$cases++;
$failClosed('wrong Teacher',$continueCase,"UPDATE {$p}canonical_continuation_cases SET teacher_id={$otherTeacher} WHERE id=".(int)$continueCase->id,"UPDATE {$p}canonical_continuation_cases SET teacher_id=".(int)$continueCase->teacher_id." WHERE id=".(int)$continueCase->id,$integrity);$cases++;
$failClosed('wrong Course',$continueCase,"UPDATE {$p}canonical_continuation_cases SET course_id={$otherCourse} WHERE id=".(int)$continueCase->id,"UPDATE {$p}canonical_continuation_cases SET course_id=".(int)$continueCase->course_id." WHERE id=".(int)$continueCase->id,$integrity);$cases++;
$failClosed('wrong introductory Lesson',$continueCase,"UPDATE {$p}canonical_continuation_cases SET intro_lesson_id={$otherLesson} WHERE id=".(int)$continueCase->id,"UPDATE {$p}canonical_continuation_cases SET intro_lesson_id=".(int)$continueCase->intro_lesson_id." WHERE id=".(int)$continueCase->id,array('canonical_continuation_integrity_conflict','continuation_case_required'));$cases++;
// 5-6: bound occurrence and source anchors.
$failClosed('wrong source schedule version',$continueCase,"UPDATE {$p}canonical_continuation_cases SET intro_schedule_version_id=intro_schedule_version_id+100000 WHERE id=".(int)$continueCase->id,"UPDATE {$p}canonical_continuation_cases SET intro_schedule_version_id=intro_schedule_version_id-100000 WHERE id=".(int)$continueCase->id,$integrity);$cases++;
$failClosed('source occurrence anchors',$continueCase,"UPDATE {$p}canonical_continuation_cases SET intro_starts_at_utc=DATE_ADD(intro_starts_at_utc, INTERVAL 60 SECOND) WHERE id=".(int)$continueCase->id,"UPDATE {$p}canonical_continuation_cases SET intro_starts_at_utc=DATE_SUB(intro_starts_at_utc, INTERVAL 60 SECOND) WHERE id=".(int)$continueCase->id,$integrity);$cases++;
$failClosed('source timezone provenance',$continueCase,"UPDATE {$p}canonical_continuation_cases SET intro_local_wall_time=ADDTIME(intro_local_wall_time,'00:01:00') WHERE id=".(int)$continueCase->id,"UPDATE {$p}canonical_continuation_cases SET intro_local_wall_time=SUBTIME(intro_local_wall_time,'00:01:00') WHERE id=".(int)$continueCase->id,$integrity);$cases++;
// 7-8: the frozen slot and frozen expiry must recompute exactly.
$failClosed('reservation start',$continueCase,"UPDATE {$p}canonical_continuation_reservations SET starts_at_utc=DATE_ADD(starts_at_utc, INTERVAL 60 SECOND) WHERE id={$reservationId}","UPDATE {$p}canonical_continuation_reservations SET starts_at_utc=DATE_SUB(starts_at_utc, INTERVAL 60 SECOND) WHERE id={$reservationId}",$integrity);$cases++;
$failClosed('reservation expiry',$continueCase,"UPDATE {$p}canonical_continuation_reservations SET expires_at=DATE_ADD(expires_at, INTERVAL 3600 SECOND) WHERE id={$reservationId}","UPDATE {$p}canonical_continuation_reservations SET expires_at=DATE_SUB(expires_at, INTERVAL 3600 SECOND) WHERE id={$reservationId}",$integrity);$cases++;
$failClosed('reservation duration',$continueCase,"UPDATE {$p}canonical_continuation_reservations SET duration_minutes=15 WHERE id={$reservationId}","UPDATE {$p}canonical_continuation_reservations SET duration_minutes=30 WHERE id={$reservationId}",$integrity);$cases++;
// 9-11: reservation/decision coherence.
$failClosed('reservation owner decision',$continueCase,"UPDATE {$p}canonical_continuation_reservations SET decision_id=999999 WHERE id={$reservationId}","UPDATE {$p}canonical_continuation_reservations SET decision_id={$decisionId} WHERE id={$reservationId}",$integrity);$cases++;
$failClosed('reservation state',$continueCase,"UPDATE {$p}canonical_continuation_reservations SET state='unknown_state' WHERE id={$reservationId}","UPDATE {$p}canonical_continuation_reservations SET state='active' WHERE id={$reservationId}",$integrity);$cases++;
$failClosed('reservation teacher binding',$continueCase,"UPDATE {$p}canonical_continuation_reservations SET teacher_id={$otherTeacher} WHERE id={$reservationId}","UPDATE {$p}canonical_continuation_reservations SET teacher_id=".(int)$continueCase->teacher_id." WHERE id={$reservationId}",$integrity);$cases++;
// 12-13: rule version and case decision lineage.
$failClosed('rule version',$continueCase,"UPDATE {$p}canonical_continuation_cases SET rule_version='canonical_continuation_v0' WHERE id=".(int)$continueCase->id,"UPDATE {$p}canonical_continuation_cases SET rule_version='canonical_continuation_v1' WHERE id=".(int)$continueCase->id,$integrity);$cases++;
$failClosed('case decision',$continueCase,"UPDATE {$p}canonical_continuation_cases SET current_decision='free_form_state' WHERE id=".(int)$continueCase->id,"UPDATE {$p}canonical_continuation_cases SET current_decision='continue_with_teacher' WHERE id=".(int)$continueCase->id,$integrity);$cases++;
$failClosed('case/latest decision',$continueCase,"UPDATE {$p}canonical_continuation_cases SET latest_decision_id=999999 WHERE id=".(int)$continueCase->id,"UPDATE {$p}canonical_continuation_cases SET latest_decision_id={$decisionId} WHERE id=".(int)$continueCase->id,$integrity);$cases++;
// 14-16: append-only decision evidence.
$failClosed('decision sequence',$continueCase,"UPDATE {$p}canonical_continuation_decisions SET decision_sequence=7 WHERE id={$decisionId}","UPDATE {$p}canonical_continuation_decisions SET decision_sequence=1 WHERE id={$decisionId}",$integrity);$cases++;
$failClosed('decision value',$continueCase,"UPDATE {$p}canonical_continuation_decisions SET decision='free_form_state' WHERE id={$decisionId}","UPDATE {$p}canonical_continuation_decisions SET decision='continue_with_teacher' WHERE id={$decisionId}",$integrity);$cases++;
$originalDigest=(string)$wpdb->get_var($wpdb->prepare("SELECT evidence_reference_digest FROM {$p}canonical_continuation_decisions WHERE id=%d",$decisionId));
$failClosed('decision evidence digest',$continueCase,"UPDATE {$p}canonical_continuation_decisions SET evidence_reference_digest='short' WHERE id={$decisionId}","UPDATE {$p}canonical_continuation_decisions SET evidence_reference_digest='{$originalDigest}' WHERE id={$decisionId}",$integrity);$cases++;
$failClosed('decision authority basis',$continueCase,"UPDATE {$p}canonical_continuation_decisions SET actor_basis='caller_asserted' WHERE id={$decisionId}","UPDATE {$p}canonical_continuation_decisions SET actor_basis='adult_principal' WHERE id={$decisionId}",$integrity);$cases++;
// 17-19: administrator intervention linkage.
$failClosed('intervention reason',$stopCase,"UPDATE {$p}canonical_continuation_interventions SET reason_code='free_form_reason' WHERE id={$interventionId}","UPDATE {$p}canonical_continuation_interventions SET reason_code='student_requested_contact' WHERE id={$interventionId}",$integrity);$cases++;
$failClosed('intervention decision linkage',$stopCase,"UPDATE {$p}canonical_continuation_interventions SET decision_id=999999 WHERE id={$interventionId}","UPDATE {$p}canonical_continuation_interventions SET decision_id=(SELECT id FROM (SELECT id FROM {$p}canonical_continuation_decisions WHERE continuation_case_id=".(int)$stopCase->id." ORDER BY decision_sequence LIMIT 1) t) WHERE id={$interventionId}",$integrity);$cases++;
$failClosed('cross-continuation intervention',$stopCase,"UPDATE {$p}canonical_continuation_interventions SET continuation_case_id=".(int)$continueCase->id." WHERE id={$interventionId}","UPDATE {$p}canonical_continuation_interventions SET continuation_case_id=".(int)$stopCase->id." WHERE id={$interventionId}",array('canonical_continuation_integrity_conflict'));$cases++;
// 21: corrupted Teacher-capacity source must fail closed rather than silently releasing capacity.
$failClosed('capacity source teacher',$continueCase,"UPDATE {$p}canonical_continuation_reservations SET teacher_id=teacher_id+100000 WHERE id={$reservationId}","UPDATE {$p}canonical_continuation_reservations SET teacher_id=teacher_id-100000 WHERE id={$reservationId}",$integrity);$cases++;
// 22-27: the explicit first-regular-slot authority must be intact and exactly bound (Q-1).
$slotId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_continuation_slot_authorities WHERE intro_lesson_id=%d",(int)$continueFixture['lesson_id']));
dzn_qc_assert($slotId>0,'Phase Q slot authority fixture missing');
$failClosed('slot authority student',$continueCase,"UPDATE {$p}canonical_continuation_slot_authorities SET student_id={$otherStudent} WHERE id={$slotId}","UPDATE {$p}canonical_continuation_slot_authorities SET student_id=".(int)$continueCase->student_id." WHERE id={$slotId}",$integrity);$cases++;
$failClosed('slot authority teacher',$continueCase,"UPDATE {$p}canonical_continuation_slot_authorities SET teacher_id={$otherTeacher} WHERE id={$slotId}","UPDATE {$p}canonical_continuation_slot_authorities SET teacher_id=".(int)$continueCase->teacher_id." WHERE id={$slotId}",$integrity);$cases++;
$failClosed('slot authority interval',$continueCase,"UPDATE {$p}canonical_continuation_slot_authorities SET starts_at_utc=DATE_ADD(starts_at_utc, INTERVAL 3600 SECOND) WHERE id={$slotId}","UPDATE {$p}canonical_continuation_slot_authorities SET starts_at_utc=DATE_SUB(starts_at_utc, INTERVAL 3600 SECOND) WHERE id={$slotId}",$integrity);$cases++;
$failClosed('slot authority wall clock',$continueCase,"UPDATE {$p}canonical_continuation_slot_authorities SET local_wall_time=ADDTIME(local_wall_time,'01:00:00') WHERE id={$slotId}","UPDATE {$p}canonical_continuation_slot_authorities SET local_wall_time=SUBTIME(local_wall_time,'01:00:00') WHERE id={$slotId}",$integrity);$cases++;
$failClosed('slot authority basis',$continueCase,"UPDATE {$p}canonical_continuation_slot_authorities SET authority_basis='caller_asserted' WHERE id={$slotId}","UPDATE {$p}canonical_continuation_slot_authorities SET authority_basis='administrator_attestation' WHERE id={$slotId}",$integrity);$cases++;
$failClosed('slot authority rule version',$continueCase,"UPDATE {$p}canonical_continuation_slot_authorities SET rule_version='canonical_continuation_v0' WHERE id={$slotId}","UPDATE {$p}canonical_continuation_slot_authorities SET rule_version='canonical_continuation_v1' WHERE id={$slotId}",$integrity);$cases++;
$originalSlotDigest=(string)$wpdb->get_var($wpdb->prepare("SELECT evidence_reference_digest FROM {$p}canonical_continuation_slot_authorities WHERE id=%d",$slotId));
$failClosed('slot authority provenance digest',$continueCase,"UPDATE {$p}canonical_continuation_slot_authorities SET evidence_reference_digest='short' WHERE id={$slotId}","UPDATE {$p}canonical_continuation_slot_authorities SET evidence_reference_digest='{$originalSlotDigest}' WHERE id={$slotId}",$integrity);$cases++;
$failClosed('reservation slot binding',$continueCase,"UPDATE {$p}canonical_continuation_reservations SET slot_authority_id=999999 WHERE id={$reservationId}","UPDATE {$p}canonical_continuation_reservations SET slot_authority_id={$slotId} WHERE id={$reservationId}",$integrity);$cases++;
// 28-29: arrangement lineage (Q-3) — arbitrary historical lineage is never authoritative.
$arrangementId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_continuation_cases WHERE id=%d",(int)$continueCase->id));
$existingArrangement=(int)($continueCase->accepted_service_arrangement_id??0);
if($existingArrangement>0){
    $failClosed('accepted arrangement lineage',$continueCase,"UPDATE {$p}accepted_service_arrangements SET student_id={$otherStudent} WHERE id={$existingArrangement}","UPDATE {$p}accepted_service_arrangements SET student_id=".(int)$continueCase->student_id." WHERE id={$existingArrangement}",$integrity);$cases++;
    $failClosed('accepted arrangement provenance',$continueCase,"UPDATE {$p}accepted_service_arrangements SET arrangement_fingerprint='short' WHERE id={$existingArrangement}","UPDATE {$p}accepted_service_arrangements SET arrangement_fingerprint=REPEAT('a',64) WHERE id={$existingArrangement}",$integrity);$cases++;
}

wp_set_current_user($previous);
echo "corruption_cases=".$cases."\ncontinuation_authority_fail_closed=pass\nrepair_recovery=pass\nPhase 2A.2-Q corruption runtime passed\n";
