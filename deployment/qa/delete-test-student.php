<?php
/**
 * Targeted deletion of one pre-launch test Student.
 *
 * This payload is executed only through deployment/qa/delete-test-student.sh
 * and the workflow-dispatch-only delete-test-student.yml. The target is
 * resolved by the SHA-256 of its email address; the raw address is never
 * accepted by this script and never appears in its output.
 *
 * Safety properties:
 *   - production environment only;
 *   - the retained administrator hash is refused;
 *   - a target WordPress user with manage_options is refused;
 *   - report mode is read-only and returns a pre-state fingerprint;
 *   - execute mode requires that fingerprint, a verified full database export,
 *     the exact confirm token and the protected workflow attestation;
 *   - only user-activity tables from purge-test-data-tables.php are touched;
 *   - preserved catalogue/configuration tables are counted before and after.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Refused: WP-CLI execution only.\n" );
	exit( 1 );
}

$dzn_delete_args = isset( $args ) && is_array( $args ) ? array_values( $args ) : array();
$dzn_delete_arg  = static function ( int $index ) use ( $dzn_delete_args ): string {
	$value = (string) ( $dzn_delete_args[ $index ] ?? '' );
	return 'none' === $value ? '' : $value;
};

$dzn_delete_mode        = strtolower( '' !== $dzn_delete_arg( 0 ) ? $dzn_delete_arg( 0 ) : 'report' );
$dzn_delete_target_hash = strtolower( trim( $dzn_delete_arg( 1 ) ) );
$dzn_delete_confirm     = $dzn_delete_arg( 2 );
$dzn_delete_expected    = strtolower( trim( $dzn_delete_arg( 3 ) ) );
$dzn_delete_backup      = $dzn_delete_arg( 4 );
$dzn_delete_backup_sha  = strtolower( trim( $dzn_delete_arg( 5 ) ) );
$dzn_delete_plan_file   = $dzn_delete_arg( 6 );
$dzn_delete_attestation = $dzn_delete_arg( 7 );

$dzn_delete_confirm_token = 'DELETE_TEST_STUDENT';
// SHA-256 of the retained administrator email. Never accept this target.
$dzn_delete_admin_hash    = 'a6b159ec09a2ad752655da929d8f996d28862aed6851ccc250108ead42e21f7d';
// SHA-256 of the protected workflow attestation token. The preimage is never
// stored in the repository; the workflow maps the existing protected secret.
$dzn_delete_attestation_sha256 = 'b74053a74d5419f2bb026036e1dd05ca233b7ccef2df5949acd4ef1303041102';

foreach ( array(
	'DZN_DELETE_CONFIRM_TOKEN'    => $dzn_delete_confirm_token,
	'DZN_DELETE_ADMIN_HASH'       => $dzn_delete_admin_hash,
	'DZN_DELETE_ATTESTATION_SHA256' => $dzn_delete_attestation_sha256,
) as $dzn_delete_constant => $dzn_delete_value ) {
	if ( ! defined( $dzn_delete_constant ) ) {
		define( $dzn_delete_constant, $dzn_delete_value );
	}
}

global $wpdb;

function dzn_delete_emit( array $payload, int $code = 0 ): void {
	$payload['deletions_performed'] = ! empty( $payload['deletions_performed'] );
	echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
	exit( $code );
}

function dzn_delete_fail( string $error, string $stage, array $extra = array() ): void {
	dzn_delete_emit( array_merge( array( 'ok' => false, 'error' => $error, 'stage' => $stage ), $extra ), 1 );
}

/** Only whitelisted identifiers reach SQL. */
function dzn_delete_ident( string $name ): string {
	if ( ! preg_match( '/\A[a-z0-9_]+\z/', $name ) ) {
		throw new RuntimeException( 'invalid_identifier' );
	}
	return '`' . $name . '`';
}

function dzn_delete_table_name( string $short ): string {
	global $wpdb;
	return $wpdb->prefix . 'dzn_' . $short;
}

function dzn_delete_plan( string $file ): array {
	if ( '' === $file || ! is_readable( $file ) || 'purge-test-data-tables.php' !== basename( $file ) ) {
		throw new RuntimeException( 'plan_file_unavailable' );
	}
	$plan = require $file;
	if ( ! is_array( $plan ) || ! isset( $plan['preserve'], $plan['purge'] ) ) {
		throw new RuntimeException( 'plan_file_invalid' );
	}
	return $plan;
}

