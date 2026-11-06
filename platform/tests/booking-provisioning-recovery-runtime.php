<?php
/** MariaDB runtime proof that provider failure preserves Teacher acceptance and portal visibility. */
if ( getenv( 'DZN_BOOKING_PROVISIONING_RUNTIME' ) !== 'runtime' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) exit( 1 );

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository;
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Integrations\BookingCalendarProjectionService;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$now = gmdate( 'Y-m-d H:i:s' );
$suffix = strtolower( wp_generate_password( 10, false, false ) );
$start = gmdate( 'Y-m-d H:i:s', time() + 172800 );
$end = gmdate( 'Y-m-d H:i:s', time() + 172800 + 1800 );
$assert = static function ( bool $ok, string $message ): void { if ( ! $ok ) throw new RuntimeException( $message ); };

$wpdb->insert( $p . 'instruments', array( 'uid' => Identifier::uid(), 'reference_code' => 'DZN-INSTR-REC-' . strtoupper( $suffix ), 'slug' => 'booking-rec-' . $suffix, 'name_fa' => 'ساز بازیابی', 'name_en' => 'Recovery Instrument', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
$instrument = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'courses', array( 'uid' => Identifier::uid(), 'reference_code' => 'DZN-COURSE-REC-' . strtoupper( $suffix ), 'instrument_id' => $instrument, 'name_fa' => 'دورهٔ بازیابی', 'name_en' => 'Recovery Course', 'course_type' => 'introductory', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15, 'created_at' => $now, 'updated_at' => $now ) );
$course = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'teachers', array( 'uid' => Identifier::uid(), 'reference_code' => 'DZN-TCH-REC-' . strtoupper( $suffix ), 'status' => 'active', 'display_name' => 'Runtime Recovery Teacher', 'english_name' => 'Runtime Recovery Teacher', 'email' => 'runtime-recovery-' . $suffix . '@example.invalid', 'timezone' => 'Asia/Tehran', 'locale' => 'fa_IR', 'calendar_preference' => 'persian', 'created_at' => $now, 'updated_at' => $now ) );
$teacher = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'booking_requests', array( 'uid' => Identifier::uid(), 'reference_code' => 'REQ-REC-' . strtoupper( $suffix ), 'student_id' => null, 'requested_instrument_id' => $instrument, 'selected_intro_course_id' => $course, 'lifecycle_status' => 'submitted', 'resolution_state' => 'unresolved', 'retention_due_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+24 months', strtotime( $now . ' UTC' ) ) ), 'version' => 1, 'created_at' => $now, 'updated_at' => $now ) );
$request = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'booking_request_requested_times', array( 'booking_request_id' => $request, 'sequence_number' => 1, 'local_date' => substr( $start, 0, 10 ), 'local_start_time' => substr( $start, 11 ), 'timezone' => 'Asia/Tehran', 'starts_at_utc' => $start, 'instructional_ends_at_utc' => $end, 'occupied_ends_at_utc' => gmdate( 'Y-m-d H:i:s', strtotime( $end . ' UTC +15 minutes' ) ), 'instructional_duration_minutes' => 30, 'buffer_minutes' => 15, 'version' => 1, 'created_at' => $now ) );
$time = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'booking_request_contact_snapshots', array( 'booking_request_id' => $request, 'snapshot_sequence' => 1, 'full_name' => 'Runtime Learner', 'email' => 'runtime-learner-' . $suffix . '@example.invalid', 'mobile' => '+61400000000', 'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane', 'communication_language' => 'en', 'whatsapp_same_as_mobile' => 1, 'whatsapp_number' => '+61400000000', 'privacy_notice_version' => 'runtime', 'privacy_notice_accepted' => 1, 'privacy_notice_accepted_at' => $now, 'submission_source' => 'runtime_fixture', 'created_at' => $now ) );
$wpdb->insert( $p . 'booking_request_teacher_deliveries', array( 'uid' => Identifier::uid(), 'booking_request_id' => $request, 'requested_time_id' => $time, 'teacher_id' => $teacher, 'state' => 'accepting', 'match_strength' => 'manual', 'created_at' => $now, 'updated_at' => $now ) );
$delivery = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'booking_allocation_offers', array( 'booking_request_id' => $request, 'delivery_id' => $delivery, 'teacher_id' => $teacher, 'requested_time_id' => $time, 'offer_sequence' => 1, 'state' => 'accepted', 'active_slot' => null, 'match_strength' => 'manual', 'offered_at' => $now, 'deadline_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'responded_at' => $now, 'reason_code' => 'teacher_accepted' ) );
$confirmationUid = Identifier::uid();
$wpdb->insert( $p . 'booking_request_confirmations', array( 'uid' => $confirmationUid, 'delivery_id' => $delivery, 'booking_request_id' => $request, 'requested_time_id' => $time, 'teacher_id' => $teacher, 'connection_id' => 0, 'state' => 'failed', 'version' => 4, 'starts_at_utc' => $start, 'ends_at_utc' => $end, 'schedule_timezone' => 'Asia/Tehran', 'local_wall_date' => substr( $start, 0, 10 ), 'local_wall_time' => substr( $start, 11 ), 'provider_code' => 'google_calendar', 'failure_reason_code' => 'meet_cohost_assignment_blocked', 'attempt_count' => 6, 'next_attempt_at' => null, 'confirmed_at' => null, 'created_at' => $now, 'created_by' => 1, 'updated_at' => $now, 'updated_by' => 1 ) );
$confirmation = (int) $wpdb->insert_id;

$rows = ( new BookingTeacherDeliveryRepository() )->acceptedProvisioningForTeacher( $teacher, 10 );
$assert( count( $rows ) === 1, 'The accepted booking must remain visible without an active allocation offer.' );
$assert( (string) $rows[0]->confirmation_state === 'failed' && (string) $rows[0]->delivery_state === 'accepting', 'The portal read must expose the accepted and failed provisioning state.' );
$assert( (int) $rows[0]->confirmation_id === $confirmation && (string) $rows[0]->student_name === 'Runtime Learner', 'The portal read must expose the exact confirmation, Student and instrument.' );

// Keep the local runtime deterministic and prove that a paused organizer never reverses acceptance.
putenv( 'DZN_MEET_CLASSROOM_ENABLED=0' );
$result = ( new BookingCalendarProjectionService() )->retryFailed( $confirmation, 4, 'Runtime recovery verification' );
$assert( in_array( (string) ( $result['state'] ?? '' ), array( 'retryable', 'failed' ), true ), 'A paused organizer must leave the booking in a recoverable state.' );

$deliveryState = (string) $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$p}booking_request_teacher_deliveries WHERE id=%d", $delivery ) );
$requestRow = $wpdb->get_row( $wpdb->prepare( "SELECT lifecycle_status,resolution_state FROM {$p}booking_requests WHERE id=%d", $request ) );
$confirmationState = (string) $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$p}booking_request_confirmations WHERE id=%d", $confirmation ) );
$offerState = $wpdb->get_row( $wpdb->prepare( "SELECT state,active_slot FROM {$p}booking_allocation_offers WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1", $request ) );
$offerCount = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_allocation_offers WHERE booking_request_id=%d", $request ) );
$notificationCount = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_request_student_notifications WHERE delivery_id=%d", $delivery ) );
$auditCount = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}platform_audit_events WHERE aggregate_type='booking_confirmation' AND aggregate_id=%d AND event_type='projection_retry_authorised'", $confirmation ) );

