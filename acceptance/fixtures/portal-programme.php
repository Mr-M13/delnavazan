<?php
/** Synthetic portal-visual fixture for the disposable acceptance runtime. */
if (!defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local','development'), true)) { fwrite(STDERR, "Refused.\n"); exit(1); }
use Delnavazan\Platform\Core\Application\{CanonicalLessonAuthorityService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService};
global $wpdb; $p=$wpdb->prefix.'dzn_';
$fixture=get_option('dzn_phase_2a2j_fixture'); if(!is_array($fixture)||empty($fixture['sources'][0]))throw new RuntimeException('fixture_required');
$source=$fixture['sources'][0]; $enrolmentId=(int)$source['enrolment_id']; $teacherId=(int)$source['teacher_id'];
wp_set_current_user(1);
$termId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}terms WHERE enrolment_id=%d AND archived_at IS NULL ORDER BY id DESC LIMIT 1",$enrolmentId));
if($termId<1){$term=(new CanonicalTermAuthorityService())->create($enrolmentId,null,null,array('evidence_channel'=>'staff_record','evidence_reference'=>'visual-term','evidence_at'=>gmdate('Y-m-d H:i:s')),'visual-term-'.wp_generate_uuid4());$termId=(int)$term['term_id'];(new CanonicalTermAuthorityService())->activate($termId,'authorised',array('evidence_channel'=>'staff_record','evidence_reference'=>'visual-term-active','evidence_at'=>gmdate('Y-m-d H:i:s')),'visual-term-active-'.wp_generate_uuid4());}
$assignmentId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}teacher_assignments WHERE enrolment_id=%d AND applicable_slot=1 AND state='assigned' LIMIT 1",$enrolmentId));
if($assignmentId<1){$a=(new TeacherAssignmentService())->assignInitial($enrolmentId,'visual-assignment-'.wp_generate_uuid4());$assignmentId=(int)$a['assignment_id'];}
$wpdb->update($p.'teachers',array('timezone'=>'UTC'),array('id'=>$teacherId));
try{(new TeacherAcceptingStateService())->set(array('teacher_id'=>$teacherId,'state'=>'accepting','reason_code'=>'synthetic_fixture'));}catch(Throwable $e){}
try{(new TeacherAvailabilityService())->setProfile(array('teacher_id'=>$teacherId,'timezone'=>'UTC','status'=>'active','reason_code'=>'synthetic_fixture'));}catch(Throwable $e){}
for($weekday=1;$weekday<=7;$weekday++){try{(new TeacherAvailabilityService())->setRecurringRule(array('teacher_id'=>$teacherId,'timezone'=>'UTC','weekday'=>$weekday,'local_start_time'=>'00:00:00','local_end_time'=>'23:59:59','state'=>'requestable','status'=>'active','reason_code'=>'synthetic_fixture'));}catch(Throwable $e){}}
$made=array();
foreach(array('+30 minutes','+3 hours','+2 days') as $offset){$lesson=(new CanonicalLessonAuthorityService())->createStandard($termId,$assignmentId,array('evidence_channel'=>'staff_record','evidence_reference'=>'visual-lesson','evidence_at'=>gmdate('Y-m-d H:i:s')),'visual-lesson-'.wp_generate_uuid4());$start=gmdate('Y-m-d H:i:s',strtotime($offset));$schedule=(new CanonicalLessonScheduleService())->schedule((int)$lesson['lesson_id'],$assignmentId,array('schedule_timezone'=>'UTC','local_wall_date'=>substr($start,0,10),'local_wall_time'=>substr($start,11),'duration_minutes'=>30,'reason_code'=>'synthetic_visual')+array('evidence_channel'=>'staff_record','evidence_reference'=>'visual-schedule','evidence_at'=>gmdate('Y-m-d H:i:s')),'visual-schedule-'.wp_generate_uuid4());$made[]=(int)$lesson['lesson_id'];}
echo json_encode(array('term_id'=>$termId,'assignment_id'=>$assignmentId,'lessons'=>$made,'teacher_id'=>$teacherId,'student_id'=>(int)$source['student_id']))."\n";
