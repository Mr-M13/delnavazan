<?php
/**
 * Disposable production-path proof for the interim attendance and completion policy.
 *
 * Synthetic local data only. Run inside the offline WordPress/MariaDB harness:
 *   DZN_INTERIM_ATTENDANCE_RUNTIME=authority wp eval-file .../interim-attendance-completion-runtime.php
 */
if(getenv('DZN_INTERIM_ATTENDANCE_RUNTIME')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Interim attendance runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalAcademyObligationService,CanonicalAttendanceIntakeService,CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonCompletionPolicy,CanonicalLessonDeliveryReadService,CanonicalLessonDeliveryService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,Finance\LessonPayabilityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService,TeacherService,TeachingEligibilityService};
use Delnavazan\Platform\Integrations\LessonCompletionAutomationWorker;

global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_ic_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_ic_key(string $label):string{return 'dzn-interim-'.$label.'-'.wp_generate_uuid4();}
function dzn_ic_evidence(string $reference):array{return array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));}
function dzn_ic_rejected(callable $call,string $expected,string $message):void{$caught=null;try{$call();}catch(Throwable$e){$caught=$e;}dzn_ic_assert($caught!==null,$message.' was accepted');dzn_ic_assert($caught->getMessage()===$expected,$message.' rejected with an unexpected error: '.$caught->getMessage());}
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_ic_assert(is_array($fixture)&&count($fixture['sources']??array())>=8,'Phase-J production fixture required');
wp_set_current_user(1);
foreach(array('canonical_attendance_conflicts','canonical_attendance_case_anomalies','canonical_attendance_decisions','canonical_attendance_commands','canonical_attendance_evidence','canonical_attendance_cases','canonical_attendance_cutover_policies')as$table)dzn_ic_assert($wpdb->query("DELETE FROM {$p}{$table}")!==false,'Failed to reset disposable attendance storage: '.$table);

$lessons=new CanonicalLessonAuthorityService();$enrolments=new CanonicalEnrolmentLifecycleService();$terms=new CanonicalTermAuthorityService();
$assignments=new TeacherAssignmentService();$schedules=new CanonicalLessonScheduleService();$availability=new TeacherAvailabilityService();
$accepting=new TeacherAcceptingStateService();$intake=new CanonicalAttendanceIntakeService();$delivery=new CanonicalLessonDeliveryService();
$deliveryRead=new CanonicalLessonDeliveryReadService();

