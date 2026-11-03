<?php
/**
 * Combined release proof: cancellation releases Student occupancy, stale queued
 * invitations are refused by the worker and by authorised recovery, and a
 * replacement request can occupy the released slot exactly once.
 */
if(getenv('DZN_BOOKING_RELEASE_INTEGRATION')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Booking release integration runtime refused.\n");exit(1);}

use Delnavazan\Platform\Core\Application\BookingNotificationRecoveryService;
use Delnavazan\Platform\Core\Application\NotificationDeliveryStatusReadService;
use Delnavazan\Platform\Core\Application\BookingNotificationRelevance;
use Delnavazan\Platform\Core\Application\IntroLessonLifecycleService;
use Delnavazan\Platform\Core\Application\StudentOccupancyAuthority;
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Integrations\BookingStudentNotificationWorker;

global $wpdb;
$p=$wpdb->prefix.'dzn_';
$now=gmdate('Y-m-d H:i:s');
$suffix=strtolower(wp_generate_password(10,false,false));
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$occupancy=new StudentOccupancyAuthority();
$interval=static function(string $start)use($now):array{
    return array(
        'starts_at_utc'=>$start,
        'occupied_ends_at_utc'=>gmdate('Y-m-d H:i:s',strtotime($start.' UTC +45 minutes')),
    );
};
$conflicts=static function(int $student,string $start,bool $open=false)use($occupancy,$interval):array{
    return $occupancy->conflicts($student,array($interval($start)),array('include_open_requests'=>$open));
};

// Synthetic catalogue and Student.
$wpdb->insert($p.'instruments',array('uid'=>Identifier::uid(),'reference_code'=>'DZN-INSTR-REL-'.strtoupper($suffix),'slug'=>'rel-'.$suffix,'name_fa'=>'یکپارچه','name_en'=>'Integration','status'=>'active','created_at'=>$now,'updated_at'=>$now));
$instrument=(int)$wpdb->insert_id;
$wpdb->insert($p.'courses',array('uid'=>Identifier::uid(),'reference_code'=>'DZN-COURSE-REL-'.strtoupper($suffix),'instrument_id'=>$instrument,'name_fa'=>'یکپارچه','name_en'=>'Integration','course_type'=>'introductory','status'=>'active','default_duration_minutes'=>30,'default_buffer_minutes'=>15,'created_at'=>$now,'updated_at'=>$now));
$course=(int)$wpdb->insert_id;
$wpdb->insert($p.'students',array('uid'=>Identifier::uid(),'reference_code'=>'DZN-STU-REL-'.strtoupper($suffix),'status'=>'active','display_name'=>'Synthetic Release','email'=>'release-student-'.$suffix.'@example.invalid','phone'=>'+61400000000','country_code'=>'AU','city'=>'Brisbane','timezone'=>'UTC','locale'=>'en','calendar_preference'=>'auto','created_at'=>$now,'updated_at'=>$now));
$student=(int)$wpdb->insert_id;

// Synthetic Teacher account.
$teacherUser=wp_insert_user(array('user_login'=>'rel-teacher-'.$suffix,'user_pass'=>wp_generate_password(32,true,true),'user_email'=>'release-teacher-'.$suffix.'@example.invalid','role'=>'subscriber','display_name'=>'Synthetic Release Teacher'));
if(is_wp_error($teacherUser))throw new RuntimeException('Teacher user fixture failed');
$wpdb->insert($p.'teachers',array('uid'=>Identifier::uid(),'reference_code'=>'DZN-TEACH-REL-'.strtoupper($suffix),'status'=>'active','display_name'=>'Synthetic Release Teacher','timezone'=>'UTC','email'=>'release-teacher-'.$suffix.'@example.invalid','wordpress_user_id'=>(int)$teacherUser,'created_at'=>$now,'updated_at'=>$now));
$teacher=(int)$wpdb->insert_id;

$snapshot=static function(int $request)use($wpdb,$p,$now,$suffix):void{
    $wpdb->insert($p.'booking_request_contact_snapshots',array('booking_request_id'=>$request,'snapshot_sequence'=>1,'full_name'=>'Synthetic Release','email'=>'release-student-'.$suffix.'@example.invalid','mobile'=>'+61400000000','country'=>'AU','city'=>'Brisbane','timezone'=>'UTC','communication_language'=>'en','whatsapp_same_as_mobile'=>1,'whatsapp_number'=>'+61400000000','privacy_notice_version'=>'2026-09-05','privacy_notice_accepted'=>1,'privacy_notice_accepted_at'=>$now,'submission_source'=>'synthetic_release','created_at'=>$now));
};
$request=static function(string $code,string $lifecycle,string $resolution,string $start)use($wpdb,$p,$student,$instrument,$course,$now,$suffix,$snapshot):array{
    $wpdb->insert($p.'booking_requests',array('uid'=>Identifier::uid(),'reference_code'=>$code.'-'.strtoupper($suffix),'student_id'=>$student,'requested_instrument_id'=>$instrument,'selected_intro_course_id'=>$course,'lifecycle_status'=>$lifecycle,'resolution_state'=>$resolution,'retention_due_at'=>gmdate('Y-m-d H:i:s',strtotime('+24 months',strtotime($now.' UTC'))),'version'=>1,'created_at'=>$now,'updated_at'=>$now));
    $id=(int)$wpdb->insert_id;
    $end=gmdate('Y-m-d H:i:s',strtotime($start.' UTC +30 minutes'));
    $occupied=gmdate('Y-m-d H:i:s',strtotime($start.' UTC +45 minutes'));
    $wpdb->insert($p.'booking_request_requested_times',array('booking_request_id'=>$id,'sequence_number'=>1,'local_date'=>substr($start,0,10),'local_start_time'=>substr($start,11),'timezone'=>'UTC','starts_at_utc'=>$start,'instructional_ends_at_utc'=>$end,'occupied_ends_at_utc'=>$occupied,'instructional_duration_minutes'=>30,'buffer_minutes'=>15,'version'=>1,'created_at'=>$now));
    $time=(int)$wpdb->insert_id;
    $snapshot($id);
    return array('request_id'=>$id,'time_id'=>$time,'starts_at_utc'=>$start,'ends_at_utc'=>$end);
};

// Original confirmed booking with a queued invitation that never reached the provider.
$slot='2027-02-10 10:00:00';
$original=$request('REQ-REL-ORIG','closed','teacher_confirmed',$slot);
$wpdb->insert($p.'booking_request_teacher_deliveries',array('uid'=>Identifier::uid(),'booking_request_id'=>$original['request_id'],'requested_time_id'=>$original['time_id'],'teacher_id'=>$teacher,'state'=>'confirmed','match_strength'=>'strong','created_at'=>$now,'updated_at'=>$now,'responded_at'=>$now));
$delivery=(int)$wpdb->insert_id;
$confirmationUid=Identifier::uid();
$wpdb->insert($p.'booking_request_confirmations',array('uid'=>$confirmationUid,'delivery_id'=>$delivery,'booking_request_id'=>$original['request_id'],'requested_time_id'=>$original['time_id'],'teacher_id'=>$teacher,'connection_id'=>14,'state'=>'confirmed','version'=>1,'starts_at_utc'=>$original['starts_at_utc'],'ends_at_utc'=>$original['ends_at_utc'],'schedule_timezone'=>'UTC','local_wall_date'=>substr($slot,0,10),'local_wall_time'=>substr($slot,11),'provider_code'=>'google_calendar','confirmed_at'=>$now,'created_at'=>$now,'created_by'=>1,'updated_at'=>$now,'updated_by'=>1));
$confirmation=(int)$wpdb->insert_id;

$notification=static function(string $kind,string $state,?int $confirmationId=null)use($wpdb,$p,$delivery,$now):int{
    $wpdb->insert($p.'booking_request_student_notifications',array('uid'=>Identifier::uid(),'delivery_id'=>$delivery,'confirmation_id'=>$confirmationId,'notification_kind'=>$kind,'state'=>$state,'attempt_count'=>0,'next_attempt_at'=>$now,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>null,'handed_off_at'=>null,'created_at'=>$now,'updated_at'=>$now));
    return (int)$wpdb->insert_id;
};
$queuedInvitation=$notification('requested_teacher','queued');

// A verified provider receipt is read alongside the booking notification without changing its state.
$wpdb->insert($p.'booking_request_student_notifications',array('uid'=>Identifier::uid(),'delivery_id'=>$delivery,'confirmation_id'=>null,'notification_kind'=>'requested_teacher','state'=>'handed_off','attempt_count'=>1,'next_attempt_at'=>null,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>null,'handed_off_at'=>$now,'created_at'=>$now,'updated_at'=>$now));
$receiptNotification=(int)$wpdb->insert_id;
$wpdb->insert($p.'telnyx_messages',array('uid'=>Identifier::uid(),'provider'=>'telnyx','channel'=>'whatsapp','message_reference_digest'=>hash('sha256',$suffix.'-receipt'),'notification_id'=>$receiptNotification,'attempt_id'=>0,'provider_template_name'=>'delnavazan_teacher_new_booking_fa','provider_template_language'=>'fa','state'=>'delivered','created_at'=>$now,'updated_at'=>$now));
$receiptStatus=(new NotificationDeliveryStatusReadService())->forBookingRequest($original['request_id']);
$receiptRow=null;
foreach($receiptStatus['rows'] as $candidate){if((int)($candidate['notification_id']??0)===$receiptNotification){$receiptRow=$candidate;break;}}
$assert($receiptRow!==null,'The delivered provider receipt was not projected into booking notification health.');
$assert((string)($receiptRow['provider_receipt_state']??'')==='delivered'&&(string)($receiptRow['provider_channel']??'')==='whatsapp','The delivered WhatsApp receipt was not shown with its channel.');
$assert((string)($receiptRow['provider_receipt_at']??'')!=='','The delivered receipt timestamp was not projected.');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}booking_request_student_notifications WHERE id=%d",$receiptNotification))==='handed_off','A provider receipt must not rewrite the booking notification state.');

