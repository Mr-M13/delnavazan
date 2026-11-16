<?php
/**
 * Go-live test-data purge — guarded one-shot payload.
 *
 * Executed only through deployment/qa/purge-test-data.sh, which:
 *   - pins the deployed build to the exact current main SHA,
 *   - takes and verifies a full database export first,
 *   - runs `report` and prints a pre-state fingerprint,
 *   - requires the operator to pass that fingerprint plus the exact confirm
 *     token before `execute` deletes anything.
 *
 * Scope (all rows are pre-launch test data, per the owner's written decision):
 *   - purge every Student, Teacher, booking, enrolment, lesson, attendance,
 *     finance, consent-activity, notification-activity, provider and God Mode
 *     test row listed in purge-test-data-tables.php;
 *   - delete every WordPress user except the retained administrator and the
 *     two dedicated automation identities;
 *   - delete public research-name submissions (`dzn_research_name` posts);
 *   - preserve the catalogue, commercial policy, finance policy, notification
 *     template/workflow, provider-account, meet-workspace and consent-purpose
 *     configuration listed as `preserve`.
 *
 * The payload never calls a provider. Google Calendar/Meet events, LiveKit
 * rooms and Stripe objects are left for provider-side cleanup and are recorded
 * as such in the receipt.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Refused: WP-CLI execution only.\n" );
	exit( 1 );
}

$dzn_purge_args = isset( $args ) && is_array( $args ) ? array_values( $args ) : array();
$dzn_purge_arg  = static function ( int $index ) use ( $dzn_purge_args ): string {
	$value = (string) ( $dzn_purge_args[ $index ] ?? '' );
	return 'none' === $value ? '' : $value;
};
$dzn_mode        = strtolower( '' !== $dzn_purge_arg( 0 ) ? $dzn_purge_arg( 0 ) : 'report' );
$dzn_confirm     = $dzn_purge_arg( 1 );
$dzn_expected    = strtolower( $dzn_purge_arg( 2 ) );
$dzn_backup      = $dzn_purge_arg( 3 );
$dzn_backup_sha  = strtolower( $dzn_purge_arg( 4 ) );
$dzn_plan_file   = $dzn_purge_arg( 5 );

$dzn_purge_confirm_token = 'PURGE_ALL_TEST_DATA';
$dzn_admin_email         = 'hamed.moqadasi@yahoo.com';
$dzn_service_logins      = array( 'dzn_post_intro_automation', 'dzn_provider_evidence' );
$dzn_system_user_options = array(
	'dzn_post_intro_automation_user_id',
	'dzn_platform_payment_worker_principal',
	'dzn_reschedule_system_user_id',
	'dzn_reschedule_calendar_system_user_id',
	'dzn_replacement_system_user_id',
	'dzn_platform_provider_projection_worker_principal',
	'dzn_notification_dispatch_user_id',
	'dzn_provider_evidence_system_user_id',
);
$dzn_research_post_type  = 'dzn_research_name';

// eval-file runs inside a closure, so named helpers cannot rely on the
// enclosing scope's locals. Publish the fixed policy values as constants.
if ( ! defined( 'DZN_PURGE_CONFIRM_TOKEN' ) ) {
	define( 'DZN_PURGE_CONFIRM_TOKEN', $dzn_purge_confirm_token );
	define( 'DZN_PURGE_ADMIN_EMAIL', $dzn_admin_email );
	define( 'DZN_PURGE_SERVICE_LOGINS', $dzn_service_logins );
	define( 'DZN_PURGE_SYSTEM_USER_OPTIONS', $dzn_system_user_options );
	define( 'DZN_PURGE_RESEARCH_POST_TYPE', $dzn_research_post_type );
}

global $wpdb;

/** Emit one JSON line and exit. */
function dzn_purge_emit( array $payload, int $code = 0 ): void {
	$payload['deletions_performed'] = ! empty( $payload['deletions_performed'] );
	echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
	exit( $code );
}

function dzn_purge_fail( string $error, string $stage, array $extra = array() ): void {
	dzn_purge_emit( array_merge( array( 'ok' => false, 'error' => $error, 'stage' => $stage ), $extra ), 1 );
}

