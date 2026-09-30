<?php
/** Disposable production-path Phase-Q post-intro continuation & slot reservation proof; synthetic local data only. */
if(getenv('DZN_PHASE_2A2Q_RUNTIME_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-Q runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalContinuationRule,CanonicalContinuationService,CanonicalContinuationReadService,CanonicalContinuationValidator,CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,LessonScheduleService,LessonService,StudentAcceptanceAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService,TeacherService,TeachingEligibilityService};

global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_q_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_q_key(string $label):string{return 'dzn-2a2q-'.$label.'-'.wp_generate_uuid4();}
function dzn_q_evidence(string $reference):array{return array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));}
function dzn_q_rejected(callable $call,string $expected,string $message):void{$caught=null;try{$call();}catch(Throwable$e){$caught=$e;}dzn_q_assert($caught!==null,$message.' was accepted');dzn_q_assert($caught->getMessage()===$expected,$message.' rejected with an unexpected error: '.$caught->getMessage());}
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_q_assert(is_array($fixture)&&count($fixture['sources']??array())>=6,'Phase-J production fixture required');
$sources=$fixture['sources'];
// Disposable harness: reset only Phase-Q continuation storage so every scenario's derived slot and
// capacity hold are deterministic for this run.
foreach(array('canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases')as$qTable)dzn_q_assert($wpdb->query("DELETE FROM {$p}{$qTable}")!==false,'Failed to reset disposable Phase Q storage: '.$qTable);
$svc=new CanonicalContinuationService();$read=new CanonicalContinuationReadService();
$lessonsSvc=new LessonService();$scheduleSvc=new LessonScheduleService();
$authority=new StudentAcceptanceAuthorityService();
$admin=1;$previous=get_current_user_id();
wp_set_current_user($admin);
/** A principal WordPress user for one fixture Student (adult authority already established by the fixture). */
$principalOf=static function(int $studentId) use($wpdb,$p):int{
    $id=(int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 LIMIT 1",$studentId));
    if($id<1)throw new RuntimeException('Phase-J principal fixture required');
    return $id;
};
/** Create a legacy introductory Lesson with one past authoritative occurrence. */
$introOf=function(int $index,string $label,int $daysAgo=3,bool $withSlot=true,int $slotOffsetDays=7,?string $explicitSlotWall=null) use($sources,$lessonsSvc,$scheduleSvc,$wpdb,$p,$svc,$admin):array{
    static $sequence=0;$sequence++;
    $src=$sources[$index%count($sources)];
    $lessonId=(int)$lessonsSvc->create(array('student_id'=>(int)$src['student_id'],'teacher_id'=>(int)$src['teacher_id'],'course_id'=>(int)$src['course_id'],'lesson_type'=>'introductory','status'=>'draft'));
    // Every scenario gets a distinct introductory wall-clock minute so its derived first regular
    // slot is unique unless the scenario deliberately copies another occurrence.
    $wall=gmdate('Y-m-d H:i:s',strtotime('-'.$daysAgo.' days')-($sequence*3600));
    (new LessonScheduleService())->initial($lessonId,array('schedule_timezone'=>'UTC','local_wall_date'=>substr($wall,0,10),'local_wall_time'=>substr($wall,11,8),'reason'=>$label));
    $occurrence=$wpdb->get_row($wpdb->prepare("SELECT v.* FROM {$p}lesson_schedule_versions v INNER JOIN {$p}lessons l ON l.id=v.lesson_id AND l.current_schedule_version_id=v.id WHERE v.lesson_id=%d AND v.superseded_at IS NULL",$lessonId));
    if(!$occurrence)throw new RuntimeException('Phase Q intro occurrence fixture missing');
    $slot=null;
    if($withSlot){
        // The first regular slot is an EXPLICIT authorised fact recorded by the administrator, never
        // derived from the one-off introduction.
        $slotWall=$explicitSlotWall??gmdate('Y-m-d H:i:s',strtotime($wall)+$slotOffsetDays*86400);
        $slot=$svc->recordFirstRegularSlot($lessonId,array('schedule_timezone'=>'UTC','local_wall_date'=>substr($slotWall,0,10),'local_wall_time'=>substr($slotWall,11,8),'authority_basis'=>'administrator_attestation','reason_code'=>'agreed_regular_slot','evidence_channel'=>'staff_record','evidence_reference'=>'slot-'.$label,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_q_key('slot-'.$label));
    }
    return array('lesson_id'=>$lessonId,'student_id'=>(int)$src['student_id'],'teacher_id'=>(int)$src['teacher_id'],'course_id'=>(int)$src['course_id'],'occurrence'=>$occurrence,'slot'=>$slot);
};
$caseOf=static function(int $lessonId) use($wpdb,$p):?object{return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_cases WHERE intro_lesson_id=%d",$lessonId));};
$reservationOf=static function(int $caseId) use($wpdb,$p):?object{return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_reservations WHERE continuation_case_id=%d",$caseId));};
$interventionCount=static function(int $caseId) use($wpdb,$p):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_interventions WHERE continuation_case_id=%d",$caseId));};
$decisionCount=static function(int $caseId) use($wpdb,$p):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_decisions WHERE continuation_case_id=%d",$caseId));};
$qCaseCount=static function() use($wpdb,$p):int{return(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_continuation_cases");};
$qReservationCount=static function() use($wpdb,$p):int{return(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_continuation_reservations");};
$termCount=static function() use($wpdb,$p):int{return(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}terms");};
$lessonCount=static function() use($wpdb,$p):int{return(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}lessons");};
$outcomeCount=static function() use($wpdb,$p):int{return(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_lesson_delivery_outcomes");};
$obligationCount=static function() use($wpdb,$p):int{return(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_academy_obligations");};

// ---------------------------------------------------------------------------
// 1. Student continues: one decision, one real capacity hold, derived slot + frozen expiry.
// ---------------------------------------------------------------------------
$a=$introOf(0,'continue');
$obligationBefore=$obligationCount();
$principalA=$principalOf((int)$a['student_id']);
$holdBefore=$qReservationCount();$termBefore=$termCount();$lessonBefore=$lessonCount();$outcomeBefore=$outcomeCount();
$slotA=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_slot_authorities WHERE intro_lesson_id=%d",(int)$a['lesson_id']));
dzn_q_assert($slotA&&(string)$slotA->authority_basis==='administrator_attestation','the fixture must record an explicit authoritative first-regular-slot fact');
$expectedExpiry=CanonicalContinuationRule::expiresAt((string)$a['occurrence']->ends_at_utc,(string)$slotA->starts_at_utc);
wp_set_current_user($principalA);
$continued=$svc->continueWithTeacher((int)$a['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'continue-a'),dzn_q_key('continue-a'));
wp_set_current_user($admin);
dzn_q_assert($continued['decision']==='continue_with_teacher','a continuing Student decision must be recorded');
dzn_q_assert((int)$continued['reservation']['reservation_id']>0&&$continued['reservation']['state']==='active','a continuing decision must hold the expected first regular slot');
dzn_q_assert($qReservationCount()===$holdBefore+1,'a continuing decision must create exactly one reservation');
dzn_q_assert($termCount()===$termBefore&&$outcomeCount()===$outcomeBefore,'Phase Q must not create a Term or delivery outcome');
$caseA=$caseOf((int)$a['lesson_id']);
$reservationA=$reservationOf((int)$caseA->id);
dzn_q_assert((int)$reservationA->slot_authority_id===(int)$slotA->id,'the hold must bind the exact authoritative slot record');
dzn_q_assert((string)$reservationA->starts_at_utc===(string)$slotA->starts_at_utc&&(string)$reservationA->ends_at_utc===(string)$slotA->ends_at_utc,'the hold must bind the authorised slot interval, not the introduction time');
dzn_q_assert((string)$reservationA->schedule_timezone===(string)$slotA->schedule_timezone&&(string)$reservationA->local_wall_time===(string)$slotA->local_wall_time,'the hold must preserve the authorised wall-clock/timezone provenance');
dzn_q_assert((string)$reservationA->expires_at===$expectedExpiry,'the hold expiry must be frozen at min(slot start, intro boundary + 6 days)');
dzn_q_assert($expectedExpiry<=(string)gmdate('Y-m-d H:i:s',strtotime((string)$a['occurrence']->ends_at_utc.' UTC')+6*86400),'the hold must never exceed six days after the introductory occurrence boundary');
$readA=$read->forIntroLesson((int)$a['lesson_id']);
dzn_q_assert($readA['case']['current_decision']==='continue_with_teacher'&&$readA['reservation']['capacity_effective']===true,'the protected read must expose the current decision and an effective hold');
dzn_q_assert($readA['case']['admin_action_required']===false&&$readA['reservation']['expires_at']===$expectedExpiry,'the protected read must expose the frozen expiry and no admin requirement');
// The hold is real capacity, not a Lesson schedule projection.
dzn_q_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d",(int)$a['lesson_id']))===0,'a temporary hold must never become a canonical Lesson schedule');
dzn_q_assert($lessonCount()>=$lessonBefore,'Phase Q must never delete Lessons');

// ---------------------------------------------------------------------------
// 2. Exact replay is idempotent; changed decision/context under the same key fails closed.
// ---------------------------------------------------------------------------
$b=$introOf(1,'replay');
$principalB=$principalOf((int)$b['student_id']);
$replayKey=dzn_q_key('replay');
wp_set_current_user($principalB);
$first=$svc->continueWithTeacher((int)$b['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'replay-1'),$replayKey);
$replay=$svc->continueWithTeacher((int)$b['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'replay-1'),$replayKey);
dzn_q_assert(!empty($replay['idempotent'])&&(int)$replay['decision_id']===(int)$first['decision_id'],'an exact continuation replay must converge on the recorded decision');
$caseB=$caseOf((int)$b['lesson_id']);
dzn_q_assert($decisionCount((int)$caseB->id)===1&&$qReservationCount()>=$holdBefore+2,'an exact replay must not append a second decision or reservation');
$changed=false;
try{$svc->continueWithTeacher((int)$b['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'replay-2'),$replayKey);}catch(\Throwable$e){$changed=$e->getMessage()==='Idempotency conflict';}
dzn_q_assert($changed,'the same command key with a changed evidence context must fail idempotency conflict');
$otherDecision=false;
try{$svc->stopContinuation((int)$b['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'replay-1'),$replayKey);}catch(\Throwable$e){$otherDecision=$e->getMessage()==='Idempotency conflict';}
dzn_q_assert($otherDecision,'the same command key reused for another decision must fail idempotency conflict');
wp_set_current_user($admin);

// ---------------------------------------------------------------------------
// 3. Alternative-Teacher and contact-me decisions require administrator intervention and hold nothing.
// ---------------------------------------------------------------------------
$interventionCases=array('different_teacher'=>array('reason'=>'student_requested_different_teacher','source'=>2),'contact_me'=>array('reason'=>'student_requested_contact','source'=>3));
foreach($interventionCases as$decision=>$spec){
    $reason=$spec['reason'];
    $o=$introOf((int)$spec['source'],'intervention-'.$decision);
    $principal=$principalOf((int)$o['student_id']);
    wp_set_current_user($principal);
    $result=$decision==='different_teacher'
        ?$svc->requestDifferentTeacher((int)$o['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'iw-'.$decision),dzn_q_key('iw-'.$decision))
        :$svc->requestContact((int)$o['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'iw-'.$decision),dzn_q_key('iw-'.$decision));
    wp_set_current_user($admin);
    dzn_q_assert($result['decision']===$decision&&(int)$result['intervention_id']>0,'a '.$decision.' decision must record an administrator intervention');
    $case=$caseOf((int)$o['lesson_id']);
    dzn_q_assert($reservationOf((int)$case->id)===null,'a non-continuing decision must hold no Teacher capacity');
    dzn_q_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_interventions WHERE continuation_case_id=%d AND reason_code=%s",(int)$case->id,$reason))===1,'the intervention must carry the controlled reason classification');
    $model=$read->forIntroLesson((int)$o['lesson_id']);
    dzn_q_assert($model['case']['admin_action_required']===true&&in_array($reason,$model['admin']['reason_codes'],true),'the protected read must expose the required administrator action');
}

// ---------------------------------------------------------------------------
// 4. Not continuing closes the immediate path; feedback is optional and controlled.
// ---------------------------------------------------------------------------
$d=$introOf(4,'not-continuing');
$principalD=$principalOf((int)$d['student_id']);
wp_set_current_user($principalD);
$stopped=$svc->stopContinuation((int)$d['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'not-continuing','feedback_reason'=>'schedule'),dzn_q_key('not-continuing'));
wp_set_current_user($admin);
dzn_q_assert($stopped['decision']==='not_continuing'&&$stopped['feedback_reason']==='schedule','optional controlled feedback must be retained when supplied');
dzn_q_assert((int)$stopped['intervention_id']===0&&$stopped['intervention_id']===null,'not_continuing must not require administrator intervention');
$caseD=$caseOf((int)$d['lesson_id']);
dzn_q_assert($reservationOf((int)$caseD->id)===null,'not_continuing must hold no capacity');
wp_set_current_user($principalD);
dzn_q_rejected(fn()=>$svc->continueWithTeacher((int)$d['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'after-stop'),dzn_q_key('after-stop')),'continuation_closed','a decision after not_continuing');
wp_set_current_user($admin);
$noFeedback=$introOf(5,'no-feedback');
$principalE=$principalOf((int)$noFeedback['student_id']);
wp_set_current_user($principalE);
$plain=$svc->stopContinuation((int)$noFeedback['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'no-feedback'),dzn_q_key('no-feedback'));
wp_set_current_user($admin);
dzn_q_assert($plain['feedback_reason']===null,'feedback must never be mandatory');

// ---------------------------------------------------------------------------
// 5. Teacher match exception: own principal only, releases capacity, suppresses ordinary continuation.
// ---------------------------------------------------------------------------
$e=$introOf(6,'teacher-unsuitable');
$principalF=$principalOf((int)$e['student_id']);
wp_set_current_user($principalF);
$svc->continueWithTeacher((int)$e['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'continue-e'),dzn_q_key('continue-e'));
wp_set_current_user($admin);
$caseE=$caseOf((int)$e['lesson_id']);
$heldReservation=$reservationOf((int)$caseE->id);
dzn_q_assert($heldReservation&&(string)$heldReservation->state==='active','the continuing decision must hold capacity before the Teacher exception');
$teacherUser=(int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}teacher_principal_links WHERE teacher_id=%d AND status='active' AND revoked_at IS NULL LIMIT 1",(int)$e['teacher_id']));
dzn_q_assert($teacherUser>0,'the introductory Teacher needs an authoritative principal');
(new \WP_User($teacherUser))->add_cap('dzn_submit_own_continuation_match_exception');
wp_set_current_user($principalF);
dzn_q_rejected(fn()=>$svc->markMatchNeedsAdmin((int)$e['lesson_id'],dzn_q_evidence('not-teacher'),dzn_q_key('not-teacher')),'Unauthorized','a Teacher exception from a non-Teacher principal');
wp_set_current_user($teacherUser);
$unsuitable=$svc->markMatchNeedsAdmin((int)$e['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'teacher-unsuitable'),dzn_q_key('teacher-unsuitable'));
wp_set_current_user($admin);
dzn_q_assert($unsuitable['decision']==='teacher_unsuitable'&&(int)$unsuitable['intervention_id']>0,'the Teacher exception must record an administrator intervention');
$released=$reservationOf((int)$caseE->id);
dzn_q_assert((string)$released->state==='released','a Teacher match exception must stop holding Teacher capacity');
wp_set_current_user($principalF);
dzn_q_rejected(fn()=>$svc->continueWithTeacher((int)$e['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'after-suppress'),dzn_q_key('after-suppress')),'teacher_match_suppressed','a Student continuation while the match is suppressed');
wp_set_current_user($admin);
wp_set_current_user($teacherUser);
dzn_q_rejected(fn()=>$svc->markMatchNeedsAdmin((int)$e['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'again'),dzn_q_key('again')),'teacher_match_already_suppressed','a repeated Teacher suppression');
wp_set_current_user($admin);

// ---------------------------------------------------------------------------
// 6. Student authority: no principal, no guardian, and a minor acting without a grant all fail closed.
// ---------------------------------------------------------------------------
$f=$introOf(7,'authority');
$principalG=$principalOf((int)$f['student_id']);
$outsider=wp_insert_user(array('user_login'=>'dzn-2a2q-outsider-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(32,true,true),'user_email'=>'outsider-'.wp_generate_uuid4().'@phase-2a2q.invalid','role'=>'subscriber'));
wp_set_current_user((int)$outsider);
dzn_q_rejected(fn()=>$svc->continueWithTeacher((int)$f['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'outsider'),dzn_q_key('outsider')),'Unauthorized','a continuation decision by a user with no Student authority');
wp_set_current_user($admin);
dzn_q_rejected(fn()=>$read->forIntroLesson((int)$f['lesson_id']),'continuation_case_required','a protected read before any continuation case exists');
wp_set_current_user((int)$outsider);
dzn_q_assert($qCaseCount()>=$qReservationCount()-1,'an unauthorized decision must not create a continuation case');
wp_set_current_user($admin);
dzn_q_rejected(fn()=>$read->forIntroLesson((int)$f['lesson_id']),'continuation_case_required','an unauthorized decision must leave no continuation case');
// Minor: a guardian representative with the service-acceptance scope acts for the Student.
$minorStudent=(int)$f['student_id'];
$guardianUser=(int)wp_insert_user(array('user_login'=>'dzn-2a2q-guardian-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(32,true,true),'user_email'=>'guardian-'.wp_generate_uuid4().'@phase-2a2q.invalid','role'=>'subscriber'));
$authority->classify($minorStudent,'minor','synthetic_fixture','synthetic_fixture',gmdate('Y-m-d H:i:s'),$admin);
wp_set_current_user($admin);
$grant=$authority->grantGuardian($minorStudent,$guardianUser,'synthetic_fixture','synthetic_fixture',gmdate('Y-m-d H:i:s'),null,$admin);
dzn_q_assert($grant>0,'a guardian representative grant must be establishable through the Phase-F authority');
wp_set_current_user($guardianUser);
$minorResult=$svc->requestContact((int)$f['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'guardian-contact'),dzn_q_key('guardian-contact'));
wp_set_current_user($admin);
dzn_q_assert($minorResult['decision']==='contact_me','a valid guardian representative must be able to make the Student continuation decision');
$caseF=$caseOf((int)$f['lesson_id']);
$guardianBasis=(string)$wpdb->get_var($wpdb->prepare("SELECT actor_basis FROM {$p}canonical_continuation_decisions WHERE continuation_case_id=%d ORDER BY decision_sequence LIMIT 1",(int)$caseF->id));
dzn_q_assert($guardianBasis==='guardian_representative','the guardian decision must be recorded on the guardian authority basis');
dzn_q_assert(CanonicalContinuationValidator::validForCase((int)$caseF->id),'the guardian continuation aggregate must validate');

// ---------------------------------------------------------------------------
// 7. Real capacity arbitration: an active hold blocks conflicting commitments only.
// ---------------------------------------------------------------------------
$g=$introOf(0,'capacity-hold');
$gSlot=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_slot_authorities WHERE intro_lesson_id=%d",(int)$g['lesson_id']));
dzn_q_assert($gSlot!==null,'the capacity fixture must hold an authorised slot');
$principalH=$principalOf((int)$g['student_id']);
wp_set_current_user($principalH);
$svc->continueWithTeacher((int)$g['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'capacity-hold'),dzn_q_key('capacity-hold'));
wp_set_current_user($admin);
$caseG=$caseOf((int)$g['lesson_id']);$holdG=$reservationOf((int)$caseG->id);
dzn_q_assert((string)$holdG->state==='active','the capacity fixture must hold an active reservation');
// A second Student continuing with the SAME Teacher at the SAME authorised slot must fail closed.
$h=$introOf(1,'capacity-conflict',3,true,7,(string)$gSlot->local_wall_date.' '.(string)$gSlot->local_wall_time);
$wpdb->update($p.'lessons',array('teacher_id'=>(int)$g['teacher_id']),array('id'=>(int)$h['lesson_id']));
$wpdb->update($p.'lesson_schedule_versions',array('starts_at_utc'=>(string)$g['occurrence']->starts_at_utc,'ends_at_utc'=>(string)$g['occurrence']->ends_at_utc,'local_wall_date'=>(string)$g['occurrence']->local_wall_date,'local_wall_time'=>(string)$g['occurrence']->local_wall_time),array('lesson_id'=>(int)$h['lesson_id']));
$principalI=$principalOf((int)$h['student_id']);
wp_set_current_user($principalI);
dzn_q_rejected(fn()=>$svc->continueWithTeacher((int)$h['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'capacity-conflict'),dzn_q_key('capacity-conflict')),'teacher_slot_conflict','a second hold on the same Teacher and exact slot');
wp_set_current_user($admin);
$hCase=$caseOf((int)$h['lesson_id']);
dzn_q_assert($hCase===null||$reservationOf((int)$hCase->id)===null,'a conflicting hold must leave no partial capacity hold');
// Canonical Lesson scheduling against the held interval must also fail closed (Phase-N parity).
$enrolments=new CanonicalEnrolmentLifecycleService();$terms=new CanonicalTermAuthorityService();$assignments=new TeacherAssignmentService();$lessonAuth=new CanonicalLessonAuthorityService();$canonicalSchedules=new CanonicalLessonScheduleService();
$availability=new TeacherAvailabilityService();$accepting=new TeacherAcceptingStateService();
$allocate=static function() use($wpdb,$p):int{$id=(int)$wpdb->get_var("SELECT e.id FROM {$p}enrolments e WHERE e.lifecycle_state='authorised' AND NOT EXISTS(SELECT 1 FROM {$p}terms t WHERE t.enrolment_id=e.id AND t.record_model='canonical_enrolment_term_v1') ORDER BY e.id LIMIT 1");if($id<1)throw new RuntimeException('no_available_source');return $id;};
$enrolmentId=$allocate();
$enrolments->activate($enrolmentId,'authorised',dzn_q_evidence('activate'),dzn_q_key('activate'));
$term=$terms->create($enrolmentId,null,null,dzn_q_evidence('term'),dzn_q_key('term'));
$terms->activate((int)$term['term_id'],'authorised',dzn_q_evidence('term-active'),dzn_q_key('term-active'));
$assignment=$assignments->assignInitial($enrolmentId,dzn_q_key('assignment'));
$courseId=(int)$wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}enrolments WHERE id=%d",$enrolmentId));
$holdingTeacher=(int)$wpdb->get_var($wpdb->prepare("SELECT teacher_id FROM {$p}lessons WHERE id=%d",(int)$g['lesson_id']));
(new TeachingEligibilityService())->setEligibility(array('teacher_id'=>$holdingTeacher,'course_id'=>$courseId,'status'=>'active','reason_code'=>'synthetic_provision'));
$accepting->set(array('teacher_id'=>$holdingTeacher,'state'=>'accepting','reason_code'=>'synthetic_provision'));
$availability->setProfile(array('teacher_id'=>$holdingTeacher,'timezone'=>'UTC','status'=>'active','reason_code'=>'synthetic_provision'));
for($weekday=1;$weekday<=7;$weekday++)$availability->setRecurringRule(array('teacher_id'=>$holdingTeacher,'timezone'=>'UTC','weekday'=>$weekday,'local_start_time'=>'00:00:00','local_end_time'=>'23:59:59','state'=>'requestable','status'=>'active','reason_code'=>'synthetic_provision'));
$moved=$assignments->replace($enrolmentId,$holdingTeacher,array('expected_assignment_id'=>(int)$assignment['assignment_id'],'route'=>'staff_attestation','evidence_channel'=>'phone','evidence_reference'=>'hold-parity','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_q_key('hold-parity'));
$lessonId=(int)$lessonAuth->createStandard((int)$term['term_id'],(int)$moved['assignment_id'],dzn_q_evidence('hold-parity'),dzn_q_key('hold-parity'))['lesson_id'];
dzn_q_rejected(fn()=>$canonicalSchedules->schedule($lessonId,(int)$moved['assignment_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr((string)$holdG->starts_at_utc,0,10),'local_wall_time'=>substr((string)$holdG->starts_at_utc,11,8),'duration_minutes'=>(int)$holdG->duration_minutes,'reason_code'=>'synthetic_hold_parity')+dzn_q_evidence('hold-parity-schedule'),dzn_q_key('hold-parity-schedule')),'teacher_slot_conflict','canonical Lesson scheduling against an active Phase-Q hold');
dzn_q_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d",$lessonId))===0,'a refused conflicting schedule must leave no partial schedule authority');

// ---------------------------------------------------------------------------
// 8. Expiry is frozen and lazy: an already-expired hold no longer blocks capacity.
// ---------------------------------------------------------------------------
$i=$introOf(2,'expired-hold',20);
$wpdb->update($p.'lessons',array('teacher_id'=>(int)$g['teacher_id']),array('id'=>(int)$i['lesson_id']));
$principalJ=$principalOf((int)$i['student_id']);
wp_set_current_user($principalJ);
$expired=$svc->continueWithTeacher((int)$i['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'expired-hold'),dzn_q_key('expired-hold'));
wp_set_current_user($admin);
dzn_q_assert($expired['reservation']['state']==='expired'&&$expired['reservation']['capacity_effective']===false,'a hold whose frozen expiry has already passed must be created expired and non-effective');
$expiredCase=$caseOf((int)$i['lesson_id']);
$expiredRow=$reservationOf((int)$expiredCase->id);
dzn_q_assert((string)$expiredRow->expires_at<(string)gmdate('Y-m-d H:i:s'),'the frozen expiry must not be recomputed from the current instant');
$expiredRead=$read->forIntroLesson((int)$i['lesson_id']);
dzn_q_assert($expiredRead['reservation']['state']==='expired'&&$expiredRead['reservation']['capacity_effective']===false,'the protected read must classify an expired hold as non-effective');
// The expired hold must not block a new commitment for the same Teacher on that interval.
$j=$introOf(3,'after-expiry',20);
$wpdb->update($p.'lessons',array('teacher_id'=>(int)$g['teacher_id']),array('id'=>(int)$j['lesson_id']));
$wpdb->update($p.'lesson_schedule_versions',array('starts_at_utc'=>(string)$i['occurrence']->starts_at_utc,'ends_at_utc'=>(string)$i['occurrence']->ends_at_utc),array('lesson_id'=>(int)$j['lesson_id']));
$principalK=$principalOf((int)$j['student_id']);
wp_set_current_user($principalK);
$afterExpiry=$svc->continueWithTeacher((int)$j['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'after-expiry'),dzn_q_key('after-expiry'));
wp_set_current_user($admin);
dzn_q_assert((int)$afterExpiry['reservation']['reservation_id']>0&&$afterExpiry['reservation']['state']==='expired','an expired hold must not block a later commitment for the same interval');

// ---------------------------------------------------------------------------
// 9. Absolute boundaries: no Term, no Lesson, no payment, no delivery truth, no notification.
// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// 9a. Q-R1-1: a Student may continue BEFORE the agreed slot exists; recording the authoritative slot
// later converges that same decision into the hold inside one transaction.
// ---------------------------------------------------------------------------
$delayed=$introOf(9,'delayed-slot',3,false);
$delayedPrincipal=$principalOf((int)$delayed['student_id']);
wp_set_current_user($delayedPrincipal);
$delayedContinue=$svc->continueWithTeacher((int)$delayed['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'delayed-continue'),dzn_q_key('delayed-continue'));
wp_set_current_user($admin);
$delayedCase=$caseOf((int)$delayed['lesson_id']);
dzn_q_assert((string)$delayedCase->current_decision==='continue_with_teacher'&&$delayedContinue['reservation']===null,'a continuing decision without an authorised slot must hold nothing');
dzn_q_assert($reservationOf((int)$delayedCase->id)===null&&$decisionCount((int)$delayedCase->id)===1,'the pre-slot decision must be the only decision and hold no capacity');
dzn_q_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_interventions WHERE continuation_case_id=%d AND reason_code='first_regular_slot_authority_required' AND state='required'",(int)$delayedCase->id))===1,'the missing-slot requirement must be current before convergence');
$delayedDecisionId=(int)$delayedCase->latest_decision_id;
$delayedWall=gmdate('Y-m-d H:i:s',time()+7200+9*3600);
$delayedSlotKey=dzn_q_key('delayed-slot');
$delayedSlot=$svc->recordFirstRegularSlot((int)$delayed['lesson_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr($delayedWall,0,10),'local_wall_time'=>substr($delayedWall,11,8),'authority_basis'=>'administrator_attestation','reason_code'=>'agreed_regular_slot','evidence_channel'=>'staff_record','evidence_reference'=>'delayed-slot','evidence_at'=>gmdate('Y-m-d H:i:s')),$delayedSlotKey);
dzn_q_assert($delayedSlot['converged']===true&&(int)$delayedSlot['case_id']===(int)$delayedCase->id,'recording the slot later must converge the existing case');
$delayedCaseAfter=$caseOf((int)$delayed['lesson_id']);
dzn_q_assert((int)$delayedCaseAfter->id===(int)$delayedCase->id&&(int)$delayedCaseAfter->latest_decision_id===$delayedDecisionId,'delayed convergence must preserve the original case and decision');
dzn_q_assert($decisionCount((int)$delayedCaseAfter->id)===1,'delayed convergence must never create a second Student decision');
dzn_q_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_slot_authorities WHERE intro_lesson_id=%d",(int)$delayed['lesson_id']))===1,'delayed convergence must record exactly one slot authority');
$delayedReservation=$reservationOf((int)$delayedCaseAfter->id);
dzn_q_assert($delayedReservation!==null&&(int)$delayedReservation->decision_id===$delayedDecisionId,'the converged reservation must reference the ORIGINAL continue decision');
$delayedSlotRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_continuation_slot_authorities WHERE intro_lesson_id=%d",(int)$delayed['lesson_id']));
dzn_q_assert(CanonicalContinuationRule::expiresAt((string)$delayed['occurrence']->ends_at_utc,(string)$delayedSlotRow->starts_at_utc)===(string)$delayedReservation->expires_at,'the converged hold must freeze the locked expiry rule');
dzn_q_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_interventions WHERE continuation_case_id=%d AND reason_code='first_regular_slot_authority_required' AND state='required'",(int)$delayedCaseAfter->id))===0,'the missing-slot requirement must no longer be current');
dzn_q_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_interventions WHERE continuation_case_id=%d AND reason_code='first_regular_slot_authority_required'",(int)$delayedCaseAfter->id))===1,'the historical requirement row must be retained');
$delayedRead=$read->forIntroLesson((int)$delayed['lesson_id']);
dzn_q_assert($delayedRead['reservation']['capacity_effective']===true&&$delayedRead['case']['admin_action_required']===false,'the converged read must show an effective hold and no current admin requirement');
dzn_q_assert((int)$delayedRead['reservation_decision_id']===$delayedDecisionId,'the protected read must expose the reservation provenance decision');
// Exact replay of the slot command converges idempotently without duplicating anything.
$delayedReplay=$svc->recordFirstRegularSlot((int)$delayed['lesson_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr($delayedWall,0,10),'local_wall_time'=>substr($delayedWall,11,8),'authority_basis'=>'administrator_attestation','reason_code'=>'agreed_regular_slot','evidence_channel'=>'staff_record','evidence_reference'=>'delayed-slot','evidence_at'=>gmdate('Y-m-d H:i:s')),$delayedSlotKey);
dzn_q_assert(!empty($delayedReplay['idempotent'])&&(int)$delayedReplay['slot_authority_id']===(int)$delayedSlot['slot_authority_id']&&(int)$delayedReplay['reservation_id']===(int)$delayedSlot['reservation_id'],'an exact slot replay must return the same authority and reservation idempotently');
$delayedChanged=false;
try{$svc->recordFirstRegularSlot((int)$delayed['lesson_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr(gmdate('Y-m-d H:i:s',time()+10800),0,10),'local_wall_time'=>substr(gmdate('Y-m-d H:i:s',time()+10800),11,8),'authority_basis'=>'administrator_attestation','reason_code'=>'agreed_regular_slot','evidence_channel'=>'staff_record','evidence_reference'=>'delayed-slot-changed','evidence_at'=>gmdate('Y-m-d H:i:s')),$delayedSlotKey);}catch(\Throwable$e){$delayedChanged=$e->getMessage()==='Idempotency conflict';}
dzn_q_assert($delayedChanged,'a changed slot under the same command key must fail idempotency conflict');
dzn_q_assert($decisionCount((int)$delayedCaseAfter->id)===1&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_reservations WHERE continuation_case_id=%d",(int)$delayedCaseAfter->id))===1,'replay must not duplicate decisions or reservations');
// Non-continuing decisions keep their history and never gain a hold from a later slot record.
$notConverging=$introOf(10,'no-converge',3,false);
$notConvergingPrincipal=$principalOf((int)$notConverging['student_id']);
wp_set_current_user($notConvergingPrincipal);
$svc->requestContact((int)$notConverging['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'no-converge'),dzn_q_key('no-converge'));
wp_set_current_user($admin);
$notConvergingCase=$caseOf((int)$notConverging['lesson_id']);
$notConvergingDecision=(int)$notConvergingCase->latest_decision_id;
$ncWall=gmdate('Y-m-d H:i:s',time()+7200+10*3600);
$ncResult=$svc->recordFirstRegularSlot((int)$notConverging['lesson_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr($ncWall,0,10),'local_wall_time'=>substr($ncWall,11,8),'authority_basis'=>'administrator_attestation','reason_code'=>'agreed_regular_slot','evidence_channel'=>'staff_record','evidence_reference'=>'no-converge-slot','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_q_key('no-converge-slot'));
$notConvergingCase=$caseOf((int)$notConverging['lesson_id']);
dzn_q_assert($ncResult['converged']===false&&(string)$notConvergingCase->current_decision==='contact_me','a non-continuing decision must never be reactivated by a later slot record');
dzn_q_assert($reservationOf((int)$notConvergingCase->id)===null&&(int)$notConvergingCase->latest_decision_id===$notConvergingDecision,'a non-continuing case must keep its decision history and hold nothing');
// Capacity conflict rolls back the whole convergence, including the slot authority.
$conflict=$introOf(11,'converge-conflict',3,false);
$conflictPrincipal=$principalOf((int)$conflict['student_id']);
wp_set_current_user($conflictPrincipal);
$svc->continueWithTeacher((int)$conflict['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'converge-conflict'),dzn_q_key('converge-conflict'));
wp_set_current_user($admin);
$conflictCase=$caseOf((int)$conflict['lesson_id']);
$conflictDecision=(int)$conflictCase->latest_decision_id;
$conflictWall=gmdate('Y-m-d H:i:s',time()+7200+11*3600);
$wpdb->query("DELETE FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=999999");
dzn_q_assert($wpdb->insert($p.'canonical_lesson_schedule_versions',array('uid'=>\Delnavazan\Platform\Core\Support\Identifier::uid(),'lesson_id'=>999999,'version_number'=>(int)(time()%100000)+1,'applicable_slot'=>1,'enrolment_id'=>0,'term_id'=>0,'teacher_assignment_id'=>0,'teacher_id'=>(int)$conflict['teacher_id'],'starts_at_utc'=>$conflictWall,'ends_at_utc'=>gmdate('Y-m-d H:i:s',strtotime($conflictWall.' UTC')+1800),'duration_minutes'=>30,'buffer_minutes'=>30,'duration_source'=>'course_default','occupied_ends_at_utc'=>gmdate('Y-m-d H:i:s',strtotime($conflictWall.' UTC')+3600),'schedule_timezone'=>'UTC','local_wall_date'=>substr($conflictWall,0,10),'local_wall_time'=>substr($conflictWall,11,8),'availability_basis'=>'within_availability','reason_code'=>'synthetic_converge_conflict','evidence_channel'=>'staff_record','evidence_reference_digest'=>str_repeat('b',64),'evidence_at'=>gmdate('Y-m-d H:i:s'),'created_at'=>gmdate('Y-m-d H:i:s'),'created_by'=>$admin))!==false,'the conflicting Phase-N schedule fixture must be inserted: '.$wpdb->last_error);
$conflictRejected=false;
try{$svc->recordFirstRegularSlot((int)$conflict['lesson_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr($conflictWall,0,10),'local_wall_time'=>substr($conflictWall,11,8),'authority_basis'=>'administrator_attestation','reason_code'=>'agreed_regular_slot','evidence_channel'=>'staff_record','evidence_reference'=>'converge-conflict-slot','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_q_key('converge-conflict-slot'));}catch(\Throwable$e){$conflictRejected=$e->getMessage()==='teacher_slot_conflict';}
dzn_q_assert($conflictRejected,'a capacity conflict must fail the delayed convergence');
dzn_q_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_slot_authorities WHERE intro_lesson_id=%d",(int)$conflict['lesson_id']))===0,'a failed convergence must roll back its slot authority');
dzn_q_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_commands WHERE intro_lesson_id=%d AND operation='record_first_regular_slot'",(int)$conflict['lesson_id']))===0,'a failed convergence must record no false command success');
$conflictCase=$caseOf((int)$conflict['lesson_id']);
dzn_q_assert((int)$conflictCase->latest_decision_id===$conflictDecision&&$decisionCount((int)$conflictCase->id)===1,'a failed convergence must not duplicate the Student decision');
dzn_q_assert($reservationOf((int)$conflictCase->id)===null,'a failed convergence must leave no reservation');
dzn_q_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_interventions WHERE continuation_case_id=%d AND reason_code='first_regular_slot_authority_required' AND state='required'",(int)$conflictCase->id))===1,'a failed convergence must leave the requirement current');
// ---------------------------------------------------------------------------
// 9b. Q-1: capacity may only be held against an EXPLICIT authoritative slot, never a +7-day guess.
// ---------------------------------------------------------------------------
$introEnd=static function(array $o):string{return (string)$o['occurrence']->ends_at_utc;};
$slotCases=array(
    'before_six_day_limit'=>array('offsetDays'=>2,'expect'=>'slot'),
    'exactly_six_day_limit'=>array('offsetDays'=>6,'expect'=>'equal'),
    'after_six_day_limit'=>array('offsetDays'=>10,'expect'=>'six_day'),
);
foreach($slotCases as$caseLabel=>$spec){
    $o=$introOf(2,$caseLabel,3,false);
    $authorisedWall=gmdate('Y-m-d H:i:s',strtotime($introEnd($o).' UTC')+((int)$spec['offsetDays'])*86400);
    $svc->recordFirstRegularSlot((int)$o['lesson_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr($authorisedWall,0,10),'local_wall_time'=>substr($authorisedWall,11,8),'authority_basis'=>'administrator_attestation','reason_code'=>'agreed_regular_slot','evidence_channel'=>'staff_record','evidence_reference'=>'boundary-'.$caseLabel,'evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_q_key('boundary-slot-'.$caseLabel));
    $principal=$principalOf((int)$o['student_id']);
    wp_set_current_user($principal);
    $result=$svc->continueWithTeacher((int)$o['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'boundary-'.$caseLabel),dzn_q_key('boundary-'.$caseLabel));
    wp_set_current_user($admin);
    $case=$caseOf((int)$o['lesson_id']);
    $reservation=$reservationOf((int)$case->id);
    $sixDay=gmdate('Y-m-d H:i:s',strtotime($introEnd($o).' UTC')+6*86400);
    $expected=match($spec['expect']){
        'slot'=>$authorisedWall,
        'equal'=>$authorisedWall,
        'six_day'=>$sixDay,
    };
    dzn_q_assert((string)$reservation->starts_at_utc===$authorisedWall,'the hold must bind the explicitly authorised slot, not a derived one ('.$caseLabel.')');
    dzn_q_assert((string)$reservation->expires_at===$expected,'the frozen expiry must follow the earlier of slot and six-day bound ('.$caseLabel.')');
    dzn_q_assert($caseLabel!=='exactly_six_day_limit'||(string)$reservation->expires_at===$authorisedWall,'an exactly-equal slot and six-day bound must agree ('.$caseLabel.')');
}
// The introduction time itself never authorises a slot: continue without a slot record holds nothing.
$noSlot=$introOf(3,'no-slot-authority',3,false);
$principalNoSlot=$principalOf((int)$noSlot['student_id']);
wp_set_current_user($principalNoSlot);
$noSlotResult=$svc->continueWithTeacher((int)$noSlot['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'no-slot'),dzn_q_key('no-slot'));
wp_set_current_user($admin);
dzn_q_assert($noSlotResult['decision']==='continue_with_teacher'&&$noSlotResult['reservation']===null,'a continuing decision without an authoritative slot must hold no capacity');
dzn_q_assert((int)$noSlotResult['intervention_id']>0,'a missing slot authority must raise an explicit administrator requirement');
$noSlotCase=$caseOf((int)$noSlot['lesson_id']);
dzn_q_assert($reservationOf((int)$noSlotCase->id)===null,'no reservation may exist without an authoritative slot');
dzn_q_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_continuation_interventions WHERE continuation_case_id=%d AND reason_code='first_regular_slot_authority_required'",(int)$noSlotCase->id))===1,'the missing-slot intervention must carry its controlled reason');
$noSlotRead=$read->forIntroLesson((int)$noSlot['lesson_id']);
dzn_q_assert($noSlotRead['case']['admin_action_required']===true&&$noSlotRead['reservation']===null,'the protected read must expose the missing slot authority without inventing a hold');
// An explicit slot that is not the introduction day/time is honoured exactly (proof of no derivation).
$explicit=$introOf(4,'explicit-slot',3,false);
$explicitWall=gmdate('Y-m-d H:i:s',strtotime($introEnd($explicit).' UTC')+3*86400+7200);
$explicitSlot=$svc->recordFirstRegularSlot((int)$explicit['lesson_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr($explicitWall,0,10),'local_wall_time'=>substr($explicitWall,11,8),'authority_basis'=>'administrator_attestation','reason_code'=>'rescheduled_agreement','evidence_channel'=>'phone','evidence_reference'=>'explicit-slot','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_q_key('explicit-slot'));
wp_set_current_user($principalOf((int)$explicit['student_id']));
$explicitResult=$svc->continueWithTeacher((int)$explicit['lesson_id'],array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'explicit-slot'),dzn_q_key('explicit-slot-continue'));
wp_set_current_user($admin);
dzn_q_assert((string)$explicitResult['reservation']['starts_at_utc']===(string)$explicitSlot['starts_at_utc'],'the hold must honour the explicitly authorised slot exactly');
$derivedGuess=gmdate('Y-m-d H:i:s',strtotime((string)$explicit['occurrence']->starts_at_utc.' UTC')+7*86400);
dzn_q_assert($explicitResult['reservation']['starts_at_utc']!==$derivedGuess,'the hold must never be a +7-day derivation of the introduction time');
// DST boundaries: a nonexistent and an ambiguous local wall clock must both fail closed.
$dst=$introOf(5,'dst',3,false);
dzn_q_rejected(fn()=>$svc->recordFirstRegularSlot((int)$dst['lesson_id'],array('schedule_timezone'=>'Australia/Sydney','local_wall_date'=>'2026-10-04','local_wall_time'=>'02:30:00','evidence_channel'=>'staff_record','evidence_reference'=>'dst-gap','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_q_key('dst-gap')),'continuation_slot_wall_clock_invalid','a nonexistent local wall clock (DST gap)');
dzn_q_rejected(fn()=>$svc->recordFirstRegularSlot((int)$dst['lesson_id'],array('schedule_timezone'=>'Australia/Sydney','local_wall_date'=>'2026-04-05','local_wall_time'=>'02:30:00','evidence_channel'=>'staff_record','evidence_reference'=>'dst-ambiguous','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_q_key('dst-ambiguous')),'continuation_slot_wall_clock_ambiguous','an ambiguous local wall clock (DST repeat)');
dzn_q_rejected(fn()=>$svc->recordFirstRegularSlot((int)$dst['lesson_id'],array('schedule_timezone'=>'UTC','local_wall_date'=>substr(gmdate('Y-m-d H:i:s',strtotime((string)$dst['occurrence']->starts_at_utc.' UTC')),0,10),'local_wall_time'=>substr((string)$dst['occurrence']->starts_at_utc,11,8),'evidence_channel'=>'staff_record','evidence_reference'=>'dst-past','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_q_key('dst-past')),'first_regular_slot_not_after_introduction','a slot that is not after the introduction');

dzn_q_assert($termCount()===$termBefore+1,'Phase Q must create no Term beyond the fixture chain used for capacity parity');
dzn_q_assert($outcomeCount()===$outcomeBefore,'Phase Q must create no canonical delivery/attendance outcome');
dzn_q_assert($obligationCount()===$obligationBefore,'Phase Q must create no academy obligation');
dzn_q_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_continuation_cases WHERE current_decision NOT IN ('continue_with_teacher','different_teacher','contact_me','not_continuing','teacher_unsuitable')")===0,'every case decision must be a controlled value');
dzn_q_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_continuation_commands WHERE result_state<>'recorded'")===0,'every Phase Q command must record its durable state');

wp_set_current_user($previous);
echo "continue_hold=pass\nexact_replay=pass\nadmin_intervention=pass\nstop_continuation=pass\nteacher_match_exception=pass\nstudent_authority=pass\ncapacity_arbitration=pass\nfrozen_expiry=pass\nboundaries=pass\nPhase 2A.2-Q authority runtime passed\n";
