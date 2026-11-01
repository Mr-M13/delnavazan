<?php
/**
 * Disposable production-path proof for the missed-lesson replacement lifecycle.
 *
 * Runs after the interim attendance runtime has produced a presumed-completed occurrence, its
 * canonical schedule version and a valid prospective attendance policy. It proves:
 *  - the completed original is refused by the reschedule path but accepted by replacement;
 *  - Student acceptance issues a linked replacement Lesson and schedule;
 *  - the original Lesson, schedule version, attendance case and evidence are untouched;
 *  - the accepted proposal is the provenance authority for the replacement;
 *  - a second replacement for the same origin is refused.
 *
 * Synthetic local data only.
 */
if(getenv('DZN_LESSON_REPLACEMENT_RUNTIME')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Lesson replacement runtime refused.\n");exit(1);}

use Delnavazan\Platform\Core\Application\{CanonicalAttendanceIntakeService,CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonAuthorityValidator,CanonicalLessonScheduleService,CanonicalTeacherOccupancyReadService,CanonicalTermAuthorityService,LessonRescheduleService,ProviderIntegrationRule,ProviderIntegrationService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService,TeacherService,TeachingEligibilityService};
use Delnavazan\Platform\Integrations\{ContractProviderAdapters,LessonCompletionAutomationWorker,LessonRescheduleCalendarService};
use Delnavazan\Platform\Portals\PortalAuthenticatedReadService;

global $wpdb;
$p=$wpdb->prefix.'dzn_';
function dzn_rr_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Lesson replacement runtime failed: '.$message);}
wp_set_current_user(1);

function dzn_rr_evidence(string $reference):array{return array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));}
function dzn_rr_key(string $label):string{return 'dzn-replacement-'.$label.'-'.wp_generate_uuid4();}
function dzn_rr_principal(int $entityId,string $kind):int{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    $table=$kind==='teacher'?'teacher_principal_links':'student_principal_links';
    $column=$kind==='teacher'?'teacher_id':'student_id';
    $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}{$table} WHERE {$column}=%d AND status='active' AND revoked_at IS NULL".($kind==='student'?' AND active_slot=1':'')." LIMIT 1",$entityId));
    if($existing>0)return $existing;
    $userId=wp_insert_user(array('user_login'=>$kind.'-replacement-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(32,true,true),'user_email'=>$kind.'-replacement-'.wp_generate_uuid4().'@dzn.invalid','role'=>'subscriber'));
    if(is_wp_error($userId))throw new RuntimeException('replacement principal creation failed');
    $now=gmdate('Y-m-d H:i:s');
    if($kind==='teacher'){
        if($wpdb->insert($p.'teacher_principal_links',array('teacher_id'=>$entityId,'wordpress_user_id'=>(int)$userId,'status'=>'active','linked_at'=>$now,'linked_by'=>1))===false)throw new RuntimeException('teacher principal link failed');
    }else{
        if($wpdb->insert($p.'student_principal_links',array('student_id'=>$entityId,'wordpress_user_id'=>(int)$userId,'status'=>'active','link_sequence'=>1,'active_slot'=>1,'version'=>1,'verification_basis'=>'runtime_fixture','evidence_channel'=>'authenticated_platform','evidence_reference'=>'replacement-runtime-'.(int)$userId,'evidence_at'=>$now,'linked_at'=>$now,'linked_by'=>1,'updated_at'=>$now,'updated_by'=>1))===false)throw new RuntimeException('student principal link failed');
    }
    return (int)$userId;
}

// Use a presumed-completed canonical occurrence produced by the attendance runtime. Its
// disposable delivery/attendance projections are cleared so it represents silence + a later
// Student missed-class report, not a delivered class.
$origin=$wpdb->get_row(
    "SELECT l.id,l.uid lesson_uid,l.term_id,l.enrolment_id,l.student_id,l.teacher_id,l.teacher_assignment_id,v.id version_id,v.uid version_uid,v.starts_at_utc,v.ends_at_utc
     FROM {$p}lessons l
     INNER JOIN {$p}terms t ON t.id=l.term_id AND t.lifecycle_state='current' AND t.applicable_slot=1 AND t.archived_at IS NULL
     INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
     WHERE l.record_model='canonical_term_lesson_v1' AND l.lesson_type='standard' AND l.lifecycle_state='completed' AND l.archived_at IS NULL
       AND EXISTS (SELECT 1 FROM {$p}canonical_lesson_lifecycle_events ev WHERE ev.lesson_id=l.id AND ev.to_state='completed' AND ev.reason_code='canonical_lesson_presumed_completed')
       AND EXISTS (SELECT 1 FROM {$p}teacher_availability_rules r WHERE r.teacher_id=l.teacher_id AND r.state='requestable' AND r.status='active' AND r.local_start_time='00:00:00' AND r.local_end_time='23:59:59')
       AND NOT EXISTS (SELECT 1 FROM {$p}lessons r WHERE r.canonical_replacement_origin_lesson_id=l.id)
       AND (SELECT COUNT(*) FROM {$p}lessons r WHERE r.term_id=l.term_id AND r.lesson_type='replacement' AND r.archived_at IS NULL) < t.replacement_allowance
     ORDER BY l.id DESC LIMIT 1"
);
dzn_rr_assert($origin instanceof stdClass,'a presumed-completed canonical occurrence is required; run the attendance runtime first');
$originId=(int)$origin->id;
$caseIds=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}canonical_attendance_cases WHERE lesson_id=%d",$originId))?:array();
foreach($caseIds as$caseId){
    foreach(array('canonical_attendance_conflicts','canonical_attendance_case_anomalies','canonical_attendance_decisions','canonical_attendance_commands','canonical_attendance_evidence')as$table)$wpdb->delete($p.$table,array('case_id'=>(int)$caseId));
    $wpdb->delete($p.'canonical_attendance_cases',array('id'=>(int)$caseId));
}
$wpdb->delete($p.'canonical_academy_obligations',array('source_lesson_id'=>$originId));
$wpdb->delete($p.'canonical_lesson_delivery_commands',array('lesson_id'=>$originId));
$wpdb->delete($p.'canonical_lesson_delivery_outcomes',array('lesson_id'=>$originId));
$before=$wpdb->get_row($wpdb->prepare("SELECT lifecycle_state,current_schedule_version_id FROM {$p}lessons WHERE id=%d",$originId));
$beforeEvents=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_lifecycle_events WHERE lesson_id=%d",$originId));
$beforeVersion=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d",(int)$origin->version_id));
dzn_rr_assert($before instanceof stdClass&&(string)$before->lifecycle_state==='completed','the origin must remain completed');