/** Only whitelisted identifier shapes reach SQL. */
function dzn_purge_ident( string $name ): string {
	if ( ! preg_match( '/\A[a-z0-9_]+\z/', $name ) ) {
		throw new RuntimeException( 'invalid_table_identifier' );
	}
	return '`' . $name . '`';
}

function dzn_purge_table_name( string $short ): string {
	global $wpdb;
	return $wpdb->prefix . 'dzn_' . $short;
}

function dzn_purge_count( string $physical ): int {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . dzn_purge_ident( $physical ) );
}

function dzn_purge_plan( string $file ): array {
	if ( '' === $file || ! is_readable( $file ) || 'purge-test-data-tables.php' !== basename( $file ) ) {
		throw new RuntimeException( 'plan_file_unavailable' );
	}
	$plan = require $file;
	if ( ! is_array( $plan ) || ! isset( $plan['preserve'], $plan['purge'] ) ) {
		throw new RuntimeException( 'plan_file_invalid' );
	}
	return $plan;
}

function dzn_purge_state( array $plan ): array {
	global $wpdb;
	$prefix      = $wpdb->prefix . 'dzn_';
	$like        = $wpdb->esc_like( $prefix ) . '%';
	$discovered  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	$short       = array();
	foreach ( (array) $discovered as $physical ) {
		$short[] = substr( (string) $physical, strlen( $prefix ) );
	}
	sort( $short );

	$known      = array_merge( $plan['preserve'], $plan['purge'] );
	$unclassified = array_values( array_diff( $short, $known ) );
	// Absent tables are migrations this deployment has not applied yet. They
	// hold no rows, so they are reported but never a reason to refuse.
	$absent = array_values( array_diff( $known, $short ) );

	$counts = array();
	foreach ( $short as $name ) {
		$counts[ $name ] = dzn_purge_count( $prefix . $name );
	}

	$preserve_counts = array();
	foreach ( $plan['preserve'] as $name ) {
		$preserve_counts[ $name ] = $counts[ $name ] ?? 0;
	}
	$purge_counts = array();
	$purge_total  = 0;
	foreach ( $plan['purge'] as $name ) {
		$purge_counts[ $name ] = $counts[ $name ] ?? 0;
		$purge_total         += $purge_counts[ $name ];
	}

	$users       = get_users( array( 'fields' => 'ID' ) );
	$role_counts = array();
	foreach ( (array) $users as $userId ) {
		$user = get_userdata( (int) $userId );
		if ( ! $user ) {
			continue;
		}
		foreach ( (array) $user->roles as $role ) {
			$role_counts[ (string) $role ] = ( $role_counts[ (string) $role ] ?? 0 ) + 1;
		}
	}
	ksort( $role_counts );

	$research_posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s", 'dzn_research_name' ) );
	$schema         = (string) get_option( 'dzn_platform_schema_version', '' );
	$environment    = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown';

	$fingerprint_material = array(
		'schema'   => $schema,
		'tables'   => $counts,
		'users'    => count( (array) $users ),
		'roles'    => $role_counts,
		'research' => $research_posts,
	);

	return array(
		'prefix'            => $wpdb->prefix,
		'discovered'        => $short,
		'unclassified'      => $unclassified,
		'absent'            => $absent,
		'counts'            => $counts,
		'preserve_counts'   => $preserve_counts,
		'purge_counts'      => $purge_counts,
		'purge_total'       => $purge_total,
		'user_total'        => count( (array) $users ),
		'role_counts'       => $role_counts,
		'research_posts'    => $research_posts,
		'schema'            => $schema,
		'environment'       => $environment,
		'fingerprint'       => hash( 'sha256', (string) wp_json_encode( $fingerprint_material ) ),
	);
}

function dzn_purge_admin(): ?WP_User {
	$user = get_user_by( 'email', DZN_PURGE_ADMIN_EMAIL );
	if ( ! $user instanceof WP_User ) {
		return null;
	}
	if ( ! user_can( $user, 'manage_options' ) ) {
		return null;
	}
	return $user;
}

