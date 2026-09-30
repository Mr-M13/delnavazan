<?php
/** Disposable Phase-P failure injection: every material write boundary must roll back completely. */
if(getenv('DZN_PHASE_2A2P_RUNTIME_TEST')!=='failure'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-P failure runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalAttendanceIntakeService,CanonicalAttendanceReadService,CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService,TeacherService,TeachingEligibilityService};

global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_pf_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_pf_key(string $label):string{return 'dzn-2a2pf-'.$label.'-'.wp_generate_uuid4();}
function dzn_pf_evidence(string $reference):array{return array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));}
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_pf_assert(is_array($fixture)&&count($fixture['sources']??array())>=1,'Phase-J production fixture required');
$lessons=new CanonicalLessonAuthorityService();$enrolments=new CanonicalEnrolmentLifecycleService();$terms=new CanonicalTermAuthorityService();
$assignments=new TeacherAssignmentService();$schedules=new CanonicalLessonScheduleService();$availability=new TeacherAvailabilityService();$accepting=new TeacherAcceptingStateService();
$intake=new CanonicalAttendanceIntakeService();$read=new CanonicalAttendanceReadService();
$enrolmentId=(int)$wpdb->get_var("SELECT e.id FROM {$p}enrolments e WHERE e.lifecycle_state='authorised' AND NOT EXISTS(SELECT 1 FROM {$p}terms t WHERE t.enrolment_id=e.id AND t.record_model='canonical_enrolment_term_v1') ORDER BY e.id LIMIT 1");
dzn_pf_assert($enrolmentId>0,'A disposable canonical Enrolment source is required');
$enrolments->activate($enrolmentId,'authorised',dzn_pf_evidence('activate'),dzn_pf_key('activate'));
$term=$terms->create($enrolmentId,null,null,dzn_pf_evidence('term'),dzn_pf_key('term'));
$terms->activate((int)$term['term_id'],'authorised',dzn_pf_evidence('term-active'),dzn_pf_key('term-active'));
$assignment=$assignments->assignInitial($enrolmentId,dzn_pf_key('assignment'));
$course=(int)$wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}enrolments WHERE id=%d",$enrolmentId));
$teacher=(int)(new TeacherService())->create(array('display_name'=>'Synthetic PF Teacher','email'=>'pf-'.wp_generate_uuid4().'@phase-2a2p.invalid'));
$accepting->set(array('teacher_id'=>$teacher,'state'=>'accepting','reason_code'=>'synthetic_provision'));
$availability->setProfile(array('teacher_id'=>$teacher,'timezone'=>'UTC','status'=>'active','reason_code'=>'synthetic_provision'));
for($weekday=1;$weekday<=7;$weekday++)$availability->setRecurringRule(array('teacher_id'=>$teacher,'timezone'=>'UTC','weekday'=>$weekday,'local_start_time'=>'00:00:00','local_end_time'=>'23:59:59','state'=>'requestable','status'=>'active','reason_code'=>'synthetic_provision'));
(new TeachingEligibilityService())->setEligibility(array('teacher_id'=>$teacher,'course_id'=>$course,'status'=>'active','reason_code'=>'synthetic_provision'));
$moved=$assignments->replace($enrolmentId,$teacher,array('expected_assignment_id'=>(int)$assignment['assignment_id'],'route'=>'staff_attestation','evidence_channel'=>'phone','evidence_reference'=>'isolated-'.$teacher,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_pf_key('isolate'));
$assignmentId=(int)$moved['assignment_id'];
$intake->recordCutoverPolicy(gmdate('Y-m-d H:i:s',strtotime('+2 seconds')),dzn_pf_key('cutover'));
$occurrence=function(string $label) use($lessons,$schedules,$wpdb,$p,$term,$assignmentId):array{
    $lessonId=(int)$lessons->createStandard((int)$term['term_id'],$assignmentId,dzn_pf_evidence($label),dzn_pf_key($label))['lesson_id'];
    $wall=gmdate('Y-m-d H:i:s',strtotime('+30 minutes'));
    $scheduled=$schedules->schedule($lessonId,$assignmentId,array('schedule_timezone'=>'UTC','local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11),'duration_minutes'=>30,'reason_code'=>'synthetic_schedule')+dzn_pf_evidence('s-'.$label),dzn_pf_key('s-'.$label));
    $version=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d",(int)$scheduled['schedule_version_id']));
    $schedules->release($lessonId,array('expected_schedule_version_id'=>(int)$version->id,'reason_code'=>'synthetic_release')+dzn_pf_evidence('r-'.$label),dzn_pf_key('r-'.$label));
    return array('lesson_id'=>$lessonId,'version_id'=>(int)$version->id);
};
$counts=function() use($wpdb,$p):array{return array(
    'cases'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_attendance_cases"),
    'evidence'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_attendance_evidence"),
    'decisions'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_attendance_decisions"),
    'commands'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_attendance_commands"),
);};
$outcomes=function() use($wpdb,$p):int{return(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_lesson_delivery_outcomes");};
$inject=static function(string $hook):void{add_action($hook,static function() use($hook):void{throw new RuntimeException('injected:'.$hook);});};
$clear=static function(string $hook):void{remove_all_actions($hook);};

// 1. Case-creation boundary.
$first=$occurrence('fail-case');
$before=$counts();$beforeOutcomes=$outcomes();
$inject('dzn_phase_2a2p_after_case_insert');
$caught=false;
try{$intake->submitClaim((int)$first['lesson_id'],(int)$first['version_id'],array('claim_kind'=>'review_request','reason_code'=>'case_failure','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'case-failure'),dzn_pf_key('case-failure'));}
catch(RuntimeException$e){$caught=$e->getMessage()==='injected:dzn_phase_2a2p_after_case_insert';}
$clear('dzn_phase_2a2p_after_case_insert');
dzn_pf_assert($caught,'case-creation failure injection was not observed');
$after=$counts();
dzn_pf_assert($after===$before,'case-creation failure left partial intake authority');
dzn_pf_assert($outcomes()===$beforeOutcomes,'case-creation failure changed canonical truth');

// 2. Evidence-persistence boundary.
$second=$occurrence('fail-evidence');
$intake->submitClaim((int)$second['lesson_id'],(int)$second['version_id'],array('claim_kind'=>'review_request','reason_code'=>'bootstrap','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'bootstrap'),dzn_pf_key('bootstrap'));
$before=$counts();
$inject('dzn_phase_2a2p_after_evidence_insert');
$caught=false;
try{$intake->submitClaim((int)$second['lesson_id'],(int)$second['version_id'],array('claim_kind'=>'advance_absence_claim','reason_code'=>'evidence_failure','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'evidence-failure'),dzn_pf_key('evidence-failure'));}
catch(RuntimeException$e){$caught=$e->getMessage()==='injected:dzn_phase_2a2p_after_evidence_insert';}
$clear('dzn_phase_2a2p_after_evidence_insert');
dzn_pf_assert($caught,'evidence-persistence failure injection was not observed');
$after=$counts();
dzn_pf_assert($after['evidence']===$before['evidence']&&$after['decisions']===$before['decisions']&&$after['commands']===$before['commands'],'evidence failure left orphan evidence or decisions');

// 3. Decision-persistence boundary.
$third=$occurrence('fail-decision');
$intake->submitClaim((int)$third['lesson_id'],(int)$third['version_id'],array('claim_kind'=>'review_request','reason_code'=>'bootstrap','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'bootstrap'),dzn_pf_key('bootstrap'));
$before=$counts();
$inject('dzn_phase_2a2p_after_decision_insert');
$caught=false;
try{$intake->submitClaim((int)$third['lesson_id'],(int)$third['version_id'],array('claim_kind'=>'attendance_claim','reason_code'=>'decision_failure','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'decision-failure'),dzn_pf_key('decision-failure'));}
catch(RuntimeException$e){$caught=$e->getMessage()==='injected:dzn_phase_2a2p_after_decision_insert';}
$clear('dzn_phase_2a2p_after_decision_insert');
dzn_pf_assert($caught,'decision-persistence failure injection was not observed');
$after=$counts();
dzn_pf_assert($after['decisions']===$before['decisions']&&$after['evidence']===$before['evidence'],'decision failure left a partial assessment');
$caseRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_cases WHERE lesson_id=%d",(int)$third['lesson_id']));
dzn_pf_assert($caseRow&&$read->forOccurrence((int)$third['lesson_id'],(int)$third['version_id'])['case']['case_id']===(int)$caseRow->id,'decision failure left the case unreadable');

// 4. Convergence: a clean retry after rollback succeeds without duplication, and an already-recorded
//    provider event replays idempotently instead of double-counting.
$retry=$intake->submitClaim((int)$third['lesson_id'],(int)$third['version_id'],array('claim_kind'=>'attendance_claim','reason_code'=>'decision_failure','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'decision-failure'),dzn_pf_key('decision-failure-retry'));
dzn_pf_assert(!empty($retry['case_id']),'clean retry after decision failure did not succeed');
$fourth=$occurrence('fail-replay');
$intake->submitClaim((int)$fourth['lesson_id'],(int)$fourth['version_id'],array('claim_kind'=>'review_request','reason_code'=>'bootstrap','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'bootstrap'),dzn_pf_key('bootstrap'));
$key=dzn_pf_key('replay');
$replayObserved=gmdate('Y-m-d H:i:s');
$firstPass=$intake->submitClaim((int)$fourth['lesson_id'],(int)$fourth['version_id'],array('claim_kind'=>'review_request','reason_code'=>'same_intent','observed_at'=>$replayObserved,'evidence_reference'=>'same-intent'),$key);
$secondPass=$intake->submitClaim((int)$fourth['lesson_id'],(int)$fourth['version_id'],array('claim_kind'=>'review_request','reason_code'=>'same_intent','observed_at'=>$replayObserved,'evidence_reference'=>'same-intent'),$key);
dzn_pf_assert(!empty($secondPass['idempotent'])&&(int)$secondPass['evidence_id']===(int)$firstPass['evidence_id'],'exact replay did not converge on the recorded evidence');
$conflict=false;
try{$intake->submitClaim((int)$fourth['lesson_id'],(int)$fourth['version_id'],array('claim_kind'=>'delivery_claim','reason_code'=>'changed_intent','observed_at'=>$replayObserved,'evidence_reference'=>'changed-intent'),$key);}catch(Throwable$e){$conflict=$e->getMessage()==='Idempotency conflict';}
dzn_pf_assert($conflict,'changed payload under the same command key did not fail closed');

// 5. P-4: a refused conflicting provider event must still leave a durable conflict receipt, and the
//    original immutable evidence must survive untouched when recovery is attempted.
$fifth=$occurrence('fail-conflict');
$intake->submitClaim((int)$fifth['lesson_id'],(int)$fifth['version_id'],array('claim_kind'=>'review_request','reason_code'=>'bootstrap','observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'bootstrap'),dzn_pf_key('bootstrap'));
$identity=new Delnavazan\Platform\Core\Application\CanonicalAttendanceIdentityService();
$lessonRow=$wpdb->get_row($wpdb->prepare("SELECT student_id,teacher_id FROM {$p}lessons WHERE id=%d",(int)$fifth['lesson_id']));
$identity->record(array('provider_code'=>'google_meet','provider_account_key'=>'pf-account','participant_role'=>'teacher','participant_id'=>(int)$lessonRow->teacher_id,'state'=>'verified','provenance_reference'=>'pf','evidence_reference'=>'pf'),dzn_pf_key('pf-map'));
$versionRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d",(int)$fifth['version_id']));
$observed=gmdate('Y-m-d H:i:s');
$providerIntent=array('provider_code'=>'google_meet','provider_account_key'=>'pf-account','provider_event_key'=>'pf-event','provider_payload_key'=>'pf-payload','participant_role'=>'teacher','join_at_utc'=>$versionRow->starts_at_utc,'leave_at_utc'=>gmdate('Y-m-d H:i:s',strtotime($versionRow->starts_at_utc.' UTC')+60),'observed_at'=>$observed,'provenance_reference'=>'pf-prov','evidence_reference'=>'pf-ref');
$intake->ingestProviderEvidence((int)$fifth['lesson_id'],(int)$fifth['version_id'],$providerIntent,dzn_pf_key('pf-ingest'));
$conflictCase=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_cases WHERE lesson_id=%d",(int)$fifth['lesson_id']));
$evidenceBefore=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_evidence WHERE case_id=%d",(int)$conflictCase->id));
$originalDigest=(string)$wpdb->get_var($wpdb->prepare("SELECT provider_payload_digest FROM {$p}canonical_attendance_evidence WHERE case_id=%d AND provider_event_key_digest IS NOT NULL LIMIT 1",(int)$conflictCase->id));
$changes=0;
try{$intake->ingestProviderEvidence((int)$fifth['lesson_id'],(int)$fifth['version_id'],array_merge($providerIntent,array('provider_payload_key'=>'pf-payload-changed','leave_at_utc'=>gmdate('Y-m-d H:i:s',strtotime($versionRow->starts_at_utc.' UTC')+120))),dzn_pf_key('pf-conflict'));}catch(Throwable$e){$changes=$e->getMessage()==='Idempotency conflict'?1:0;}
dzn_pf_assert($changes===1,'a changed provider payload under one durable event key did not fail closed');
dzn_pf_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_evidence WHERE case_id=%d",(int)$conflictCase->id))===$evidenceBefore,'a refused conflict mutated immutable evidence');
dzn_pf_assert($originalDigest===(string)$wpdb->get_var($wpdb->prepare("SELECT provider_payload_digest FROM {$p}canonical_attendance_evidence WHERE case_id=%d AND provider_event_key_digest IS NOT NULL LIMIT 1",(int)$conflictCase->id)),'a refused conflict overwrote the original provider payload digest');
dzn_pf_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_conflicts WHERE case_id=%d",(int)$conflictCase->id))>=1,'a refused conflict left no durable conflict receipt');
dzn_pf_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d",(int)$fifth['lesson_id']))===0,'a refused conflict created canonical truth');

echo "failure_injection_boundaries=3\nconvergence_replay=pass\nrollback_complete=pass\nno_false_success=pass\ndurable_payload_conflict=pass\nPhase 2A.2-P failure runtime passed\n";
