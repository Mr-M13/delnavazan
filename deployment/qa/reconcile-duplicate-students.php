<?php
/**
 * Guarded consolidation of duplicate Student identities.
 *
 * This payload is executed only through deployment/qa/reconcile-duplicate-students.sh and the
 * workflow-dispatch-only reconcile-duplicate-students.yml.
 *
 * Policy (deliberately conservative):
 *   - duplicates are found by the exact lowercased email among non-archived Students;
 *   - a copy is archived only when it has NO operational relations, NO active portal principal
 *     and NO WordPress user of its own;
 *   - capacity evidence and identity-link history are allowed (they are not operational);
 *   - a group where more than one copy has history, or where the empty copy owns an account,
 *     is reported as manual review and is never touched;
 *   - nothing is deleted: archiving preserves every row and is reversible from the workspace;
 *   - report mode is read-only and returns the fingerprint execute must match;
 *   - execute requires that fingerprint, a verified full database export, the exact confirm token
 *     and the protected workflow attestation.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Refused: WP-CLI execution only.\n" );
	exit( 1 );
}

$dzn_rec_args = isset( $args ) && is_array( $args ) ? array_values( $args ) : array();
$dzn_rec_arg  = static function ( int $index ) use ( $dzn_rec_args ): string {
	$value = (string) ( $dzn_rec_args[ $index ] ?? '' );
	return 'none' === $value ? '' : $value;
};

$dzn_rec_mode        = strtolower( '' !== $dzn_rec_arg( 0 ) ? $dzn_rec_arg( 0 ) : 'report' );
$dzn_rec_expected    = strtolower( trim( $dzn_rec_arg( 1 ) ) );
$dzn_rec_confirm     = $dzn_rec_arg( 2 );
$dzn_rec_backup      = $dzn_rec_arg( 3 );
$dzn_rec_backup_sha  = strtolower( trim( $dzn_rec_arg( 4 ) ) );
$dzn_rec_attestation = $dzn_rec_arg( 5 );

$dzn_rec_confirm_token = 'RECONCILE_DUPLICATES';
// SHA-256 of the protected workflow attestation token (same secret as the purge/delete tooling).
$dzn_rec_attestation_sha256 = 'b74053a74d5419f2bb026036e1dd05ca233b7ccef2df5949acd4ef1303041102';

global $wpdb;
$dzn_rec_prefix = $wpdb->prefix . 'dzn_';

function dzn_rec_emit( array $payload, int $code = 0 ): void {
	echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
	exit( $code );
}

function dzn_rec_fail( string $error, string $stage, array $extra = array() ): void {
	dzn_rec_emit( array_merge( array( 'ok' => false, 'error' => $error, 'stage' => $stage ), $extra ), 1 );
}

/** Tables that are identity evidence, not operational history. */
function dzn_rec_allowed_empty_relation( string $table ): bool {
	return in_array( $table, array( 'student_principal_links', 'student_acceptance_capacity_classifications' ), true );
}

/**
 * Read-only plan. Every decision is derived from stored facts; no row is written here.
 *
 * @return array<int,array<string,mixed>>
 */
