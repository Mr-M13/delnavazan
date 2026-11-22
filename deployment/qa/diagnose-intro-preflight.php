<?php
/**
 * Read-only diagnostic for a failing "Create intro" pre-flight.
 *
 * This payload writes nothing. It loads the same services the admin action
 * uses, runs each precondition in isolation, and prints the exception class
 * and message for every check that refuses. It exists because a failed manual
 * intro creation surfaced only an opaque EWI reference and the host keeps no
 * readable PHP error log for it.
 *
 * It is executed only through deployment/qa/intro-preflight-diagnostic.sh and
 * the workflow-dispatch-only diagnose-intro-preflight.yml.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Refused: WP-CLI execution only.\n" );
	exit( 1 );
}

global $wpdb;

function dzn_diag_emit( array $payload ): void {
	echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
	exit( 0 );
}

/** Run one check and capture its refusal without aborting the report. */
function dzn_diag_check( string $name, callable $check ): array {
	try {
		$value = $check();
		return array( 'check' => $name, 'status' => 'pass', 'detail' => is_scalar( $value ) ? (string) $value : '' );
	} catch ( \Throwable $error ) {
		return array(
			'check'  => $name,
			'status' => 'refused',
			'class'  => get_class( $error ),
			'detail' => $error->getMessage(),
		);
	}
}

$args = isset( $args ) && is_array( $args ) ? array_values( $args ) : array();
$arg  = static function ( int $index ) use ( $args ): string {
	$value = (string) ( $args[ $index ] ?? '' );
	return 'none' === $value ? '' : $value;
};

$student_ref = strtoupper( trim( $arg( 0 ) ) );
$teacher_id  = (int) $arg( 1 );
$course_id   = (int) $arg( 2 );
$local_date  = trim( $arg( 3 ) );
$local_time  = trim( $arg( 4 ) );
$timezone    = trim( $arg( 5 ) );

if ( $student_ref === '' || $teacher_id < 1 || $course_id < 1 || $local_date === '' || $local_time === '' || $timezone === '' ) {
	dzn_diag_emit( array( 'ok' => false, 'error' => 'args_invalid' ) );
}

// Run as a real administrator so capability-guarded read services behave exactly
// as they do in the admin request. Nothing in this payload writes.
$admin_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) );
if ( ! empty( $admin_ids ) ) {
	wp_set_current_user( (int) $admin_ids[0] );
}

$student = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}dzn_students WHERE reference_code=%s",
	$student_ref
) );
$teacher = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}dzn_teachers WHERE id=%d",
	$teacher_id
) );
$course = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}dzn_courses WHERE id=%d",
	$course_id
) );

$report = array(
	'ok'        => true,
	'read_only' => true,
	'target'    => array(
		'student' => $student ? array(
			'id' => (int) $student->id, 'reference' => (string) $student->reference_code,
			'status' => (string) $student->status, 'archived' => null !== $student->archived_at,
			'timezone' => (string) ( $student->timezone ?? '' ), 'city' => (string) ( $student->city ?? '' ),
			'country' => (string) ( $student->country_code ?? '' ),
			'has_email' => trim( (string) ( $student->email ?? '' ) ) !== '',
			'has_phone' => trim( (string) ( $student->phone ?? '' ) ) !== '',
			'has_whatsapp' => trim( (string) ( $student->whatsapp_phone ?? '' ) ) !== '',
		) : null,
		'teacher' => $teacher ? array(
			'id' => (int) $teacher->id, 'name' => (string) $teacher->display_name,
			'status' => (string) $teacher->status, 'archived' => null !== $teacher->archived_at,
			'onboarding_state' => (string) ( $teacher->onboarding_state ?? '' ),
			'readiness_state' => (string) ( $teacher->readiness_state ?? '' ),
			'timezone' => (string) ( $teacher->timezone ?? '' ),
		) : null,
		'course' => $course ? array(
			'id' => (int) $course->id, 'name' => (string) ( $course->name_fa ?: $course->name_en ),
			'course_type' => (string) $course->course_type, 'status' => (string) $course->status,
			'archived' => null !== $course->archived_at,
			'instrument_id' => (int) ( $course->instrument_id ?? 0 ),
			'duration' => (int) $course->default_duration_minutes,
			'buffer' => (int) $course->default_buffer_minutes,
		) : null,
		'slot' => array( 'local_date' => $local_date, 'local_time' => $local_time, 'timezone' => $timezone ),
	),
	'checks'    => array(),
	'facts'     => array(),
);

if ( ! $student || ! $teacher || ! $course ) {
	$report['ok'] = false;
	$report['error'] = 'target_not_found';
	dzn_diag_emit( $report );
}

$report['checks'][] = dzn_diag_check( 'student_active', static function () use ( $student ): string {
	if ( null !== $student->archived_at || (string) $student->status === 'archived' ) {
		throw new RuntimeException( 'Choose an active Student' );
	}
	return 'active';
} );

$report['checks'][] = dzn_diag_check( 'teacher_active', static function () use ( $teacher ): string {
	if ( (string) $teacher->status !== 'active' || null !== $teacher->archived_at ) {
		throw new RuntimeException( 'Choose an active Teacher' );
	}
	return 'active';
} );

$report['checks'][] = dzn_diag_check( 'teacher_ready', static function () use ( $teacher ): string {
	if ( (string) $teacher->onboarding_state !== 'active' || (string) $teacher->readiness_state !== 'ready' ) {
		throw new RuntimeException( 'The Teacher is not ready to accept bookings' );
	}
	return 'ready';
} );

$report['checks'][] = dzn_diag_check( 'course_introductory', static function () use ( $course ): string {
	if ( (string) $course->course_type !== 'introductory' ) {
		throw new RuntimeException( 'Choose an active introductory Course' );
	}
	return 'introductory';
} );

