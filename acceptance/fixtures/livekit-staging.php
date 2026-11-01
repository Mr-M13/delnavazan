<?php
/** Synthetic LiveKit staging fixture: one joinable canonical Lesson and linked portal principals. */
if (!defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local','development'), true)) { fwrite(STDERR, "Refused.\n"); exit(1); }
use Delnavazan\Platform\Core\Application\{CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService};
global $wpdb; $p=$wpdb->prefix.'dzn_';
$fixture=get_option('dzn_phase_2a2j_fixture'); if(!is_array($fixture)||empty($fixture['sources'][0]))throw new RuntimeException('fixture_required');
$source=$fixture['sources'][0]; $enrolmentId=(int)$source['enrolment_id']; $teacherId=(int)$source['teacher_id']; $studentId=(int)$source['student_id'];
wp_set_current_user(1);
$state=(string)$wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d",$enrolmentId));
if($state!=='current'){(new CanonicalEnrolmentLifecycleService())->activate($enrolmentId,$state,array('evidence_channel'=>'staff_record','evidence_reference'=>'lk-activate','evidence_at'=>gmdate('Y-m-d H:i:s')),'lk-activate-'.wp_generate_uuid4());}
$term=(new CanonicalTermAuthorityService())->create($enrolmentId,null,null,array('evidence_channel'=>'staff_record','evidence_reference'=>'lk-term','evidence_at'=>gmdate('Y-m-d H:i:s')),'lk-term-'.wp_generate_uuid4());$termId=(int)$term['term_id'];
(new CanonicalTermAuthorityService())->activate($termId,'authorised',array('evidence_channel'=>'staff_record','evidence_reference'=>'lk-term-active','evidence_at'=>gmdate('Y-m-d H:i:s')),'lk-term-active-'.wp_generate_uuid4());
$assignmentId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}teacher_assignments WHERE enrolment_id=%d AND applicable_slot=1 AND state='assigned' LIMIT 1",$enrolmentId));
if($assignmentId<1){$a=(new TeacherAssignmentService())->assignInitial($enrolmentId,'lk-assignment-'.wp_generate_uuid4());$assignmentId=(int)$a['assignment_id'];}
$wpdb->update($p.'teachers',array('timezone'=>'UTC'),array('id'=>$teacherId));
try{(new TeacherAcceptingStateService())->set(array('teacher_id'=>$teacherId,'state'=>'accepting','reason_code'=>'synthetic_livekit'));}catch(Throwable $e){}
try{(new TeacherAvailabilityService())->setProfile(array('teacher_id'=>$teacherId,'timezone'=>'UTC','status'=>'active','reason_code'=>'synthetic_livekit'));}catch(Throwable $e){}
for($weekday=1;$weekday<=7;$weekday++){try{(new TeacherAvailabilityService())->setRecurringRule(array('teacher_id'=>$teacherId,'timezone'=>'UTC','weekday'=>$weekday,'local_start_time'=>'00:00:00','local_end_time'=>'23:59:59','state'=>'requestable','status'=>'active','reason_code'=>'synthetic_livekit'));}catch(Throwable $e){}}
$lesson=(new CanonicalLessonAuthorityService())->createStandard($termId,$assignmentId,array('evidence_channel'=>'staff_record','evidence_reference'=>'lk-lesson','evidence_at'=>gmdate('Y-m-d H:i:s')),'lk-lesson-'.wp_generate_uuid4());$lessonId=(int)$lesson['lesson_id'];
$start=gmdate('Y-m-d H:i:s',strtotime('+5 seconds'));
$schedule=(new CanonicalLessonScheduleService())->schedule($lessonId,$assignmentId,array('schedule_timezone'=>'UTC','local_wall_date'=>substr($start,0,10),'local_wall_time'=>substr($start,11),'duration_minutes'=>1,'reason_code'=>'synthetic_livekit')+array('evidence_channel'=>'staff_record','evidence_reference'=>'lk-schedule','evidence_at'=>gmdate('Y-m-d H:i:s')),'lk-schedule-'.wp_generate_uuid4());
$versionUid=(string)$wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}canonical_lesson_schedule_versions WHERE id=%d",(int)$schedule['schedule_version_id']));
$lessonUid=(string)$wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}lessons WHERE id=%d",$lessonId));
// Link the deterministic acceptance users to the synthetic fixture principals.
$wpdb->query("DELETE FROM {$p}teacher_principal_links WHERE teacher_id={$teacherId} OR wordpress_user_id=3");
$wpdb->query("DELETE FROM {$p}student_principal_links WHERE student_id={$studentId} OR wordpress_user_id=2");
$now=gmdate('Y-m-d H:i:s');
$wpdb->insert($p.'teacher_principal_links',array('teacher_id'=>$teacherId,'wordpress_user_id'=>3,'status'=>'active','linked_at'=>$now,'linked_by'=>1));
$wpdb->insert($p.'student_principal_links',array('student_id'=>$studentId,'wordpress_user_id'=>2,'status'=>'active','link_sequence'=>1,'active_slot'=>1,'version'=>1,'verification_basis'=>'synthetic_fixture','evidence_channel'=>'synthetic_fixture','evidence_reference'=>'livekit-stage','evidence_at'=>$now,'linked_at'=>$now,'linked_by'=>1,'updated_at'=>$now,'updated_by'=>1));
echo json_encode(array('lesson_id'=>$lessonId,'lesson_uid'=>$lessonUid,'schedule_uid'=>$versionUid,'term_id'=>$termId,'teacher_id'=>$teacherId,'student_id'=>$studentId,'starts_at_utc'=>$start,'ends_at_utc'=>gmdate('Y-m-d H:i:s',strtotime($start)+60)))."\n";