function dzn_rec_plan(): array {
	global $wpdb, $dzn_rec_prefix;
	$rows = $wpdb->get_results(
		"SELECT id,uid,reference_code,display_name,email,status,archived_at,wordpress_user_id
		 FROM {$dzn_rec_prefix}students
		 WHERE archived_at IS NULL AND email<>'' AND status IN ('active','inactive')
		 ORDER BY LOWER(email) ASC,id ASC"
	);
	if ( ! is_array( $rows ) ) throw new RuntimeException( 'student_read_failed' );
	$groups = array();
	foreach ( $rows as $row ) {
		$key = strtolower( trim( (string) $row->email ) );
		if ( '' === $key ) continue;
		$groups[ $key ][] = $row;
	}
	$plan = array();
	foreach ( $groups as $email => $members ) {
		if ( count( $members ) < 2 ) continue;
		$detail = array();
		foreach ( $members as $member ) {
			$manifest = ( new \Delnavazan\Platform\Core\Application\AccountRemovalDiscoveryService() )->discover( 'student', (int) $member->id );
			$operations = array();
			$relations  = array();
			foreach ( (array) ( $manifest['relations'] ?? array() ) as $relation ) {
				$table = (string) ( $relation['table'] ?? '' );
				$relations[ $table . '.' . (string) ( $relation['column'] ?? '' ) ] = (int) ( $relation['count'] ?? 0 );
				if ( ! dzn_rec_allowed_empty_relation( $table ) ) $operations[] = array( 'table' => $table, 'column' => (string) ( $relation['column'] ?? '' ), 'count' => (int) ( $relation['count'] ?? 0 ) );
			}
			$principal = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$dzn_rec_prefix}student_principal_links
				 WHERE student_id=%d AND status='active' AND active_slot=1 AND revoked_at IS NULL LIMIT 1",
				(int) $member->id
			) );
			if ( (string) $wpdb->last_error !== '' ) throw new RuntimeException( 'principal_read_failed' );
			$has_user = (int) $member->wordpress_user_id > 0;
			$detail[] = array(
				'id'                   => (int) $member->id,
				'reference'            => (string) $member->reference_code,
				'status'               => (string) $member->status,
				'has_active_principal' => null !== $principal,
				'has_wordpress_user'   => $has_user,
				'operational'          => $operations,
				'relations'            => $relations,
				'empty'                => array() === $operations && null === $principal && ! $has_user,
			);
		}
		$keep = array_values( array_filter( $detail, static fn( array $d ): bool => ! $d['empty'] ) );
		if ( count( $keep ) === 1 ) {
			$survivor = $keep[0];
			$archive  = array_values( array_filter( $detail, static fn( array $d ): bool => $d['empty'] ) );
			$decision = $archive ? 'archive_empty_duplicates' : 'no_action';
		} elseif ( count( $keep ) === 0 ) {
			$survivor = $detail[0];
			$archive  = array_slice( $detail, 1 );
			$decision = 'archive_empty_duplicates';
		} else {
			$survivor = null;
			$archive  = array();
			$decision = 'manual_review';
		}
		$plan[] = array(
			'email_sha256' => hash( 'sha256', (string) $email ),
			'decision'     => $decision,
			'survivor'     => null === $survivor ? null : array( 'id' => $survivor['id'], 'reference' => $survivor['reference'] ),
			'archive'      => array_map( static fn( array $d ): array => array( 'id' => $d['id'], 'reference' => $d['reference'] ), $archive ),
			'members'      => $detail,
		);
	}
	usort( $plan, static fn( array $a, array $b ): int => strcmp( $a['email_sha256'], $b['email_sha256'] ) );
	return $plan;
}

function dzn_rec_fingerprint( array $plan ): string {
	return hash( 'sha256', (string) wp_json_encode( $plan ) );
}

/**
 * Read-only diagnosis of the newest Students: the exact identity-anomaly codes the workspace shows.
 * This answers "why does every new student carry two items" without guessing.
 *
 * @return array<string,mixed>
 */
function dzn_rec_diagnose( int $limit = 25 ): array {
	global $wpdb, $dzn_rec_prefix;
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
	if ( ! is_array( $admins ) || ! isset( $admins[0]->ID ) ) return array( 'available' => false, 'reason' => 'administrator_unavailable' );
	wp_set_current_user( (int) $admins[0]->ID );
	$limit = max( 1, min( 50, $limit ) );
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$dzn_rec_prefix}students" );
	$live  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$dzn_rec_prefix}students WHERE archived_at IS NULL" );
	$read_error = (string) $wpdb->last_error;
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT id,reference_code,status,created_at FROM {$dzn_rec_prefix}students WHERE archived_at IS NULL ORDER BY id DESC LIMIT %d",
		$limit
	) );
	if ( ! is_array( $rows ) ) return array( 'available' => false, 'reason' => 'student_read_failed', 'total_students' => $total, 'live_students' => $live, 'read_error' => $read_error );
	$service = new \Delnavazan\Platform\Core\Application\StudentWorkspaceReadService();
	$out     = array();
	foreach ( $rows as $row ) {
		try {
			$profile = $service->profile( (int) $row->id );
			$codes   = array();
			$titles  = array();
			foreach ( (array) ( $profile['identity']['anomalies'] ?? array() ) as $anomaly ) {
				$codes[]  = (string) ( $anomaly['code'] ?? '' );
				$titles[] = (string) ( $anomaly['title'] ?? '' );
			}
			$out[] = array(
				'id' => (int) $row->id,
				'reference' => (string) $row->reference_code,
				'status' => (string) $row->status,
				'created_at' => (string) $row->created_at,
				'anomalies' => $codes,
				'anomaly_titles' => $titles,
			);
		} catch ( \Throwable $error ) {
			$out[] = array(
				'id' => (int) $row->id,
				'reference' => (string) $row->reference_code,
				'status' => (string) $row->status,
				'created_at' => (string) $row->created_at,
				'error' => get_class( $error ) . ':' . substr( $error->getMessage(), 0, 120 ),
			);
		}
	}
	return array( 'available' => true, 'limit' => $limit, 'total_students' => $total, 'live_students' => $live, 'read_error' => $read_error, 'students' => $out );
}

function dzn_rec_counts( array $plan ): array {
	$groups = count( $plan );
	$safe   = 0;
	$manual = 0;
	$rows   = 0;
	foreach ( $plan as $group ) {
		if ( 'archive_empty_duplicates' === $group['decision'] ) {
			$safe++;
			$rows += count( $group['archive'] );
		} elseif ( 'manual_review' === $group['decision'] ) {
			$manual++;
		}
	}
	return array( 'groups' => $groups, 'safe_groups' => $safe, 'manual_review_groups' => $manual, 'archive_rows' => $rows );
}