$allocate=static function() use($wpdb,$p):int{
    $id=(int)$wpdb->get_var("SELECT e.id FROM {$p}enrolments e WHERE e.lifecycle_state='authorised' AND NOT EXISTS(SELECT 1 FROM {$p}terms t WHERE t.enrolment_id=e.id AND t.record_model='canonical_enrolment_term_v1') ORDER BY e.id LIMIT 1");
    if($id<1)throw new RuntimeException('no_available_source');
    return $id;
};
$chain=function() use($allocate,$enrolments,$terms,$assignments,$availability,$accepting,$wpdb,$p):array{
    $id=$allocate();
    $enrolments->activate($id,'authorised',dzn_ic_evidence('activate'),dzn_ic_key('activate'));
    $term=$terms->create($id,null,null,dzn_ic_evidence('term'),dzn_ic_key('term'));
    $terms->activate((int)$term['term_id'],'authorised',dzn_ic_evidence('term-active'),dzn_ic_key('term-active'));
    $assignment=$assignments->assignInitial($id,dzn_ic_key('assignment'));
    $course=(int)$wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}enrolments WHERE id=%d",$id));
    $teacher=(int)(new TeacherService())->create(array('display_name'=>'Synthetic Interim Teacher','email'=>'interim-'.wp_generate_uuid4().'@dzn.invalid'));
    $now=gmdate('Y-m-d H:i:s');
    dzn_ic_assert($wpdb->insert($p.'teacher_onboarding_states',array('teacher_id'=>$teacher,'state'=>'active','readiness_state'=>'ready','profile_state'=>'complete','availability_state'=>'complete','agreement_state'=>'not_required','activated_at'=>$now,'version'=>1,'created_at'=>$now,'updated_at'=>$now,'created_by'=>1,'updated_by'=>1))!==false,'Teacher onboarding fixture failed');
    $accepting->set(array('teacher_id'=>$teacher,'state'=>'accepting','reason_code'=>'synthetic_provision'));
    $availability->setProfile(array('teacher_id'=>$teacher,'timezone'=>'UTC','status'=>'active','reason_code'=>'synthetic_provision'));
    for($weekday=1;$weekday<=7;$weekday++)$availability->setRecurringRule(array('teacher_id'=>$teacher,'timezone'=>'UTC','weekday'=>$weekday,'local_start_time'=>'00:00:00','local_end_time'=>'23:59:59','state'=>'requestable','status'=>'active','reason_code'=>'synthetic_provision'));
    (new TeachingEligibilityService())->setEligibility(array('teacher_id'=>$teacher,'course_id'=>$course,'status'=>'active','reason_code'=>'synthetic_provision'));
    $moved=$assignments->replace($id,$teacher,array('expected_assignment_id'=>(int)$assignment['assignment_id'],'route'=>'staff_attestation','evidence_channel'=>'phone','evidence_reference'=>'interim-'.$teacher,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_ic_key('isolate'));
    $student=(int)$wpdb->get_var($wpdb->prepare("SELECT student_id FROM {$p}enrolments WHERE id=%d",$id));
    return array('enrolment_id'=>$id,'term_id'=>(int)$term['term_id'],'assignment_id'=>(int)$moved['assignment_id'],'student_id'=>$student,'teacher_id'=>$teacher);
};
$occurrence=function(array $chain,string $label,int $durationMinutes,?string $wall=null,string $timezone='UTC') use($lessons,$schedules,$wpdb,$p):array{
    $lessonId=(int)$lessons->createStandard((int)$chain['term_id'],(int)$chain['assignment_id'],dzn_ic_evidence($label),dzn_ic_key($label))['lesson_id'];
    $wall??=gmdate('Y-m-d H:i:s',strtotime('+6 seconds'));
    $scheduled=$schedules->schedule($lessonId,(int)$chain['assignment_id'],array('schedule_timezone'=>$timezone,'local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11),'duration_minutes'=>$durationMinutes,'reason_code'=>'synthetic_schedule')+dzn_ic_evidence('schedule-'.$label),dzn_ic_key('schedule-'.$label));
    $version=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d",(int)$scheduled['schedule_version_id']));
    $schedules->release($lessonId,array('expected_schedule_version_id'=>(int)$version->id,'reason_code'=>'synthetic_release')+dzn_ic_evidence('release-'.$label),dzn_ic_key('release-'.$label));
    return array('lesson_id'=>$lessonId,'version_id'=>(int)$version->id,'start'=>(string)$version->starts_at_utc,'end'=>(string)$version->ends_at_utc);
};
$lessonState=static function(int $lessonId) use($wpdb,$p):string{return(string)$wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}lessons WHERE id=%d",$lessonId));};
$events=static function(int $lessonId) use($wpdb,$p):array{return$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_lifecycle_events WHERE lesson_id=%d ORDER BY event_sequence,id",$lessonId))?:array();};
$outcome=static function(int $lessonId) use($wpdb,$p){return$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d AND applicable_slot=1",$lessonId));};
$caseFor=static function(int $lessonId,int $versionId) use($wpdb,$p):?object{return$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_cases WHERE lesson_id=%d AND schedule_version_id=%d",$lessonId,$versionId));};
$user=static function(string $login,string $email,string $role='subscriber'):int{
    $id=wp_insert_user(array('user_login'=>$login,'user_pass'=>wp_generate_password(32,true,true),'user_email'=>$email,'role'=>$role));
    if(is_wp_error($id))throw new RuntimeException('Synthetic principal user creation failed');
    return (int)$id;
};
$principalLinks=static function(array $chain,int $studentUser,int $teacherUser) use($wpdb,$p):void{
    $now=gmdate('Y-m-d H:i:s');
    dzn_ic_assert($wpdb->insert($p.'teacher_principal_links',array('teacher_id'=>(int)$chain['teacher_id'],'wordpress_user_id'=>$teacherUser,'status'=>'active','linked_at'=>$now,'linked_by'=>1))!==false,'Teacher principal link fixture failed');
    dzn_ic_assert($wpdb->insert($p.'student_principal_links',array('student_id'=>(int)$chain['student_id'],'wordpress_user_id'=>$studentUser,'status'=>'active','link_sequence'=>1,'active_slot'=>1,'version'=>1,'verification_basis'=>'runtime_fixture','evidence_channel'=>'authenticated_platform','evidence_reference'=>'interim-runtime-'.$studentUser,'evidence_at'=>$now,'linked_at'=>$now,'linked_by'=>1,'updated_at'=>$now,'updated_by'=>1))!==false,'Student principal link fixture failed');
};

// One prospective cutover admits every claim below; occurrences start after it.
$intake->recordCutoverPolicy(gmdate('Y-m-d H:i:s',strtotime('+2 seconds')),dzn_ic_key('cutover'));
$chains=array();foreach(array('presumed','future','outcome','claim','reconcile','dispute','dst')as$label)$chains[$label]=$chain();
$startWall=gmdate('Y-m-d H:i:s',strtotime('+6 seconds'));
$futureWall=gmdate('Y-m-d H:i:s',strtotime('+1 hour'));
$occurrences=array();
foreach(array('presumed','outcome','claim','reconcile','dispute')as$label)$occurrences[$label]=$occurrence($chains[$label],$label,1,$startWall);
$occurrences['future']=$occurrence($chains['future'],'future',1,$futureWall);
// A real fall-back boundary: 2026-11-01 01:30 America/New_York spans 60 real minutes over the
// 02:00 local fall-back and must persist two hours of UTC difference.
$dstWall='2026-11-01 01:30:00';
$occurrences['dst']=$occurrence($chains['dst'],'dst',60,$dstWall,'America/New_York');
$dst=$occurrences['dst'];
dzn_ic_assert((strtotime($dst['end'].' UTC')-strtotime($dst['start'].' UTC'))===7200,'A fall-back DST occurrence must retain its real UTC duration');

// A future occurrence must be refused even when the worker is asked directly.
dzn_ic_rejected(fn()=>LessonCompletionAutomationWorker::runLesson((int)$occurrences['future']['lesson_id']),'occurrence_not_ended','a future occurrence');

// A recorded outcome exists before the automation pass, so it can never be presumed away.
$outcomeOccurrence=$occurrences['outcome'];
$delivered=$delivery->record((int)$outcomeOccurrence['lesson_id'],'authorised',array('outcome_code'=>'delivered','reason_code'=>'administrator_verified_outcome')+dzn_ic_evidence('verified-outcome'),dzn_ic_key('verified-outcome'));
dzn_ic_assert((int)$delivered['outcome_id']>0,'the verified outcome fixture must be recorded');

// Wait for every one-minute synthetic occurrence to end.
$latest=0;foreach(array('presumed','outcome','claim','reconcile','dispute')as$label)$latest=max($latest,strtotime($occurrences[$label]['end'].' UTC'));
while(time()<$latest+3)sleep(1);

// 1. Silence becomes presumed completion exactly once, with no Phase-O delivered attendance.
$first=LessonCompletionAutomationWorker::run();
dzn_ic_assert($first['presumed']>=4,'the worker must presume at least the four silence-only ended occurrences');
$presumed=$occurrences['presumed'];
dzn_ic_assert($lessonState((int)$presumed['lesson_id'])==='completed','an ended silence-only occurrence must become completed');
$presumedEvents=$events((int)$presumed['lesson_id']);
dzn_ic_assert(count($presumedEvents)===2&&(string)$presumedEvents[1]->reason_code===CanonicalLessonCompletionPolicy::REASON_PRESUMED,'presumed completion must append exactly one distinct lifecycle event');
dzn_ic_assert($outcome((int)$presumed['lesson_id'])===null,'presumed completion must not create a Phase-O delivered outcome');
$presumedRead=$deliveryRead->forLesson((int)$presumed['lesson_id']);
dzn_ic_assert($presumedRead['completion_basis']==='presumed'&&$presumedRead['attendance_verified']===false,'presumed completion must be reported as unverified attendance');

// Repeated worker runs must not duplicate lifecycle evidence.
$second=LessonCompletionAutomationWorker::run();
dzn_ic_assert($second['presumed']===0,'a repeated worker run must presume nothing');
dzn_ic_assert(count($events((int)$presumed['lesson_id']))===2,'a repeated worker run must not duplicate lifecycle events');
$exactReplay=LessonCompletionAutomationWorker::runLesson((int)$presumed['lesson_id']);
dzn_ic_assert(($exactReplay['state']??'')==='skipped'&&($exactReplay['reason']??'')==='stale_lesson_state','an exact worker replay must converge as a skip');

// 2. A delivered outcome prevents presumption.
$replayed=LessonCompletionAutomationWorker::runLesson((int)$outcomeOccurrence['lesson_id']);
dzn_ic_assert(($replayed['state']??'')==='skipped'&&($replayed['reason']??'')==='delivery_outcome_exists','a recorded outcome must block presumption');
dzn_ic_assert($lessonState((int)$outcomeOccurrence['lesson_id'])==='authorised','the worker must not complete a Lesson with a recorded outcome');

// 3. Presumed completion is pending in Finance, never automatically payable.
$presumedContext=array('outcome'=>null,'obligation'=>null,'finalised'=>true,'kind'=>'standard','state'=>'completed','completion_presumed'=>true);
$presumedDerivation=LessonPayabilityService::derive($presumedContext,array());
dzn_ic_assert($presumedDerivation['disposition']==='pending'&&$presumedDerivation['basis_code']==='presumed_completion_review','presumed completion must remain pending and statement-blocking');
$verifiedContext=$presumedContext;$verifiedContext['completion_presumed']=false;
$verifiedDerivation=LessonPayabilityService::derive($verifiedContext,array());
dzn_ic_assert($verifiedDerivation['disposition']==='payable','ordinary completed-without-outcome behaviour must remain intact');

// 4. An administrator can later confirm verified delivery without deleting history.
$confirmed=$delivery->record((int)$presumed['lesson_id'],'completed',array('outcome_code'=>'delivered','reason_code'=>'administrator_verified_outcome')+dzn_ic_evidence('late-delivery-confirmation'),dzn_ic_key('late-delivery-confirmation'));
$afterConfirmation=$deliveryRead->forLesson((int)$presumed['lesson_id']);
dzn_ic_assert($afterConfirmation['effective']&&(string)$afterConfirmation['effective']->outcome_code==='delivered','late verified delivery must become the effective outcome');
dzn_ic_assert($afterConfirmation['completion_basis']==='presumed','the historical presumed completion event must remain visible');
dzn_ic_assert($afterConfirmation['attendance_verified']===true,'verified delivery must become visible attendance');
dzn_ic_assert(count($events((int)$presumed['lesson_id']))===2,'confirmed delivery must not rewrite lifecycle history');

// 5. A late non-delivery correction preserves completion history and establishes academy debt.
$reconcileOccurrence=$occurrences['reconcile'];
$reconciled=$delivery->reconcile((int)$reconcileOccurrence['lesson_id'],array('outcome_code'=>'teacher_non_delivery','reason_code'=>'administrator_non_delivery')+dzn_ic_evidence('late-non-delivery'),dzn_ic_key('late-non-delivery'));
$reconciledOutcome=$outcome((int)$reconcileOccurrence['lesson_id']);
dzn_ic_assert($reconciledOutcome&&(string)$reconciledOutcome->outcome_code==='teacher_non_delivery','the late correction must become effective non-delivery');
dzn_ic_assert((int)$reconciledOutcome->reconciles_completion_event_id>0,'the correction must name the immutable completion event');
dzn_ic_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_academy_obligations WHERE source_lesson_id=%d AND state='owed'",(int)$reconcileOccurrence['lesson_id']))===1,'non-delivery must establish exactly one academy obligation');
dzn_ic_assert(count($events((int)$reconcileOccurrence['lesson_id']))===2,'non-delivery correction must not delete the completion event');

// 6. A disputed completion is pending, then resolves to verified delivery append-only.
$disputeOccurrence=$occurrences['dispute'];
$disputed=$delivery->dispute((int)$disputeOccurrence['lesson_id'],array('outcome_code'=>'review_required','reason_code'=>'late_report_dispute')+dzn_ic_evidence('late-dispute'),dzn_ic_key('late-dispute'));
$disputedOutcome=$outcome((int)$disputeOccurrence['lesson_id']);
dzn_ic_assert($disputedOutcome&&(string)$disputedOutcome->outcome_code==='review_required'&&(int)$disputedOutcome->reconciles_completion_event_id>0,'a dispute must contradict completion through append-only lineage');
$disputeDerivation=LessonPayabilityService::derive(array('outcome'=>$disputedOutcome,'obligation'=>null,'finalised'=>true,'kind'=>'standard','state'=>'completed','completion_presumed'=>true),array());
dzn_ic_assert($disputeDerivation['disposition']==='pending','a disputed completion must block payability');
$resolved=$delivery->correct((int)$disputeOccurrence['lesson_id'],(int)$disputedOutcome->id,array('outcome_code'=>'delivered','reason_code'=>'administrator_resolved_dispute')+dzn_ic_evidence('dispute-resolution'),dzn_ic_key('dispute-resolution'));
$resolvedOutcome=$outcome((int)$disputeOccurrence['lesson_id']);
dzn_ic_assert((string)$resolvedOutcome->outcome_code==='delivered'&&$resolvedOutcome->reconciles_completion_event_id===null,'resolving a dispute to delivered must clear the contradiction without deleting history');
dzn_ic_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d",(int)$disputeOccurrence['lesson_id']))===2,'dispute resolution must append, never rewrite');

// 7. Teacher and Student reports are idempotent and principal-scoped.
$studentUser=$user('interim-student-'.substr(wp_generate_uuid4(),0,8),'student-'.wp_generate_uuid4().'@dzn.invalid');
$teacherUser=$user('interim-teacher-'.substr(wp_generate_uuid4(),0,8),'teacher-'.wp_generate_uuid4().'@dzn.invalid');
$principalLinks($chains['claim'],$studentUser,$teacherUser);
$claimOccurrence=$occurrences['claim'];
wp_set_current_user($studentUser);
$studentInput=array('claim_kind'=>'attendance_claim','reason_code'=>'student_disputes_class','observed_at'=>$claimOccurrence['end'],'evidence_reference'=>'student-claim-fixture');
$studentFirst=$intake->submitClaim((int)$claimOccurrence['lesson_id'],(int)$claimOccurrence['version_id'],$studentInput,'student-claim-fixture');
$studentAgain=$intake->submitClaim((int)$claimOccurrence['lesson_id'],(int)$claimOccurrence['version_id'],$studentInput,'student-claim-fixture');
dzn_ic_assert(!empty($studentAgain['idempotent'])&&(int)$studentAgain['evidence_id']===(int)$studentFirst['evidence_id'],'a repeated Student report must converge on the first evidence');
$claimCase=$caseFor((int)$claimOccurrence['lesson_id'],(int)$claimOccurrence['version_id']);
dzn_ic_assert($claimCase&&(string)$claimCase->state==='ready_for_review','an unresolved Student report must stay visible to administrators');
wp_set_current_user($teacherUser);
$teacherInput=array('claim_kind'=>'delivery_claim','reason_code'=>'teacher_reports_delivered','observed_at'=>$claimOccurrence['start'],'evidence_reference'=>'teacher-claim-fixture');
$teacherFirst=$intake->submitClaim((int)$claimOccurrence['lesson_id'],(int)$claimOccurrence['version_id'],$teacherInput,'teacher-claim-fixture');
$teacherAgain=$intake->submitClaim((int)$claimOccurrence['lesson_id'],(int)$claimOccurrence['version_id'],$teacherInput,'teacher-claim-fixture');
dzn_ic_assert(!empty($teacherAgain['idempotent'])&&(int)$teacherAgain['evidence_id']===(int)$teacherFirst['evidence_id'],'a repeated Teacher report must converge on the first evidence');
$otherUser=$user('interim-other-'.substr(wp_generate_uuid4(),0,8),'other-'.wp_generate_uuid4().'@dzn.invalid');
wp_set_current_user($otherUser);
dzn_ic_rejected(fn()=>$intake->submitClaim((int)$claimOccurrence['lesson_id'],(int)$claimOccurrence['version_id'],$studentInput,'cross-user-claim'),'Unauthorized','a non-owning principal report');

// 8. A delayed report on a presumed completion is adjudicated into an append-only correction.
wp_set_current_user(1);
$lateCase=$caseFor((int)$claimOccurrence['lesson_id'],(int)$claimOccurrence['version_id']);
dzn_ic_assert($lateCase!==null,'the delayed report must leave a durable review case');
$lateCase=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_cases WHERE id=%d",(int)$lateCase->id));
$adjudicated=$intake->adjudicate((int)$lateCase->id,array('expected_case_version'=>(int)$lateCase->case_version,'adjudication'=>'teacher_non_delivery'),dzn_ic_key('late-adjudication'));
$lateOutcome=$outcome((int)$claimOccurrence['lesson_id']);
dzn_ic_assert($lateOutcome&&(string)$lateOutcome->outcome_code==='teacher_non_delivery','a delayed report must be able to correct a presumed completion');
dzn_ic_assert((int)$lateOutcome->reconciles_completion_event_id>0,'the late correction must preserve completion-event lineage');
dzn_ic_assert(count($events((int)$claimOccurrence['lesson_id']))===2,'the late correction must not delete the completion event');
dzn_ic_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_academy_obligations WHERE source_lesson_id=%d AND state='owed'",(int)$claimOccurrence['lesson_id']))===1,'a late non-delivery adjudication must establish academy debt');

// 9. DST: the canonical end is honoured and the guard is provider-independent at the boundary.
$dstLesson=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lessons WHERE id=%d",(int)$dst['lesson_id']));
$dstHistory=$events((int)$dst['lesson_id']);
(new CanonicalLessonCompletionPolicy())->assertPresumable((int)$dst['lesson_id'],$dstLesson,$dstHistory,'2026-11-01 08:00:00');
dzn_ic_rejected(fn()=>LessonCompletionAutomationWorker::runLesson((int)$dst['lesson_id']),'occurrence_not_ended','a DST-crossing future occurrence');

echo "Interim attendance and completion runtime passed\n";