function dzn_purge_kept_user_ids( int $adminId ): array {
	$keep = array( $adminId );
	foreach ( (array) DZN_PURGE_SERVICE_LOGINS as $login ) {
		$user = get_user_by( 'login', $login );
		if ( $user instanceof WP_User ) {
			$keep[] = (int) $user->ID;
		}
	}
	// Dedicated worker principals referenced by reviewed options. Only a
	// synthetic `dzn` login qualifies; a teacher/student identity never does.
	foreach ( (array) DZN_PURGE_SYSTEM_USER_OPTIONS as $option ) {
		$userId = (int) get_option( $option, 0 );
		if ( $userId < 1 ) {
			continue;
		}
		$user = get_userdata( $userId );
		if ( ! $user instanceof WP_User ) {
			continue;
		}
		if ( ! preg_match( '/\Adzn[-_]/i', (string) $user->user_login ) ) {
			continue;
		}
		if ( in_array( 'dzn_teacher', (array) $user->roles, true ) ) {
			continue;
		}
		$keep[] = $userId;
	}
	return array_values( array_unique( array_filter( $keep ) ) );
}

function dzn_purge_unmanaged_tables(): array {
	global $wpdb;
	$prefix = $wpdb->prefix;
	$core   = array();
	foreach ( (array) $wpdb->tables( 'all' ) as $table ) {
		$core[] = (string) $table;
	}
	$out = array();
	foreach ( (array) $wpdb->get_col( 'SHOW TABLES' ) as $table ) {
		$table = (string) $table;
		if ( 0 === strpos( $table, $prefix . 'dzn_' ) ) {
			continue;
		}
		if ( in_array( $table, $core, true ) ) {
			continue;
		}
		if ( 0 !== strpos( $table, $prefix ) ) {
			continue;
		}
		$out[ $table ] = dzn_purge_count( $table );
	}
	ksort( $out );
	return $out;
}

try {
	$plan = dzn_purge_plan( $dzn_plan_file );
} catch ( Throwable $e ) {
	dzn_purge_fail( $e->getMessage(), 'plan' );
}

if ( ! in_array( $dzn_mode, array( 'report', 'execute' ), true ) ) {
	dzn_purge_fail( 'invalid_mode', 'arguments' );
}
if ( 'production' !== ( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '' ) ) {
	dzn_purge_fail( 'production_environment_required', 'preflight' );
}

$dzn_admin = dzn_purge_admin();
if ( ! $dzn_admin ) {
	dzn_purge_fail( 'retained_admin_unavailable_or_not_administrator', 'preflight' );
}

try {
	$state = dzn_purge_state( $plan );
} catch ( Throwable $e ) {
	dzn_purge_fail( $e->getMessage(), 'inventory' );
}

if ( array() !== $state['unclassified'] ) {
	dzn_purge_fail( 'unclassified_platform_tables', 'inventory', array( 'unclassified' => $state['unclassified'] ) );
}

$report = array(
	'ok'              => true,
	'mode'            => 'report',
	'environment'     => $state['environment'],
	'site_url'        => home_url( '/' ),
	'prefix'          => $state['prefix'],
	'schema_version'  => $state['schema'],
	'fingerprint'     => $state['fingerprint'],
	'plan_revision'   => (string) ( $plan['schema_revision'] ?? '' ),
	'retained_admin'  => array(
		'id'             => (int) $dzn_admin->ID,
		'email'          => $dzn_admin_email,
		'manage_options' => true,
	),
	'users'           => array(
		'total'   => $state['user_total'],
		'roles'   => $state['role_counts'],
		'retained_ids' => dzn_purge_kept_user_ids( (int) $dzn_admin->ID ),
	),
	'research_name_posts' => $state['research_posts'],
	'purge_table_count'   => count( $plan['purge'] ),
	'purge_row_total'     => $state['purge_total'],
	'purge_counts'        => $state['purge_counts'],
	'absent_table_count'  => count( $state['absent'] ),
	'absent_tables'       => $state['absent'],
	'preserve_table_count' => count( $plan['preserve'] ),
	'preserve_counts'     => $state['preserve_counts'],
	'unmanaged_tables'    => dzn_purge_unmanaged_tables(),
);

if ( 'report' === $dzn_mode ) {
	dzn_purge_emit( $report );
}

/* ---------------------------------------------------------------- execute */

