<?php
/** Disposable production-path Phase-P attendance intake/review proof; synthetic local data only. */
if(getenv('DZN_PHASE_2A2P_RUNTIME_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-P runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalAttendanceIdentityService,CanonicalAttendanceIntakeService,CanonicalAttendanceReadService,CanonicalAttendanceSettlementService,CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonDeliveryService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService,TeacherService,TeachingEligibilityService};

global $wpdb; $p=$wpdb->prefix.'dzn_';
function dzn_p_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_p_key(string $label):string{return 'dzn-2a2p-'.$label.'-'.wp_generate_uuid4();}
function dzn_p_evidence(string $reference):array{return array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));}
function dzn_p_rejected(callable $call,string $expected,string $message):void{$caught=null;try{$call();}catch(Throwable$e){$caught=$e;}dzn_p_assert($caught!==null,$message.' was accepted');dzn_p_assert($caught->getMessage()===$expected,$message.' rejected with an unexpected error: '.$caught->getMessage());}
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_p_assert(is_array($fixture)&&count($fixture['sources']??array())>=1,'Phase-J production fixture required');
// Disposable harness: reset only Phase-P intake storage so the provider identity registry, the
// prospective cutover policy and every occurrence aggregate are deterministic for this run.
foreach(array('canonical_attendance_conflicts','canonical_attendance_case_anomalies','canonical_attendance_decisions','canonical_attendance_commands','canonical_attendance_evidence','canonical_attendance_cases','canonical_attendance_participant_mappings','canonical_attendance_cutover_policies')as$intakeTable)dzn_p_assert($wpdb->query("DELETE FROM {$p}{$intakeTable}")!==false,'Failed to reset disposable Phase P storage: '.$intakeTable);
$lessons=new CanonicalLessonAuthorityService();$enrolments=new CanonicalEnrolmentLifecycleService();
$terms=new CanonicalTermAuthorityService();$assignments=new TeacherAssignmentService();$schedules=new CanonicalLessonScheduleService();
$availability=new TeacherAvailabilityService();$accepting=new TeacherAcceptingStateService();
$intake=new CanonicalAttendanceIntakeService();$settlement=new CanonicalAttendanceSettlementService();$read=new CanonicalAttendanceReadService();
$identity=new CanonicalAttendanceIdentityService();$delivery=new CanonicalLessonDeliveryService();
/** Allocate any still-unused canonical Enrolment so the suite never depends on the option snapshot. */
$allocate=static function() use($wpdb,$p):int{
    $id=(int)$wpdb->get_var("SELECT e.id FROM {$p}enrolments e WHERE e.lifecycle_state='authorised' AND NOT EXISTS(SELECT 1 FROM {$p}terms t WHERE t.enrolment_id=e.id AND t.record_model='canonical_enrolment_term_v1') ORDER BY e.id LIMIT 1");
    if($id<1)throw new RuntimeException('no_available_source');
    return $id;
};
$chain=function() use($allocate,$enrolments,$terms,$assignments,$availability,$accepting,$wpdb,$p):array{
    $id=$allocate();
    $enrolments->activate($id,'authorised',dzn_p_evidence('activate'),dzn_p_key('activate'));
    $term=$terms->create($id,null,null,dzn_p_evidence('term'),dzn_p_key('term'));
    $terms->activate((int)$term['term_id'],'authorised',dzn_p_evidence('term-active'),dzn_p_key('term-active'));
    $assignment=$assignments->assignInitial($id,dzn_p_key('assignment'));
    $course=(int)$wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}enrolments WHERE id=%d",$id));
    $teacher=(int)(new TeacherService())->create(array('display_name'=>'Synthetic P Teacher','email'=>'p-'.wp_generate_uuid4().'@phase-2a2p.invalid'));
    $accepting->set(array('teacher_id'=>$teacher,'state'=>'accepting','reason_code'=>'synthetic_provision'));
    $availability->setProfile(array('teacher_id'=>$teacher,'timezone'=>'UTC','status'=>'active','reason_code'=>'synthetic_provision'));
    for($weekday=1;$weekday<=7;$weekday++)$availability->setRecurringRule(array('teacher_id'=>$teacher,'timezone'=>'UTC','weekday'=>$weekday,'local_start_time'=>'00:00:00','local_end_time'=>'23:59:59','state'=>'requestable','status'=>'active','reason_code'=>'synthetic_provision'));
    (new TeachingEligibilityService())->setEligibility(array('teacher_id'=>$teacher,'course_id'=>$course,'status'=>'active','reason_code'=>'synthetic_provision'));
    $moved=$assignments->replace($id,$teacher,array('expected_assignment_id'=>(int)$assignment['assignment_id'],'route'=>'staff_attestation','evidence_channel'=>'phone','evidence_reference'=>'isolated-'.$teacher,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_p_key('isolate'));
    return array('enrolment_id'=>$id,'term_id'=>(int)$term['term_id'],'assignment_id'=>(int)$moved['assignment_id']);
};
$occurrence=function(array $chain,string $label,int $durationMinutes,?string $wall=null) use($lessons,$schedules,$wpdb,$p):array{
    $lessonId=(int)$lessons->createStandard((int)$chain['term_id'],(int)$chain['assignment_id'],dzn_p_evidence($label),dzn_p_key($label))['lesson_id'];
    $wall??=gmdate('Y-m-d H:i:s',strtotime('+3 seconds'));
    $scheduled=$schedules->schedule($lessonId,(int)$chain['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11),'duration_minutes'=>$durationMinutes,'reason_code'=>'synthetic_schedule')+dzn_p_evidence('schedule-'.$label),dzn_p_key('schedule-'.$label));
    $version=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d",(int)$scheduled['schedule_version_id']));
    $schedules->release($lessonId,array('expected_schedule_version_id'=>(int)$version->id,'reason_code'=>'synthetic_release')+dzn_p_evidence('release-'.$label),dzn_p_key('release-'.$label));
    $row=$wpdb->get_row($wpdb->prepare("SELECT student_id,teacher_id FROM {$p}lessons WHERE id=%d",$lessonId));
    return array('lesson_id'=>$lessonId,'version_id'=>(int)$version->id,'start'=>(string)$version->starts_at_utc,'end'=>(string)$version->ends_at_utc,'student_id'=>(int)$row->student_id,'teacher_id'=>(int)$row->teacher_id,'assignment_id'=>(int)$chain['assignment_id']);
};
/** Provider event keys are durable global receipts: every event needs a per-run unique key. */
$runSuffix=substr(str_replace('-','',wp_generate_uuid4()),0,10);
$plus=static fn(string $utc,int $seconds):string=>gmdate('Y-m-d H:i:s',strtotime($utc.' UTC')+$seconds);
$account=static fn(string $label):string=>'acct-'.$label.'-'.$runSuffix;
$map=function(string $role,int $participantId,string $accountKey,string $state='verified') use($identity):array{
    return $identity->record(array('provider_code'=>'google_meet','provider_account_key'=>$accountKey,'participant_role'=>$role,'participant_id'=>$participantId,'state'=>$state,'provenance_reference'=>'prov-'.$accountKey,'evidence_reference'=>'ref-'.$accountKey),dzn_p_key('map-'.$accountKey.'-'.$state));
};
$ingest=function(array $o,string $role,int $joinOffset,int $leaveOffset,string $label,?string $accountKey=null,?string $eventKey=null,?string $payloadKey=null,?string $commandKey=null,?string $observedAt=null) use($intake,$plus,$runSuffix):array{
    $accountKey??='acct-'.$label;
    return $intake->ingestProviderEvidence((int)$o['lesson_id'],(int)$o['version_id'],array(
        'provider_code'=>'google_meet','provider_account_key'=>$accountKey,
        'provider_event_key'=>$eventKey??('event-'.$label.'-'.$runSuffix),'provider_payload_key'=>$payloadKey??('payload-'.$label.'-'.$runSuffix),
        'participant_role'=>$role,
        'join_at_utc'=>$plus($o['start'],$joinOffset),'leave_at_utc'=>$plus($o['start'],$leaveOffset),'observed_at'=>$observedAt??gmdate('Y-m-d H:i:s'),
        'provenance_reference'=>'prov-'.$label,'evidence_reference'=>'ref-'.$label,
    ),$commandKey??dzn_p_key('provider-'.$label));
};
/** Map a provider account to the exact canonical participant, then ingest through the public seam. */
$provider=function(array $o,string $role,int $joinOffset,int $leaveOffset,string $label,?string $observedAt=null) use($map,$ingest):array{
    $accountKey='acct-'.$label;
    $map($role,$role==='teacher'?(int)$o['teacher_id']:(int)$o['student_id'],$accountKey);
    return $ingest($o,$role,$joinOffset,$leaveOffset,$label,$accountKey,null,null,null,$observedAt);
};
$caseOf=static function(int $lessonId,int $versionId) use($wpdb,$p):?object{return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_cases WHERE lesson_id=%d AND schedule_version_id=%d",$lessonId,$versionId));};
$requireCase=static function(int $lessonId,int $versionId) use($caseOf):object{$case=$caseOf($lessonId,$versionId);if(!$case)throw new RuntimeException('Phase P case fixture missing');return $case;};
$lessonState=static function(int $lessonId) use($wpdb,$p):string{return(string)$wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}lessons WHERE id=%d",$lessonId));};
$outcome=static function(int $lessonId) use($wpdb,$p){return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d AND applicable_slot=1",$lessonId));};
$obligationCount=static function(int $lessonId) use($wpdb,$p):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_academy_obligations WHERE source_lesson_id=%d",$lessonId));};
$decisionCount=static function(int $caseId) use($wpdb,$p):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_decisions WHERE case_id=%d",$caseId));};
$conflicts=static function(int $caseId) use($wpdb,$p):array{return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_conflicts WHERE case_id=%d ORDER BY id",$caseId))?:array();};
$inject=static function(string $hook):void{add_action($hook,static function() use($hook):void{throw new RuntimeException('injected:'.$hook);});};
$clear=static function(string $hook):void{remove_all_actions($hook);};
$openTransactions=static function() use($wpdb):int{$value=$wpdb->get_var('SELECT @@in_transaction');return $value===null?-1:(int)$value;};

// ---------------------------------------------------------------------------
// Fixture: prospective cutover policy, chains, one shared wait for the longest occurrence.
// ---------------------------------------------------------------------------
$policy=$intake->recordCutoverPolicy(gmdate('Y-m-d H:i:s',strtotime('+2 seconds')),dzn_p_key('cutover'));
dzn_p_assert((int)$policy['policy_id']>0,'prospective cutover policy was not recorded');
$chains=array();$occurrences=array();
$settleWall=gmdate('Y-m-d H:i:s',strtotime('+3 seconds'));
foreach(array('settle','below','review','teacher_nd','forced','no_change','late')as$label)$chains[$label]=$chain();
$occurrences['settle']=$occurrence($chains['settle'],'settle',5,$settleWall);
$occurrences['below']=$occurrence($chains['below'],'below',5,$settleWall);
foreach(array('review','teacher_nd','forced','no_change','late')as$label)$occurrences[$label]=$occurrence($chains[$label],$label,1,$settleWall);
// Canonical delivered truth may only be recorded once the occurrence has actually ended: every
// occurrence that must converge through canonical authority is therefore created up front so one
// shared wait covers all of them.
$settlingExtras=array();
foreach(array('bound-a'=>5,'bound-b'=>5,'bound-c'=>5,'bound-e'=>5,'id-multi'=>5,'id-cross-a'=>5,'id-cross-b'=>5,'id-stale'=>5)as$label=>$minutes)$settlingExtras[$label]=$occurrence($chain(),$label,$minutes,$settleWall);
$latestEnd=0;foreach($occurrences as$o)$latestEnd=max($latestEnd,strtotime($o['end'].' UTC'));
foreach($settlingExtras as$o)$latestEnd=max($latestEnd,strtotime($o['end'].' UTC'));
while(time()<$latestEnd+3)sleep(1);

// ---------------------------------------------------------------------------
// 1. Automatic settlement: exactly 1200 seconds of trusted, identity-resolved overlap.
// ---------------------------------------------------------------------------
$settle=$occurrences['settle'];
$settleObserved=gmdate('Y-m-d H:i:s');
$provider($settle,'teacher',0,1200,'settle-teacher',$settleObserved);
$provider($settle,'student',0,1200,'settle-student',$settleObserved);
$case=$requireCase((int)$settle['lesson_id'],(int)$settle['version_id']);
dzn_p_assert((string)$case->state==='settled','automatic settlement did not settle the case');
$settledOutcome=$outcome((int)$settle['lesson_id']);
dzn_p_assert($settledOutcome&&(string)$settledOutcome->outcome_code==='delivered','automatic settlement must create canonical delivered truth');
dzn_p_assert($lessonState((int)$settle['lesson_id'])==='completed','automatic settlement must converge Lesson completion');
dzn_p_assert($obligationCount((int)$settle['lesson_id'])===0,'ordinary success must not create academy debt');
dzn_p_assert((string)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_lessons WHERE canonical_replacement_origin_lesson_id=%d",(int)$settle['lesson_id']))==='0','ordinary success must not create a replacement Lesson');
$readModel=$read->forOccurrence((int)$settle['lesson_id'],(int)$settle['version_id']);
dzn_p_assert($readModel['assessment']['qualifying_overlap_seconds']===1200&&$readModel['assessment']['threshold_seconds']===1200,'read model must expose the locked threshold and measured overlap');
dzn_p_assert($readModel['canonical_truth']['effective_outcome']==='delivered'&&$readModel['case']['state']==='settled','read model must compose canonical truth and case state');
dzn_p_assert($readModel['assessment']['pre_grace_seconds']===0&&$readModel['assessment']['post_grace_seconds']===900,'read model must expose the locked temporal policy');
dzn_p_assert($readModel['cutover']['threshold_seconds']===1200&&$readModel['cutover']['post_grace_seconds']===900,'read model must expose the frozen cutover policy constants');
dzn_p_assert($readModel['evidence']['counts']['verified']===2&&$readModel['evidence']['counts']['mismatch']===0,'read model must expose identity-resolved evidence counts');

// ---------------------------------------------------------------------------
// 2. 1199 seconds is not success: anomaly only, no canonical side effect.
// ---------------------------------------------------------------------------
$below=$occurrences['below'];
$provider($below,'teacher',0,1200,'below-teacher');
$provider($below,'student',0,1199,'below-student');
$belowCase=$requireCase((int)$below['lesson_id'],(int)$below['version_id']);
dzn_p_assert((string)$belowCase->state==='ready_for_review','below-threshold evidence must open review');
dzn_p_assert($outcome((int)$below['lesson_id'])===null,'below-threshold evidence must not create canonical truth');
dzn_p_assert($lessonState((int)$below['lesson_id'])==='authorised','below-threshold evidence must not complete the Lesson');
dzn_p_assert($obligationCount((int)$below['lesson_id'])===0,'insufficient evidence must never identify responsibility');
$belowRead=$read->forOccurrence((int)$below['lesson_id'],(int)$below['version_id']);
dzn_p_assert($belowRead['assessment']['qualifying_overlap_seconds']===1199,'read model must report the measured 1199 seconds');
dzn_p_assert(in_array('overlap_below_threshold',$belowRead['assessment']['anomaly_codes'],true),'overlap_below_threshold anomaly must be recorded');
dzn_p_assert(in_array('teacher_participation_unproven',$belowRead['assessment']['anomaly_codes'],true)===false,'proven teacher participation must not be reported unproven');

// ---------------------------------------------------------------------------
// 3. Human claims: evidence only, never settlement, and they coexist append-only.
// ---------------------------------------------------------------------------
$claim=$intake->submitClaim((int)$below['lesson_id'],(int)$below['version_id'],array('claim_kind'=>'advance_absence_claim','reason_code'=>'student_travel','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'absence-claim'),dzn_p_key('claim'));
dzn_p_assert((int)$claim['case_id']===(int)$belowCase->id,'claim must bind to the existing occurrence case');
dzn_p_assert($outcome((int)$below['lesson_id'])===null&&$lessonState((int)$below['lesson_id'])==='authorised','a Student absence claim must not change canonical truth');
dzn_p_assert($obligationCount((int)$below['lesson_id'])===0,'a Student absence claim must not create academy debt');
$intake->submitClaim((int)$below['lesson_id'],(int)$below['version_id'],array('claim_kind'=>'delivery_claim','reason_code'=>'teacher_statement','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'delivery-claim'),dzn_p_key('claim-delivery'));
$claimRead=$read->forOccurrence((int)$below['lesson_id'],(int)$below['version_id']);
dzn_p_assert(count($claimRead['claims'])===2,'conflicting human claims must coexist append-only');
dzn_p_assert($outcome((int)$below['lesson_id'])===null,'a Teacher claim alone must not settle delivery');

// ---------------------------------------------------------------------------
// 4. Administrative adjudication is the only Phase-P path that may publish truth.
// ---------------------------------------------------------------------------
$review=$occurrences['review'];
$intake->submitClaim((int)$review['lesson_id'],(int)$review['version_id'],array('claim_kind'=>'review_request','reason_code'=>'dispute','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'review-request'),dzn_p_key('review-request'));
$reviewCase=$requireCase((int)$review['lesson_id'],(int)$review['version_id']);
$adjudicated=$intake->adjudicate((int)$reviewCase->id,array('adjudication'=>'review_required','expected_case_version'=>(int)$reviewCase->case_version),dzn_p_key('adjudicate-review'));
dzn_p_assert((string)$adjudicated['state']==='adjudicated','administrative adjudication did not close the case');
$reviewOutcome=$outcome((int)$review['lesson_id']);
dzn_p_assert($reviewOutcome&&(string)$reviewOutcome->outcome_code==='review_required','explicit adjudication must delegate review_required to Phase O');
dzn_p_rejected(fn()=>$settlement->settleDelivered((int)$reviewCase->id,true),'attendance_settlement_not_pending','settlement over a case with no durable settlement intent');
$teacherNd=$occurrences['teacher_nd'];
$intake->submitClaim((int)$teacherNd['lesson_id'],(int)$teacherNd['version_id'],array('claim_kind'=>'review_request','reason_code'=>'attendance_dispute','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'teacher-nd-claim'),dzn_p_key('teacher-nd-claim'));
$teacherNdCase=$requireCase((int)$teacherNd['lesson_id'],(int)$teacherNd['version_id']);
$intake->adjudicate((int)$teacherNdCase->id,array('adjudication'=>'teacher_non_delivery','expected_case_version'=>(int)$teacherNdCase->case_version),dzn_p_key('adjudicate-nd'));
$ndOutcome=$outcome((int)$teacherNd['lesson_id']);
dzn_p_assert($ndOutcome&&(string)$ndOutcome->outcome_code==='teacher_non_delivery','adjudication must delegate non-delivery truth to Phase O');
dzn_p_assert($obligationCount((int)$teacherNd['lesson_id'])===1,'Phase O must own the academy obligation created by non-delivery');
dzn_p_assert((string)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_lessons WHERE canonical_replacement_origin_lesson_id=%d",(int)$teacherNd['lesson_id']))==='0','Phase P must never create a Phase-M replacement');
$forced=$occurrences['forced'];
$intake->submitClaim((int)$forced['lesson_id'],(int)$forced['version_id'],array('claim_kind'=>'review_request','reason_code'=>'forced_settlement','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'forced'),dzn_p_key('forced-claim'));
$forcedCase=$requireCase((int)$forced['lesson_id'],(int)$forced['version_id']);
$forcedResult=$intake->adjudicate((int)$forcedCase->id,array('adjudication'=>'settle_delivered','expected_case_version'=>(int)$forcedCase->case_version),dzn_p_key('adjudicate-forced'));
dzn_p_assert((string)$forcedResult['state']==='settled','forced administrative settlement did not settle');
dzn_p_assert($outcome((int)$forced['lesson_id'])&&$lessonState((int)$forced['lesson_id'])==='completed','forced settlement must reach delivered + completed');
$noChange=$occurrences['no_change'];
$intake->submitClaim((int)$noChange['lesson_id'],(int)$noChange['version_id'],array('claim_kind'=>'review_request','reason_code'=>'no_change','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'no-change'),dzn_p_key('no-change-claim'));
$noChangeCase=$requireCase((int)$noChange['lesson_id'],(int)$noChange['version_id']);
$intake->adjudicate((int)$noChangeCase->id,array('adjudication'=>'record_no_change','expected_case_version'=>(int)$noChangeCase->case_version),dzn_p_key('adjudicate-no-change'));
dzn_p_assert($outcome((int)$noChange['lesson_id'])===null,'record_no_change must not publish canonical truth');
dzn_p_assert((string)$requireCase((int)$noChange['lesson_id'],(int)$noChange['version_id'])->state==='closed_no_change','record_no_change must close the case without change');

// ---------------------------------------------------------------------------
// 5. Term closure and late evidence.
// ---------------------------------------------------------------------------
$late=$occurrences['late'];
$intake->submitClaim((int)$late['lesson_id'],(int)$late['version_id'],array('claim_kind'=>'advance_absence_claim','reason_code'=>'planned_absence','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'late-claim'),dzn_p_key('late-claim'));
$lessons->cancel((int)$late['lesson_id'],'authorised',dzn_p_evidence('cancel-late'),dzn_p_key('cancel-late'));
$terms->close((int)$chains['late']['term_id'],'current',dzn_p_evidence('term-close'),dzn_p_key('term-close'));
dzn_p_assert((string)$wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$wpdb->prefix}dzn_terms WHERE id=%d",(int)$chains['late']['term_id']))==='closed','Term must close with an unresolved attendance case');
$lateProvider=$provider($late,'teacher',0,60,'late-provider');
dzn_p_assert($lateProvider['settlement']===null,'late evidence after Term closure must not settle automatically');
$lateRead=$read->forOccurrence((int)$late['lesson_id'],(int)$late['version_id']);
dzn_p_assert($lateRead['review']['term_closed']===true&&$lateRead['review']['late_evidence']===true,'late evidence must be flagged for administrative review');
dzn_p_assert($lateRead['evidence']['counts']['provider_interval']>=1,'late provider receipt must be retained');
dzn_p_assert($outcome((int)$late['lesson_id'])===null,'late evidence must not mutate canonical truth automatically');

// ---------------------------------------------------------------------------
// 6. Idempotency and capability boundaries.
// ---------------------------------------------------------------------------
$replayAccount='acct-settle-teacher';
$replayEvent='event-settle-teacher-'.$runSuffix;$replayPayload='payload-settle-teacher-'.$runSuffix;
$settleCaseRow=$requireCase((int)$settle['lesson_id'],(int)$settle['version_id']);
$teacherEvidenceId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_attendance_evidence WHERE case_id=%d AND participant_role='teacher' ORDER BY id LIMIT 1",(int)$settleCaseRow->id));
$evidenceBefore=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_evidence WHERE case_id=%d",(int)$settleCaseRow->id));
$replayArgs=array($settle,'teacher',0,1200,'settle-teacher',$replayAccount,$replayEvent,$replayPayload,null,$settleObserved);
$replay=$ingest(...$replayArgs);
dzn_p_assert((int)$replay['evidence_id']===$teacherEvidenceId,'a fresh key for an already-received provider event must reuse the recorded receipt');
dzn_p_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_evidence WHERE case_id=%d",(int)$settleCaseRow->id))===$evidenceBefore,'a duplicate provider event must never add another evidence row');
dzn_p_assert((string)$requireCase((int)$settle['lesson_id'],(int)$settle['version_id'])->state==='settled','a duplicate provider receipt of a settled occurrence stays settled');
$conflictReplay=false;
try{$ingest($settle,'teacher',0,1260,'settle-teacher',$replayAccount,$replayEvent,$replayPayload.'-changed',null,$settleObserved);}catch(\Throwable$e){$conflictReplay=$e->getMessage()==='Idempotency conflict';}
dzn_p_assert($conflictReplay,'a changed payload for an existing provider event key must fail closed');
$settleCase=$requireCase((int)$settle['lesson_id'],(int)$settle['version_id']);
dzn_p_assert(count($conflicts((int)$settleCase->id))>=1,'a refused conflicting provider event must leave a durable conflict receipt');
$conflictRead=$read->forOccurrence((int)$settle['lesson_id'],(int)$settle['version_id']);
dzn_p_assert($conflictRead['review']['conflict_count']>=1&&in_array('duplicate_event_conflict',$conflictRead['assessment']['anomaly_codes'],true),'a refused conflict must surface through protected review');
dzn_p_assert($conflictRead['canonical_truth']['effective_outcome']==='delivered','a refused conflict must never mutate canonical truth');
dzn_p_assert($lessonState((int)$settle['lesson_id'])==='completed'&&$conflictRead['case']['state']==='settled','a post-settlement conflict must not reopen the case');
$subscriber=wp_insert_user(array('user_login'=>'dzn-2a2p-sub-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(32,true,true),'user_email'=>'sub-'.wp_generate_uuid4().'@phase-2a2p.invalid','role'=>'subscriber'));
$previous=get_current_user_id();wp_set_current_user((int)$subscriber);
try{
    dzn_p_rejected(fn()=>$intake->recordCutoverPolicy(gmdate('Y-m-d H:i:s',strtotime('+1 hour')),dzn_p_key('sub-cutover')),'Unauthorized','non-administrator cutover policy');
    dzn_p_rejected(fn()=>$intake->adjudicate((int)$belowCase->id,array('adjudication'=>'record_no_change','expected_case_version'=>(int)$requireCase((int)$below['lesson_id'],(int)$below['version_id'])->case_version),dzn_p_key('sub-adjudicate')),'Unauthorized','non-administrator adjudication');
    dzn_p_rejected(fn()=>$intake->ingestProviderEvidence((int)$settle['lesson_id'],(int)$settle['version_id'],array('provider_code'=>'google_meet','provider_account_key'=>'x','provider_event_key'=>'x','provider_payload_key'=>'x','participant_role'=>'teacher','observed_at'=>gmdate('Y-m-d H:i:s')),dzn_p_key('sub-ingest')),'Unauthorized','non-administrator provider ingestion');
    dzn_p_rejected(fn()=>$identity->record(array('provider_code'=>'google_meet','provider_account_key'=>'sub','participant_role'=>'student','participant_id'=>(int)$settle['student_id']),dzn_p_key('sub-identity')),'Unauthorized','non-administrator identity mapping');
    $deniedRead=false;try{$read->forOccurrence((int)$settle['lesson_id'],(int)$settle['version_id']);}catch(RuntimeException$e){$deniedRead=$e->getMessage()==='Unauthorized';}
    dzn_p_assert($deniedRead,'non-administrator protected attendance review read');
}finally{wp_set_current_user($previous);}
$firstEvidence=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dzn_canonical_attendance_evidence WHERE provider_event_key_digest=%s",hash_hmac('sha256','canonical_attendance_event_key:'.$replayEvent,wp_salt('dzn_canonical_attendance'))));
dzn_p_assert($firstEvidence&&preg_match('/^[a-f0-9]{64}$/D',(string)$firstEvidence->provider_event_key_digest)===1,'provider event keys must persist only as keyed digests');
dzn_p_assert((string)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_canonical_attendance_evidence WHERE provider_event_key_digest=%s",$replayEvent))==='0','raw provider event keys must never persist');
dzn_p_assert((string)$firstEvidence->provider_account_digest===(string)\Delnavazan\Platform\Core\Application\CanonicalAttendanceIdempotency::evidence($replayAccount),'provider account identity must persist only as a keyed digest');

// ---------------------------------------------------------------------------
// 7. P-1 provider identity authority matrix: only a durable verified mapping qualifies.
// ---------------------------------------------------------------------------
$identityCases=array(
    'unmapped'=>function() use($chain,$occurrence,$ingest,$map):array{
        $o=$occurrence($chain(),'id-unmapped',5);
        $ingest($o,'teacher',0,1300,'id-unmapped-teacher');   // no mapping recorded for this account
        $ingest($o,'student',0,1300,'id-unmapped-student');
        return $o;
    },
    'unverified'=>function() use($chain,$occurrence,$ingest,$map,$account):array{
        $o=$occurrence($chain(),'id-unverified',5);
        $map('teacher',(int)$o['teacher_id'],'acct-id-unverified-teacher','unverified');
        $map('student',(int)$o['student_id'],'acct-id-unverified-student','unverified');
        $ingest($o,'teacher',0,1300,'id-unverified-teacher');$ingest($o,'student',0,1300,'id-unverified-student');
        return $o;
    },
    'wrong_student'=>function() use($chain,$occurrence,$ingest,$map,$wpdb,$p):array{
        $o=$occurrence($chain(),'id-wrong-student',5);
        $other=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}students WHERE id<>%d ORDER BY id DESC LIMIT 1",(int)$o['student_id']));
        dzn_p_assert($other>0&&$other!==(int)$o['student_id'],'a distinct synthetic Student is required');
        $map('teacher',(int)$o['teacher_id'],'acct-id-wrong-student-teacher');
        $map('student',$other,'acct-id-wrong-student-student');
        $ingest($o,'teacher',0,1300,'id-wrong-student-teacher');$ingest($o,'student',0,1300,'id-wrong-student-student');
        return $o;
    },
    'wrong_teacher'=>function() use($chain,$occurrence,$ingest,$map,$wpdb,$p):array{
        $o=$occurrence($chain(),'id-wrong-teacher',5);
        $other=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}teachers WHERE id<>%d ORDER BY id DESC LIMIT 1",(int)$o['teacher_id']));
        dzn_p_assert($other>0&&$other!==(int)$o['teacher_id'],'a distinct synthetic Teacher is required');
        $map('teacher',$other,'acct-id-wrong-teacher-teacher');
        $map('student',(int)$o['student_id'],'acct-id-wrong-teacher-student');
        $ingest($o,'teacher',0,1300,'id-wrong-teacher-teacher');$ingest($o,'student',0,1300,'id-wrong-teacher-student');
        return $o;
    },
    'wrong_role'=>function() use($chain,$occurrence,$ingest,$map,$account):array{
        $o=$occurrence($chain(),'id-wrong-role',5);
        // The Teacher's account is registered as a Student binding: a Teacher claim can never resolve.
        $map('student',(int)$o['teacher_id'],'acct-id-wrong-role-teacher');
        $map('student',(int)$o['student_id'],'acct-id-wrong-role-student');
        $ingest($o,'teacher',0,1300,'id-wrong-role-teacher');$ingest($o,'student',0,1300,'id-wrong-role-student');
        return $o;
    },
    'ambiguous'=>function() use($chain,$occurrence,$ingest,$map,$wpdb,$p):array{
        $o=$occurrence($chain(),'id-ambiguous',5);
        $other=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}students WHERE id<>%d ORDER BY id DESC LIMIT 1",(int)$o['student_id']));
        dzn_p_assert($other>0&&$other!==(int)$o['student_id'],'a distinct synthetic Student is required');
        $map('teacher',(int)$o['teacher_id'],'acct-id-ambiguous-teacher');
        $map('student',(int)$o['student_id'],'acct-id-ambiguous-student');
        $map('student',$other,'acct-id-ambiguous-student');
        $ingest($o,'teacher',0,1300,'id-ambiguous-teacher');$ingest($o,'student',0,1300,'id-ambiguous-student');
        return $o;
    },
    'revoked'=>function() use($chain,$occurrence,$ingest,$map,$identity):array{
        $o=$occurrence($chain(),'id-revoked',5);
        $teacherMapping=$map('teacher',(int)$o['teacher_id'],'acct-id-revoked-teacher');
        $map('student',(int)$o['student_id'],'acct-id-revoked-student');
        $identity->revoke((int)$teacherMapping['mapping_id'],array(),dzn_p_key('revoke'));
        $ingest($o,'teacher',0,1300,'id-revoked-teacher');$ingest($o,'student',0,1300,'id-revoked-student');
        return $o;
    },
    'caller_claims_verified'=>function() use($chain,$occurrence,$intake,$plus,$runSuffix):array{
        $o=$occurrence($chain(),'id-claims',5);
        // A caller asserting resolved/verified identity with no registry entry must not qualify.
        $intake->ingestProviderEvidence((int)$o['lesson_id'],(int)$o['version_id'],array(
            'provider_code'=>'google_meet','provider_account_key'=>'acct-id-claims-teacher','provider_event_key'=>'event-id-claims-'.$runSuffix,'provider_payload_key'=>'payload-id-claims-'.$runSuffix,
            'participant_role'=>'teacher','participant_identity_state'=>'resolved','verification_state'=>'verified','resolved_teacher_id'=>(int)$o['teacher_id'],
            'join_at_utc'=>$o['start'],'leave_at_utc'=>$plus($o['start'],1300),'observed_at'=>gmdate('Y-m-d H:i:s'),'provenance_reference'=>'prov-id-claims','evidence_reference'=>'ref-id-claims',
        ),dzn_p_key('id-claims-teacher'));
        return $o;
    },
);
$identityResults=array();
foreach(array('unmapped','unverified','wrong_student','wrong_teacher','wrong_role','ambiguous','revoked','caller_claims_verified')as$label){
    $o=call_user_func($identityCases[$label]);
    $case=$requireCase((int)$o['lesson_id'],(int)$o['version_id']);
    $model=$read->forOccurrence((int)$o['lesson_id'],(int)$o['version_id']);
    dzn_p_assert((string)$case->state!=='settled','identity case '.$label.' must not settle automatically');
    dzn_p_assert($outcome((int)$o['lesson_id'])===null,'identity case '.$label.' must not create canonical truth');
    dzn_p_assert($lessonState((int)$o['lesson_id'])==='authorised','identity case '.$label.' must not complete the Lesson');
    dzn_p_assert($obligationCount((int)$o['lesson_id'])===0,'identity case '.$label.' must not identify responsibility');
    dzn_p_assert($model['assessment']['automatic_success_eligible']===false,'identity case '.$label.' must not be automatically eligible');
    $identityResults[$label]=array($o,$model);
}
dzn_p_assert(in_array('provider_identity_unmapped',$identityResults['unmapped'][1]['assessment']['anomaly_codes'],true),'missing mapping must be recorded as an unmapped provider identity');
dzn_p_assert(in_array('provider_identity_unmapped',$identityResults['unverified'][1]['assessment']['anomaly_codes'],true),'unverified mapping must never qualify');
dzn_p_assert(in_array('provider_identity_unmapped',$identityResults['revoked'][1]['assessment']['anomaly_codes'],true),'revoked mapping must never qualify');
dzn_p_assert(in_array('participant_ambiguous',$identityResults['ambiguous'][1]['assessment']['anomaly_codes'],true),'ambiguous mapping must be recorded as ambiguous');
dzn_p_assert(in_array('participant_identity_mismatch',$identityResults['wrong_student'][1]['assessment']['anomaly_codes'],true),'wrong Student mapping must be recorded as a mismatch');
dzn_p_assert(in_array('participant_identity_mismatch',$identityResults['wrong_teacher'][1]['assessment']['anomaly_codes'],true),'wrong Teacher mapping must be recorded as a mismatch');
dzn_p_assert($identityResults['wrong_role'][1]['evidence']['counts']['unresolved']>=1,'a role-mismatched account must never resolve for the claimed role');
dzn_p_assert($identityResults['wrong_student'][1]['evidence']['counts']['mismatch']>=1&&$identityResults['wrong_teacher'][1]['evidence']['counts']['mismatch']>=1,'mismatched provider identity must be visible in the protected read');
dzn_p_assert((string)$requireCase((int)$identityResults['caller_claims_verified'][0]['lesson_id'],(int)$identityResults['caller_claims_verified'][0]['version_id'])->state!=='settled','caller-asserted verification must never become authoritative');

// Final threshold-crossing evidence with a wrong mapping must not settle.
$crossing=$occurrence($chain(),'id-crossing',5);
$map('teacher',(int)$crossing['teacher_id'],'acct-id-crossing-teacher');
$map('student',(int)$crossing['student_id'],'acct-id-crossing-student');
$ingest($crossing,'teacher',0,1300,'id-crossing-teacher');
$wrongAccount='acct-id-crossing-wrong';
$otherStudent=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}students WHERE id<>%d ORDER BY id DESC LIMIT 1",(int)$crossing['student_id']));
$map('student',$otherStudent,$wrongAccount);
$crossingResult=$ingest($crossing,'student',0,1300,'id-crossing-wrong-student',$wrongAccount);
dzn_p_assert((string)$requireCase((int)$crossing['lesson_id'],(int)$crossing['version_id'])->state!=='settled','a threshold-crossing provider event with a wrong mapping must not settle');
dzn_p_assert($outcome((int)$crossing['lesson_id'])===null,'a threshold-crossing wrong mapping must not create canonical truth');
$crossingRead=$read->forOccurrence((int)$crossing['lesson_id'],(int)$crossing['version_id']);
dzn_p_assert(in_array('participant_identity_mismatch',$crossingRead['assessment']['anomaly_codes'],true),'the threshold-crossing mismatch must be recorded and retained');
dzn_p_assert($crossingRead['evidence']['counts']['mismatch']===1,'the mismatched account must be counted explicitly, never silently dropped');
dzn_p_assert($crossingRead['evidence']['counts']['provider_interval']===2,'the mismatched evidence row must be retained append-only');

// Multiple verified provider accounts for the same canonical participant must union, not double-count.
$multi=$settlingExtras['id-multi'];
$map('teacher',(int)$multi['teacher_id'],'acct-id-multi-teacher');
$map('teacher',(int)$multi['teacher_id'],'acct-id-multi-teacher-2');
$map('student',(int)$multi['student_id'],'acct-id-multi-student');
$map('student',(int)$multi['student_id'],'acct-id-multi-student-2');
$ingest($multi,'teacher',0,1300,'id-multi-teacher');$ingest($multi,'teacher',300,1300,'id-multi-teacher-2');
$ingest($multi,'student',0,1300,'id-multi-student');$ingest($multi,'student',600,1300,'id-multi-student-2');
$multiCase=$requireCase((int)$multi['lesson_id'],(int)$multi['version_id']);
dzn_p_assert((string)$multiCase->state==='settled','two verified accounts for one participant must union and settle');
$multiRead=$read->forOccurrence((int)$multi['lesson_id'],(int)$multi['version_id']);
dzn_p_assert($multiRead['assessment']['qualifying_overlap_seconds']===1200,'two overlapping accounts for one participant must union without double-counting (the qualifying window caps at 1200s)');

// Cross-participant qualification: each occurrence qualifies only from its own canonical participants.
$crossA=$settlingExtras['id-cross-a'];$crossB=$settlingExtras['id-cross-b'];
$map('teacher',(int)$crossA['teacher_id'],'acct-id-cross-teacher');
$map('teacher',(int)$crossB['teacher_id'],'acct-id-cross-teacher-b');
$map('student',(int)$crossA['student_id'],'acct-id-cross-student');
$map('student',(int)$crossB['student_id'],'acct-id-cross-student-b');
$ingest($crossA,'teacher',0,1300,'id-cross-teacher');$ingest($crossA,'student',0,1300,'id-cross-student');
$ingest($crossB,'teacher',0,1300,'id-cross-teacher-b');$ingest($crossB,'student',0,1300,'id-cross-student-b');
dzn_p_assert((string)$requireCase((int)$crossA['lesson_id'],(int)$crossA['version_id'])->state==='settled','a correctly mapped occurrence must settle');
dzn_p_assert((string)$requireCase((int)$crossB['lesson_id'],(int)$crossB['version_id'])->state==='settled','the second correctly mapped occurrence must settle independently');

// ---------------------------------------------------------------------------
// 8. P-3 cross-context provider replay: same event key in another context is a conflict.
// ---------------------------------------------------------------------------
$sharedEvent='event-cross-context-'.$runSuffix;$sharedPayload='payload-cross-context-'.$runSuffix;
$ctxA=$occurrence($chain(),'ctx-a',5);$ctxB=$occurrence($chain(),'ctx-b',5);
$map('teacher',(int)$ctxA['teacher_id'],'acct-ctx-a-teacher');
$map('teacher',(int)$ctxB['teacher_id'],'acct-ctx-b-teacher');
$ingest($ctxA,'teacher',0,600,'ctx-a-teacher','acct-ctx-a-teacher',$sharedEvent,$sharedPayload);
$crossConflict=false;
try{$ingest($ctxB,'teacher',0,600,'ctx-b-teacher','acct-ctx-b-teacher',$sharedEvent,$sharedPayload);}catch(\Throwable$e){$crossConflict=$e->getMessage()==='Idempotency conflict';}
dzn_p_assert($crossConflict,'a provider event replayed into another Lesson context must fail closed');
$ctxBCase=$requireCase((int)$ctxB['lesson_id'],(int)$ctxB['version_id']);
dzn_p_assert(count($conflicts((int)$ctxBCase->id))>=1,'a cross-context conflict must leave a durable receipt on the current case');
dzn_p_assert((string)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_evidence WHERE provider_event_key_digest=%s",hash_hmac('sha256','canonical_attendance_event_key:'.$sharedEvent,wp_salt('dzn_canonical_attendance'))))==='1','a cross-context replay must never create a second evidence row');
dzn_p_assert((int)$wpdb->get_var($wpdb->prepare("SELECT lesson_id FROM {$p}canonical_attendance_evidence WHERE provider_event_key_digest=%s",hash_hmac('sha256','canonical_attendance_event_key:'.$sharedEvent,wp_salt('dzn_canonical_attendance'))))===(int)$ctxA['lesson_id'],'the retained evidence row must still belong to the original Lesson');

// ---------------------------------------------------------------------------
// 9. P-5 exact ingest replay convergence across the settlement boundaries.
// ---------------------------------------------------------------------------
$boundary=function(array $o,string $label,?string $hook) use($map,$ingest,$inject,$clear):array{
    $map('teacher',(int)$o['teacher_id'],'acct-'.$label.'-teacher');
    $map('student',(int)$o['student_id'],'acct-'.$label.'-student');
    $key=dzn_p_key('boundary-'.$label);
    $observed=gmdate('Y-m-d H:i:s');
    if($hook!==null)$inject($hook);
    $caught=null;
    try{
        $ingest($o,'teacher',0,1300,$label.'-teacher',null,null,null,$key,$observed);
        $ingest($o,'student',0,1300,$label.'-student',null,null,null,$key.'-2',$observed);
    }catch(\Throwable$e){$caught=$e;}
    if($hook!==null)$clear($hook);
    return array($o,$caught,$key,$observed);
};
// A. pending persisted, no Phase-O result: the exact replay converges.
[$bA,$caughtA,$keyA,$obsA]=$boundary($settlingExtras['bound-a'],'bound-a','dzn_phase_2a2p_before_settlement_convergence');
dzn_p_assert($caughtA!==null&&str_contains($caughtA->getMessage(),'before_settlement_convergence'),'boundary A failure injection was not observed');
$caseA=$requireCase((int)$bA['lesson_id'],(int)$bA['version_id']);
dzn_p_assert((string)$caseA->state==='settlement_pending','boundary A must leave a durable pending settlement');
dzn_p_assert($outcome((int)$bA['lesson_id'])===null&&$lessonState((int)$bA['lesson_id'])==='authorised','boundary A must not reach canonical truth');
$replayA=$ingest($bA,'student',0,1300,'bound-a-student',null,null,null,$keyA.'-2',$obsA);
dzn_p_assert(!empty($replayA['converged'])&&$replayA['state']==='settled','an exact ingest replay must resume the interrupted settlement');
dzn_p_assert($outcome((int)$bA['lesson_id'])&&$lessonState((int)$bA['lesson_id'])==='completed','resumed settlement must reach delivered + completed');
dzn_p_assert($obligationCount((int)$bA['lesson_id'])===0,'resumed settlement must not create academy debt');
// B. Phase-O delivered exists, Lesson incomplete.
[$bB,$caughtB,$keyB,$obsB]=$boundary($settlingExtras['bound-b'],'bound-b','dzn_phase_2a2p_after_delivery_truth');
dzn_p_assert($caughtB!==null&&str_contains($caughtB->getMessage(),'after_delivery_truth'),'boundary B failure injection was not observed');
dzn_p_assert($outcome((int)$bB['lesson_id'])!==null&&$lessonState((int)$bB['lesson_id'])==='authorised','boundary B must leave delivered truth without completion');
$replayB=$ingest($bB,'student',0,1300,'bound-b-student',null,null,null,$keyB.'-2',$obsB);
dzn_p_assert(!empty($replayB['converged'])&&$lessonState((int)$bB['lesson_id'])==='completed','replay must complete a Lesson left incomplete after delivered truth');
dzn_p_assert((string)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d",(int)$bB['lesson_id']))==='1','recovery must not duplicate the Phase-O outcome');
// C. Lesson completed, Phase-P final result missing.
[$bC,$caughtC,$keyC,$obsC]=$boundary($settlingExtras['bound-c'],'bound-c','dzn_phase_2a2p_after_lesson_completion');
dzn_p_assert($caughtC!==null&&str_contains($caughtC->getMessage(),'after_lesson_completion'),'boundary C failure injection was not observed');
dzn_p_assert($lessonState((int)$bC['lesson_id'])==='completed','boundary C must reach Lesson completion');
$caseC=$requireCase((int)$bC['lesson_id'],(int)$bC['version_id']);
dzn_p_assert((string)$caseC->state==='settlement_pending','boundary C must leave the Phase-P settlement result unrecorded');
$replayC=$ingest($bC,'student',0,1300,'bound-c-student',null,null,null,$keyC.'-2',$obsC);
dzn_p_assert(!empty($replayC['converged'])&&$replayC['state']==='settled','replay must record the missing final settlement result');
dzn_p_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_academy_obligations WHERE source_lesson_id=%d",(int)$bC['lesson_id']))===0,'recovery must not create academy debt');
// D. Full completion, then exact replay: idempotent with no duplicate effects.
$caseDBefore=$requireCase((int)$bA['lesson_id'],(int)$bA['version_id']);
$decisionsBefore=$decisionCount((int)$caseDBefore->id);
$replayD=$ingest($bA,'student',0,1300,'bound-a-student',null,null,null,$keyA.'-2',$obsA);
dzn_p_assert(!empty($replayD['idempotent'])&&empty($replayD['converged']),'a replay of an already settled occurrence must be a pure idempotent replay');
dzn_p_assert($decisionCount((int)$caseDBefore->id)===$decisionsBefore,'replaying a settled occurrence must not append decisions');
dzn_p_assert((string)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d",(int)$bA['lesson_id']))==='1','replaying a settled occurrence must not duplicate canonical truth');
// E. Conflicting canonical truth during recovery must fail closed.
[$bE,$caughtE,$keyE,$obsE]=$boundary($settlingExtras['bound-e'],'bound-e','dzn_phase_2a2p_before_settlement_convergence');
dzn_p_assert($caughtE!==null,'boundary E failure injection was not observed');
$delivery->record((int)$bE['lesson_id'],'authorised',array('outcome_code'=>'teacher_non_delivery','reason_code'=>'synthetic_conflict','evidence_channel'=>'staff_record','evidence_reference'=>'bound-e-conflict','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_p_key('bound-e-truth'));
$conflictingReplay=false;
try{$ingest($bE,'student',0,1300,'bound-e-student',null,null,null,$keyE.'-2',$obsE);}catch(\Throwable$e){$conflictingReplay=$e->getMessage()==='canonical_truth_conflict';}
dzn_p_assert($conflictingReplay,'recovery over conflicting canonical truth must fail closed');
dzn_p_assert($lessonState((int)$bE['lesson_id'])==='authorised','failed recovery must not complete the Lesson');

// ---------------------------------------------------------------------------
// 10. P-7 prospective cutover authority.
// ---------------------------------------------------------------------------
dzn_p_rejected(fn()=>$intake->recordCutoverPolicy(gmdate('Y-m-d H:i:s',strtotime('-1 hour')),dzn_p_key('past-cutover')),'cutover_instant_not_prospective','a backdated cutover instant');
$prospective=$intake->recordCutoverPolicy(gmdate('Y-m-d H:i:s',strtotime('+2 seconds')),dzn_p_key('prospective-cutover'));
dzn_p_assert((int)$prospective['policy_id']>0,'a prospective cutover instant must be accepted');
$settleCaseId=(int)$requireCase((int)$settle['lesson_id'],(int)$settle['version_id'])->cutover_policy_id;
$laterPolicy=$intake->recordCutoverPolicy(gmdate('Y-m-d H:i:s',strtotime('+3 seconds')),dzn_p_key('later-cutover'));
dzn_p_assert((int)$laterPolicy['policy_id']!==$settleCaseId,'a later policy must be a distinct immutable row');
$unchanged=$requireCase((int)$settle['lesson_id'],(int)$settle['version_id']);
dzn_p_assert((int)$unchanged->cutover_policy_id===$settleCaseId,'a later policy must never reinterpret an existing case');
dzn_p_assert($read->forOccurrence((int)$settle['lesson_id'],(int)$settle['version_id'])['cutover']['policy_id']===$settleCaseId,'a settled occurrence must keep its original policy binding');
// R2: one immutable policy per cutover instant; exact replay stays idempotent; a second policy at the
// same instant fails deterministically and applicability never uses the database insertion id.
$duplicateInstant=gmdate('Y-m-d H:i:s',strtotime('+10 seconds'));
$duplicateKey=dzn_p_key('cutover-dup');
$firstCutover=$intake->recordCutoverPolicy($duplicateInstant,$duplicateKey);
dzn_p_assert((int)$firstCutover['policy_id']>0,'the first policy at a prospective cutover instant must succeed');
$replayedCutover=$intake->recordCutoverPolicy($duplicateInstant,$duplicateKey);
dzn_p_assert(!empty($replayedCutover['idempotent']),'the exact cutover-policy replay must succeed idempotently');
dzn_p_rejected(fn()=>$intake->recordCutoverPolicy($duplicateInstant,dzn_p_key('cutover-dup-b')),'duplicate_cutover_instant','a second distinct policy at the same cutover instant');
// D/E: even corrupted/legacy data with duplicate maximum cutover instants must fail closed, never
// silently select by database id.
$maxInstant=(string)$wpdb->get_var("SELECT MAX(cutover_utc) FROM {$p}canonical_attendance_cutover_policies");
dzn_p_assert($maxInstant!=='','a cutover policy fixture is required for the ambiguity regression');
dzn_p_assert($wpdb->query("ALTER TABLE {$p}canonical_attendance_cutover_policies DROP INDEX cutover_instant")!==false,'Failed to simulate a non-unique cutover instant');
$duplicateUid=\Delnavazan\Platform\Core\Support\Identifier::uid();
dzn_p_assert($wpdb->query($wpdb->prepare("INSERT INTO {$p}canonical_attendance_cutover_policies (uid,policy_version,cutover_utc,rule_version,threshold_seconds,pre_grace_seconds,post_grace_seconds,created_at,created_by) VALUES (%s,'canonical_attendance_cutover_v1',%s,'canonical_attendance_overlap_v1',1200,0,900,%s,1)",$duplicateUid,$maxInstant,gmdate('Y-m-d H:i:s')))===1,'Failed to inject a duplicate cutover instant');
$ambiguous=null;
try{(new \Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalAttendanceRepository())->applicablePolicy(gmdate('Y-m-d H:i:s',strtotime($maxInstant.' UTC')+1));}catch(\Throwable$e){$ambiguous=$e->getMessage();}
dzn_p_assert($ambiguous==='cutover_policy_ambiguous','duplicate maximum cutover instants must be rejected as ambiguous, not decided by id');
dzn_p_assert($wpdb->query($wpdb->prepare("DELETE FROM {$p}canonical_attendance_cutover_policies WHERE uid=%s",$duplicateUid))===1,'Failed to remove the injected duplicate cutover instant');
dzn_p_assert($wpdb->query("ALTER TABLE {$p}canonical_attendance_cutover_policies ADD UNIQUE KEY cutover_instant(cutover_utc)")!==false,'Failed to restore the cutover-instant uniqueness');

// ---------------------------------------------------------------------------
// 11. P-8 duplicate command recovery: expected payload and context are always compared.
// ---------------------------------------------------------------------------
$dupA=$occurrence($chain(),'dup-a',5);$dupB=$occurrence($chain(),'dup-b',5);
$map('teacher',(int)$dupA['teacher_id'],'acct-dup-a-teacher');
$map('teacher',(int)$dupB['teacher_id'],'acct-dup-b-teacher');
$dupKey=dzn_p_key('dup');
$dupObserved=gmdate('Y-m-d H:i:s');
$dupFirst=$ingest($dupA,'teacher',0,600,'dup-a-teacher','acct-dup-a-teacher','event-dup-'.$runSuffix,'payload-dup-'.$runSuffix,$dupKey,$dupObserved);
$dupReplay=$ingest($dupA,'teacher',0,600,'dup-a-teacher','acct-dup-a-teacher','event-dup-'.$runSuffix,'payload-dup-'.$runSuffix,$dupKey,$dupObserved);
dzn_p_assert(!empty($dupReplay['idempotent'])&&(int)$dupReplay['evidence_id']===(int)$dupFirst['evidence_id'],'an exact command replay must converge on the recorded evidence');
$dupCross=false;
try{$ingest($dupB,'teacher',0,600,'dup-b-teacher','acct-dup-b-teacher','event-dup-b-'.$runSuffix,'payload-dup-b-'.$runSuffix,$dupKey);}catch(\Throwable$e){$dupCross=$e->getMessage()==='Idempotency conflict';}
dzn_p_assert($dupCross,'the same command key in a different Lesson context must fail closed');
// The same command key on the SAME Lesson and schedule version must compare the complete incoming
// context; a changed payload, event, account, interval, observation or provenance must never be
// acknowledged as an exact replay.
$dupVariants=array(
    'changed payload'=>array('teacher',0,600,'dup-a-teacher','acct-dup-a-teacher','event-dup-'.$runSuffix,'payload-dup-'.$runSuffix.'-changed',$dupKey,$dupObserved),
    'changed event'=>array('teacher',0,600,'dup-a-teacher','acct-dup-a-teacher','event-dup-'.$runSuffix.'-changed','payload-dup-'.$runSuffix,$dupKey,$dupObserved),
    'changed account'=>array('teacher',0,600,'dup-a-teacher','acct-dup-a-teacher-alt','event-dup-'.$runSuffix,'payload-dup-'.$runSuffix,$dupKey,$dupObserved),
    'changed interval'=>array('teacher',0,900,'dup-a-teacher','acct-dup-a-teacher','event-dup-'.$runSuffix,'payload-dup-'.$runSuffix,$dupKey,$dupObserved),
    'changed observation'=>array('teacher',0,600,'dup-a-teacher','acct-dup-a-teacher','event-dup-'.$runSuffix,'payload-dup-'.$runSuffix,$dupKey,gmdate('Y-m-d H:i:s',strtotime($dupObserved.' UTC')-1)),
    'changed provenance'=>array('teacher',0,600,'dup-a-teacher-alt','acct-dup-a-teacher','event-dup-'.$runSuffix,'payload-dup-'.$runSuffix,$dupKey,$dupObserved),
);
foreach($dupVariants as$variantLabel=>$variant){
    $caught=null;try{$ingest($dupA,...$variant);}catch(\Throwable$e){$caught=$e->getMessage();}
    dzn_p_assert($caught==='Idempotency conflict','the same command key on the same occurrence with a '.$variantLabel.' must fail closed');
}
$dupOperation=false;
try{$intake->submitClaim((int)$dupA['lesson_id'],(int)$dupA['version_id'],array('claim_kind'=>'review_request','reason_code'=>'dup_operation','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'dup-operation'),$dupKey);}catch(\Throwable$e){$dupOperation=$e->getMessage()==='Idempotency conflict';}
dzn_p_assert($dupOperation,'the same command key reused for another operation must fail closed');
$adjudicateKey=dzn_p_key('dup-adjudicate');
$dupAdjudicateCase=$requireCase((int)$dupA['lesson_id'],(int)$dupA['version_id']);
$intake->adjudicate((int)$dupAdjudicateCase->id,array('adjudication'=>'record_no_change','expected_case_version'=>(int)$dupAdjudicateCase->case_version),$adjudicateKey);
$dupVersion=false;
try{$intake->adjudicate((int)$dupAdjudicateCase->id,array('adjudication'=>'record_no_change','expected_case_version'=>(int)$dupAdjudicateCase->case_version+1),$adjudicateKey);}catch(\Throwable$e){$dupVersion=$e->getMessage()==='Idempotency conflict';}
dzn_p_assert($dupVersion,'the same adjudication key with another expected case version must fail closed');

// ---------------------------------------------------------------------------
// 12. P-2 case/schedule validation and P-6 adjudication versioning.
// ---------------------------------------------------------------------------
dzn_p_rejected(fn()=>$intake->adjudicate(999999999,array('adjudication'=>'record_no_change','expected_case_version'=>1),dzn_p_key('fabricated')),'canonical_attendance_case_required','a fabricated case adjudication');
dzn_p_rejected(fn()=>$intake->reassess(999999999,array('expected_case_version'=>1),dzn_p_key('fabricated-reassess')),'canonical_attendance_case_required','a fabricated case reassessment');
dzn_p_rejected(fn()=>$settlement->settleDelivered(999999999,false),'canonical_attendance_case_required','a fabricated direct settlement');
$staleCase=$requireCase((int)$below['lesson_id'],(int)$below['version_id']);
dzn_p_rejected(fn()=>$intake->adjudicate((int)$staleCase->id,array('adjudication'=>'record_no_change','expected_case_version'=>(int)$staleCase->case_version+1),dzn_p_key('stale')),'stale_case_version','a stale expected case version');
dzn_p_rejected(fn()=>$intake->adjudicate((int)$staleCase->id,array('adjudication'=>'record_no_change'),dzn_p_key('missing-version')),'expected_case_version_required','an adjudication without an expected case version');
dzn_p_rejected(fn()=>$intake->reassess((int)$staleCase->id,array(),dzn_p_key('missing-version')),'expected_case_version_required','a reassessment without an expected case version');
$directCase=$requireCase((int)$below['lesson_id'],(int)$below['version_id']);
dzn_p_rejected(fn()=>$settlement->settleDelivered((int)$directCase->id,false),'attendance_settlement_not_pending','a direct settlement attempt outside a durable settlement intent');
// A superseded schedule version must never settle, adjudicate or reassess canonical truth.
[$staleOccurrence,$staleCaught,$staleKey,$staleObserved]=$boundary($settlingExtras['id-stale'],'id-stale','dzn_phase_2a2p_before_settlement_convergence');
dzn_p_assert($staleCaught!==null,'the stale-schedule probe must leave a durable pending settlement');
$staleScheduleCase=$requireCase((int)$staleOccurrence['lesson_id'],(int)$staleOccurrence['version_id']);
dzn_p_assert((string)$staleScheduleCase->state==='settlement_pending','the stale-schedule probe must start from a durable pending settlement');
dzn_p_assert($outcome((int)$staleOccurrence['lesson_id'])===null,'the stale-schedule probe must not hold canonical truth');
$rescheduled=$schedules->schedule((int)$staleOccurrence['lesson_id'],(int)$staleOccurrence['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr(gmdate('Y-m-d H:i:s',strtotime('+2 days')),0,10),'local_wall_time'=>'09:00:00','duration_minutes'=>30,'reason_code'=>'synthetic_reschedule')+dzn_p_evidence('reschedule'),dzn_p_key('reschedule'));
dzn_p_assert((int)$rescheduled['schedule_version_id']!==(int)$staleOccurrence['version_id'],'the reschedule must create a new schedule version');
dzn_p_rejected(fn()=>$intake->adjudicate((int)$staleScheduleCase->id,array('adjudication'=>'record_no_change','expected_case_version'=>(int)$staleScheduleCase->case_version),dzn_p_key('stale-adjudicate')),'schedule_version_conflict','adjudication of a superseded schedule version');
dzn_p_rejected(fn()=>$intake->reassess((int)$staleScheduleCase->id,array('expected_case_version'=>(int)$staleScheduleCase->case_version),dzn_p_key('stale-reassess')),'schedule_version_conflict','reassessment of a superseded schedule version');
dzn_p_rejected(fn()=>$settlement->settleDelivered((int)$staleScheduleCase->id,false),'schedule_version_conflict','settlement of a superseded schedule version');
// R3: the protected current-attendance read must fail closed on a superseded schedule version even
// though the old version row still exists durably.
dzn_p_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions WHERE id=%d",(int)$staleOccurrence['version_id']))===1,'the superseded schedule version must remain durable for history');
dzn_p_rejected(fn()=>$read->forOccurrence((int)$staleOccurrence['lesson_id'],(int)$staleOccurrence['version_id']),'schedule_version_conflict','the protected read of a superseded schedule version');

// ---------------------------------------------------------------------------
// 13. P-9 exact cutover-policy replay must close its transaction and retain no stale lock.
// ---------------------------------------------------------------------------
$txnKey=dzn_p_key('txn-replay');
$txnInstant=gmdate('Y-m-d H:i:s',strtotime('+5 seconds'));
$firstPolicy=$intake->recordCutoverPolicy($txnInstant,$txnKey);
$openAfterFirst=$openTransactions();
dzn_p_assert($openAfterFirst===0,'a recorded cutover policy must not leave an open transaction');
$replayedPolicy=$intake->recordCutoverPolicy($txnInstant,$txnKey);
dzn_p_assert(!empty($replayedPolicy['idempotent'])&&(string)$replayedPolicy['operation']==='record_cutover_policy','an exact cutover-policy replay must converge idempotently');
dzn_p_assert($openTransactions()===0,'an exact cutover-policy replay must close its transaction');
$repository=new \Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalAttendanceRepository();
$repository->begin();$repository->rollback();
dzn_p_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_cutover_policies WHERE id=%d",(int)$firstPolicy['policy_id']))===1,'the replayed policy must be durably committed and unaffected by a later rollback');
dzn_p_assert((string)$wpdb->get_var($wpdb->prepare("SELECT cutover_utc FROM {$p}canonical_attendance_cutover_policies WHERE id=%d",(int)$firstPolicy['policy_id']))===$txnInstant,'the replayed policy must retain its exact instant');

// ---------------------------------------------------------------------------
// 14. Capability repair and least privilege.
// ---------------------------------------------------------------------------
$administrator=get_role('administrator');
$repairCapability='dzn_ingest_canonical_attendance_evidence';
$administrator->remove_cap($repairCapability);
update_option('dzn_platform_capability_version_2a2p','stale',false);
dzn_p_assert(!$administrator->has_cap($repairCapability),'the partial-capability fixture did not remove the grant');
\Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();
foreach(array('dzn_ingest_canonical_attendance_evidence','dzn_submit_own_attendance_claim','dzn_submit_own_delivery_claim','dzn_manage_canonical_attendance_review','dzn_view_canonical_attendance_review','dzn_manage_canonical_attendance_identity')as$capability)dzn_p_assert(get_role('administrator')->has_cap($capability),'partial capability repair did not restore '.$capability);
dzn_p_assert((string)get_option('dzn_platform_capability_version_2a2p')==='2a2p','partial capability repair must advance the Phase P marker');
$teacherRole=get_role('dzn_teacher');
dzn_p_assert($teacherRole->has_cap('dzn_submit_own_delivery_claim'),'the Teacher role must hold its own delivery-claim grant');
foreach(array('dzn_manage_canonical_attendance_review','dzn_view_canonical_attendance_review','dzn_manage_canonical_attendance_identity','dzn_ingest_canonical_attendance_evidence')as$reserved)dzn_p_assert(!$teacherRole->has_cap($reserved),'the Teacher role must never hold '.$reserved);

// ---------------------------------------------------------------------------
// 15. No policy at all must fail closed, and no production cutover is performed by migration.
// ---------------------------------------------------------------------------
$wpdb->query("DELETE FROM {$p}canonical_attendance_cutover_policies");
$noPolicy=$occurrence($chain(),'no-policy',5);
dzn_p_rejected(fn()=>$ingest($noPolicy,'teacher',0,60,'no-policy-teacher',null,null,null,dzn_p_key('no-policy')),'cutover_policy_required','provider evidence with no cutover policy');
$deferred=$intake->recordCutoverPolicy(gmdate('Y-m-d H:i:s',strtotime('+40 seconds')),dzn_p_key('deferred-cutover'));
dzn_p_assert((int)$deferred['policy_id']>0,'a deferred prospective cutover must be accepted');
dzn_p_rejected(fn()=>$ingest($noPolicy,'teacher',0,60,'no-policy-teacher',null,null,null,dzn_p_key('no-policy-deferred')),'occurrence_before_cutover','an occurrence before the activated cutover instant');
$restored=$intake->recordCutoverPolicy(gmdate('Y-m-d H:i:s',strtotime('+1 second')),dzn_p_key('restore-cutover'));
$afterRestore=$ingest($noPolicy,'teacher',0,60,'no-policy-teacher',null,null,null,dzn_p_key('no-policy-after'));
dzn_p_assert((int)$afterRestore['case_id']>0,'a restored prospective policy must admit the occurrence');
dzn_p_assert((int)$requireCase((int)$noPolicy['lesson_id'],(int)$noPolicy['version_id'])->cutover_policy_id===(int)$restored['policy_id'],'the restored case must bind the restored policy row');

// ---------------------------------------------------------------------------
// 16. R3-1: an exact completed cutover command must stay idempotent after the wall-clock instant
// passes; prospective validation applies only to a genuinely new command.
// ---------------------------------------------------------------------------
$boundaryKey=dzn_p_key('cutover-boundary');
$boundaryInstant=gmdate('Y-m-d H:i:s',strtotime('+2 seconds'));
$boundaryFirst=$intake->recordCutoverPolicy($boundaryInstant,$boundaryKey);
dzn_p_assert((int)$boundaryFirst['policy_id']>0,'the boundary cutover policy must be recorded while still prospective');
$boundaryTarget=strtotime($boundaryInstant.' UTC')+1;while(time()<$boundaryTarget)usleep(100000);
$boundaryReplay=$intake->recordCutoverPolicy($boundaryInstant,$boundaryKey);
dzn_p_assert(!empty($boundaryReplay['idempotent']),'an exact cutover-policy replay must remain idempotent after the instant passes');
dzn_p_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_cutover_policies WHERE cutover_utc=%s",$boundaryInstant))===1,'an exact cutover-policy replay must not create a second policy row');
dzn_p_rejected(fn()=>$intake->recordCutoverPolicy($boundaryInstant,dzn_p_key('cutover-boundary-new')),'cutover_instant_not_prospective','a genuinely new command at an already-passed cutover instant');
$changedBoundary=false;
try{$intake->recordCutoverPolicy(gmdate('Y-m-d H:i:s',strtotime('+9 seconds')),$boundaryKey);}catch(\Throwable$e){$changedBoundary=$e->getMessage()==='Idempotency conflict';}
dzn_p_assert($changedBoundary,'a changed payload under the original cutover command key must fail idempotency conflict');

echo "automatic_settlement=pass\nbelow_threshold_review=pass\nhuman_claims=pass\nadministrative_adjudication=pass\nterm_closure_and_late_evidence=pass\nidempotency_and_capability=pass\nprovider_identity_authority=pass cases=".count($identityResults)."\ncross_context_replay=pass\nsettlement_convergence_boundaries=pass cases=5\ncutover_policy_authority=pass\nduplicate_command_recovery=pass\ncase_schedule_validation=pass\ncutover_transaction_state=pass\ncapability_repair=pass\nno_policy_fail_closed=pass\npost_cutover_exact_replay=pass\nprotected_read=pass\nPhase 2A.2-P authority runtime passed\n";