function dzn_delete_columns( string $physical ): array {
	static $cache = array();
	if ( isset( $cache[ $physical ] ) ) {
		return $cache[ $physical ];
	}
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT COLUMN_NAME,DATA_TYPE,COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION',
		$physical
	) );
	$columns = array();
	foreach ( (array) $rows as $row ) {
		$name = (string) ( $row->COLUMN_NAME ?? '' );
		if ( '' === $name ) {
			continue;
		}
		$columns[ $name ] = array(
			'type' => strtolower( (string) ( $row->DATA_TYPE ?? '' ) ),
			'key'  => (string) ( $row->COLUMN_KEY ?? '' ),
		);
	}
	$cache[ $physical ] = $columns;
	return $columns;
}

function dzn_delete_table_exists( string $physical ): bool {
	static $cache = array();
	if ( isset( $cache[ $physical ] ) ) {
		return $cache[ $physical ];
	}
	global $wpdb;
	$exists = (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
		$physical
	) ) === 1;
	$cache[ $physical ] = $exists;
	return $exists;
}

/**
 * @param list<array{column:string,ids:list<int>,extra?:string}> $conditions
 * @return array{sql:string,args:list<int>}
 */
function dzn_delete_condition_sql( array $conditions ): array {
	$parts = array();
	$args  = array();
	foreach ( $conditions as $condition ) {
		$ids = array_values( array_unique( array_map( 'intval', (array) ( $condition['ids'] ?? array() ) ) ) );
		if ( ! $ids ) {
			continue;
		}
		$column = dzn_delete_ident( (string) $condition['column'] );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$part = $column . ' IN (' . $placeholders . ')';
		$extra = trim( (string) ( $condition['extra'] ?? '' ) );
		if ( '' !== $extra ) {
			$part = '(' . $extra . ' AND ' . $part . ')';
		}
		$parts[] = $part;
		$args    = array_merge( $args, $ids );
	}
	return array( 'sql' => implode( ' OR ', $parts ), 'args' => $args );
}

/** @return list<int> */
function dzn_delete_ids_for_conditions( string $physical, array $conditions ): array {
	$where = dzn_delete_condition_sql( $conditions );
	if ( '' === $where['sql'] ) {
		return array();
	}
	global $wpdb;
	$sql = 'SELECT id FROM ' . dzn_delete_ident( $physical ) . ' WHERE ' . $where['sql'];
	$rows = $wpdb->get_col( $wpdb->prepare( $sql, ...$where['args'] ) );
	return array_values( array_unique( array_map( 'intval', (array) $rows ) ) );
}

/** @param list<int> $ids */
function dzn_delete_rows_by_id( string $physical, array $ids, int &$deleted ): int {
	global $wpdb;
	$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
	$total = 0;
	foreach ( array_chunk( $ids, 500 ) as $chunk ) {
		$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
		$sql = 'DELETE FROM ' . dzn_delete_ident( $physical ) . ' WHERE id IN (' . $placeholders . ')';
		$result = $wpdb->query( $wpdb->prepare( $sql, ...$chunk ) );
		if ( false === $result ) {
			throw new RuntimeException( 'delete_failed:' . $physical . ':' . (string) $wpdb->last_error );
		}
		$total += (int) $result;
	}
	$deleted += $total;
	return $total;
}

function dzn_delete_rows_by_conditions( string $physical, array $conditions, int &$deleted ): int {
	$where = dzn_delete_condition_sql( $conditions );
	if ( '' === $where['sql'] ) {
		return 0;
	}
	global $wpdb;
	$sql = 'DELETE FROM ' . dzn_delete_ident( $physical ) . ' WHERE ' . $where['sql'];
	$result = $wpdb->query( $wpdb->prepare( $sql, ...$where['args'] ) );
	if ( false === $result ) {
		throw new RuntimeException( 'delete_failed:' . $physical . ':' . (string) $wpdb->last_error );
	}
	$deleted += (int) $result;
	return (int) $result;
}