// Report the missed class after presumed completion. Attendance remains evidence only.
$intake=new CanonicalAttendanceIntakeService();
$claim=$intake->submitClaim($originId,(int)$origin->version_id,array(
    'claim_kind'=>'attendance_claim','reason_code'=>'student_reports_missed_class',
    'observed_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'replacement-runtime:'.$originId,
),'replacement-runtime-claim-'.wp_generate_uuid4());
dzn_rr_assert((int)$claim['case_id']>0,'the missed-class report must create attendance evidence');
$caseBefore=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_cases WHERE id=%d",(int)$claim['case_id']));
$evidenceBefore=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_evidence WHERE case_id=%d",(int)$claim['case_id']));

// Least-privilege replacement issuer.
$systemUserId=wp_insert_user(array('user_login'=>'replacement-issuer-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(32,true,true),'user_email'=>'replacement-issuer-'.wp_generate_uuid4().'@dzn.invalid','role'=>'subscriber'));
dzn_rr_assert(!is_wp_error($systemUserId),'the replacement system user must be created');
$systemUser=get_user_by('id',(int)$systemUserId);
$systemUser->add_cap('dzn_manage_canonical_lessons');
$systemUser->add_cap('dzn_manage_canonical_lesson_schedules');
update_option('dzn_replacement_system_user_id',(int)$systemUserId,false);
update_option('dzn_reschedule_system_user_id',(int)$systemUserId,false);

// One free future slot for both Student and Teacher.
$conflicts=new Delnavazan\Platform\Core\Infrastructure\Repository\LessonRescheduleRepository();
$occupancy=new CanonicalTeacherOccupancyReadService();
$slot=null;
for($offset=10;$offset<25;$offset++){
    $timestamp=strtotime('+'.$offset.' days 04:00:00 UTC');
    if($timestamp===false)continue;
    $start=gmdate('Y-m-d H:i:s',$timestamp);
    $end=gmdate('Y-m-d H:i:s',$timestamp+3600);
    if($conflicts->studentConflict((int)$origin->student_id,$start,$end,$originId))continue;
    if($occupancy->overlapping((int)$origin->teacher_id,$start,$end,$originId))continue;
    $slot=array('start'=>$start,'date'=>gmdate('Y-m-d',$timestamp),'time'=>gmdate('H:i',$timestamp));
    break;
}
dzn_rr_assert(is_array($slot),'a conflict-free replacement slot is required');

$teacherUserId=dzn_rr_principal((int)$origin->teacher_id,'teacher');
$studentUserId=dzn_rr_principal((int)$origin->student_id,'student');
$teacherPrincipal=get_user_by('id',$teacherUserId);
if($teacherPrincipal instanceof WP_User)$teacherPrincipal->add_cap('dzn_view_own_portal_schedule');
// The authenticated teacher read requires the Teacher profile and availability profile to agree.
$wpdb->update($p.'teachers',array('timezone'=>'UTC'),array('id'=>(int)$origin->teacher_id));

$service=new LessonRescheduleService(null,null,null,null,static fn(int $teacherId,string $starts,string $ends):bool=>true);

wp_set_current_user($studentUserId);
$studentBefore=(new PortalAuthenticatedReadService())->student();
dzn_rr_assert(empty(array_filter($studentBefore['reschedule_proposals'],static fn($row)=>(string)($row['lesson_uid']??'')===(string)$origin->lesson_uid)),'the Student must not see a proposal before the Teacher sends one');
// The reported absence allocates one slot before any replacement Lesson exists.
$originEnrolmentUid=(string)$wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}enrolments WHERE id=%d LIMIT 1",(int)$origin->enrolment_id));
$beforeEnrolment=null;foreach($studentBefore['enrolments'] as$row)if((string)($row['enrolment_uid']??'')===$originEnrolmentUid)$beforeEnrolment=$row;
dzn_rr_assert($beforeEnrolment!==null,'the Term projection must be available before acceptance');
dzn_rr_assert((int)$beforeEnrolment['replacement_pending_allocated']>=1,'a pending eligible absence must consume a visible slot');
$beforeExpectedAvailable=max(0,(int)$beforeEnrolment['replacement_allowance']-(int)$beforeEnrolment['replacement_used']-(int)$beforeEnrolment['replacement_pending_allocated']);
dzn_rr_assert((int)$beforeEnrolment['replacement_available']===$beforeExpectedAvailable,'the truly available count must exclude used and pending allocations');

wp_set_current_user($teacherUserId);
$proposal=$service->propose((string)$origin->lesson_uid,(string)$origin->version_uid,array(
    'timezone'=>'UTC','calendar'=>'gregorian','local_date'=>gmdate('Y-m-d',strtotime($slot['start'].' UTC')),'local_time'=>gmdate('H:i',strtotime($slot['start'].' UTC')),
),'replacement-runtime-request-'.wp_generate_uuid4());
dzn_rr_assert((string)$proposal['state']==='pending'&&($proposal['kind']??'')==='replacement','a completed occurrence must create a replacement proposal through the authenticated Teacher path');

wp_set_current_user($studentUserId);
$studentPending=(new PortalAuthenticatedReadService())->student();
$pendingRows=array_values(array_filter($studentPending['reschedule_proposals'],static fn($row)=>(string)($row['lesson_uid']??'')===(string)$origin->lesson_uid));
dzn_rr_assert(count($pendingRows)===1,'the Student portal must show one pending replacement proposal');
dzn_rr_assert((string)$pendingRows[0]['kind']==='replacement','the Student portal must label the proposal as a replacement');
dzn_rr_assert((string)$pendingRows[0]['original_starts_at_utc']===gmdate('Y-m-d H:i:s',strtotime((string)$origin->starts_at_utc.' UTC'))||(string)$pendingRows[0]['original_starts_at_utc']!=='','the Student portal must expose the original occurrence');
dzn_rr_assert((string)$pendingRows[0]['starts_at_utc']===(string)$slot['start'],'the Student portal must expose the proposed occurrence');
if(!function_exists('dzn_theme_platform_student_reschedule_proposals')){
    $themeFile=dirname(__DIR__,2).'/theme/inc/platform-bridge.php';
    if(is_readable($themeFile)){try{require_once $themeFile;}catch(Throwable $e){}}
}
if(function_exists('dzn_theme_platform_student_reschedule_proposals')){
    $presented=dzn_theme_platform_student_reschedule_proposals($studentPending['reschedule_proposals'],'UTC','gregorian','en_US');
    dzn_rr_assert(count($presented)===1&&($presented[0]['kind_label']??'')==='جلسهٔ جبرانی','the themed Student portal must label the replacement');
    dzn_rr_assert(($presented[0]['original_date']??'')!==''&&($presented[0]['date']??'')!==''&&($presented[0]['time']??'')!=='','the themed Student portal must show original and proposed dates');
}

$decisionKey='replacement-runtime-decision-'.wp_generate_uuid4();
$accepted=$service->decide((string)$proposal['proposal_uid'],'accept',$decisionKey);
dzn_rr_assert((string)$accepted['state']==='accepted','the Student must be able to accept the replacement through the authenticated path');
$replay=$service->decide((string)$proposal['proposal_uid'],'accept',$decisionKey);
dzn_rr_assert(!empty($replay['idempotent']),'a repeated acceptance with the same decision key must be idempotent');
$threw=null;try{$service->decide((string)$proposal['proposal_uid'],'reject','replacement-runtime-competing-'.wp_generate_uuid4());}catch(Throwable $e){$threw=$e;}
dzn_rr_assert($threw!==null&&$threw->getMessage()==='reschedule_proposal_already_decided','a competing decision cannot move an accepted replacement');
$newLessonId=(int)$wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$p}lessons WHERE canonical_replacement_origin_lesson_id=%d AND lesson_type='replacement' AND archived_at IS NULL LIMIT 1",
    $originId
));
dzn_rr_assert($newLessonId>0,'acceptance must issue a linked replacement Lesson');
$newLesson=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lessons WHERE id=%d",$newLessonId));
dzn_rr_assert((string)$newLesson->lesson_type==='replacement'&&(string)$newLesson->lifecycle_state==='authorised','the replacement must be an authorised canonical Lesson');
$newVersion=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1",$newLessonId));
dzn_rr_assert($newVersion instanceof stdClass,'the replacement must have an applicable schedule version');
dzn_rr_assert((string)$newVersion->starts_at_utc===(string)$slot['start']&&(int)$newVersion->lesson_id===$newLessonId,'the replacement schedule must match the accepted time');

