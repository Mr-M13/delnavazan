<?php
/** Disposable DB proof for authorised failed-booking-notification recovery. */
if(getenv('DZN_BOOKING_NOTIFICATION_RECOVERY')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Booking notification recovery runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\BookingNotificationRecoveryService;
use Delnavazan\Platform\Core\Application\BookingNotificationRetentionService;
use Delnavazan\Platform\Core\Support\Identifier;

global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');$suffix=strtolower(wp_generate_password(10,false,false));
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$wpdb->insert($p.'instruments',array('uid'=>Identifier::uid(),'reference_code'=>'DZN-INSTR-REC-'.strtoupper($suffix),'slug'=>'rec-'.$suffix,'name_fa'=>'آزمون','name_en'=>'Recover','status'=>'active','created_at'=>$now,'updated_at'=>$now));$instrument=(int)$wpdb->insert_id;
$wpdb->insert($p.'courses',array('uid'=>Identifier::uid(),'reference_code'=>'DZN-COURSE-REC-'.strtoupper($suffix),'instrument_id'=>$instrument,'name_fa'=>'آزمون','name_en'=>'Recover','course_type'=>'introductory','status'=>'active','default_duration_minutes'=>30,'default_buffer_minutes'=>15,'created_at'=>$now,'updated_at'=>$now));$course=(int)$wpdb->insert_id;
$wpdb->insert($p.'students',array('uid'=>Identifier::uid(),'reference_code'=>'DZN-STU-REC-'.strtoupper($suffix),'status'=>'active','display_name'=>'Synthetic Student','email'=>'rec-student-'.$suffix.'@example.invalid','phone'=>'+61400000000','country_code'=>'AU','city'=>'Brisbane','timezone'=>'UTC','locale'=>'en','calendar_preference'=>'auto','created_at'=>$now,'updated_at'=>$now));$student=(int)$wpdb->insert_id;
$userId=wp_insert_user(array('user_login'=>'rec-teacher-'.$suffix,'user_pass'=>wp_generate_password(32,true,true),'user_email'=>'rec-teacher-'.$suffix.'@example.invalid','role'=>'subscriber','display_name'=>'Synthetic Teacher'));if(is_wp_error($userId))throw new RuntimeException('Teacher user failed');$userId=(int)$userId;
$wpdb->insert($p.'teachers',array('uid'=>Identifier::uid(),'reference_code'=>'DZN-TEACH-REC-'.strtoupper($suffix),'status'=>'active','display_name'=>'Synthetic Teacher','timezone'=>'UTC','email'=>'rec-teacher-'.$suffix.'@example.invalid','wordpress_user_id'=>$userId,'created_at'=>$now,'updated_at'=>$now));$teacher=(int)$wpdb->insert_id;
$wpdb->insert($p.'booking_requests',array('uid'=>Identifier::uid(),'reference_code'=>'REQ-REC-'.strtoupper($suffix),'student_id'=>$student,'requested_instrument_id'=>$instrument,'selected_intro_course_id'=>$course,'lifecycle_status'=>'submitted','resolution_state'=>'unresolved','retention_due_at'=>gmdate('Y-m-d H:i:s',strtotime('+24 months',strtotime($now.' UTC'))),'version'=>1,'created_at'=>$now,'updated_at'=>$now));$request=(int)$wpdb->insert_id;
$start=gmdate('Y-m-d H:i:s',strtotime('+10 days 10:00:00 UTC'));$end=gmdate('Y-m-d H:i:s',strtotime($start.' UTC +30 minutes'));$occupied=gmdate('Y-m-d H:i:s',strtotime($start.' UTC +45 minutes'));
$wpdb->insert($p.'booking_request_contact_snapshots',array('booking_request_id'=>$request,'snapshot_sequence'=>1,'full_name'=>'Synthetic Student','email'=>'rec-student-'.$suffix.'@example.invalid','mobile'=>'+61400000000','country'=>'AU','city'=>'Brisbane','timezone'=>'UTC','communication_language'=>'en','whatsapp_same_as_mobile'=>1,'whatsapp_number'=>'+61400000000','privacy_notice_version'=>'2026-09-05','privacy_notice_accepted'=>1,'privacy_notice_accepted_at'=>$now,'submission_source'=>'synthetic_recovery','created_at'=>$now));
$wpdb->insert($p.'booking_request_requested_times',array('booking_request_id'=>$request,'sequence_number'=>1,'local_date'=>substr($start,0,10),'local_start_time'=>substr($start,11),'timezone'=>'UTC','starts_at_utc'=>$start,'instructional_ends_at_utc'=>$end,'occupied_ends_at_utc'=>$occupied,'instructional_duration_minutes'=>30,'buffer_minutes'=>15,'version'=>1,'created_at'=>$now));$time=(int)$wpdb->insert_id;
$wpdb->insert($p.'booking_request_teacher_deliveries',array('uid'=>Identifier::uid(),'booking_request_id'=>$request,'requested_time_id'=>$time,'teacher_id'=>$teacher,'state'=>'pending_request','match_strength'=>'strong','created_at'=>$now,'updated_at'=>$now));$delivery=(int)$wpdb->insert_id;
$wpdb->insert($p.'booking_request_student_notifications',array('uid'=>Identifier::uid(),'delivery_id'=>$delivery,'confirmation_id'=>null,'notification_kind'=>'requested_teacher','state'=>'failed','attempt_count'=>1,'next_attempt_at'=>null,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>'contact_unusable','handed_off_at'=>null,'created_at'=>$now,'updated_at'=>$now));$failed=(int)$wpdb->insert_id;
$user=new WP_User(1);$user->add_cap('dzn_operate_notification_dispatch');wp_set_current_user(1);
$service=new BookingNotificationRecoveryService();
$preview=$service->preview($failed);$assert(!empty($preview['eligible']),'A relevant failed notification with a valid recipient was not eligible.');
$first=$service->recover($failed,$suffix.'-recovery-command-000');
$assert(($first['state']??'')==='queued'&&empty($first['replayed']),'Recovery did not requeue the notification.');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}booking_request_student_notifications WHERE id=%d",$failed))==='queued','Recovery did not persist the queued state.');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}booking_request_teacher_deliveries WHERE id=%d",$delivery))==='pending_request','Recovery changed the Teacher delivery.');
$replay=$service->recover($failed,$suffix.'-recovery-command-000');
$assert(!empty($replay['replayed']),'Recovery replay was not idempotent.');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}platform_audit_events WHERE event_type='booking_request.notification_recovery'")===1,'Recovery audit was duplicated.');