// The confirmed booking owns the slot.
$assert($conflicts($student,$slot)!==array(),'A confirmed introductory class must occupy the Student slot before cancellation.');

$user=new WP_User(1);
$user->add_cap('dzn_manage_booking_request_coordination');
$user->add_cap('dzn_operate_notification_dispatch');
wp_set_current_user(1);
$lifecycle=new IntroLessonLifecycleService();
$cancelled=$lifecycle->cancel($original['request_id'],'admin','duplicate_request',$suffix.'-release-cancel-000',$confirmation,1);
$assert(($cancelled['state']??'')==='cancelled','The original confirmed booking was not cancelled.');

// Cancellation releases the Student slot and leaves the queued invitation stale.
$assert($conflicts($student,$slot)===array(),'Cancellation did not release the Student slot.');
$stalePayload=(new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository())->notificationPayload($queuedInvitation);
$assert($stalePayload!==null&&!BookingNotificationRelevance::isStillRelevant($stalePayload),'A queued invitation stayed relevant after cancellation.');

$staleDispatch=BookingStudentNotificationWorker::dispatch($queuedInvitation);
$assert(($staleDispatch['state']??'')==='failed','A stale queued invitation was not terminally refused.');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT last_error_code FROM {$p}booking_request_student_notifications WHERE id=%d",$queuedInvitation))==='invitation_stale','The stale invitation did not record invitation_stale.');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}booking_request_teacher_deliveries WHERE id=%d",$delivery))==='cancelled','Invitation invalidation changed the cancelled delivery.');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT resolution_state FROM {$p}booking_requests WHERE id=%d",$original['request_id']))==='cancelled','Invitation invalidation changed the cancelled request.');