// Portal read models must converge on the replacement occurrence and remove the pending card.
wp_set_current_user($studentUserId);
$studentAfter=(new PortalAuthenticatedReadService())->student();
dzn_rr_assert(empty(array_filter($studentAfter['reschedule_proposals'],static fn($row)=>(string)($row['lesson_uid']??'')===(string)$origin->lesson_uid)),'the accepted proposal must leave the Student pending list');
$studentLesson=null;foreach($studentAfter['lessons'] as$row)if((string)($row['lesson_uid']??'')===(string)$newLesson->uid)$studentLesson=$row;
dzn_rr_assert($studentLesson!==null&&(string)$studentLesson['starts_at_utc']===(string)$slot['start'],'the Student portal schedule must show the confirmed replacement');
// The original occurrence stays immutable but its Student-reported absence and the linked
// replacement must be visible; the Term allowance must be consumed exactly once.
$originAfter=null;foreach($studentAfter['lessons'] as$row)if((string)($row['lesson_uid']??'')===(string)$origin->lesson_uid)$originAfter=$row;
dzn_rr_assert($originAfter!==null&&!empty($originAfter['absence_reported']),'the Student portal must mark the notified absence');
dzn_rr_assert((string)($originAfter['replacement_lesson_uid']??'')===(string)$newLesson->uid,'the notified absence must expose its replacement Lesson');
$replacementEnrolment=null;foreach($studentAfter['enrolments'] as$row){foreach((array)($row['replacement_lessons']??array())as$replacementRow)if((string)($replacementRow['lesson_uid']??'')===(string)$newLesson->uid)$replacementEnrolment=$row;}
dzn_rr_assert($replacementEnrolment!==null,'the Term projection must include the accepted replacement');
dzn_rr_assert((int)$replacementEnrolment['replacement_pending_allocated']===0,'the confirmed replacement must replace, not duplicate, the pending allocation');
$afterExpectedAvailable=max(0,(int)$replacementEnrolment['replacement_allowance']-(int)$replacementEnrolment['replacement_used']);
dzn_rr_assert((int)$replacementEnrolment['replacement_available']===$afterExpectedAvailable,'the confirmed replacement must consume exactly one Term allowance');
$reviewQueue=(new \Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalAttendanceRepository())->reviewQueue(250);
dzn_rr_assert(empty(array_filter($reviewQueue,static fn($row)=>(int)$row->id===(int)$claim['case_id'])),'an accepted replacement must not remain an administrator review item');
wp_set_current_user($teacherUserId);
$teacherAfter=(new PortalAuthenticatedReadService())->teacher();
$teacherLesson=null;foreach($teacherAfter['lessons'] as$row)if((string)($row['lesson_uid']??'')===(string)$newLesson->uid)$teacherLesson=$row;
dzn_rr_assert($teacherLesson!==null&&(string)$teacherLesson['starts_at_utc']===(string)$slot['start'],'the Teacher portal schedule must show the confirmed replacement');