$assert( $deliveryState === 'accepting', 'Provider retry must not reverse the accepted delivery.' );
$assert( (string) $requestRow->lifecycle_status === 'submitted' && (string) $requestRow->resolution_state === 'unresolved', 'Provider retry must not reopen, resolve or supersede the request.' );
$assert( $confirmationState === 'retryable', 'The confirmation must remain durably retryable while provisioning is paused.' );
$assert( $offerCount === 1 && (string) $offerState->state === 'accepted' && $offerState->active_slot === null, 'Recovery must not create or reactivate a Teacher offer.' );
$assert( $notificationCount === 0, 'A failed or paused provisioning attempt must not queue a Student confirmation or joining link.' );
$assert( $auditCount === 1, 'The administrator retry must create exactly one durable audit event.' );

// The dashboard read path must apply the same eligibility rule against real rows.
$wpdb->update( $p . 'booking_request_confirmations', array( 'state' => 'failed', 'failure_reason_code' => 'meet_cohost_assignment_blocked', 'updated_at' => $now ), array( 'id' => $confirmation ) );
$read = new \Delnavazan\Platform\Core\Application\EnrolmentWorkspaceReadService();
$confirmedBooking = new ReflectionMethod( $read, 'confirmedBooking' );
$confirmedBooking->setAccessible( true );
$case = $confirmedBooking->invoke( $read, $request );
$assert( is_array( $case ), 'The dashboard must project the failed confirmation.' );
$assert( $case['recovery_eligible'] === true && $case['recovery_block_reason'] === null, 'The dashboard must offer exactly the retry that the mutation accepts.' );

// A live offer makes both the display and mutation ineligible.
$wpdb->update( $p . 'booking_allocation_offers', array( 'state' => 'pending', 'active_slot' => 1 ), array( 'booking_request_id' => $request ) );
$caseWithOffer = $confirmedBooking->invoke( $read, $request );
$assert( $caseWithOffer['recovery_eligible'] === false && $caseWithOffer['recovery_block_reason'] === 'allocation_superseded', 'A live re-offer must suppress the dashboard retry before the mutation is attempted.' );

echo wp_json_encode( array(
    'portal_visible' => 'pass',
    'delivery_preserved' => $deliveryState,
    'request_preserved' => 'submitted_unresolved',
    'offers' => $offerCount,
    'notifications' => $notificationCount,
    'audit_events' => $auditCount,
    'confirmation' => $confirmationState,
) ) . "\n";