$recovery=new BookingNotificationRecoveryService();
$preview=$recovery->preview($queuedInvitation);
$assert(empty($preview['eligible']),'Recovery offered to resend a cancelled invitation.');
$refused=$recovery->recover($queuedInvitation,$suffix.'-release-recovery-000');
$assert(($refused['state']??'')==='failed'&&($refused['reason']??'')==='invitation_stale','Recovery did not refuse the stale invitation.');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}booking_request_student_notifications WHERE id=%d",$queuedInvitation))==='failed','Recovery requeued a stale invitation.');

// A replacement submission can take the released slot, and only one can.
$replacement=$request('REQ-REL-REPL','submitted','unresolved',$slot);
$intake=$conflicts($student,$slot,true);
$assert($intake!==array()&&(string)$intake[0]['source']==='booking_request'&&(int)$intake[0]['conflict_id']===$replacement['request_id'],'The replacement request did not reserve the released slot for intake.');
$assert($conflicts($student,$slot)===array(),'An unresolved request must not block scheduled occupancy before confirmation.');
$duplicateIntake=$conflicts($student,$slot,true);
$assert(count($duplicateIntake)===1&&(int)$duplicateIntake[0]['conflict_id']===$replacement['request_id'],'A second duplicate submission was not blocked by the open replacement request.');