// Reschedule the replacement itself: the original completed occurrence must stay untouched,
// while the replacement calendar hand-off moves the replacement schedule version.
$newSlot=null;
for($offset=20;$offset<35;$offset++){
    $timestamp=strtotime('+'.$offset.' days 05:00:00 UTC');
    if($timestamp===false)continue;
    $start=gmdate('Y-m-d H:i:s',$timestamp);$end=gmdate('Y-m-d H:i:s',$timestamp+1800);
    if($conflicts->studentConflict((int)$origin->student_id,$start,$end,$newLessonId))continue;
    if($occupancy->overlapping((int)$origin->teacher_id,$start,$end,$newLessonId))continue;
    $newSlot=array('start'=>$start,'date'=>gmdate('Y-m-d',$timestamp),'time'=>gmdate('H:i',$timestamp));break;
}
dzn_rr_assert(is_array($newSlot),'a conflict-free slot for the replacement reschedule is required');
wp_set_current_user($teacherUserId);
$replacementProposal=$service->propose((string)$newLesson->uid,(string)$newVersion->uid,array(
    'timezone'=>'UTC','calendar'=>'gregorian','local_date'=>$newSlot['date'],'local_time'=>$newSlot['time'],
),'replacement-runtime-reschedule-'.wp_generate_uuid4());
dzn_rr_assert((string)$replacementProposal['state']==='pending'&&($replacementProposal['kind']??'')==='reschedule','an authorised replacement must remain reschedulable');
wp_set_current_user($studentUserId);
$replacementAccepted=$service->decide((string)$replacementProposal['proposal_uid'],'accept','replacement-runtime-reschedule-decision-'.wp_generate_uuid4());
dzn_rr_assert((string)$replacementAccepted['state']==='accepted','the Student must accept the replacement reschedule');
$movedVersion=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1",$newLessonId));
dzn_rr_assert($movedVersion instanceof stdClass&&(string)$movedVersion->starts_at_utc===(string)$newSlot['start'],'the replacement schedule must move to the accepted time');
$oldReplacementVersion=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d",(int)$newVersion->id));
dzn_rr_assert($oldReplacementVersion->superseded_at!==null,'the previous replacement schedule version must be superseded');
$rescheduleJob=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lesson_reschedule_calendar_jobs WHERE proposal_id=(SELECT id FROM {$p}lesson_reschedule_proposals WHERE uid=%s) LIMIT 1",(string)$replacementProposal['proposal_uid']));
dzn_rr_assert($rescheduleJob instanceof stdClass&&(int)$rescheduleJob->lesson_id===$newLessonId&&(int)$rescheduleJob->from_schedule_version_id===(int)$newVersion->id&&(int)$rescheduleJob->to_schedule_version_id===(int)$movedVersion->id,'the replacement reschedule must queue a new calendar hand-off for the same Lesson');
dzn_rr_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE canonical_replacement_origin_lesson_id=%d AND archived_at IS NULL",$originId))===1,'rescheduling the replacement must not issue another replacement');
dzn_rr_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND lesson_type='replacement' AND archived_at IS NULL",(int)$origin->term_id))===1,'rescheduling must not consume an additional replacement allowance');

