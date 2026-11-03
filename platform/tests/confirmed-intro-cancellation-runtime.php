<?php
/** Disposable DB proof for confirmed-intro cancellation, idempotency and retraction recovery. */
if(getenv('DZN_CONFIRMED_CANCELLATION_RUNTIME')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Confirmed cancellation runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{IntegrationSecretService,IntroLessonLifecycleService,ProviderReferenceVault};
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Integrations\BookingCancellationRetractionWorker;

global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');$suffix=strtolower(wp_generate_password(10,false,false));
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$wpdb->insert($p.'instruments',array('uid'=>Identifier::uid(),'reference_code'=>'DZN-INSTR-CANCEL-'.strtoupper($suffix),'slug'=>'cancel-'.$suffix,'name_fa'=>'لغو','name_en'=>'Cancel','status'=>'active','created_at'=>$now,'updated_at'=>$now));$instrument=(int)$wpdb->insert_id;
$wpdb->insert($p.'courses',array('uid'=>Identifier::uid(),'reference_code'=>'DZN-COURSE-CANCEL-'.strtoupper($suffix),'instrument_id'=>$instrument,'name_fa'=>'لغو','name_en'=>'Cancel','course_type'=>'introductory','status'=>'active','default_duration_minutes'=>30,'default_buffer_minutes'=>15,'created_at'=>$now,'updated_at'=>$now));$course=(int)$wpdb->insert_id;
$wpdb->insert($p.'students',array('uid'=>Identifier::uid(),'reference_code'=>'DZN-STU-CANCEL-'.strtoupper($suffix),'status'=>'active','display_name'=>'Synthetic Cancel','email'=>'cancel-'.$suffix.'@example.invalid','phone'=>'+61400000000','country_code'=>'AU','city'=>'Brisbane','timezone'=>'UTC','locale'=>'en','calendar_preference'=>'auto','created_at'=>$now,'updated_at'=>$now));$student=(int)$wpdb->insert_id;
$wpdb->insert($p.'booking_requests',array('uid'=>Identifier::uid(),'reference_code'=>'REQ-CANCEL-'.strtoupper($suffix),'student_id'=>$student,'requested_instrument_id'=>$instrument,'selected_intro_course_id'=>$course,'lifecycle_status'=>'closed','resolution_state'=>'teacher_confirmed','retention_due_at'=>gmdate('Y-m-d H:i:s',strtotime('+24 months',strtotime($now.' UTC'))),'version'=>1,'created_at'=>$now,'updated_at'=>$now));$request=(int)$wpdb->insert_id;
$start=gmdate('Y-m-d H:i:s',strtotime('+10 days 10:00:00 UTC'));$end=gmdate('Y-m-d H:i:s',strtotime($start.' UTC +30 minutes'));$occupied=gmdate('Y-m-d H:i:s',strtotime($start.' UTC +45 minutes'));
$wpdb->insert($p.'booking_request_contact_snapshots',array('booking_request_id'=>$request,'snapshot_sequence'=>1,'full_name'=>'Synthetic Cancel','email'=>'cancel-'.$suffix.'@example.invalid','mobile'=>'+61400000000','country'=>'AU','city'=>'Brisbane','timezone'=>'UTC','communication_language'=>'en','whatsapp_same_as_mobile'=>1,'whatsapp_number'=>'+61400000000','privacy_notice_version'=>'2026-09-05','privacy_notice_accepted'=>1,'privacy_notice_accepted_at'=>$now,'submission_source'=>'synthetic_cancel','created_at'=>$now));
$wpdb->insert($p.'booking_request_requested_times',array('booking_request_id'=>$request,'sequence_number'=>1,'local_date'=>substr($start,0,10),'local_start_time'=>substr($start,11),'timezone'=>'UTC','starts_at_utc'=>$start,'instructional_ends_at_utc'=>$end,'occupied_ends_at_utc'=>$occupied,'instructional_duration_minutes'=>30,'buffer_minutes'=>15,'version'=>1,'created_at'=>$now));$timeId=(int)$wpdb->insert_id;
$wpdb->insert($p.'booking_request_teacher_deliveries',array('uid'=>Identifier::uid(),'booking_request_id'=>$request,'requested_time_id'=>$timeId,'teacher_id'=>10,'state'=>'confirmed','match_strength'=>'strong','created_at'=>$now,'updated_at'=>$now,'responded_at'=>$now));$delivery=(int)$wpdb->insert_id;
$confirmationUid=Identifier::uid();
$wpdb->insert($p.'booking_request_confirmations',array('uid'=>$confirmationUid,'delivery_id'=>$delivery,'booking_request_id'=>$request,'requested_time_id'=>$timeId,'teacher_id'=>10,'connection_id'=>14,'state'=>'confirmed','version'=>1,'starts_at_utc'=>$start,'ends_at_utc'=>$end,'schedule_timezone'=>'UTC','local_wall_date'=>substr($start,0,10),'local_wall_time'=>substr($start,11),'provider_code'=>'google_calendar','confirmed_at'=>$now,'created_at'=>$now,'created_by'=>1,'updated_at'=>$now,'updated_by'=>1));$confirmation=(int)$wpdb->insert_id;
$secrets=new IntegrationSecretService();$sealed=$secrets->seal(14,'google_calendar',wp_json_encode(array('access_token'=>'synthetic-cancel-token')));$wpdb->insert($p.'integration_credentials',array('uid'=>Identifier::uid(),'connection_id'=>14,'credential_sequence'=>1,'state'=>'active','key_version'=>$sealed['key_version'],'cipher_version'=>$sealed['cipher_version'],'nonce'=>$sealed['nonce'],'ciphertext'=>$sealed['ciphertext'],'valid_until_utc'=>gmdate('Y-m-d H:i:s',time()+3600),'created_at'=>$now,'created_by'=>1));
$vault=new ProviderReferenceVault();$refs=$vault->seal('booking_calendar',$confirmation,array('provider_object_reference'=>'abcdef0123456789abcdef0123456789'));$wpdb->insert($p.'provider_projection_secrets',array_merge(array('uid'=>Identifier::uid(),'mapping_kind'=>'booking_calendar','mapping_id'=>$confirmation,'state'=>'active','active_slot'=>1,'created_at'=>$now,'created_by'=>1),$refs));$secret=(int)$wpdb->insert_id;
$wpdb->insert($p.'coordination_cases',array('uid'=>Identifier::uid(),'reference_code'=>'CCS-CANCEL-'.strtoupper($suffix),'booking_request_id'=>$request,'state'=>'open','state_reason_code'=>'manual_review','version'=>1,'created_at'=>$now,'updated_at'=>$now,'created_by'=>1,'updated_by'=>1));$case=(int)$wpdb->insert_id;

$user=new WP_User(1);$user->add_cap('dzn_manage_booking_request_coordination');wp_set_current_user(1);
$service=new IntroLessonLifecycleService();
$first=$service->cancel($request,'admin','duplicate_request',$suffix.'-cancel-command-000',$confirmation,1);
$second=$service->cancel($request,'admin','duplicate_request',$suffix.'-cancel-command-000',$confirmation,1);
$assert(($first['state']??'')==='cancelled'&&($second['state']??'')==='cancelled','Cancellation or replay failed');
$stale=false;try{$service->cancel($request,'admin','duplicate_request',$suffix.'-cancel-command-001',$confirmation,1);}catch(\Throwable $error){$stale=$error->getMessage()==='Booking confirmation changed concurrently';}
$assert($stale,'A stale confirmation version was not refused');
$third=$service->cancel($request,'admin','duplicate_request',$suffix.'-cancel-command-002',$confirmation,0);
$assert(($third['state']??'')==='cancelled','A later command did not converge on the existing cancellation');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}booking_request_cancellations WHERE confirmation_id={$confirmation}")===1,'Duplicate cancellation evidence was written');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}booking_request_cancellation_commands")===1,'Duplicate command evidence was written');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}booking_request_provider_retraction_jobs WHERE confirmation_id={$confirmation}")===1,'Duplicate retraction job was written');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}booking_request_student_notifications WHERE confirmation_id={$confirmation} AND notification_kind IN ('cancelled','cancelled_teacher')")===2,'Cancellation notices were not queued exactly once');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}booking_request_confirmations WHERE id=%d",$confirmation))==='cancelled','Confirmation was not cancelled');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}booking_request_teacher_deliveries WHERE id=%d",$delivery))==='cancelled','Delivery was not cancelled');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT resolution_state FROM {$p}booking_requests WHERE id=%d",$request))==='cancelled','Request resolution was not reconciled');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}coordination_cases WHERE id=%d",$case))==='closed','Coordination case was not closed');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}provider_projection_secrets WHERE id=%d",$secret))==='active','Cancellation must leave the provider reference until retraction succeeds');