$report['checks'][] = dzn_diag_check( 'teacher_eligible', static function () use ( $teacher_id, $course_id ): string {
	return ( new \Delnavazan\Platform\Core\Application\TeachingEligibilityService() )->effectivelyEligible( $teacher_id, $course_id ) ? 'eligible' : 'not_eligible';
} );

// The admin action's own public preview (read-only): interval, teacher occupancy, availability coverage.
$report['checks'][] = dzn_diag_check( 'service_preview', static function () use ( $course_id, $teacher_id, $local_date, $local_time, $timezone ): string {
	$preview = ( new \Delnavazan\Platform\Core\Application\AdministratorIntroBookingService() )->preview( $course_id, $teacher_id, $local_date, $local_time, $timezone );
	return wp_json_encode( $preview );
} );

// The exact private pre-flight the create action runs before it writes anything.
$report['checks'][] = dzn_diag_check( 'preflight', static function () use ( $student, $teacher_id, $course, $local_date, $local_time, $timezone ): string {
	$service = new \Delnavazan\Platform\Core\Application\AdministratorIntroBookingService();
	$method  = new ReflectionMethod( $service, 'assertAvailableForPreview' );
	$method->setAccessible( true );
	$method->invoke( $service, (int) $student->id, $teacher_id, $course, $local_date, $local_time, $timezone );
	return 'preflight_passed';
} );

try {
	$zone  = new DateTimeZone( $timezone );
	$local = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $local_date . ' ' . $local_time, $zone );
	if ( $local ) {
		$end  = $local->modify( '+' . max( 5, (int) $course->default_duration_minutes ) . ' minutes' );
		$occ  = $end->modify( '+' . max( 0, (int) $course->default_buffer_minutes ) . ' minutes' );
		$utc  = new DateTimeZone( 'UTC' );
		$start_utc = $local->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$end_utc   = $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$occ_utc   = $occ->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$report['facts']['interval'] = array( 'starts_at_utc' => $start_utc, 'ends_at_utc' => $end_utc, 'occupied_ends_at_utc' => $occ_utc );

		$report['checks'][] = dzn_diag_check( 'teacher_occupancy', static function () use ( $teacher_id, $start_utc, $occ_utc ): string {
			$rows = ( new \Delnavazan\Platform\Core\Application\BookingTeacherOccupancyReadService() )->overlapping( $teacher_id, $start_utc, $occ_utc );
			if ( $rows ) {
				throw new RuntimeException( 'occupancy:' . wp_json_encode( $rows ) );
			}
			return 'clear';
		} );

		$report['checks'][] = dzn_diag_check( 'continuation_hold', static function () use ( $teacher_id, $start_utc, $occ_utc ): string {
			\Delnavazan\Platform\Core\Application\CanonicalContinuationCapacityAuthority::assertNoActiveHold( $teacher_id, $start_utc, $occ_utc );
			return 'none';
		} );

		$report['checks'][] = dzn_diag_check( 'commercial_claim', static function () use ( $teacher_id, $start_utc, $occ_utc ): string {
			\Delnavazan\Platform\Core\Application\CommercialCapacityAuthority::assertNoConflictingClaim( $teacher_id, $start_utc, $occ_utc );
			return 'none';
		} );

		$report['checks'][] = dzn_diag_check( 'student_occupancy', static function () use ( $student, $start_utc, $occ_utc ): string {
			$rows = ( new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestRepository() )->studentOccupancyConflicts( (int) $student->id, array( array(
				'starts_at_utc' => $start_utc, 'occupied_ends_at_utc' => $occ_utc,
			) ), array( 'include_open_requests' => true ) );
			if ( $rows ) {
				throw new RuntimeException( 'occupancy:' . wp_json_encode( $rows ) );
			}
			return 'clear';
		} );
	}
} catch ( \Throwable $error ) {
	$report['facts']['interval_error'] = $error->getMessage();
}

$report['facts']['active_legacy_enrolments'] = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}dzn_enrolments WHERE student_id=%d AND course_id=%d AND archived_at IS NULL AND status IN ('active','current')",
	(int) $student->id,
	(int) $course->id
) );

$report['facts']['recent_requests'] = array_values( (array) $wpdb->get_results( $wpdb->prepare(
	"SELECT id,reference_code,lifecycle_status,resolution_state,created_at FROM {$wpdb->prefix}dzn_booking_requests WHERE student_id=%d AND created_at >= %s ORDER BY id DESC LIMIT 5",
	(int) $student->id,
	gmdate( 'Y-m-d H:i:s', time() - 12 * 3600 )
) ) );

$report['facts']['recent_confirmations'] = array_values( (array) $wpdb->get_results( $wpdb->prepare(
	"SELECT c.id,c.state,c.starts_at_utc,c.teacher_id,c.created_at FROM {$wpdb->prefix}dzn_booking_request_confirmations c INNER JOIN {$wpdb->prefix}dzn_booking_requests r ON r.id=c.booking_request_id WHERE r.student_id=%d AND c.created_at >= %s ORDER BY c.id DESC LIMIT 5",
	(int) $student->id,
	gmdate( 'Y-m-d H:i:s', time() - 12 * 3600 )
) ) );

$report['facts']['teacher_capabilities_for_current_user'] = array();
foreach ( array(
	'dzn_manage_booking_request_coordination',
	'dzn_manage_teacher_availability_assent',
	'dzn_override_canonical_lesson_schedule_availability',
	'dzn_manage_post_intro_automation',
) as $capability ) {
	$report['facts']['teacher_capabilities_for_current_user'][ $capability ] = current_user_can( $capability );
}

dzn_diag_emit( $report );
