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

use Delnavazan\Platform\Core\Application\{CanonicalAttendanceIntakeService,CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonAuthorityValidator,CanonicalLessonScheduleService,CanonicalTeacherOccupancyReadService,CanonicalTermAuthorityService,LessonRescheduleService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService,TeacherService,TeachingEligibilityService};
use Delnavazan\Platform\Integrations\LessonCompletionAutomationWorker;

global $wpdb;
$p=$wpdb->prefix.'dzn_';
function dzn_rr_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Lesson replacement runtime failed: '.$message);}
wp_set_current_user(1);

function dzn_rr_evidence(string $reference):array{return array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));}
function dzn_rr_key(string $label):string{return 'dzn-replacement-'.$label.'-'.wp_generate_uuid4();}

// Use a presumed-completed canonical occurrence produced by the attendance runtime. Its
// disposable delivery/attendance projections are cleared so it represents silence + a later
// Student missed-class report, not a delivered class.
$origin=$wpdb->get_row(
    "SELECT l.id,l.uid lesson_uid,l.term_id,l.enrolment_id,l.student_id,l.teacher_id,l.teacher_assignment_id,v.id version_id,v.uid version_uid
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

$service=new LessonRescheduleService(null,null,null,null,static fn(int $teacherId,string $starts,string $ends):bool=>true);
$proposal=$service->proposeForTeacher((int)$origin->teacher_id,'UTC',(string)$origin->lesson_uid,(string)$origin->version_uid,array(
    'timezone'=>'UTC','calendar'=>'gregorian','local_date'=>$slot['date'],'local_time'=>$slot['time'],
),'replacement-runtime-request-'.wp_generate_uuid4());
dzn_rr_assert((string)$proposal['state']==='pending'&&($proposal['kind']??'')==='replacement','a completed occurrence must create a replacement proposal');

$accepted=$service->decideForStudent((int)$origin->student_id,(string)$proposal['proposal_uid'],'accept','replacement-runtime-decision-'.wp_generate_uuid4());
dzn_rr_assert((string)$accepted['state']==='accepted','the Student must be able to accept the replacement');
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

// The original occurrence, its schedule authority and its attendance evidence are immutable.
$after=$wpdb->get_row($wpdb->prepare("SELECT lifecycle_state,current_schedule_version_id FROM {$p}lessons WHERE id=%d",$originId));
dzn_rr_assert((string)$after->lifecycle_state===(string)$before->lifecycle_state,'the original Lesson lifecycle must not change');
dzn_rr_assert((string)$after->current_schedule_version_id===(string)$before->current_schedule_version_id,'the original schedule pointer must not change');
dzn_rr_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_lifecycle_events WHERE lesson_id=%d",$originId))===$beforeEvents,'the original lifecycle evidence must be append-only and unchanged');
$afterVersion=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d",(int)$origin->version_id));
dzn_rr_assert((string)$afterVersion->superseded_at===''||$afterVersion->superseded_at===null,'the original schedule version must not be superseded');
dzn_rr_assert((string)$afterVersion->starts_at_utc===(string)$beforeVersion->starts_at_utc,'the original occurrence instant must not be rewritten');
$caseAfter=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_cases WHERE id=%d",(int)$claim['case_id']));
dzn_rr_assert((string)$caseAfter->state===(string)$caseBefore->state,'the attendance case state must not be mutated by replacement acceptance');
dzn_rr_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_evidence WHERE case_id=%d",(int)$claim['case_id']))===$evidenceBefore,'attendance evidence must be preserved');

// The accepted proposal is the provenance authority and the canonical validator accepts it.
dzn_rr_assert(CanonicalLessonAuthorityValidator::valid($newLesson,(new Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonAuthorityRepository())->events($newLessonId)),'the replacement Lesson must pass canonical provenance validation without a caller-supplied repository');
$acceptedEvent=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lesson_reschedule_events WHERE proposal_id=(SELECT id FROM {$p}lesson_reschedule_proposals WHERE uid=%s) AND event_type='accepted' LIMIT 1",(string)$proposal['proposal_uid']));
dzn_rr_assert($acceptedEvent instanceof stdClass&&(string)$acceptedEvent->reason_code==='student_replacement_accepted','the accepted event must name the replacement lifecycle');
dzn_rr_assert((int)$acceptedEvent->result_schedule_version_id===(int)$newVersion->id,'the accepted event must record the replacement schedule version');

$job=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lesson_reschedule_calendar_jobs WHERE proposal_id=(SELECT id FROM {$p}lesson_reschedule_proposals WHERE uid=%s) LIMIT 1",(string)$proposal['proposal_uid']));
dzn_rr_assert($job instanceof stdClass&&(int)$job->lesson_id===$newLessonId&&(int)$job->to_schedule_version_id===(int)$newVersion->id,'a calendar hand-off must target the replacement occurrence');
dzn_rr_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lesson_reschedule_notifications WHERE proposal_id=(SELECT id FROM {$p}lesson_reschedule_proposals WHERE uid=%s) AND event_type='accepted'",(string)$proposal['proposal_uid']))===3,'both parties and admin must receive the accepted notice');

// No double issuance and no second replacement for one origin.
$threw=null;
try{$service->proposeForTeacher((int)$origin->teacher_id,'UTC',(string)$origin->lesson_uid,(string)$origin->version_uid,array('timezone'=>'UTC','calendar'=>'gregorian','local_date'=>$slot['date'],'local_time'=>$slot['time']),'replacement-runtime-request-'.wp_generate_uuid4());}catch(Throwable $e){$threw=$e;}
dzn_rr_assert($threw!==null&&$threw->getMessage()==='replacement_already_issued','a second replacement for the same origin must be refused');
dzn_rr_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE canonical_replacement_origin_lesson_id=%d AND archived_at IS NULL",$originId))===1,'exactly one replacement may exist per origin');

echo "Lesson replacement runtime: PASS\n";