$transport=new class{public int $calls=0;public bool $fail=true;public function cancel(string $eventId,string $material):void{$this->calls++;if($this->fail){$this->fail=false;throw new RuntimeException('google_calendar_transport_failed');}}};
$job=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_request_provider_retraction_jobs WHERE confirmation_id=%d AND cancellation_id=(SELECT id FROM {$p}booking_request_cancellations WHERE confirmation_id=%d)",$confirmation,$confirmation));
$failed=BookingCancellationRetractionWorker::dispatch($job,null,$transport);
$assert($failed['state']==='retry'&&!empty($failed['retryable'])&&$transport->calls===1,'Provider failure did not schedule a bounded retry');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}provider_projection_secrets WHERE id=%d",$secret))==='active','Failed retraction retired the provider reference too early');
$wpdb->update($p.'booking_request_provider_retraction_jobs',array('next_attempt_at'=>$now),array('id'=>$job));
$recovered=BookingCancellationRetractionWorker::dispatch($job,null,$transport);
$assert($recovered['state']==='retracted'&&$transport->calls===2,'Provider retry did not converge after recovery');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}provider_projection_secrets WHERE id=%d",$secret))==='retired','Successful retraction did not retire the sealed provider reference');
$assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}booking_request_provider_retraction_jobs WHERE id=%d",$job))==='retracted','Retraction job did not complete');
echo wp_json_encode(array('cancellation'=>'idempotent','evidence'=>'immutable','notice'=>'queued_once','coordination'=>'closed','provider_failure'=>'retry','provider_recovery'=>'retracted')) . "\n";
