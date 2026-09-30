<?php
/** Disposable Phase-P corruption regressions: corrupted intake authority must fail closed. */
if(getenv('DZN_PHASE_2A2P_RUNTIME_TEST')!=='corruption'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-P corruption runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalAttendanceIdentityService,CanonicalAttendanceIntakeService,CanonicalAttendanceReadService,CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService,TeacherService,TeachingEligibilityService};

global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_pc_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_pc_key(string $label):string{return 'dzn-2a2pc-'.$label.'-'.wp_generate_uuid4();}
function dzn_pc_evidence(string $reference):array{return array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));}
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_pc_assert(is_array($fixture)&&count($fixture['sources']??array())>=1,'Phase-J production fixture required');
$lessons=new CanonicalLessonAuthorityService();$enrolments=new CanonicalEnrolmentLifecycleService();$terms=new CanonicalTermAuthorityService();
$assignments=new TeacherAssignmentService();$schedules=new CanonicalLessonScheduleService();$availability=new TeacherAvailabilityService();$accepting=new TeacherAcceptingStateService();
$intake=new CanonicalAttendanceIntakeService();$read=new CanonicalAttendanceReadService();$identity=new CanonicalAttendanceIdentityService();
$allocate=static function() use($wpdb,$p):int{
    $id=(int)$wpdb->get_var("SELECT e.id FROM {$p}enrolments e WHERE e.lifecycle_state='authorised' AND NOT EXISTS(SELECT 1 FROM {$p}terms t WHERE t.enrolment_id=e.id AND t.record_model='canonical_enrolment_term_v1') ORDER BY e.id LIMIT 1");
    if($id<1)throw new RuntimeException('no_available_source');
    return $id;
};
$chain=function() use($allocate,$enrolments,$terms,$assignments,$availability,$accepting,$wpdb,$p):array{
    $id=$allocate();
    $enrolments->activate($id,'authorised',dzn_pc_evidence('activate'),dzn_pc_key('activate'));
    $term=$terms->create($id,null,null,dzn_pc_evidence('term'),dzn_pc_key('term'));
    $terms->activate((int)$term['term_id'],'authorised',dzn_pc_evidence('term-active'),dzn_pc_key('term-active'));
    $assignment=$assignments->assignInitial($id,dzn_pc_key('assignment'));
    $course=(int)$wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}enrolments WHERE id=%d",$id));
    $teacher=(int)(new TeacherService())->create(array('display_name'=>'Synthetic PC Teacher','email'=>'pc-'.wp_generate_uuid4().'@phase-2a2p.invalid'));
    $accepting->set(array('teacher_id'=>$teacher,'state'=>'accepting','reason_code'=>'synthetic_provision'));
    $availability->setProfile(array('teacher_id'=>$teacher,'timezone'=>'UTC','status'=>'active','reason_code'=>'synthetic_provision'));
    for($weekday=1;$weekday<=7;$weekday++)$availability->setRecurringRule(array('teacher_id'=>$teacher,'timezone'=>'UTC','weekday'=>$weekday,'local_start_time'=>'00:00:00','local_end_time'=>'23:59:59','state'=>'requestable','status'=>'active','reason_code'=>'synthetic_provision'));
    (new TeachingEligibilityService())->setEligibility(array('teacher_id'=>$teacher,'course_id'=>$course,'status'=>'active','reason_code'=>'synthetic_provision'));
    $moved=$assignments->replace($id,$teacher,array('expected_assignment_id'=>(int)$assignment['assignment_id'],'route'=>'staff_attestation','evidence_channel'=>'phone','evidence_reference'=>'isolated-'.$teacher,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_pc_key('isolate'));
    return array('enrolment_id'=>$id,'term_id'=>(int)$term['term_id'],'assignment_id'=>(int)$moved['assignment_id']);
};
$occurrence=function(array $chain,string $label,int $duration) use($lessons,$schedules,$wpdb,$p):array{
    $lessonId=(int)$lessons->createStandard((int)$chain['term_id'],(int)$chain['assignment_id'],dzn_pc_evidence($label),dzn_pc_key($label))['lesson_id'];
    $wall=gmdate('Y-m-d H:i:s',strtotime('+8 seconds'));
    $scheduled=$schedules->schedule($lessonId,(int)$chain['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11),'duration_minutes'=>$duration,'reason_code'=>'synthetic_schedule')+dzn_pc_evidence('s-'.$label),dzn_pc_key('s-'.$label));
    $version=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d",(int)$scheduled['schedule_version_id']));
    $schedules->release($lessonId,array('expected_schedule_version_id'=>(int)$version->id,'reason_code'=>'synthetic_release')+dzn_pc_evidence('r-'.$label),dzn_pc_key('r-'.$label));
    $row=$wpdb->get_row($wpdb->prepare("SELECT student_id,teacher_id FROM {$p}lessons WHERE id=%d",$lessonId));
    return array('lesson_id'=>$lessonId,'version_id'=>(int)$version->id,'start'=>(string)$version->starts_at_utc,'end'=>(string)$version->ends_at_utc,'student_id'=>(int)$row->student_id,'teacher_id'=>(int)$row->teacher_id);
};
$intake->recordCutoverPolicy(gmdate('Y-m-d H:i:s',strtotime('+2 seconds')),dzn_pc_key('cutover'));
$occurrences=array();
foreach(array('case','evidence','decision','link')as$label)$occurrences[$label]=$occurrence($chain(),'corrupt-'.$label,1);
$latest=0;foreach($occurrences as$o)$latest=max($latest,strtotime($o['end'].' UTC'));
while(time()<$latest+3)sleep(1);
$runSuffix=substr(str_replace('-','',wp_generate_uuid4()),0,10);
foreach($occurrences as$label=>$o){
    $accountKey='acct-corrupt-'.$label;
    $identity->record(array('provider_code'=>'google_meet','provider_account_key'=>$accountKey,'participant_role'=>'teacher','participant_id'=>(int)$o['teacher_id'],'state'=>'verified','provenance_reference'=>'prov-'.$label,'evidence_reference'=>'ref-'.$label),dzn_pc_key('map-'.$label));
    $intake->ingestProviderEvidence((int)$o['lesson_id'],(int)$o['version_id'],array('provider_code'=>'google_meet','provider_account_key'=>$accountKey,'provider_event_key'=>'evt-'.$label.'-'.$runSuffix,'provider_payload_key'=>'pay-'.$label.'-'.$runSuffix,'participant_role'=>'teacher','join_at_utc'=>$o['start'],'leave_at_utc'=>$o['end'],'observed_at'=>$o['end'],'provenance_reference'=>'prov-'.$label.'-'.$runSuffix,'evidence_reference'=>'ref-'.$label.'-'.$runSuffix),dzn_pc_key('evt-'.$label));
}
$caseOf=static function(int $lessonId,int $versionId) use($wpdb,$p):object{$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_cases WHERE lesson_id=%d AND schedule_version_id=%d",$lessonId,$versionId));if(!$row)throw new RuntimeException('Phase P case fixture missing');return $row;};
$cases=array();foreach($occurrences as$label=>$o)$cases[$label]=$caseOf((int)$o['lesson_id'],(int)$o['version_id']);
$evidenceId=static function(int $caseId) use($wpdb,$p):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_attendance_evidence WHERE case_id=%d ORDER BY id LIMIT 1",$caseId));};
$decisionId=static function(int $caseId) use($wpdb,$p):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_attendance_decisions WHERE case_id=%d ORDER BY decision_sequence LIMIT 1",$caseId));};
$value=static function(string $sql) use($wpdb){return $wpdb->get_var($sql);};
$casesRun=0;
/** Damage one material fact, prove the protected read fails closed, then repair and prove recovery. */
$failClosed=function(string $label,object $case,string $damage,string $repair,array $expected) use($wpdb,$read):void{
    dzn_pc_assert($wpdb->query($damage)!==false,'Failed to damage Phase P authority: '.$label);
    $observed=null;
    try{$read->forOccurrence((int)$case->lesson_id,(int)$case->schedule_version_id);}catch(Throwable$e){$observed=$e->getMessage();}
    dzn_pc_assert(in_array($observed,$expected,true),'Corrupted Phase P authority ('.$label.') was not rejected (observed: '.var_export($observed,true).')');
    dzn_pc_assert($wpdb->query($repair)!==false,'Failed to repair Phase P authority: '.$label);
    $restored=$read->forOccurrence((int)$case->lesson_id,(int)$case->schedule_version_id);
    dzn_pc_assert((string)$restored['case']['case_id']===(string)$case->id,'Repaired Phase P authority is still unreadable: '.$label);
};
/** A corrupted selector makes the occurrence undiscoverable; both outcomes fail closed. */
$selectorFail=array('canonical_attendance_integrity_conflict','canonical_attendance_case_required');
$integrityFail=array('canonical_attendance_integrity_conflict');

// 1. Lesson binding.
$failClosed('Lesson binding',$cases['case'],"UPDATE {$p}canonical_attendance_cases SET lesson_id=lesson_id+100000 WHERE id=".(int)$cases['case']->id,"UPDATE {$p}canonical_attendance_cases SET lesson_id=lesson_id-100000 WHERE id=".(int)$cases['case']->id,$selectorFail);$casesRun++;
// 2. Schedule-version binding.
$failClosed('schedule-version binding',$cases['case'],"UPDATE {$p}canonical_attendance_cases SET schedule_version_id=schedule_version_id+100000 WHERE id=".(int)$cases['case']->id,"UPDATE {$p}canonical_attendance_cases SET schedule_version_id=schedule_version_id-100000 WHERE id=".(int)$cases['case']->id,$selectorFail);$casesRun++;
// 3. Occurrence anchors (window must keep the locked 15-minute post-class grace).
$failClosed('occurrence window',$cases['case'],"UPDATE {$p}canonical_attendance_cases SET window_end_utc=DATE_ADD(window_end_utc, INTERVAL 60 SECOND) WHERE id=".(int)$cases['case']->id,"UPDATE {$p}canonical_attendance_cases SET window_end_utc=DATE_SUB(window_end_utc, INTERVAL 60 SECOND) WHERE id=".(int)$cases['case']->id,array('canonical_attendance_integrity_conflict'));$casesRun++;
// 4. Selectors: Term and Enrolment.
$failClosed('Term selector',$cases['case'],"UPDATE {$p}canonical_attendance_cases SET term_id=term_id+100000 WHERE id=".(int)$cases['case']->id,"UPDATE {$p}canonical_attendance_cases SET term_id=term_id-100000 WHERE id=".(int)$cases['case']->id,array('canonical_attendance_integrity_conflict'));$casesRun++;
$failClosed('Enrolment selector',$cases['case'],"UPDATE {$p}canonical_attendance_cases SET enrolment_id=enrolment_id+100000 WHERE id=".(int)$cases['case']->id,"UPDATE {$p}canonical_attendance_cases SET enrolment_id=enrolment_id-100000 WHERE id=".(int)$cases['case']->id,array('canonical_attendance_integrity_conflict'));$casesRun++;
$failClosed('Student selector',$cases['case'],"UPDATE {$p}canonical_attendance_cases SET student_id=student_id+100000 WHERE id=".(int)$cases['case']->id,"UPDATE {$p}canonical_attendance_cases SET student_id=student_id-100000 WHERE id=".(int)$cases['case']->id,array('canonical_attendance_integrity_conflict'));$casesRun++;
$failClosed('Teacher selector',$cases['case'],"UPDATE {$p}canonical_attendance_cases SET teacher_id=teacher_id+100000 WHERE id=".(int)$cases['case']->id,"UPDATE {$p}canonical_attendance_cases SET teacher_id=teacher_id-100000 WHERE id=".(int)$cases['case']->id,array('canonical_attendance_integrity_conflict'));$casesRun++;
// 5. Case state, version and locked rule identity (P-10).
$failClosed('review state',$cases['case'],"UPDATE {$p}canonical_attendance_cases SET state='unknown_state' WHERE id=".(int)$cases['case']->id,"UPDATE {$p}canonical_attendance_cases SET state='ready_for_review' WHERE id=".(int)$cases['case']->id,array('canonical_attendance_integrity_conflict'));$casesRun++;
$failClosed('case rule version',$cases['case'],"UPDATE {$p}canonical_attendance_cases SET rule_version='canonical_attendance_overlap_v0' WHERE id=".(int)$cases['case']->id,"UPDATE {$p}canonical_attendance_cases SET rule_version='canonical_attendance_overlap_v1' WHERE id=".(int)$cases['case']->id,array('canonical_attendance_integrity_conflict'));$casesRun++;
// 6. Cutover policy binding and frozen policy constants (P-7/P-10).
$failClosed('missing cutover policy',$cases['case'],"UPDATE {$p}canonical_attendance_cases SET cutover_policy_id=999999 WHERE id=".(int)$cases['case']->id,"UPDATE {$p}canonical_attendance_cases SET cutover_policy_id=".(int)$cases['case']->cutover_policy_id." WHERE id=".(int)$cases['case']->id,array('canonical_attendance_integrity_conflict'));$casesRun++;
$policyId=(int)$cases['case']->cutover_policy_id;
$originalThreshold=(int)$value("SELECT threshold_seconds FROM {$p}canonical_attendance_cutover_policies WHERE id={$policyId}");
$failClosed('policy threshold',$cases['case'],"UPDATE {$p}canonical_attendance_cutover_policies SET threshold_seconds=600 WHERE id={$policyId}","UPDATE {$p}canonical_attendance_cutover_policies SET threshold_seconds={$originalThreshold} WHERE id={$policyId}",array('canonical_attendance_integrity_conflict'));$casesRun++;
$originalPost=(int)$value("SELECT post_grace_seconds FROM {$p}canonical_attendance_cutover_policies WHERE id={$policyId}");
$failClosed('policy post grace',$cases['case'],"UPDATE {$p}canonical_attendance_cutover_policies SET post_grace_seconds=0 WHERE id={$policyId}","UPDATE {$p}canonical_attendance_cutover_policies SET post_grace_seconds={$originalPost} WHERE id={$policyId}",array('canonical_attendance_integrity_conflict'));$casesRun++;
$originalRule=(string)$value("SELECT rule_version FROM {$p}canonical_attendance_cutover_policies WHERE id={$policyId}");
$failClosed('policy rule version',$cases['case'],"UPDATE {$p}canonical_attendance_cutover_policies SET rule_version='canonical_attendance_overlap_v0' WHERE id={$policyId}","UPDATE {$p}canonical_attendance_cutover_policies SET rule_version='{$originalRule}' WHERE id={$policyId}",array('canonical_attendance_integrity_conflict'));$casesRun++;
// A later policy must never silently reinterpret an already admitted case.
$altered=$cases['case']->occurrence_start_utc;
$failClosed('case/policy applicability',$cases['case'],"UPDATE {$p}canonical_attendance_cutover_policies SET cutover_utc=DATE_ADD(cutover_utc, INTERVAL 1 DAY) WHERE id={$policyId}","UPDATE {$p}canonical_attendance_cutover_policies SET cutover_utc=DATE_SUB(cutover_utc, INTERVAL 1 DAY) WHERE id={$policyId}",array('canonical_attendance_integrity_conflict'));$casesRun++;
// 7. Provider event key and payload digest.
$eventKeyCase=$cases['evidence'];$eventKeyEvidence=$evidenceId((int)$eventKeyCase->id);
dzn_pc_assert($eventKeyEvidence>0,'Phase P evidence fixture missing');
$originalEventKey=(string)$value("SELECT provider_event_key_digest FROM {$p}canonical_attendance_evidence WHERE id={$eventKeyEvidence}");
$failClosed('provider event key',$eventKeyCase,"UPDATE {$p}canonical_attendance_evidence SET provider_event_key_digest='short' WHERE id={$eventKeyEvidence}","UPDATE {$p}canonical_attendance_evidence SET provider_event_key_digest='{$originalEventKey}' WHERE id={$eventKeyEvidence}",array('canonical_attendance_integrity_conflict'));$casesRun++;
$originalPayload=(string)$value("SELECT provider_payload_digest FROM {$p}canonical_attendance_evidence WHERE id={$eventKeyEvidence}");
$failClosed('payload digest',$eventKeyCase,"UPDATE {$p}canonical_attendance_evidence SET provider_payload_digest=NULL WHERE id={$eventKeyEvidence}","UPDATE {$p}canonical_attendance_evidence SET provider_payload_digest='{$originalPayload}' WHERE id={$eventKeyEvidence}",array('canonical_attendance_integrity_conflict'));$casesRun++;
// 8. P-1 participant identity authority: durable registry binding, role and target.
$identityCase=$cases['evidence'];$identityEvidence=$evidenceId((int)$identityCase->id);
$originalAccount=(string)$value("SELECT provider_account_digest FROM {$p}canonical_attendance_evidence WHERE id={$identityEvidence}");
$failClosed('provider account digest',$identityCase,"UPDATE {$p}canonical_attendance_evidence SET provider_account_digest=NULL WHERE id={$identityEvidence}","UPDATE {$p}canonical_attendance_evidence SET provider_account_digest='{$originalAccount}' WHERE id={$identityEvidence}",array('canonical_attendance_integrity_conflict'));$casesRun++;
$failClosed('unmapped provider account',$identityCase,"UPDATE {$p}canonical_attendance_evidence SET provider_account_digest='".str_repeat('a',64)."' WHERE id={$identityEvidence}","UPDATE {$p}canonical_attendance_evidence SET provider_account_digest='{$originalAccount}' WHERE id={$identityEvidence}",array('canonical_attendance_integrity_conflict'));$casesRun++;
$originalResolved=(int)$value("SELECT resolved_teacher_id FROM {$p}canonical_attendance_evidence WHERE id={$identityEvidence}");
$failClosed('resolved participant',$identityCase,"UPDATE {$p}canonical_attendance_evidence SET resolved_teacher_id=resolved_teacher_id+100000 WHERE id={$identityEvidence}","UPDATE {$p}canonical_attendance_evidence SET resolved_teacher_id=resolved_teacher_id-100000 WHERE id={$identityEvidence}",array('canonical_attendance_integrity_conflict'));$casesRun++;
$originalRole=(string)$value("SELECT participant_role FROM {$p}canonical_attendance_evidence WHERE id={$identityEvidence}");
$failClosed('participant role',$identityCase,"UPDATE {$p}canonical_attendance_evidence SET participant_role='observer' WHERE id={$identityEvidence}","UPDATE {$p}canonical_attendance_evidence SET participant_role='{$originalRole}' WHERE id={$identityEvidence}",array('canonical_attendance_integrity_conflict'));$casesRun++;
// A registry row bound to another canonical participant must never justify stored resolution.
$mapId=(int)$value("SELECT id FROM {$p}canonical_attendance_participant_mappings WHERE provider_account_digest='{$originalAccount}' AND participant_role='{$originalRole}' LIMIT 1");
dzn_pc_assert($mapId>0,'Phase P participant mapping fixture missing');
$failClosed('mapping target',$identityCase,"UPDATE {$p}canonical_attendance_participant_mappings SET participant_id=participant_id+100000 WHERE id={$mapId}","UPDATE {$p}canonical_attendance_participant_mappings SET participant_id=participant_id-100000 WHERE id={$mapId}",array('canonical_attendance_integrity_conflict'));$casesRun++;
$failClosed('mapping state',$identityCase,"UPDATE {$p}canonical_attendance_participant_mappings SET state='trusted_guess' WHERE id={$mapId}","UPDATE {$p}canonical_attendance_participant_mappings SET state='verified' WHERE id={$mapId}",array('canonical_attendance_integrity_conflict'));$casesRun++;
$originalVerified=(string)$value("SELECT verified_at FROM {$p}canonical_attendance_participant_mappings WHERE id={$mapId}");
$failClosed('mapping verification instant',$identityCase,"UPDATE {$p}canonical_attendance_participant_mappings SET verified_at=NULL WHERE id={$mapId}","UPDATE {$p}canonical_attendance_participant_mappings SET verified_at='{$originalVerified}' WHERE id={$mapId}",array('canonical_attendance_integrity_conflict'));$casesRun++;
// 9. Interval endpoints.
$failClosed('interval endpoints',$identityCase,"UPDATE {$p}canonical_attendance_evidence SET join_at_utc='not-a-timestamp' WHERE id={$identityEvidence}","UPDATE {$p}canonical_attendance_evidence SET join_at_utc=(SELECT s FROM (SELECT starts_at_utc s FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=(SELECT lesson_id FROM {$p}canonical_attendance_evidence WHERE id={$identityEvidence})) t) WHERE id={$identityEvidence}",array('canonical_attendance_integrity_conflict'));$casesRun++;
// 10. Evidence reference digest and verification state.
$digestEvidence=$evidenceId((int)$cases['evidence']->id);
$originalReference=(string)$value("SELECT evidence_reference_digest FROM {$p}canonical_attendance_evidence WHERE id={$digestEvidence}");
$failClosed('evidence reference digest',$cases['evidence'],"UPDATE {$p}canonical_attendance_evidence SET evidence_reference_digest='short' WHERE id={$digestEvidence}","UPDATE {$p}canonical_attendance_evidence SET evidence_reference_digest='{$originalReference}' WHERE id={$digestEvidence}",array('canonical_attendance_integrity_conflict'));$casesRun++;
$originalVerification=(string)$value("SELECT verification_state FROM {$p}canonical_attendance_evidence WHERE id={$digestEvidence}");
$failClosed('verification state',$cases['evidence'],"UPDATE {$p}canonical_attendance_evidence SET verification_state='trusted_guess' WHERE id={$digestEvidence}","UPDATE {$p}canonical_attendance_evidence SET verification_state='{$originalVerification}' WHERE id={$digestEvidence}",array('canonical_attendance_integrity_conflict'));$casesRun++;
// 11. Decision rule/version and Phase-O result link.
$decisionCase=$cases['decision'];$firstDecision=$decisionId((int)$decisionCase->id);
$originalDecisionRule=(string)$value("SELECT rule_version FROM {$p}canonical_attendance_decisions WHERE id={$firstDecision}");
$failClosed('decision rule version',$decisionCase,"UPDATE {$p}canonical_attendance_decisions SET rule_version='attendance_v0' WHERE id={$firstDecision}","UPDATE {$p}canonical_attendance_decisions SET rule_version='{$originalDecisionRule}' WHERE id={$firstDecision}",array('canonical_attendance_integrity_conflict'));$casesRun++;
$failClosed('Phase-O result link',$decisionCase,"UPDATE {$p}canonical_attendance_decisions SET result_outcome_id=999999 WHERE id={$firstDecision}","UPDATE {$p}canonical_attendance_decisions SET result_outcome_id=NULL WHERE id={$firstDecision}",array('canonical_attendance_integrity_conflict'));$casesRun++;
// 12. Anomaly classification.
$anomalyCase=$cases['decision'];$anomalyId=(int)$value("SELECT id FROM {$p}canonical_attendance_case_anomalies WHERE case_id=".(int)$anomalyCase->id." LIMIT 1");
dzn_pc_assert($anomalyId>0,'Phase P anomaly fixture missing');
$failClosed('anomaly classification',$anomalyCase,"UPDATE {$p}canonical_attendance_case_anomalies SET code='free_form_reason' WHERE id={$anomalyId}","UPDATE {$p}canonical_attendance_case_anomalies SET code='provider_evidence_missing' WHERE id={$anomalyId}",array('canonical_attendance_integrity_conflict'));$casesRun++;
// 13. Decision-chain integrity: the stored assessment must agree with the stored evidence.
$chainCase=$cases['link'];
$latestAssessment=(int)$value("SELECT id FROM {$p}canonical_attendance_decisions WHERE case_id=".(int)$chainCase->id." AND decision_kind='assessment' ORDER BY decision_sequence DESC,id DESC LIMIT 1");
dzn_pc_assert($latestAssessment>0,'Phase P assessment decision fixture missing');
$originalOverlap=(int)$value("SELECT qualifying_overlap_seconds FROM {$p}canonical_attendance_decisions WHERE id={$latestAssessment}");
$failClosed('decision/evidence divergence',$chainCase,"UPDATE {$p}canonical_attendance_decisions SET qualifying_overlap_seconds=".($originalOverlap+1)." WHERE id={$latestAssessment}","UPDATE {$p}canonical_attendance_decisions SET qualifying_overlap_seconds={$originalOverlap} WHERE id={$latestAssessment}",array('canonical_attendance_integrity_conflict'));$casesRun++;

echo "corruption_cases=".$casesRun."\nintake_authority_fail_closed=pass\nidentity_registry_fail_closed=pass\ncutover_policy_fail_closed=pass\nrepair_recovery=pass\nPhase 2A.2-P corruption runtime passed\n";