/** Find rows whose text email column exactly matches the target hash. @return list<int> */
function dzn_delete_email_ids( string $physical, string $targetHash ): array {
	$columns = dzn_delete_columns( $physical );
	if ( ! isset( $columns['id'] ) ) {
		return array();
	}
	$emailColumns = array();
	foreach ( $columns as $name => $meta ) {
		if ( 'email' === $name || str_ends_with( $name, '_email' ) ) {
			$emailColumns[] = $name;
		}
	}
	if ( ! $emailColumns ) {
		return array();
	}
	global $wpdb;
	$ids = array();
	foreach ( $emailColumns as $column ) {
		$columnIdent = dzn_delete_ident( $column );
		$rows = $wpdb->get_results( 'SELECT id,' . $columnIdent . ' AS value FROM ' . dzn_delete_ident( $physical ) . ' WHERE ' . $columnIdent . " IS NOT NULL AND " . $columnIdent . " <> ''" );
		foreach ( (array) $rows as $row ) {
			$value = strtolower( trim( (string) ( $row->value ?? '' ) ) );
			if ( '' !== $value && hash_equals( $targetHash, hash( 'sha256', $value ) ) ) {
				$ids[] = (int) $row->id;
			}
		}
	}
	return array_values( array_unique( $ids ) );
}

/**
 * Map discovered ids into the foreign-key graph. The mapping only covers
 * references that belong to one user-activity chain; teacher ids are never
 * roots, so another teacher's records cannot be collected.
 *
 * @return array<string,list<int>>
 */
function dzn_delete_conditions_for_table( string $short, array $columns, array $sets ): array {
	$conditions = array();
	$map = array(
		'student_id' => 'students',
		'matched_student_id' => 'students',
		'wordpress_user_id' => 'wp_users',
		'booking_request_id' => 'booking_requests',
		'enrolment_id' => 'enrolments',
		'term_id' => 'terms',
		'lesson_id' => 'lessons',
		'schedule_version_id' => 'canonical_lesson_schedule_versions',
		'accepted_service_arrangement_id' => 'accepted_service_arrangements',
		'commercial_purchase_id' => 'commercial_purchases',
		'commercial_offer_id' => 'commercial_offers',
		'commercial_payment_fact_id' => 'commercial_payment_facts',
		'commercial_obligation_id' => 'commercial_offer_obligations',
		'statement_id' => 'finance_statements',
		'payment_attempt_id' => 'payment_execution_attempts',
		'delivery_id' => 'booking_request_teacher_deliveries',
		'confirmation_id' => 'booking_request_confirmations',
		'coordination_case_id' => 'coordination_cases',
		'proposal_family_id' => 'proposal_families',
		'proposal_option_id' => 'proposal_options',
		'requested_time_id' => 'booking_request_requested_times',
		'account_root_id' => 'commercial_account_roots',
		'intro_obligation_id' => 'intro_payment_obligations',
	);
	if ( str_starts_with( $short, 'commercial_' ) ) {
		$map['offer_id'] = 'commercial_offers';
		$map['purchase_id'] = 'commercial_purchases';
		$map['obligation_id'] = 'commercial_offer_obligations';
		$map['beneficiary_student_id'] = 'students';
		$map['adjustment_id'] = 'commercial_account_adjustments';
	}
	if ( str_starts_with( $short, 'booking_allocation_' ) ) {
		$map['offer_id'] = 'booking_allocation_offers';
	}
	foreach ( $map as $column => $entity ) {
		if ( isset( $columns[ $column ] ) && ! empty( $sets[ $entity ] ) ) {
			$conditions[] = array( 'column' => $column, 'ids' => $sets[ $entity ] );
		}
	}
	if ( 'operational_exceptions' === $short ) {
		$conditions[] = array( 'column' => 'entity_id', 'ids' => $sets['students'] ?? array(), 'extra' => "entity_type='student'" );
		$conditions[] = array( 'column' => 'entity_id', 'ids' => $sets['booking_requests'] ?? array(), 'extra' => "entity_type='booking_request'" );
	}
	if ( 'platform_audit_events' === $short ) {
		$conditions[] = array( 'column' => 'aggregate_id', 'ids' => $sets['students'] ?? array(), 'extra' => "aggregate_type='student'" );
		$conditions[] = array( 'column' => 'aggregate_id', 'ids' => $sets['booking_requests'] ?? array(), 'extra' => "aggregate_type='booking_request'" );
	}
	if ( isset( $columns['recipient_id'], $columns['audience'] ) && ! empty( $sets['students'] ) ) {
		$conditions[] = array( 'column' => 'recipient_id', 'ids' => $sets['students'], 'extra' => "audience='student'" );
	}
	return $conditions;
}