// Confirming the replacement transfers ownership to the canonical confirmation.
$wpdb->insert($p.'booking_request_teacher_deliveries',array('uid'=>Identifier::uid(),'booking_request_id'=>$replacement['request_id'],'requested_time_id'=>$replacement['time_id'],'teacher_id'=>$teacher,'state'=>'confirmed','match_strength'=>'strong','created_at'=>$now,'updated_at'=>$now,'responded_at'=>$now));
$replacementDelivery=(int)$wpdb->insert_id;
$wpdb->insert($p.'booking_request_confirmations',array('uid'=>Identifier::uid(),'delivery_id'=>$replacementDelivery,'booking_request_id'=>$replacement['request_id'],'requested_time_id'=>$replacement['time_id'],'teacher_id'=>$teacher,'connection_id'=>14,'state'=>'confirmed','version'=>1,'starts_at_utc'=>$replacement['starts_at_utc'],'ends_at_utc'=>$replacement['ends_at_utc'],'schedule_timezone'=>'UTC','local_wall_date'=>substr($slot,0,10),'local_wall_time'=>substr($slot,11),'provider_code'=>'google_calendar','confirmed_at'=>$now,'created_at'=>$now,'created_by'=>1,'updated_at'=>$now,'updated_by'=>1));
$replacementConfirmation=(int)$wpdb->insert_id;
$wpdb->update($p.'booking_requests',array('lifecycle_status'=>'closed','resolution_state'=>'teacher_confirmed'),array('id'=>$replacement['request_id']));
$scheduled=$conflicts($student,$slot);
$assert(count($scheduled)===1&&(string)$scheduled[0]['source']==='booking_confirmation'&&(int)$scheduled[0]['conflict_id']===$replacementConfirmation,'The replacement confirmation did not own the released slot.');
$assert((int)$scheduled[0]['conflict_id']!==$confirmation,'The cancelled confirmation still owned the slot.');

$assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_student_notifications WHERE delivery_id=%d AND notification_kind IN ('cancelled','cancelled_teacher')",$delivery))===2,'Cancellation notices were not queued exactly once for both audiences.');

echo wp_json_encode(array(
    'confirmed_slot'=>'occupied',
    'cancellation'=>'released',
    'queued_invitation'=>'refused_stale',
    'recovery'=>'refused_stale',
    'replacement_intake'=>'reserved_once',
    'replacement_confirmation'=>'owns_slot',
    'cancelled_confirmation'=>'released',
    'cancellation_notices'=>'queued_once',
))."\n";