if ( ! hash_equals( $dzn_purge_confirm_token, $dzn_confirm ) ) {
	dzn_purge_fail( 'confirm_token_invalid', 'execute' );
}
if ( ! preg_match( '/\A[0-9a-f]{64}\z/', $dzn_expected ) || ! hash_equals( $state['fingerprint'], $dzn_expected ) ) {
	dzn_purge_fail(
		'prestate_fingerprint_mismatch',
		'execute',
		array(
			'current_fingerprint' => $state['fingerprint'],
			'hint'                => 'Re-run report immediately before execute; any intervening write aborts the purge.',
		)
	);
}
if ( ! preg_match( '/\A[0-9a-f]{64}\z/', $dzn_backup_sha ) ) {
	dzn_purge_fail( 'backup_checksum_missing', 'execute' );
}
if ( '' === $dzn_backup || ! is_file( $dzn_backup ) || ! is_readable( $dzn_backup ) ) {
	dzn_purge_fail( 'backup_file_unavailable', 'execute', array( 'backup' => $dzn_backup ) );
}
clearstatcache( true, $dzn_backup );
if ( filesize( $dzn_backup ) < 1048576 ) {
	dzn_purge_fail( 'backup_file_too_small', 'execute', array( 'bytes' => filesize( $dzn_backup ) ) );
}
if ( ! hash_equals( $dzn_backup_sha, (string) hash_file( 'sha256', $dzn_backup ) ) ) {
	dzn_purge_fail( 'backup_checksum_mismatch', 'execute' );
}

$dzn_keep_ids = dzn_purge_kept_user_ids( (int) $dzn_admin->ID );
$dzn_existing = array_flip( $state['discovered'] );
$dzn_purged_users = 0;
$dzn_purged_posts = 0;
$dzn_deleted_rows  = 0;

try {
	$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
	$wpdb->query( 'START TRANSACTION' );

	// 1. Public research-name submissions (may contain a submitted name).
	$researchIds = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s", $dzn_research_post_type ) );
	foreach ( (array) $researchIds as $postId ) {
		if ( ! wp_delete_post( (int) $postId, true ) ) {
			throw new RuntimeException( 'research_post_delete_failed' );
		}
		++$dzn_purged_posts;
	}

	// 2. Every human WordPress identity except the retained administrator.
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$userIds = $wpdb->get_col( "SELECT ID FROM {$wpdb->users}" );
	foreach ( (array) $userIds as $userId ) {
		$userId = (int) $userId;
		if ( in_array( $userId, $dzn_keep_ids, true ) ) {
			continue;
		}
		// Reassign any authored content to the retained administrator, then
		// remove the identity and its usermeta. This never touches the admin.
		if ( ! wp_delete_user( $userId, (int) $dzn_admin->ID ) ) {
			throw new RuntimeException( 'user_delete_failed:' . $userId );
		}
		++$dzn_purged_users;
	}

	$wpdb->query( "DELETE um FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID=um.user_id WHERE u.ID IS NULL" );
	// Comments authored by deleted identities, and their metadata.
	$wpdb->query( "DELETE c FROM {$wpdb->comments} c LEFT JOIN {$wpdb->users} u ON u.ID=c.user_id WHERE c.user_id>0 AND u.ID IS NULL" );
	$wpdb->query( "DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID=cm.comment_id WHERE c.comment_ID IS NULL" );

	// 3. Strip any Student/Teacher portal association from the retained admin,
	//    and clear the monthly-statement draft actor if it pointed at a deleted
	//    Teacher identity. The administrator re-enables it deliberately later.
	$dzn_admin->remove_role( 'dzn_teacher' );
	delete_user_meta( (int) $dzn_admin->ID, 'dzn_access_role_assignment_v1' );
	delete_option( 'dzn_monthly_statement_draft_actor' );
	update_option( 'dzn_monthly_statement_draft_enabled', '0', false );

	// 4. Every classified user-activity table that this schema actually has.
	foreach ( $plan['purge'] as $short ) {
		if ( ! isset( $dzn_existing[ $short ] ) ) {
			continue;
		}
		$physical = dzn_purge_table_name( $short );
		$result   = $wpdb->query( 'DELETE FROM ' . dzn_purge_ident( $physical ) );
		if ( false === $result ) {
			throw new RuntimeException( 'delete_failed:' . $short . ':' . (string) $wpdb->last_error );
		}
		$dzn_deleted_rows += (int) $result;
	}

	wp_cache_flush();

	// 5. Verify inside the transaction before committing.
	$post_purge = array();
	foreach ( $plan['purge'] as $short ) {
		if ( ! isset( $dzn_existing[ $short ] ) ) {
			continue;
		}
		$remaining = dzn_purge_count( dzn_purge_table_name( $short ) );
		if ( 0 !== $remaining ) {
			throw new RuntimeException( 'purge_incomplete:' . $short . ':' . $remaining );
		}
		$post_purge[ $short ] = $remaining;
	}
	foreach ( $plan['preserve'] as $short ) {
		if ( ! isset( $dzn_existing[ $short ] ) ) {
			continue;
		}
		$physical = dzn_purge_table_name( $short );
		if ( dzn_purge_count( $physical ) !== ( $state['preserve_counts'][ $short ] ?? 0 ) ) {
			throw new RuntimeException( 'preserve_table_changed:' . $short );
		}
	}
	if ( (string) get_option( 'dzn_platform_schema_version', '' ) !== $state['schema'] ) {
		throw new RuntimeException( 'schema_version_changed' );
	}
	$remainingUsers = (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->users}" );
	foreach ( $remainingUsers as $userId ) {
		if ( ! in_array( (int) $userId, $dzn_keep_ids, true ) ) {
			throw new RuntimeException( 'unexpected_retained_user:' . (int) $userId );
		}
	}
	$verifiedAdmin = dzn_purge_admin();
	if ( ! $verifiedAdmin || (int) $verifiedAdmin->ID !== (int) $dzn_admin->ID ) {
		throw new RuntimeException( 'retained_admin_lost' );
	}

	$wpdb->query( 'COMMIT' );
	$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
} catch ( Throwable $e ) {
	$wpdb->query( 'ROLLBACK' );
	$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
	dzn_purge_fail( $e->getMessage(), 'execute' );
}