/**
 * @return array{sets:array<string,list<int>>,preserve_counts:array<string,int>}
 */
function dzn_delete_resolve( array $plan, string $targetHash ): array {
	global $wpdb;
	$p = $wpdb->prefix . 'dzn_';
	$sets = array( 'students' => array(), 'wp_users' => array(), 'booking_requests' => array() );
	$preserve = array();
	foreach ( (array) $plan['preserve'] as $short ) {
		$physical = dzn_delete_table_name( (string) $short );
		if ( ! dzn_delete_table_exists( $physical ) ) {
			$preserve[ (string) $short ] = 0;
			continue;
		}
		$preserve[ (string) $short ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . dzn_delete_ident( $physical ) );
	}

	$studentRows = $wpdb->get_results( "SELECT id,email,wordpress_user_id FROM {$p}students" );
	foreach ( (array) $studentRows as $row ) {
		$email = strtolower( trim( (string) ( $row->email ?? '' ) ) );
		$match = '' !== $email && hash_equals( $targetHash, hash( 'sha256', $email ) );
		if ( $match ) {
			$sets['students'][] = (int) $row->id;
			if ( (int) ( $row->wordpress_user_id ?? 0 ) > 0 ) {
				$sets['wp_users'][] = (int) $row->wordpress_user_id;
			}
		}
	}

	foreach ( get_users( array( 'fields' => array( 'ID', 'user_email' ) ) ) as $user ) {
		$email = strtolower( trim( (string) ( $user->user_email ?? '' ) ) );
		if ( '' !== $email && hash_equals( $targetHash, hash( 'sha256', $email ) ) ) {
			$sets['wp_users'][] = (int) $user->ID;
		}
	}
	$sets['wp_users'] = array_values( array_unique( array_map( 'intval', $sets['wp_users'] ) ) );
	$sets['students'] = array_values( array_unique( array_map( 'intval', $sets['students'] ) ) );

	if ( $sets['students'] ) {
		$placeholders = implode( ',', array_fill( 0, count( $sets['students'] ), '%d' ) );
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}booking_requests WHERE student_id IN ({$placeholders})", ...$sets['students'] ) );
		foreach ( (array) $rows as $id ) {
			$sets['booking_requests'][] = (int) $id;
		}
	}
	if ( $sets['wp_users'] ) {
		$placeholders = implode( ',', array_fill( 0, count( $sets['wp_users'] ), '%d' ) );
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}students WHERE wordpress_user_id IN ({$placeholders})", ...$sets['wp_users'] ) );
		foreach ( (array) $rows as $id ) {
			$sets['students'][] = (int) $id;
		}
	}

	$contactTable = $p . 'booking_request_contact_snapshots';
	if ( dzn_delete_table_exists( $contactTable ) ) {
		$rows = $wpdb->get_results( "SELECT booking_request_id,email FROM {$contactTable} WHERE email IS NOT NULL AND email<>''" );
		foreach ( (array) $rows as $row ) {
			$email = strtolower( trim( (string) ( $row->email ?? '' ) ) );
			if ( '' !== $email && hash_equals( $targetHash, hash( 'sha256', $email ) ) ) {
				$sets['booking_requests'][] = (int) $row->booking_request_id;
			}
		}
	}
	$sets['booking_requests'] = array_values( array_unique( array_map( 'intval', $sets['booking_requests'] ) ) );
	$sets['students'] = array_values( array_unique( array_map( 'intval', $sets['students'] ) ) );

	return array( 'sets' => $sets, 'preserve_counts' => $preserve );
}

/**
 * Iteratively follow the account's foreign-key graph through the purge tables.
 *
 * @param array<string,list<int>> $sets
 * @return array<string,list<int>>
 */
