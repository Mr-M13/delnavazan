<?php
/**
 * Shared presentation fixtures for the responsive portal contract.
 *
 * Plain PHP, no WordPress runtime. Later packages assert against these cases so
 * that "active class", "absence reported" and "payment state" cannot quietly
 * change meaning between the source contract and the renderer.
 *
 * @package Delnavazan
 */

$lesson = static function ( string $start, string $end ): array {
	return array(
		'lesson_uid'          => 'fixture-lesson',
		'schedule_version_uid' => 'fixture-schedule',
		'starts_at_utc'       => $start,
		'ends_at_utc'         => $end,
		'schedule_timezone'   => 'Asia/Tehran',
		'lifecycle_state'     => 'authorised',
		'delivery_state_summary'   => 'not_recorded',
		'attendance_state_summary' => 'not_recorded',
	);
};

return array(
	'account_states' => array(
		'signed_out' => array(
			'state'   => 'signed_out',
			'student' => array(),
		),
		'student_ok' => array(
			'state'   => 'ok',
			'student' => array( 'first_name' => 'باران', 'full_name' => 'باران فرهمند' ),
		),
		'student_no_data' => array(
			'state'   => 'no_data',
			'student' => array(),
		),
		'student_not_linked' => array(
			'state'   => 'not_linked',
			'student' => array(),
		),
		'student_error' => array(
			'state'   => 'error',
			'student' => array(),
		),
		'teacher_ready' => array(
			'state'   => 'ok',
			'teacher' => array( 'first_name' => 'سارا', 'full_name' => 'سارا نمونه' ),
		),
		'teacher_onboarding_pending' => array(
			'state'   => 'onboarding_required',
			'teacher' => array(),
		),
		'teacher_onboarding_returned' => array(
			'state'   => 'onboarding_required',
			'teacher' => array(),
			'detail'  => 'returned',
		),
		'teacher_no_data' => array(
			'state'   => 'ok',
			'teacher' => array( 'first_name' => 'سارا', 'full_name' => 'سارا نمونه' ),
			'detail'  => 'no_classes',
		),
	),
	'lesson_temporal' => array(
		'start_minus_one_second' => array(
			'lesson'   => $lesson( '2026-10-27 10:00:00', '2026-10-27 11:00:00' ),
			'now'      => '2026-10-27 09:59:59',
			'expected' => 'upcoming',
		),
		'start_exact' => array(
			'lesson'   => $lesson( '2026-10-27 10:00:00', '2026-10-27 11:00:00' ),
			'now'      => '2026-10-27 10:00:00',
			'expected' => 'in_progress',
		),
		'mid_class' => array(
			'lesson'   => $lesson( '2026-10-27 10:00:00', '2026-10-27 11:00:00' ),
			'now'      => '2026-10-27 10:30:00',
			'expected' => 'in_progress',
		),
		'end_minus_one_second' => array(
			'lesson'   => $lesson( '2026-10-27 10:00:00', '2026-10-27 11:00:00' ),
			'now'      => '2026-10-27 10:59:59',
			'expected' => 'in_progress',
		),
		'end_exact' => array(
			'lesson'   => $lesson( '2026-10-27 10:00:00', '2026-10-27 11:00:00' ),
			'now'      => '2026-10-27 11:00:00',
			'expected' => 'ended',
		),
		'end_plus_one_second' => array(
			'lesson'   => $lesson( '2026-10-27 10:00:00', '2026-10-27 11:00:00' ),
			'now'      => '2026-10-27 11:00:01',
			'expected' => 'ended',
		),
		'missing_end' => array(
			'lesson'   => $lesson( '2026-10-27 10:00:00', '' ),
			'now'      => '2026-10-27 10:30:00',
			'expected' => 'unknown',
		),
	),
	'absence' => array(
		'eligible_unreported' => array(
			'absence_available' => true,
			'expected_label'    => 'neutral',
		),
		'not_eligible' => array(
			'absence_available' => false,
			'expected_label'    => 'neutral',
		),
	),
	'payment' => array(
		'required' => array(
			'payment_state' => 'required',
			'action'        => 'pay',
			'amount_minor'  => 1200000,
			'currency'      => 'AUD',
		),
		'processing' => array(
			'payment_state' => 'processing',
			'action'        => 'resume',
			'amount_minor'  => 1200000,
			'currency'      => 'AUD',
		),
		'paid' => array(
			'payment_state' => 'paid',
			'action'        => null,
			'amount_minor'  => 1200000,
			'currency'      => 'AUD',
		),
		'unavailable' => array(
			'payment_state' => 'unavailable',
			'action'        => null,
			'amount_minor'  => 0,
			'currency'      => '',
		),
	),
	'finance' => array(
		'available' => array(
			'finance_state'       => 'available',
			'payable_amount_minor' => 450000,
			'currency'            => 'AUD',
			'expected_scale'      => 2,
		),
		'empty' => array(
			'finance_state' => 'empty',
		),
		'unavailable' => array(
			'finance_state' => 'unavailable',
		),
	),
	'onboarding' => array(
		'first_incomplete_profile' => array(
			'profile_state'      => 'incomplete',
			'availability_state' => 'incomplete',
			'google_state'       => 'not_connected',
			'expected_step'      => 1,
		),
		'first_incomplete_availability' => array(
			'profile_state'      => 'complete',
			'availability_state' => 'incomplete',
			'google_state'       => 'not_connected',
			'expected_step'      => 3,
		),
		'first_incomplete_google' => array(
			'profile_state'      => 'complete',
			'availability_state' => 'complete',
			'google_state'       => 'not_connected',
			'expected_step'      => 4,
		),
		'ready' => array(
			'profile_state'      => 'complete',
			'availability_state' => 'complete',
			'google_state'       => 'connected',
			'expected_step'      => 5,
		),
		'query_may_not_skip_ahead' => array(
			'profile_state'      => 'complete',
			'availability_state' => 'incomplete',
			'google_state'       => 'not_connected',
			'requested_step'     => 5,
			'expected_step'      => 3,
		),
	),
);
