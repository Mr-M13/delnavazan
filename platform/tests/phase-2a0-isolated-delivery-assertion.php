<?php
/*
 * Isolated WordPress/MySQL assertion helper. It is intentionally excluded from
 * distribution packages and is never loaded by the plugin. Run only through
 * WP-CLI in a disposable non-production test installation after setting both
 * DZN_PHASE_2A0_RUNTIME_TEST=isolated and a matching database marker option.
 * It never prints an invitation recipient or raw secret.
 */
defined( 'ABSPATH' ) || exit( 1 );

if ( getenv( 'DZN_PHASE_2A0_RUNTIME_TEST' ) !== 'isolated' ) {
	throw new RuntimeException( 'Isolated runtime-test acknowledgement is required.' );
}
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || wp_get_environment_type() === 'production' ) {
	throw new RuntimeException( 'This helper may run only with WP-CLI outside production.' );
}
if ( ! current_user_can( 'dzn_manage_onboarding' ) || ! current_user_can( 'dzn_issue_teacher_invitations' ) ) {
	throw new RuntimeException( 'A controlled administrator is required.' );
}

$runId = (string) getenv( 'DZN_PHASE_2A0_RUNTIME_RUN_ID' );
$generationId = absint( getenv( 'DZN_PHASE_2A0_GENERATION_ID' ) );
if ( $runId === '' || get_option( 'dzn_phase_2a0_isolated_runtime_marker' ) !== $runId || $generationId < 1 ) {
	throw new RuntimeException( 'Disposable database marker or generation identifier is missing.' );
}

function dzn_phase_2a0_runtime_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function dzn_phase_2a0_runtime_has_index( string $table, string $index, bool $unique = false ): bool {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name=%s", $index ) );
	return $row && ( ! $unique || (int) $row->Non_unique === 0 );
}

function dzn_phase_2a0_runtime_assert_schema(): void {
	global $wpdb;
	$prefix = $wpdb->prefix . 'dzn_';
	$completed = (array) get_option( 'dzn_platform_completed_migrations', [] );
	dzn_phase_2a0_runtime_assert( (string) get_option( 'dzn_platform_schema_version' ) === '3', 'Schema version is not 3.' );
	foreach ( ['001_initial_core_schema', '002_principal_invitation_foundation', '003_invitation_recipient_snapshot'] as $migration ) {
		dzn_phase_2a0_runtime_assert( in_array( $migration, $completed, true ), 'Migration is not complete: ' . $migration );
	}
	foreach ( ['teacher_onboarding_states', 'teacher_principal_links', 'student_principal_links', 'teacher_invitations', 'teacher_invitation_generations', 'student_account_invitations', 'student_account_invitation_generations', 'account_claim_attempts', 'platform_audit_events', 'platform_outbox'] as $table ) {
		dzn_phase_2a0_runtime_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prefix . $table ) ) === $prefix . $table, 'Missing table: ' . $table );
	}
	foreach ( [['teacher_principal_links', 'teacher_id', true], ['teacher_principal_links', 'wordpress_user_id', true], ['student_principal_links', 'student_id', true], ['student_principal_links', 'wordpress_user_id', true], ['teacher_invitation_generations', 'invitation_generation', true], ['teacher_invitation_generations', 'secret_digest', true], ['teacher_invitation_generations', 'delivery_key', true], ['account_claim_attempts', 'command_key', true], ['account_claim_attempts', 'generation_owner', true], ['platform_audit_events', 'idempotency_key', true], ['platform_outbox', 'idempotency_key', true]] as [$table, $index, $unique] ) {
		dzn_phase_2a0_runtime_assert( dzn_phase_2a0_runtime_has_index( $prefix . $table, $index, $unique ), 'Missing required index: ' . $table . '.' . $index );
	}
	foreach ( ['recipient_snapshot' => 'varchar(320)', 'recipient_digest' => 'char(64)', 'secret_digest' => 'char(64)'] as $column => $type ) {
		$row = $wpdb->get_row( "SHOW COLUMNS FROM {$prefix}teacher_invitation_generations LIKE '{$column}'" );
		dzn_phase_2a0_runtime_assert( $row && strtolower( $row->Type ) === $type && $row->Null === 'YES', 'Invalid recipient/secret column: ' . $column );
	}
}

function dzn_phase_2a0_assert_no_raw_secret_persistence( string $secret ): void {
	global $wpdb;
	$prefix = $wpdb->prefix . 'dzn_';
	$surfaces = [
		'teacher_invitations' => ['recipient_digest'],
		'teacher_invitation_generations' => ['recipient_snapshot', 'recipient_digest', 'secret_digest', 'delivery_key'],
		'account_claim_attempts' => ['command_key', 'provisioning_marker', 'recovery_reason'],
		'platform_audit_events' => ['event_type', 'reason_code', 'safe_detail', 'idempotency_key'],
		'platform_outbox' => ['event_type', 'idempotency_key', 'status'],
	];
	foreach ( $surfaces as $table => $columns ) {
		foreach ( $columns as $column ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}{$table} WHERE {$column} LIKE %s", '%' . $wpdb->esc_like( $secret ) . '%' ) );
			dzn_phase_2a0_runtime_assert( $count === 0, 'Raw secret reached persisted surface: ' . $table . '.' . $column );
		}
	}
}

dzn_phase_2a0_runtime_assert_schema();
$payload = ( new \Delnavazan\Platform\Core\Application\PrincipalInvitationService() )->prepareDelivery( $generationId );
dzn_phase_2a0_runtime_assert( (int) ( $payload['generation_id'] ?? 0 ) === $generationId, 'Prepared generation does not match request.' );
dzn_phase_2a0_runtime_assert( is_email( $payload['recipient'] ?? '' ) && strlen( (string) ( $payload['secret'] ?? '' ) ) === 64, 'Delivery payload is malformed.' );
dzn_phase_2a0_assert_no_raw_secret_persistence( $payload['secret'] );
unset( $payload['secret'], $payload['recipient'], $payload );
echo "Phase 2A.0 isolated delivery assertion passed; no invitation secret printed.\n";