function dzn_delete_discover( array $plan, array $sets, string $targetHash ): array {
	$scope = array_values( array_unique( array_merge( (array) $plan['purge'], array( 'students' ) ) ) );
	for ( $pass = 0; $pass < 12; $pass++ ) {
		$changed = false;
		foreach ( $scope as $short ) {
			$short = (string) $short;
			$physical = dzn_delete_table_name( $short );
			if ( ! dzn_delete_table_exists( $physical ) ) {
				continue;
			}
			$columns = dzn_delete_columns( $physical );
			if ( ! isset( $columns['id'] ) ) {
				continue;
			}
			$conditions = dzn_delete_conditions_for_table( $short, $columns, $sets );
			if ( ! $conditions ) {
				continue;
			}
			$ids = dzn_delete_ids_for_conditions( $physical, $conditions );
			$new = array_values( array_diff( $ids, $sets[ $short ] ?? array() ) );
			if ( $new ) {
				$sets[ $short ] = array_values( array_unique( array_merge( $sets[ $short ] ?? array(), $ids ) ) );
				$changed = true;
			}
		}
		if ( ! $changed ) {
			break;
		}
	}
	foreach ( $scope as $short ) {
		$short = (string) $short;
		$physical = dzn_delete_table_name( $short );
		if ( ! dzn_delete_table_exists( $physical ) ) {
			continue;
		}
		$ids = dzn_delete_email_ids( $physical, $targetHash );
		if ( $ids ) {
			$sets[ $short ] = array_values( array_unique( array_merge( $sets[ $short ] ?? array(), $ids ) ) );
		}
	}
	foreach ( $sets as $short => $ids ) {
		sort( $ids, SORT_NUMERIC );
		$sets[ $short ] = array_values( array_unique( array_map( 'intval', $ids ) ) );
	}
	return $sets;
}

function dzn_delete_fingerprint( string $targetHash, array $sets, array $preserve ): string {
	$ordered = array();
	foreach ( $sets as $short => $ids ) {
		$ordered[ (string) $short ] = array_values( array_map( 'intval', $ids ) );
	}
	ksort( $ordered );
	ksort( $preserve );
	return hash( 'sha256', (string) wp_json_encode( array( 'target' => $targetHash, 'sets' => $ordered, 'preserve' => $preserve ) ) );
}