try {
	$plan        = dzn_rec_plan();
	$fingerprint = dzn_rec_fingerprint( $plan );

	if ( 'report' === $dzn_rec_mode ) {
		dzn_rec_emit( array(
			'ok'                   => true,
			'mode'                 => 'report',
			'archives_performed'   => false,
			'fingerprint'          => $fingerprint,
			'summary'              => dzn_rec_counts( $plan ),
			'plan'                 => $plan,
			'diagnostics'          => dzn_rec_diagnose(),
		) );
	}

	if ( 'execute' !== $dzn_rec_mode ) dzn_rec_fail( 'mode_invalid', 'validate' );
	if ( ! preg_match( '/^[a-f0-9]{64}$/D', $dzn_rec_expected ) ) dzn_rec_fail( 'fingerprint_invalid', 'validate' );
	if ( ! hash_equals( $fingerprint, $dzn_rec_expected ) ) {
		dzn_rec_fail( 'prestate_changed', 'validate', array( 'observed_fingerprint' => $fingerprint ) );
	}
	if ( $dzn_rec_confirm !== $dzn_rec_confirm_token ) dzn_rec_fail( 'confirm_invalid', 'validate' );
	if ( '' === $dzn_rec_attestation || ! hash_equals( $dzn_rec_attestation_sha256, hash( 'sha256', $dzn_rec_attestation ) ) ) {
		dzn_rec_fail( 'attestation_invalid', 'validate' );
	}
	if ( '' === $dzn_rec_backup || ! is_readable( $dzn_rec_backup ) ) dzn_rec_fail( 'backup_missing', 'backup' );
	$backup_size = (int) filesize( $dzn_rec_backup );
	if ( $backup_size < 65536 ) dzn_rec_fail( 'backup_implausible', 'backup', array( 'bytes' => $backup_size ) );
	if ( ! preg_match( '/^[a-f0-9]{64}$/D', $dzn_rec_backup_sha ) || ! hash_equals( $dzn_rec_backup_sha, hash_file( 'sha256', $dzn_rec_backup ) ) ) {
		dzn_rec_fail( 'backup_digest_mismatch', 'backup' );
	}

	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
	if ( ! is_array( $admins ) || ! isset( $admins[0]->ID ) ) dzn_rec_fail( 'administrator_unavailable', 'archive' );
	wp_set_current_user( (int) $admins[0]->ID );
	if ( ! current_user_can( 'dzn_manage_students' ) ) dzn_rec_fail( 'archive_capability_missing', 'archive' );

	$service  = new \Delnavazan\Platform\Core\Application\StudentAdministrationService();
	$archived = array();
	$errors   = array();
	foreach ( $plan as $group ) {
		if ( 'archive_empty_duplicates' !== $group['decision'] ) continue;
		foreach ( $group['archive'] as $target ) {
			$id = (int) $target['id'];
			try {
				$service->archive( $id );
				$archived[] = array( 'id' => $id, 'reference' => (string) $target['reference'], 'survivor_id' => (int) ( $group['survivor']['id'] ?? 0 ) );
			} catch ( \Throwable $error ) {
				$errors[] = array( 'id' => $id, 'error' => get_class( $error ) . ':' . $error->getMessage() );
			}
		}
	}

	$post = array();
	foreach ( $archived as $row ) {
		$post[ (string) $row['id'] ] = null !== $wpdb->get_var( $wpdb->prepare(
			"SELECT archived_at FROM {$dzn_rec_prefix}students WHERE id=%d AND archived_at IS NOT NULL",
			(int) $row['id']
		) );
	}
	$post_ok = ! in_array( false, $post, true );
	// Archived duplicates must no longer expose an active portal principal.
	$principal_ok = true;
	foreach ( $archived as $row ) {
		$still = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$dzn_rec_prefix}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 AND revoked_at IS NULL LIMIT 1",
			(int) $row['id']
		) );
		if ( null !== $still ) $principal_ok = false;
	}
	dzn_rec_emit( array(
		'ok'                 => array() === $errors && $post_ok && $principal_ok,
		'mode'               => 'execute',
		'archives_performed' => array() !== $archived,
		'fingerprint'        => $fingerprint,
		'archived'           => $archived,
		'errors'             => $errors,
		'post_verification'  => array( 'archived_visible' => $post, 'no_active_principal_remains' => $principal_ok ),
	) );
} catch ( \Throwable $error ) {
	dzn_rec_fail( 'reconcile_failed', 'runtime', array( 'exception' => get_class( $error ), 'message' => substr( $error->getMessage(), 0, 200 ) ) );
}
