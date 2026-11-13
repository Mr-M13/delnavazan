<?php
namespace Delnavazan\Platform\Core\Application\GodMode;

/** Reusable, reviewable one-click scenarios covering the required platform surfaces. */
final class GodModeScenarioLibrary {
    /** @return array<string,array<string,mixed>> */
    public static function all():array{
        $s=array();
        $add=static function(string $code,string $category,string $title,string $description,array $prerequisites,array $steps,string $mode=GodModeRule::MODE_DIRECT,bool $manual=false,?string $frameworkGap=null)use(&$s):void{
            $s[$code]=array('code'=>$code,'category'=>$category,'title'=>$title,'description'=>$description,'prerequisites'=>$prerequisites,'steps'=>$steps,'mode'=>$mode,'manual_verification'=>$manual,'framework_gap'=>$frameworkGap);
        };
        $teacher=array('An active teacher exists','An active instrument and course exist');
        $google=array('Google Workspace organizer grant is connected','Meet classroom provisioning is enabled');
        $testRecipient=array('An approved testing recipient or participating teacher exists');

        // Teacher and booking
        $add('teacher_onboarding','Teacher and booking','Teacher Onboarding','Prepare a synthetic teacher onboarding journey and verify each readiness stage without creating live finance.',array('An invitation can be issued to an approved synthetic recipient'),array('Create invitation','Claim account','Complete profile','Record availability','Verify onboarding state'),GodModeRule::MODE_WORKFLOW,true,'The real invitation/claim/onboarding services are not wired into the one-click runner in this release. Use the Teachers administration workflow with a synthetic recipient.');
        $add('introductory_class_booking','Teacher and booking','Introductory Class Booking','Book an introductory test class outside normal eligibility rules and verify the real confirmation path where safe.',$teacher,array('Create intro booking','Assign teacher','Verify teacher assignment','Provision classroom'),GodModeRule::MODE_WORKFLOW,false,'The real booking-request and introductory confirmation path is not wired into the one-click runner in this release because it would create genuine booking aggregates. Use an approved staging booking workflow.');
        $add('direct_class_booking','Teacher and booking','Direct Class Booking','Create an immediate test class without teacher approval or enrolment prerequisites.',$teacher,array('Create test session','Assign teacher','Provision classroom','Record evidence'),GodModeRule::MODE_DIRECT);
        $add('teacher_assignment','Teacher and booking','Teacher Assignment','Verify the selected teacher is assigned to the test session and appears in the teacher portal.',$teacher,array('Create test session','Confirm teacher assignment','Verify teacher read projection'),GodModeRule::MODE_DIRECT);
        $add('teacher_acceptance','Teacher and booking','Teacher Acceptance','Exercise the workflow acceptance boundary with genuine teacher participation.',$teacher,array('Create workflow test session','Teacher acknowledges test class','Provision classroom'),GodModeRule::MODE_WORKFLOW,true);
        $add('booking_conflict_handling','Teacher and booking','Booking Conflict Handling','Verify conflict detection, explicit acknowledgement and that a genuine class is never modified.',array_merge($teacher,array('At least one genuine class exists')),array('Detect conflict','Require acknowledgement','Create overlapping test session','Verify genuine class unchanged'),GodModeRule::MODE_DIRECT);
        $add('booking_cancellation','Teacher and booking','Booking Cancellation','Cancel a test session and its provider event without deleting evidence or recordings.',array_merge($teacher,$google),array('Create test session','Cancel provider event','Verify cancelled state'),GodModeRule::MODE_DIRECT);
        $add('class_rescheduling','Teacher and booking','Class Rescheduling','Reschedule the same test classroom rather than creating a second provider event.',array_merge($teacher,$google),array('Create test session','Reschedule interval','Verify provider update'),GodModeRule::MODE_DIRECT);

        // Google integration
        $add('google_calendar_event_creation','Google integration','Google Calendar Event Creation','Provision and verify the organizer Calendar event for a test session.',array_merge($teacher,$google),array('Create test session','Create Calendar event','Verify provider identity'),GodModeRule::MODE_DIRECT);
        $add('google_meet_provisioning','Google integration','Google Meet Provisioning','Provision and verify an app-owned Meet space and join reference.',array_merge($teacher,$google),array('Create test session','Create Meet space','Verify join reference'),GodModeRule::MODE_DIRECT);
        $add('teacher_invitation','Google integration','Teacher Invitation','Verify the participating teacher is invited as a Calendar attendee.',array_merge($teacher,$google),array('Create test session','Provision classroom','Verify attendee invitation evidence'),GodModeRule::MODE_DIRECT);
        $add('host_cohost_permissions','Google integration','Host / Co-host Permissions','Verify organizer moderation and co-host membership.',array_merge($teacher,$google),array('Provision classroom','Assign co-host','Verify co-host state'),GodModeRule::MODE_DIRECT);
        $add('teacher_join_access','Google integration','Teacher Join Access','Verify the teacher can open the normal classroom interface during the join window.',array_merge($teacher,$google,array('A human teacher is available to join')),array('Provision classroom','Open teacher join action','Confirm human participant evidence'),GodModeRule::MODE_DIRECT,true);
        $add('student_join_access','Google integration','Student Join Access','Verify a test participant can join the Meet space.',array_merge($teacher,$google,array('A human participant is available to join')),array('Provision classroom','Open participant join action','Confirm human participant evidence'),GodModeRule::MODE_DIRECT,true);
        $add('automatic_recording_configuration','Google integration','Automatic Recording Configuration','Request auto-recording and verify the provider accepted the configuration.',array_merge($teacher,$google),array('Create recording test session','Provision classroom','Verify recording configuration'),GodModeRule::MODE_DIRECT);
        $add('recording_availability_verification','Google integration','Recording Availability Verification','Verify that a real recording artifact exists; never infer success from configuration.',array_merge($teacher,$google,array('The test class has ended')),array('Provision recording','Join class','End class','Look up official recording artifact'),GodModeRule::MODE_DIRECT,true);

        // Attendance and enrolment
        $add('automatic_attendance_detection','Attendance and enrolment','Automatic Attendance Detection','Verify participant evidence and attendance detection where provider evidence exists.',array_merge($teacher,$google,array('Participant evidence is available')),array('Provision classroom','Join class','Fetch participant evidence','Compare with attendance state'),GodModeRule::MODE_DIRECT,true);
        $add('teacher_manual_attendance_confirmation','Attendance and enrolment','Teacher Manual Attendance Confirmation','Record a manual attendance confirmation without creating finance.',$teacher,array('Create test session','Confirm attendance','Verify no finance snapshot'),GodModeRule::MODE_DIRECT);
        $add('class_completion','Attendance and enrolment','Class Completion','Complete a test class and verify the canonical completion path is not used to create liabilities.',$teacher,array('Create test session','Confirm attendance','Complete test session','Verify finance isolation'),GodModeRule::MODE_DIRECT);
        $add('post_intro_continuation','Attendance and enrolment','Post-Intro Continuation','Exercise continuation decisions without consuming a real term or enrolment.',array_merge($teacher,array('A completed introductory test exists')),array('Record continuation decision','Verify no real enrolment','Record evidence'),GodModeRule::MODE_WORKFLOW,true,'The real continuation/enrolment services are not wired into the one-click runner in this release; using them would create a genuine continuation case.');
        $add('payment_link_generation_simulation','Attendance and enrolment','Payment-Link Generation Simulation','Produce a non-chargeable simulated payment reference with no Stripe obligation.',array('Payment execution is disabled for test activity'),array('Create simulated reference','Verify no provider call','Verify no invoice or charge'),GodModeRule::MODE_DIRECT);
        $add('enrolment_workflow_simulation','Attendance and enrolment','Enrolment Workflow Simulation','Exercise the enrolment workflow in an isolated simulation without creating a real enrolment or term.',array_merge($teacher,array('A completed test session exists')),array('Select continuation','Simulate enrolment','Verify no canonical enrolment','Record evidence'),GodModeRule::MODE_WORKFLOW,true,'The real enrolment conversion/lifecycle services are not wired into the one-click runner in this release; the scenario is a simulation only.');

        // Notifications
        foreach(array(
            array('portal_notification','Portal Notification','Verify an isolated portal inbox message.'),
            array('email_notification','Email Notification','Verify the real email hand-off to an approved recipient.'),
            array('whatsapp_notification','WhatsApp Notification','Verify the real WhatsApp transport to an approved recipient.'),
            array('primary_sms','Primary SMS','Verify the primary SMS transport to an approved recipient.'),
            array('notification_failure_retry','Notification Failure and Retry','Verify a failed notification is recorded and a safe retry is available.'),
        ) as $item)$add($item[0],'Notifications',$item[1],$item[2],$testRecipient,array('Select channel','Send test notification','Record transport outcome','Confirm delivery evidence'),GodModeRule::MODE_DIRECT,true);

        // Complete workflows
        $add('teacher_onboarding_end_to_end','Complete workflows','Teacher Onboarding End-to-End','Run the real invitation, claim, onboarding, readiness and activation path with a human participant.',array('An approved synthetic teacher recipient exists'),array('Invite teacher','Claim account','Complete onboarding','Verify activation','Verify no duplicate principal'),GodModeRule::MODE_WORKFLOW,true,'End-to-end teacher onboarding still requires the real invitation/claim UI and a human participant; the one-click runner does not fabricate those steps.');
        $add('introductory_class_end_to_end','Complete workflows','Introductory Class End-to-End','Book, provision, join, attend and complete an introductory test class.',array_merge($teacher,$google,array('A human participant is available')),array('Book intro test','Assign teacher','Provision Meet','Join class','Confirm attendance','Complete'),GodModeRule::MODE_WORKFLOW,true,'The real introductory booking aggregate is not used by the test runner; use the approved booking workflow plus a human Meet participant.');
        $add('regular_class_end_to_end','Complete workflows','Regular Class End-to-End','Exercise a regular test class without creating enrolment, finance or term consumption.',array_merge($teacher,$google,array('A human participant is available')),array('Create test class','Provision Meet','Join','Confirm attendance','Complete','Verify isolation'),GodModeRule::MODE_WORKFLOW,true,'The test aggregate can verify isolation and provider provisioning, but the real regular-class enrolment/term/attendance path is not executed by the one-click runner.');
        $add('google_meet_recording_end_to_end','Complete workflows','Google Meet and Recording End-to-End','Provision, join, record, end and verify an actual recording artifact.',array_merge($teacher,$google,array('A human participant is available','The organizer Drive account is available')),array('Create recording test','Provision Meet','Join and record','End class','Verify artifact'),GodModeRule::MODE_DIRECT,true);
        $add('booking_to_attendance_end_to_end','Complete workflows','Booking to Attendance End-to-End','Trace one test session from booking to attendance with chronological evidence.',array_merge($teacher,$google,array('A human participant is available')),array('Book test','Assign teacher','Provision Meet','Join','Confirm attendance','Export evidence'),GodModeRule::MODE_WORKFLOW,true,'The trace uses the isolated test aggregate; it does not execute the genuine booking-request or canonical attendance pipeline.');
        return $s;
    }

    public static function get(string $code):?array{return self::all()[$code]??null;}

    /** @return list<string> */
    public static function codes():array{return array_keys(self::all());}
}