// Controlled provider hand-off: create a synthetic connected Teacher connection, configure the
// calendar actor, and dispatch the replacement reschedule job through the real defaultDispatch
// path. The Google seam is translation-only here, so this records pending projections and never
// contacts Google.
wp_set_current_user(1);
$calendarSystemUserId=wp_insert_user(array('user_login'=>'calendar-actor-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(32,true,true),'user_email'=>'calendar-actor-'.wp_generate_uuid4().'@dzn.invalid','role'=>'subscriber'));
dzn_rr_assert(!is_wp_error($calendarSystemUserId),'the calendar actor user must be created');
$calendarActorUser=get_user_by('id',(int)$calendarSystemUserId);
$calendarActorUser->add_cap('dzn_manage_provider_integrations');
update_option('dzn_reschedule_calendar_system_user_id',(int)$calendarSystemUserId,false);
$providerPorts=new ContractProviderAdapters(array(),ContractProviderAdapters::PROJECTION_PENDING);
$integration=new ProviderIntegrationService(null,null,$providerPorts,$providerPorts,$providerPorts);
$integrationEvidence=static fn(string $ref):array=>array('evidence_channel'=>'staff_record','evidence_reference'=>$ref,'evidence_at'=>gmdate('Y-m-d H:i:s'));
$begin=$integration->beginAuthorization(array('provider_code'=>'google_calendar','teacher_id'=>(int)$origin->teacher_id,'client_reference'=>'runtime-calendar-client','redirect_uri'=>'https://academy.example/cb','scope_snapshot'=>ProviderIntegrationRule::GOOGLE_CALENDAR_SCOPE)+$integrationEvidence('runtime-calendar-consent'),dzn_rr_key('calendar-consent'));
$connected=$integration->completeAuthorization(array('state'=>(string)$begin['state'],'code'=>'runtime-calendar-code','code_verifier'=>(string)$begin['code_verifier'],'redirect_uri'=>'https://academy.example/cb')+$integrationEvidence('runtime-calendar-complete'),dzn_rr_key('calendar-complete'));
dzn_rr_assert((string)$connected['connection_state']==='connected','the synthetic Teacher Google connection must be connected');
$handoff=(new LessonRescheduleCalendarService())->dispatchJob((int)$rescheduleJob->id);
dzn_rr_assert($handoff==='completed','the real calendar hand-off path must complete the queued replacement reschedule job');
$calendarMapping=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_calendar_event_mappings WHERE lesson_id=%d AND schedule_version_id=%d LIMIT 1",$newLessonId,(int)$movedVersion->id));
$meetingMapping=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}provider_meeting_mappings WHERE lesson_id=%d AND schedule_version_id=%d LIMIT 1",$newLessonId,(int)$movedVersion->id));
dzn_rr_assert($calendarMapping instanceof stdClass&&(string)$calendarMapping->projection_state==='pending','the hand-off must record a pending calendar projection for the replacement occurrence');
dzn_rr_assert($meetingMapping===null,'the calendar hand-off must not require a second google_meet connection');
dzn_rr_assert((string)$calendarMapping->starts_at_utc===(string)$movedVersion->starts_at_utc&&(string)$calendarMapping->schedule_timezone===(string)$movedVersion->schedule_timezone,'the calendar projection must copy the exact canonical interval and timezone');
dzn_rr_assert(!empty($calendarMapping->event_digest),'provider references must persist as digests, never raw values');

// No double issuance and no second replacement for one origin.
$threw=null;
try{$service->proposeForTeacher((int)$origin->teacher_id,'UTC',(string)$origin->lesson_uid,(string)$origin->version_uid,array('timezone'=>'UTC','calendar'=>'gregorian','local_date'=>$slot['date'],'local_time'=>$slot['time']),'replacement-runtime-request-'.wp_generate_uuid4());}catch(Throwable $e){$threw=$e;}
dzn_rr_assert($threw!==null&&$threw->getMessage()==='replacement_already_issued','a second replacement for the same origin must be refused');
dzn_rr_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE canonical_replacement_origin_lesson_id=%d AND archived_at IS NULL",$originId))===1,'exactly one replacement may exist per origin');

echo "Lesson replacement runtime: PASS\n";
