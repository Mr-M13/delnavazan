<?php
// Source contract for the operator journey: read-only queue, ordered authorities, and bounded teacher search.
$root=dirname(__DIR__);
$queue=file_get_contents($root.'/src/Core/Application/AcademyJourneyReadService.php');
$dashboard=file_get_contents($root.'/src/Admin/Controller/AcademyOperationsController.php');
$core=file_get_contents($root.'/src/Admin/Controller/CoreDatasetReadinessController.php');
$assignment=file_get_contents($root.'/src/Core/Application/TeacherAssignmentService.php');
$identity=file_get_contents($root.'/src/Admin/Controller/StudentIdentityAuthorityController.php');
$acceptance=file_get_contents($root.'/src/Admin/Controller/CoordinationCaseController.php');
foreach(array($queue,$dashboard,$core,$assignment,$identity,$acceptance)as$source)if($source===false)throw new RuntimeException('Student operations source missing');
foreach(array("current_user_can('dzn_view_booking_requests')",'booking_request_contact_snapshots','LIMIT %d OFFSET %d','needs_action','privacy_erased_at IS NULL','esc_like($term)')as$fragment)if(!str_contains($queue,$fragment))throw new RuntimeException('Searchable, bounded, privacy-aware operations queue contract missing: '.$fragment);
foreach(array('search($query, $stage, $page)','booking → coordination and reviewed identity → accepted Service Arrangement → Enrolment → Teacher Assignment','journeyDetail($r)','detail_request_id','booking_request_id','accepted_service_arrangement_id','expected_assignment_id','Review replacement by agreement','Unavailable:')as$fragment)if(!str_contains($dashboard,$fragment))throw new RuntimeException('Contextual queue or stage blocker contract missing: '.$fragment);
foreach(array('check_admin_referer( \'dzn_core_dataset_\' . $action )','current_user_can( self::ACTIONS[ $action ] )','new TeacherAssignmentService()','replacementTeacherSelect','teacher_course_eligibilities','current_load','TeacherAssignmentService')as$fragment)if(!str_contains($core,$fragment))throw new RuntimeException('Core command or bounded eligible-teacher UX contract missing: '.$fragment);
if(!str_contains($assignment,'$source[\'arrangement\']->teacher_id')||!str_contains($assignment,'authorizeReplacementEvidence')||!str_contains($assignment,'active_future_schedule_exists'))throw new RuntimeException('Assignment authority must bind initial assignment to accepted arrangement and preserve replacement guards');
if(str_contains($dashboard,'INSERT INTO')||str_contains($dashboard,'UPDATE ')||str_contains($core,'UPDATE {$p}teacher_assignments')||str_contains($core,'INSERT INTO {$p}teacher_assignments'))throw new RuntimeException('Admin journey must not bypass canonical write authority');
if(!str_contains($identity,'name===\'booking_request_id\'?$requestId:\'\''))throw new RuntimeException('Identity authority must retain contextual booking prefill');
foreach(array('studentSearch()','studentSelector()','LIMIT 25','esc_like($query)')as$fragment)if(!str_contains($identity,$fragment))throw new RuntimeException('Identity review must find Students by human context with bounded search');
foreach(array('SELECT s.id,s.display_name','name="student_id" value="\'.(int)$studentId','acceptancePrincipalOptions','Accepting Student/Guardian account','principal_q','dzn_final_acceptance')as$fragment)if(!str_contains($acceptance,$fragment))throw new RuntimeException('Acceptance must carry resolved identity and search known authority accounts');
echo "Student operations journey contract passed\n";
