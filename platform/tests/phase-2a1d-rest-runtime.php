<?php
/**
 * Disposable REST-boundary assertion. Run only with
 * DZN_PHASE_2A1D_RUNTIME_TEST=isolated; output intentionally excludes all
 * request data, references, idempotency keys, and transient identifiers.
 */
if ( getenv('DZN_PHASE_2A1D_RUNTIME_TEST') !== 'isolated' || wp_get_environment_type() === 'production' ) { fwrite(STDERR,"Refusing non-isolated runtime\n"); exit(2); }

use Delnavazan\Platform\Public\BookingRequestRestController;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$now = gmdate('Y-m-d H:i:s');
foreach ( array('booking_request_privacy_tombstones','booking_request_duplicate_flags','booking_request_submission_keys','booking_request_requested_times','booking_request_contact_snapshots','booking_requests','courses','instruments') as $table ) $wpdb->query("DELETE FROM {$p}{$table}");
$wpdb->insert($p.'instruments',array('uid'=>'RUNTIMEIDEMPOTENCY00000000','reference_code'=>'DZN-INSTR-IDEMPOTENCY','slug'=>'runtime-idempotency','name_fa'=>'Runtime','name_en'=>'Runtime','status'=>'active','created_at'=>$now,'updated_at'=>$now));
$instrument = (int) $wpdb->insert_id;
$wpdb->insert($p.'courses',array('uid'=>'RUNTIMEIDEMPOTENCYCOURSE00','reference_code'=>'DZN-COURSE-IDEMPOTENCY','instrument_id'=>$instrument,'name_fa'=>'Runtime','name_en'=>'Runtime','course_type'=>'introductory','status'=>'active','default_duration_minutes'=>45,'default_buffer_minutes'=>10,'created_at'=>$now,'updated_at'=>$now));
$course = (int) $wpdb->insert_id;
if ( ! $instrument || ! $course ) throw new RuntimeException('Synthetic REST fixture failed');

$_SERVER['REMOTE_ADDR'] = '203.0.113.97';
$rateDigest = hash_hmac('sha256', 'rate:' . $_SERVER['REMOTE_ADDR'], wp_salt('dzn_booking_request_rate'));
$rateKey = 'dzn_br_rate_' . substr($rateDigest, 0, 40);
delete_transient($rateKey);

$input = array('requested_instrument_id'=>$instrument,'selected_intro_course_id'=>$course,'full_name'=>'Synthetic REST','email'=>'phase2a1d-rest@example.invalid','mobile'=>'+61400123997','country'=>'AU','city'=>'Brisbane','timezone'=>'Australia/Brisbane','communication_language'=>'en','whatsapp_same_as_mobile'=>true,'whatsapp_number'=>'','privacy_notice_accepted'=>true,'privacy_notice_version'=>'2026-09-05','requested_times'=>array(array('local_date'=>'2026-10-22','local_start_time'=>'09:00','timezone'=>'Australia/Brisbane')));
$submit = static function(array $payload, string $key): \WP_REST_Response { $request = new \WP_REST_Request('POST','/delnavazan-platform/v1/booking-requests'); $request->set_header('content-type','application/json'); $request->set_header('idempotency-key',$key); $request->set_body(wp_json_encode($payload)); return BookingRequestRestController::submit($request); };
$assertSuccessShape = static function(\WP_REST_Response $response): array { $data=$response->get_data(); if($response->get_status()!==201||array_keys($data)!==array('success','request_reference')||$data['success']!==true||!is_string($data['request_reference'])||$data['request_reference']==='')throw new RuntimeException('Public response contract failed'); return $data; };

$first = $assertSuccessShape($submit($input,'runtime-rest-key-original-000000000001'));
$replay = $assertSuccessShape($submit($input,'runtime-rest-key-original-000000000001'));
if ( $replay['request_reference'] !== $first['request_reference'] || (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}booking_requests") !== 1 ) throw new RuntimeException('Replay created an additional request');
$changed = $input; $changed['requested_times'][0]['local_start_time']='10:00';
$conflict = $submit($changed,'runtime-rest-key-original-000000000001');
if ( $conflict->get_status() !== 409 || (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}booking_requests") !== 1 ) throw new RuntimeException('Idempotency conflict contract failed');

for($i=0;$i<4;$i++){ $attempt=$input; $attempt['email']='phase2a1d-rest-'.$i.'@example.invalid'; $attempt['requested_times'][0]['local_date']='2026-10-'.(23+$i); $assertSuccessShape($submit($attempt,'runtime-rest-key-fill-00000000000'.$i)); }
$replayAfterLimit = $assertSuccessShape($submit($input,'runtime-rest-key-original-000000000001'));
if($replayAfterLimit['request_reference']!==$first['request_reference'])throw new RuntimeException('Rate limit overrode replay');
$conflictAfterLimit = $submit($changed,'runtime-rest-key-original-000000000001');
if($conflictAfterLimit->get_status()!==409)throw new RuntimeException('Rate limit overrode conflict');
$beforeLimited=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}booking_requests"); $limited=$submit($input,'runtime-rest-key-new-limited-00000000001');
if($limited->get_status()!==429||(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}booking_requests")!==$beforeLimited)throw new RuntimeException('New intake was not rate limited');

delete_transient($rateKey);
add_filter('pre_add_option__transient_timeout_'.$rateKey,static fn() => false,PHP_INT_MAX);
add_filter('pre_add_option__transient_'.$rateKey,static fn() => false,PHP_INT_MAX);
$failOpen = $input; $failOpen['email']='phase2a1d-rest-fail-open@example.invalid'; $failOpen['requested_times'][0]['local_date']='2026-10-30';
$assertSuccessShape($submit($failOpen,'runtime-rest-key-fail-open-000000001'));
remove_all_filters('pre_add_option__transient_timeout_'.$rateKey);
remove_all_filters('pre_add_option__transient_'.$rateKey);
delete_transient($rateKey);
echo "Phase 2A.1-D REST runtime passed\n";