$wpdb->update($p.'booking_request_student_notifications',array('state'=>'failed','last_error_code'=>'contact_unusable','attempt_count'=>1),array('id'=>$failed));
$wpdb->update($p.'booking_requests',array('lifecycle_status'=>'closed','resolution_state'=>'cancelled'),array('id'=>$request));
$wpdb->update($p.'booking_request_teacher_deliveries',array('state'=>'cancelled'),array('id'=>$delivery));
$staleResult=$service->recover($failed,$suffix.'-recovery-command-001');
$assert(($staleResult['state']??'')==='failed'&&($staleResult['reason']??'')==='invitation_stale','A stale invitation was recovered.');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}booking_request_student_notifications WHERE id=%d",$failed))==='failed','A stale invitation was requeued.');

$wpdb->update($p.'booking_requests',array('lifecycle_status'=>'submitted','resolution_state'=>'unresolved'),array('id'=>$request));
$wpdb->update($p.'booking_request_teacher_deliveries',array('state'=>'pending_request'),array('id'=>$delivery));
$wpdb->update($p.'teachers',array('email'=>'invalid-address'),array('id'=>$teacher));
$wpdb->update($p.'booking_request_student_notifications',array('state'=>'failed','last_error_code'=>'contact_unusable','attempt_count'=>1),array('id'=>$failed));
$invalidResult=$service->recover($failed,$suffix.'-recovery-command-002');
$assert(($invalidResult['state']??'')==='failed'&&($invalidResult['reason']??'')==='recipient_contact_unusable','An invalid recipient was recovered.');
$wpdb->update($p.'booking_request_student_notifications',array('state'=>'failed','last_error_code'=>'contact_unusable','attempt_count'=>1,'updated_at'=>'2026-11-02 12:00:00'),array('id'=>$failed));
$wpdb->insert($p.'booking_request_student_notifications',array('uid'=>Identifier::uid(),'delivery_id'=>$delivery,'confirmation_id'=>null,'notification_kind'=>'requested_student','state'=>'failed','attempt_count'=>1,'next_attempt_at'=>null,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>'contact_unusable','handed_off_at'=>null,'created_at'=>$now,'updated_at'=>$now));$recentFailure=(int)$wpdb->insert_id;
$wpdb->insert($p.'booking_request_student_notifications',array('uid'=>Identifier::uid(),'delivery_id'=>$delivery,'confirmation_id'=>null,'notification_kind'=>'confirmed','state'=>'queued','attempt_count'=>0,'next_attempt_at'=>$now,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>null,'handed_off_at'=>null,'created_at'=>$now,'updated_at'=>$now));$queuedNotification=(int)$wpdb->insert_id;
$retention=new BookingNotificationRetentionService();
$closePreview=$retention->preview($failed);$assert(!empty($closePreview['eligible']),'A pre-fix failed notification was not eligible for historical closure.');
$closed=$retention->close($failed,$suffix.'-close-command-000');
$assert(($closed['state']??'')==='closed'&&empty($closed['replayed']),'Historical closure did not close the failed notification.');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}booking_request_student_notifications WHERE id=%d",$failed))==='closed','Historical closure did not persist the closed state.');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT last_error_code FROM {$p}booking_request_student_notifications WHERE id=%d",$failed))==='historical_stale','Historical closure did not record the retention reason.');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}booking_request_teacher_deliveries WHERE id=%d",$delivery))==='pending_request','Historical closure changed the Teacher delivery.');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT lifecycle_status FROM {$p}booking_requests WHERE id=%d",$request))==='submitted','Historical closure changed the booking request.');
$closeReplay=$retention->close($failed,$suffix.'-close-command-000');$assert(!empty($closeReplay['replayed']),'Historical closure replay was not idempotent.');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}platform_audit_events WHERE event_type='booking_request.notification_closed'")===1,'Historical closure audit was duplicated.');
$recentResult=$retention->close($recentFailure,$suffix.'-close-command-001');
$assert(($recentResult['state']??'')==='failed'&&($recentResult['reason']??'')==='notification_not_historical','A recent failure was closed as historical.');
$queuedResult=$retention->close($queuedNotification,$suffix.'-close-command-002');
$assert(($queuedResult['state']??'')==='queued'&&($queuedResult['reason']??'')==='notification_not_failed','A non-failed notification was closed.');
echo wp_json_encode(array('eligible'=>'queued','replay'=>'idempotent','stale'=>'refused','invalid_recipient'=>'refused','historical_close'=>'closed_without_send','recent'=>'refused','booking_state'=>'unchanged')) . "\n";