$receipt = array(
	'ok'                     => true,
	'mode'                   => 'execute',
	'deletions_performed'    => true,
	'executed_at_utc'        => gmdate( 'c' ),
	'site_url'               => home_url( '/' ),
	'prefix'                 => $state['prefix'],
	'schema_version'         => $state['schema'],
	'plan_revision'          => (string) ( $plan['schema_revision'] ?? '' ),
	'prestate_fingerprint'   => $state['fingerprint'],
	'backup_file'            => $dzn_backup,
	'backup_sha256'          => $dzn_backup_sha,
	'retained_admin_id'      => (int) $dzn_admin->ID,
	'retained_admin_email'   => $dzn_admin_email,
	'retained_user_ids'      => $dzn_keep_ids,
	'deleted_wordpress_users'=> $dzn_purged_users,
	'deleted_research_posts' => $dzn_purged_posts,
	'deleted_platform_rows'  => $dzn_deleted_rows,
	'purged_tables'          => count( $plan['purge'] ),
	'absent_tables'          => count( $state['absent'] ),
	'preserved_tables'       => count( $plan['preserve'] ),
	'preserve_counts'        => $state['preserve_counts'],
	'provider_side_cleanup'  => array(
		'status' => 'not_performed',
		'note'   => 'DB-only purge by owner decision. Google Calendar/Meet events, LiveKit rooms and Stripe objects created by test data must be cleaned provider-side and verified separately.',
	),
);

$receipt['receipt_digest'] = hash( 'sha256', (string) wp_json_encode( $receipt ) );

$uploads = wp_upload_dir();
$dir     = trailingslashit( (string) $uploads['basedir'] ) . 'dzn-go-live-purge';
$written = false;
if ( wp_mkdir_p( $dir ) ) {
	@chmod( $dir, 0700 );
	$path = $dir . '/purge-' . gmdate( 'Ymd\THis\Z' ) . '.json';
	$json = (string) wp_json_encode( $receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false !== file_put_contents( $path, $json, LOCK_EX ) ) {
		@chmod( $path, 0600 );
		$receipt['receipt_file'] = $path;
		$written                 = true;
		update_option( 'dzn_go_live_purge_receipt', $receipt['receipt_digest'], false );
	}
}
$receipt['receipt_written'] = $written;

$receipt['post_verification'] = array(
	'purge_rows_remaining'   => array_sum( $post_purge ),
	'users_remaining'        => count( $remainingUsers ),
	'admin_manage_options'   => user_can( $dzn_admin, 'manage_options' ),
	'schema_version_unchanged' => true,
	'catalogue_unchanged'    => true,
);

dzn_purge_emit( $receipt );