/** Rows still present for the exact collected ids or direct target conditions. */
function dzn_delete_residual_count( array $plan, array $sets ): int {
	global $wpdb;
	$scope = array_values( array_unique( array_merge( (array) $plan['purge'], array( 'students' ) ) ) );
	$total = 0;
	foreach ( $scope as $short ) {
		$short = (string) $short;
		$physical = dzn_delete_table_name( $short );
		if ( ! dzn_delete_table_exists( $physical ) ) {
			continue;
		}
		$columns = dzn_delete_columns( $physical );
		$conditions = dzn_delete_conditions_for_table( $short, $columns, $sets );
		$parts = array();
		$args = array();
		if ( isset( $columns['id'] ) && ! empty( $sets[ $short ] ) ) {
			$ids = array_values( array_unique( array_map( 'intval', $sets[ $short ] ) ) );
			$parts[] = 'id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$args = array_merge( $args, $ids );
		}
		$condition = dzn_delete_condition_sql( $conditions );
		if ( '' !== $condition['sql'] ) {
			$parts[] = $condition['sql'];
			$args = array_merge( $args, $condition['args'] );
		}
		if ( ! $parts ) {
			continue;
		}
		$sql = 'SELECT COUNT(*) FROM ' . dzn_delete_ident( $physical ) . ' WHERE ' . implode( ' OR ', $parts );
		$total += (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
	}
	foreach ( $sets['wp_users'] ?? array() as $userId ) {
		if ( get_userdata( (int) $userId ) instanceof WP_User ) {
			$total++;
		}
	}
	return $total;
}

function dzn_delete_admin(): ?WP_User {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	foreach ( (array) $admins as $admin ) {
		if ( $admin instanceof WP_User && user_can( $admin, 'manage_options' ) ) {
			return $admin;
		}
	}
	return null;
}

function dzn_delete_target_is_admin( string $targetHash, int $adminId ): bool {
	if ( hash_equals( DZN_DELETE_ADMIN_HASH, $targetHash ) ) {
		return true;
	}
	$user = get_userdata( $adminId );
	if ( $user instanceof WP_User ) {
		$email = strtolower( trim( (string) $user->user_email ) );
		if ( '' !== $email && hash_equals( $targetHash, hash( 'sha256', $email ) ) ) {
			return true;
		}
	}
	return false;
}

try {
	$plan = dzn_delete_plan( $dzn_delete_plan_file );
} catch ( Throwable $e ) {
	dzn_delete_fail( $e->getMessage(), 'plan' );
}

if ( ! in_array( $dzn_delete_mode, array( 'report', 'execute' ), true ) ) {
	dzn_delete_fail( 'invalid_mode', 'arguments' );
}
if ( ! preg_match( '/\A[0-9a-f]{64}\z/', $dzn_delete_target_hash ) ) {
	dzn_delete_fail( 'invalid_target_hash', 'arguments' );
}
if ( 'production' !== ( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '' ) ) {
	dzn_delete_fail( 'production_environment_required', 'preflight' );
}
$admin = dzn_delete_admin();
if ( ! $admin ) {
	dzn_delete_fail( 'retained_admin_unavailable', 'preflight' );
}
if ( dzn_delete_target_is_admin( $dzn_delete_target_hash, (int) $admin->ID ) ) {
	dzn_delete_fail( 'refused_retained_admin_target', 'preflight' );
}

try {
	$resolved = dzn_delete_resolve( $plan, $dzn_delete_target_hash );
	$sets = dzn_delete_discover( $plan, $resolved['sets'], $dzn_delete_target_hash );
	$preserve = $resolved['preserve_counts'];
} catch ( Throwable $e ) {
	dzn_delete_fail( $e->getMessage(), 'resolve' );
}

if ( empty( $sets['students'] ) && empty( $sets['wp_users'] ) && empty( $sets['booking_requests'] ) ) {
	dzn_delete_fail( 'target_not_found', 'resolve' );
}
foreach ( $sets['wp_users'] as $userId ) {
	$user = get_userdata( (int) $userId );
	if ( $user instanceof WP_User && ( user_can( $user, 'manage_options' ) || in_array( 'administrator', (array) $user->roles, true ) ) ) {
		dzn_delete_fail( 'refused_administrator_target', 'preflight', array( 'user_id' => (int) $userId ) );
	}
}

$counts = array();
$total  = 0;
foreach ( $sets as $short => $ids ) {
	$counts[ (string) $short ] = count( $ids );
	$total += count( $ids );
}
$fingerprint = dzn_delete_fingerprint( $dzn_delete_target_hash, $sets, $preserve );
$report = array(
	'ok'             => true,
	'mode'           => 'report',
	'target_sha256'  => $dzn_delete_target_hash,
	'fingerprint'    => $fingerprint,
	'counts'         => $counts,
	'total_rows'     => $total,
	'preserve_counts' => $preserve,
	'deletions_performed' => false,
);

if ( 'report' === $dzn_delete_mode ) {
	dzn_delete_emit( $report );
}

if ( ! hash_equals( DZN_DELETE_CONFIRM_TOKEN, $dzn_delete_confirm ) ) {
	dzn_delete_fail( 'confirm_token_invalid', 'execute' );
}
if ( ! preg_match( '/\A[0-9a-f]{64}\z/', $dzn_delete_expected ) || ! hash_equals( $fingerprint, $dzn_delete_expected ) ) {
	dzn_delete_fail( 'prestate_fingerprint_mismatch', 'execute', array( 'current_fingerprint' => $fingerprint ) );
}
if ( ! preg_match( '/\A[0-9a-f]{64}\z/', $dzn_delete_backup_sha ) ) {
	dzn_delete_fail( 'backup_checksum_missing', 'execute' );
}
if ( '' === $dzn_delete_backup || ! is_file( $dzn_delete_backup ) || ! is_readable( $dzn_delete_backup ) ) {
	dzn_delete_fail( 'backup_file_unavailable', 'execute' );
}
clearstatcache( true, $dzn_delete_backup );
if ( filesize( $dzn_delete_backup ) < 65536 ) {
	dzn_delete_fail( 'backup_file_too_small', 'execute', array( 'bytes' => filesize( $dzn_delete_backup ) ) );
}
if ( ! hash_equals( $dzn_delete_backup_sha, (string) hash_file( 'sha256', $dzn_delete_backup ) ) ) {
	dzn_delete_fail( 'backup_checksum_mismatch', 'execute' );
}
if ( '' === $dzn_delete_attestation || ! hash_equals( DZN_DELETE_ATTESTATION_SHA256, (string) hash( 'sha256', $dzn_delete_attestation ) ) ) {
	dzn_delete_fail( 'workflow_attestation_invalid', 'execute' );
}

$scope = array_values( array_unique( array_merge( (array) $plan['purge'], array( 'students' ) ) ) );
$deleted = 0;
try {
	$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
	$wpdb->query( 'START TRANSACTION' );
	foreach ( $scope as $short ) {
		$short = (string) $short;
		if ( 'wp_users' === $short ) {
			continue;
		}
		$physical = dzn_delete_table_name( $short );
		if ( ! dzn_delete_table_exists( $physical ) ) {
			continue;
		}
		$columns = dzn_delete_columns( $physical );
		$conditions = dzn_delete_conditions_for_table( $short, $columns, $sets );
		if ( isset( $columns['id'] ) ) {
			$ids = array_values( array_unique( array_merge( $sets[ $short ] ?? array(), dzn_delete_ids_for_conditions( $physical, $conditions ) ) ) );
			if ( $ids ) {
				dzn_delete_rows_by_id( $physical, $ids, $deleted );
			}
		} elseif ( $conditions ) {
			dzn_delete_rows_by_conditions( $physical, $conditions, $deleted );
		}
	}

	// Delete the account rows explicitly after dependent activity.
	if ( dzn_delete_table_exists( dzn_delete_table_name( 'students' ) ) ) {
		dzn_delete_rows_by_id( dzn_delete_table_name( 'students' ), $sets['students'] ?? array(), $deleted );
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $sets['wp_users'] ?? array() as $userId ) {
		$userId = (int) $userId;
		if ( $userId === (int) $admin->ID ) {
			throw new RuntimeException( 'refused_retained_admin_target' );
		}
		if ( ! wp_delete_user( $userId, (int) $admin->ID ) ) {
			throw new RuntimeException( 'user_delete_failed:' . $userId );
		}
		$deleted++;
	}
	$wpdb->query( "DELETE um FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID=um.user_id WHERE u.ID IS NULL" );
	$wpdb->query( "DELETE c FROM {$wpdb->comments} c LEFT JOIN {$wpdb->users} u ON u.ID=c.user_id WHERE c.user_id>0 AND u.ID IS NULL" );
	$wpdb->query( "DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID=cm.comment_id WHERE c.comment_ID IS NULL" );

	if ( false === $wpdb->query( 'COMMIT' ) ) {
		throw new RuntimeException( 'commit_failed' );
	}
	$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
} catch ( Throwable $e ) {
	$wpdb->query( 'ROLLBACK' );
	$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
	dzn_delete_fail( $e->getMessage(), 'execute' );
}

// Post-verification must prove the exact target rows are gone and the retained
// administrator/catalogue were not changed.
$afterResolved = dzn_delete_resolve( $plan, $dzn_delete_target_hash );
$afterSets = dzn_delete_discover( $plan, $afterResolved['sets'], $dzn_delete_target_hash );
$remaining = dzn_delete_residual_count( $plan, $sets );
$discoveredRemaining = 0;
foreach ( $afterSets as $ids ) {
	$discoveredRemaining += count( $ids );
}
$remaining += $discoveredRemaining;
$adminAfter = get_userdata( (int) $admin->ID );
$adminOk = $adminAfter instanceof WP_User && user_can( $adminAfter, 'manage_options' );
$catalogueUnchanged = true;
foreach ( $preserve as $short => $count ) {
	$physical = dzn_delete_table_name( (string) $short );
	if ( ! dzn_delete_table_exists( $physical ) ) {
		continue;
	}
	if ( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . dzn_delete_ident( $physical ) ) !== (int) $count ) {
		$catalogueUnchanged = false;
		break;
	}
}
if ( $remaining > 0 ) {
	dzn_delete_fail( 'target_rows_remaining', 'post_verification', array( 'remaining' => $remaining, 'remaining_by_discovery' => $afterSets ) );
}
if ( ! $adminOk ) {
	dzn_delete_fail( 'retained_admin_lost', 'post_verification' );
}
if ( ! $catalogueUnchanged ) {
	dzn_delete_fail( 'preserved_configuration_changed', 'post_verification' );
}

dzn_delete_emit( array_merge( $report, array(
	'mode' => 'execute',
	'deletions_performed' => true,
	'deleted_rows' => $deleted,
	'post_verification' => array(
		'target_rows_remaining' => 0,
		'admin_manage_options' => true,
		'catalogue_unchanged' => true,
	),
) ) );
