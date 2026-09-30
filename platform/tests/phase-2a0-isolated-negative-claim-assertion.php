<?php
/*
 * Disposable WP-CLI-only negative-claim assertion. It is never loaded by the
 * plugin and is deliberately excluded from installable packages. A secret is
 * generated only by prepareDelivery(), is never printed, and is cleared before
 * this helper reports its safe result.
 */
defined( 'ABSPATH' ) || exit( 1 );

if ( getenv( 'DZN_PHASE_2A0_RUNTIME_TEST' ) !== 'isolated' || ! defined( 'WP_CLI' ) || ! WP_CLI || wp_get_environment_type() === 'production' ) {
	echo "Negative-claim assertion refused.\n";
	exit( 1 );
}

$run_id = (string) getenv( 'DZN_PHASE_2A0_RUNTIME_RUN_ID' );
$generation_id = absint( getenv( 'DZN_PHASE_2A0_GENERATION_ID' ) );
$mode = (string) getenv( 'DZN_PHASE_2A0_NEGATIVE_CLAIM_MODE' );
$modes = [ 'expired', 'revoked', 'superseded', 'double_claim' ];

if ( $run_id === '' || get_option( 'dzn_phase_2a0_isolated_runtime_marker' ) !== $run_id || $generation_id < 1 || ! in_array( $mode, $modes, true ) || ! current_user_can( 'dzn_manage_onboarding' ) || ! current_user_can( 'dzn_issue_teacher_invitations' ) ) {
	echo "Negative-claim assertion refused.\n";
	exit( 1 );
}

function dzn_phase_2a0_negative_no_raw_secret( string $secret ): bool {
	global $wpdb;
	$prefix = $wpdb->prefix . 'dzn_';
	$surfaces = [
		'teacher_invitations' => [ 'recipient_digest' ],
		'teacher_invitation_generations' => [ 'recipient_snapshot', 'recipient_digest', 'secret_digest', 'delivery_key' ],
		'account_claim_attempts' => [ 'command_key', 'provisioning_marker', 'recovery_reason' ],
		'platform_audit_events' => [ 'event_type', 'reason_code', 'safe_detail', 'idempotency_key' ],
		'platform_outbox' => [ 'event_type', 'idempotency_key', 'status' ],
	];

	foreach ( $surfaces as $table => $columns ) {
		foreach ( $columns as $column ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}{$table} WHERE {$column} LIKE %s", '%' . $wpdb->esc_like( $secret ) . '%' ) );
			if ( $count !== 0 ) {
				return false;
			}
		}
	}

	$log = WP_CONTENT_DIR . '/debug.log';
	return ! is_readable( $log ) || strpos( (string) file_get_contents( $log ), $secret ) === false;
}

function dzn_phase_2a0_negative_rejected( callable $operation ): bool {
	try {
		$operation();
		return false;
	} catch ( Throwable $ignored ) {
		return true;
	}
}

global $wpdb;
$service = new \Delnavazan\Platform\Core\Application\PrincipalInvitationService();
$current_user = get_current_user_id();
$secret = null;
$recipient = null;
$safe_result = false;
$unexpected = false;

try {
	$generation = $wpdb->get_row( $wpdb->prepare(
		"SELECT g.id, i.teacher_id FROM {$wpdb->prefix}dzn_teacher_invitation_generations g INNER JOIN {$wpdb->prefix}dzn_teacher_invitations i ON i.id = g.invitation_id WHERE g.id = %d",
		$generation_id
	) );
	if ( ! $generation ) {
		throw new RuntimeException( 'missing_generation' );
	}

	$payload = $service->prepareDelivery( $generation_id );
	$secret = (string) ( $payload['secret'] ?? '' );
	$recipient = (string) ( $payload['recipient'] ?? '' );
	unset( $payload );
	if ( strlen( $secret ) !== 64 || ! is_email( $recipient ) ) {
		throw new RuntimeException( 'invalid_delivery_payload' );
	}

	if ( $mode === 'expired' ) {
		$wpdb->update( $wpdb->prefix . 'dzn_teacher_invitation_generations', [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ], [ 'id' => $generation_id ] );
		$safe_result = dzn_phase_2a0_negative_rejected( static fn() => $service->beginProvisioning( $secret, 'runtime-negative-expired-' . $generation_id ) );
	} elseif ( $mode === 'revoked' ) {
		$service->revoke( (int) $generation->teacher_id, 'runtime_negative_revoke', 'runtime-negative-revoke-' . $generation_id );
		$safe_result = dzn_phase_2a0_negative_rejected( static fn() => $service->beginProvisioning( $secret, 'runtime-negative-revoked-' . $generation_id ) );
	} elseif ( $mode === 'superseded' ) {
		$service->issue( (int) $generation->teacher_id, 'dzn2a0-superseded-' . $generation_id . '@example.invalid', 900, 'runtime-negative-reissue-' . $generation_id );
		$safe_result = dzn_phase_2a0_negative_rejected( static fn() => $service->beginProvisioning( $secret, 'runtime-negative-superseded-' . $generation_id ) );
	} else {
		$user_id = email_exists( $recipient );
		if ( ! $user_id ) {
			$user_id = wp_create_user( 'dzn2a0claim' . $generation_id, bin2hex( random_bytes( 16 ) ), $recipient );
		}
		if ( is_wp_error( $user_id ) || ! $user_id ) {
			throw new RuntimeException( 'controlled_user_failed' );
		}
		wp_set_current_user( (int) $user_id );
		$service->beginExistingClaim( $secret, (int) $user_id, 'runtime-negative-first-' . $generation_id );
		$safe_result = dzn_phase_2a0_negative_rejected( static fn() => $service->beginExistingClaim( $secret, (int) $user_id, 'runtime-negative-second-' . $generation_id ) );
		wp_set_current_user( $current_user );
	}
} catch ( Throwable $ignored ) {
	$unexpected = true;
}

$no_raw_secret = is_string( $secret ) && $secret !== '' && dzn_phase_2a0_negative_no_raw_secret( $secret );
unset( $secret, $recipient, $generation, $service );
wp_set_current_user( $current_user );

if ( $unexpected || ! $safe_result || ! $no_raw_secret ) {
	echo "Negative-claim assertion failed.\n";
	exit( 1 );
}

echo "Negative-claim assertion passed for generation {$generation_id}; no invitation secret printed.\n";
