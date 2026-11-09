<?php
require_once dirname(__DIR__).'/src/Core/Application/GodMode/GodModeRule.php';
require_once dirname(__DIR__).'/src/Core/Application/GodMode/GodModeScenarioLibrary.php';
use Delnavazan\Platform\Core\Application\GodMode\{GodModeRule,GodModeScenarioLibrary};

$required=array(
    'teacher_onboarding','introductory_class_booking','direct_class_booking','teacher_assignment','teacher_acceptance','booking_conflict_handling','booking_cancellation','class_rescheduling',
    'google_calendar_event_creation','google_meet_provisioning','teacher_invitation','host_cohost_permissions','teacher_join_access','student_join_access','automatic_recording_configuration','recording_availability_verification',
    'automatic_attendance_detection','teacher_manual_attendance_confirmation','class_completion','post_intro_continuation','payment_link_generation_simulation','enrolment_workflow_simulation',
    'portal_notification','email_notification','whatsapp_notification','primary_sms','sms_fallback','notification_failure_retry',
    'teacher_onboarding_end_to_end','introductory_class_end_to_end','regular_class_end_to_end','google_meet_recording_end_to_end','booking_to_attendance_end_to_end',
);
$all=GodModeScenarioLibrary::all();
foreach($required as $code){
    if(!isset($all[$code]))throw new RuntimeException('Missing scenario: '.$code);
    $scenario=$all[$code];
    foreach(array('category','title','description','prerequisites','steps','mode','manual_verification') as $field)if(!array_key_exists($field,$scenario))throw new RuntimeException('Scenario field missing: '.$code.'.'.$field);
    if(!in_array($scenario['mode'],GodModeRule::MODES,true))throw new RuntimeException('Invalid scenario mode: '.$code);
    if(!is_array($scenario['prerequisites'])||!is_array($scenario['steps'])||$scenario['steps']===array())throw new RuntimeException('Scenario prerequisites/steps incomplete: '.$code);
}
if(count($all)!==count($required))throw new RuntimeException('The library must not silently contain a different scenario count.');
$manual=array_filter($all,static fn(array $scenario):bool=>!empty($scenario['manual_verification']));
if(!isset($manual['teacher_onboarding_end_to_end'],$manual['recording_availability_verification'],$manual['sms_fallback']))throw new RuntimeException('Human/provider-dependent scenarios must be marked for manual verification.');
foreach(array('passed','failed','warning','awaiting_manual_verification') as $result)if(!in_array($result,GodModeRule::RESULTS,true))throw new RuntimeException('Result vocabulary missing '.$result);
echo "God Mode scenario library unit passed\n";
