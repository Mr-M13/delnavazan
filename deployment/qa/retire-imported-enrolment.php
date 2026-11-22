<?php
/**
 * Guarded retirement of one *imported* legacy Enrolment.
 *
 * This payload exists because the historical-course import (the "Import
 * existing enrolment" panel) has no compensating action: its lessons are
 * created through the schedule authority and become `scheduled`, so the
 * generic archive path refuses them, and no legacy cancel action exists.
 * An import made for a Student who should instead flow through the paid
 * continuation path therefore has to be retired by an explicit, audited
 * command. This is that command.
 *
 * It never deletes rows and never touches catalogue/configuration data.
 * It archives, in order: the imported lessons, their Term, then the
 * Enrolment, and records one audit event.
 *
 * Safety properties:
 *   - WP-CLI only; executed only through retire-imported-enrolment.sh and the
 *     workflow-dispatch-only retire-imported-enrolment.yml;
 *   - report mode is read-only and returns the pre-state fingerprint;
 *   - execute mode requires that fingerprint, a verified full database export,
 *     the exact confirm token and the protected workflow attestation;
 *   - refuses anything that is not an import (no historical-import evidence);
 *   - refuses a Term that recorded money;
 *   - refuses a Term with any lesson already started, completed or linked to a
 *     booking request (that is a live term, not a mistaken import);
 *   - refuses when any other platform table references the Enrolment or its
 *     lessons (finance, post-intro, offers, attendance), because retirement is
 *     only safe when nothing downstream depends on the record;
 *   - refuses when a canonical Enrolment already exists for the same
 *     Student/Course/Teacher, because the paid path already owns that course.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Refused: WP-CLI execution only.\n" );
	exit( 1 );
}

if ( ! defined( 'DZN_RETIRE_CONFIRM_TOKEN' ) ) {
	define( 'DZN_RETIRE_CONFIRM_TOKEN', 'RETIRE_IMPORTED_ENROLMENT' );
}
// SHA-256 of the protected workflow attestation token. The preimage is never
// stored in the repository; the workflow maps the existing protected secret.
if ( ! defined( 'DZN_RETIRE_ATTESTATION_SHA256' ) ) {
	define( 'DZN_RETIRE_ATTESTATION_SHA256', 'b74053a74d5419f2bb026036e1dd05ca233b7ccef2df5949acd4ef1303041102' );
}

/**
 * Tables whose rows are expected to reference the retired aggregate.
 * Everything else that references it is a downstream dependency and blocks.
 */
const DZN_RETIRE_ENROLMENT_SELF = array( 'enrolments', 'terms', 'lessons', 'platform_audit_events' );
const DZN_RETIRE_LESSON_SELF    = array( 'lessons', 'lesson_schedule_versions', 'platform_audit_events' );

/**
 * Pure pre-flight decision. Returns a list of refusal codes; empty means the
 * record is a retirable import. Kept free of database access so the unit
 * harness can exercise every branch.
 *
 * @param array<string,mixed> $state
 * @return array<int,string>
 */
function dzn_retire_decision( array $state ): array {
	$problems = array();
	if ( empty( $state['enrolment'] ) ) {
		$problems[] = 'enrolment_not_found';
		return $problems;
	}
	$enrolment = $state['enrolment'];
	if ( (string) ( $enrolment['record_model'] ?? '' ) !== 'legacy_phase1' ) {
		$problems[] = 'enrolment_not_an_import';
	}
	if ( (int) ( $enrolment['archived_at_present'] ?? 0 ) === 1 ) {
		$problems[] = 'enrolment_already_archived';
	}
	if ( (string) ( $state['student']['reference_code'] ?? '' ) !== (string) ( $state['expected_student_reference'] ?? '' ) ) {
		$problems[] = 'student_reference_mismatch';
	}
	if ( empty( $state['student']['available'] ) ) {
		$problems[] = 'student_unavailable';
	}
	if ( empty( $state['import_evidence'] ) ) {
		$problems[] = 'import_evidence_missing';
	}
	if ( ! empty( $state['canonical_enrolment_id'] ) ) {
		$problems[] = 'canonical_enrolment_exists';
	}
	$terms = (array) ( $state['terms'] ?? array() );
	if ( count( $terms ) !== 1 ) {
		$problems[] = 'term_count_invalid';
	} else {
		$term = $terms[0];
		if ( (string) ( $term['record_model'] ?? '' ) !== 'legacy_phase1' ) {
			$problems[] = 'term_not_an_import';
		}
		if ( (int) ( $term['archived_at_present'] ?? 0 ) === 1 ) {
			$problems[] = 'term_already_archived';
		}
		if ( ! in_array( (string) ( $term['payment_state'] ?? '' ), array( 'unknown', 'not_required', 'pending' ), true ) ) {
			$problems[] = 'term_payment_recorded';
		}
	}
	$lessons = (array) ( $state['lessons'] ?? array() );
	if ( $lessons === array() ) {
		$problems[] = 'lesson_count_invalid';
	}
	foreach ( $lessons as $lesson ) {
		$status = (string) ( $lesson['status'] ?? '' );
		if ( (string) ( $lesson['record_model'] ?? '' ) !== 'legacy_phase1' || in_array( $status, array( 'completed', 'cancelled', 'archived' ), true ) ) {
			$problems[] = 'lesson_not_retirable';
			break;
		}
		if ( (int) ( $lesson['archived_at_present'] ?? 0 ) === 1 ) {
			$problems[] = 'lesson_already_archived';
			break;
		}
		if ( (string) ( $lesson['lesson_type'] ?? '' ) !== 'standard' ) {
			$problems[] = 'lesson_not_standard';
			break;
		}
		if ( (int) ( $lesson['booking_request_id'] ?? 0 ) > 0 ) {
			$problems[] = 'lesson_linked_to_booking';
			break;
		}
		if ( (string) ( $lesson['starts_at_utc'] ?? '' ) === '' || (string) $lesson['starts_at_utc'] <= (string) ( $state['now'] ?? gmdate( 'Y-m-d H:i:s' ) ) ) {
			$problems[] = 'lesson_already_started';
			break;
		}
	}
	if ( (int) ( $state['enrolment_reference_count'] ?? 0 ) > 1 ) {
		$problems[] = 'enrolment_reference_ambiguous';
	}
	if ( (int) ( $state['downstream_reference_count'] ?? 0 ) > 0 ) {
		$problems[] = 'downstream_reference_exists';
	}
	if ( (int) ( $state['lesson_reference_count'] ?? 0 ) > 0 ) {
		$problems[] = 'lesson_reference_exists';
	}
	return array_values( array_unique( $problems ) );
}

function dzn_retire_emit( array $payload, int $code = 0 ): void {
	$payload['retirement_performed'] = ! empty( $payload['retirement_performed'] );
	echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
	exit( $code );
}

function dzn_retire_fail( string $error, string $stage, array $extra = array() ): void {
	dzn_retire_emit( array_merge( array( 'ok' => false, 'error' => $error, 'stage' => $stage ), $extra ), 1 );
}

/** Only whitelisted identifiers are ever interpolated into SQL. */
function dzn_retire_ident( string $name ): string {
	if ( ! preg_match( '/\A[a-z0-9_]+\z/', $name ) ) {
		throw new RuntimeException( 'invalid_identifier' );
	}
	return '`' . $name . '`';
}

function dzn_retire_table( string $short ): string {
	global $wpdb;
	if ( ! preg_match( '/\A[a-z0-9_]+\z/', $short ) ) {
		throw new RuntimeException( 'invalid_identifier' );
	}
	return $wpdb->prefix . 'dzn_' . $short;
}

/**
 * Every dzn_* table that carries the given column, excluding tables whose rows
 * are part of the retired aggregate itself.
 *
 * @param array<int,string> $except
 * @return array<int,string>
 */
function dzn_retire_referencing_tables( string $column, array $except ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME',
		$column,
		$wpdb->esc_like( $wpdb->prefix . 'dzn_' ) . '%'
	) );
	$skip = array();
	foreach ( $except as $short ) {
		$skip[ $wpdb->prefix . 'dzn_' . $short ] = true;
	}
	$tables = array();
	foreach ( (array) $rows as $row ) {
		$table = (string) ( $row->TABLE_NAME ?? '' );
		if ( $table === '' || isset( $skip[ $table ] ) || ! preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) ) {
			continue;
		}
		$tables[] = $table;
	}
	return $tables;
}

/** @param array<int,string> $tables */
function dzn_retire_reference_count( array $tables, string $column, int $id ): int {
	global $wpdb;
	$total = 0;
	foreach ( $tables as $table ) {
		if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) || ! preg_match( '/\A[a-z0-9_]+\z/', $column ) ) {
			throw new RuntimeException( 'invalid_identifier' );
		}
		$count = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . dzn_retire_ident( $table ) . ' WHERE ' . dzn_retire_ident( $column ) . '=%d',
			$id
		) );
		if ( $wpdb->last_error !== '' ) {
			throw new RuntimeException( 'reference_count_failed' );
		}
		$total += (int) $count;
	}
	return $total;
}

/**
 * Load the complete pre-state for one imported Enrolment. Read-only.
 *
 * @return array<string,mixed>
 */
function dzn_retire_state( string $enrolment_reference, string $student_reference ): array {
	global $wpdb;
	$enrolment_table = dzn_retire_table( 'enrolments' );
	$matches = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$enrolment_table} WHERE reference_code=%s ORDER BY id ASC",
		$enrolment_reference
	) );
	$state = array(
		'expected_student_reference' => $student_reference,
		'now'                        => gmdate( 'Y-m-d H:i:s' ),
		'enrolment'                  => null,
		'student'                    => array(),
		'terms'                      => array(),
		'lessons'                    => array(),
		'import_evidence'            => 0,
		'canonical_enrolment_id'     => 0,
		'enrolment_reference_count'  => count( (array) $matches ),
		'downstream_reference_count' => 0,
		'lesson_reference_count'     => 0,
	);
	if ( ! is_array( $matches ) || count( $matches ) !== 1 ) {
		return $state;
	}
	$row = $matches[0];
	$state['enrolment'] = array(
		'id'                  => (int) $row->id,
		'uid'                 => (string) $row->uid,
		'reference_code'      => (string) $row->reference_code,
		'record_model'        => (string) ( $row->record_model ?? '' ),
		'status'              => (string) $row->status,
		'student_id'          => (int) $row->student_id,
		'teacher_id'          => (int) $row->teacher_id,
		'course_id'           => (int) $row->course_id,
		'archived_at_present' => null !== ( $row->archived_at ?? null ) ? 1 : 0,
		'updated_at'          => (string) ( $row->updated_at ?? '' ),
	);
	$enrolment_id = (int) $row->id;

	$students = dzn_retire_table( 'students' );
	$student  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$students} WHERE id=%d", (int) $row->student_id ) );
	if ( $student ) {
		$state['student'] = array(
			'id'              => (int) $student->id,
			'reference_code'  => (string) ( $student->reference_code ?? '' ),
			'status'          => (string) ( $student->status ?? '' ),
			'available'       => null === ( $student->archived_at ?? null ) && (string) ( $student->status ?? '' ) !== 'archived',
		);
	}

	$terms_table = dzn_retire_table( 'terms' );
	$terms       = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$terms_table} WHERE enrolment_id=%d AND archived_at IS NULL ORDER BY sequence_number ASC, id ASC",
		$enrolment_id
	) );
	$term_ids = array();
	foreach ( (array) $terms as $term ) {
		$term_ids[] = (int) $term->id;
		$state['terms'][] = array(
			'id'                  => (int) $term->id,
			'reference_code'      => (string) ( $term->reference_code ?? '' ),
			'record_model'        => (string) ( $term->record_model ?? '' ),
			'status'              => (string) $term->status,
			'payment_state'       => (string) $term->payment_state,
			'lesson_allocation'   => (int) $term->lesson_allocation,
			'archived_at_present' => null !== ( $term->archived_at ?? null ) ? 1 : 0,
		);
	}

	if ( $term_ids !== array() ) {
		$in          = implode( ',', array_map( 'intval', $term_ids ) );
		$lessons     = $wpdb->get_results(
			"SELECT l.*,v.starts_at_utc,v.ends_at_utc FROM " . dzn_retire_table( 'lessons' ) . " l"
			. ' LEFT JOIN ' . dzn_retire_table( 'lesson_schedule_versions' ) . ' v ON v.id=l.current_schedule_version_id AND v.superseded_at IS NULL'
			. ' WHERE l.term_id IN (' . $in . ') ORDER BY l.id ASC'
		);
		foreach ( (array) $lessons as $lesson ) {
			$state['lessons'][] = array(
				'id'                  => (int) $lesson->id,
				'uid'                 => (string) $lesson->uid,
				'reference_code'      => (string) ( $lesson->reference_code ?? '' ),
				'record_model'        => (string) ( $lesson->record_model ?? '' ),
				'status'              => (string) $lesson->status,
				'lesson_type'         => (string) $lesson->lesson_type,
				'booking_request_id'  => (int) ( $lesson->booking_request_id ?? 0 ),
				'starts_at_utc'       => (string) ( $lesson->starts_at_utc ?? '' ),
				'ends_at_utc'         => (string) ( $lesson->ends_at_utc ?? '' ),
				'archived_at_present' => null !== ( $lesson->archived_at ?? null ) ? 1 : 0,
			);
		}
	}

	$audit = dzn_retire_table( 'platform_audit_events' );
	$state['import_evidence'] = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$audit} WHERE aggregate_type='enrolment' AND aggregate_id=%d AND event_type='enrolment.historical_import'",
		$enrolment_id
	) );

	$state['canonical_enrolment_id'] = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$enrolment_table} WHERE student_id=%d AND course_id=%d AND teacher_id=%d AND record_model<>'legacy_phase1' AND archived_at IS NULL LIMIT 1",
		(int) $row->student_id,
		(int) $row->course_id,
		(int) $row->teacher_id
	) );

	$state['downstream_reference_count'] = dzn_retire_reference_count(
		dzn_retire_referencing_tables( 'enrolment_id', DZN_RETIRE_ENROLMENT_SELF ),
		'enrolment_id',
		$enrolment_id
	);
	$lesson_ids = array_map( static fn( array $lesson ): int => (int) $lesson['id'], $state['lessons'] );
	$lesson_refs = 0;
	$referencing = dzn_retire_referencing_tables( 'lesson_id', DZN_RETIRE_LESSON_SELF );
	foreach ( $lesson_ids as $lesson_id ) {
		$lesson_refs += dzn_retire_reference_count( $referencing, 'lesson_id', $lesson_id );
	}
	$state['lesson_reference_count'] = $lesson_refs;

	if ( $wpdb->last_error !== '' ) {
		throw new RuntimeException( 'prestate_read_failed' );
	}
	return $state;
}

/** Stable across report and execute; never includes the clock. */
function dzn_retire_fingerprint( array $state ): string {
	$canonical = array(
		'version'    => 'retire-imported-enrolment-v1',
		'enrolment'  => array(
			'id'           => (int) ( $state['enrolment']['id'] ?? 0 ),
			'reference'    => (string) ( $state['enrolment']['reference_code'] ?? '' ),
			'uid'          => (string) ( $state['enrolment']['uid'] ?? '' ),
			'record_model' => (string) ( $state['enrolment']['record_model'] ?? '' ),
			'status'       => (string) ( $state['enrolment']['status'] ?? '' ),
			'updated_at'   => (string) ( $state['enrolment']['updated_at'] ?? '' ),
			'student_id'   => (int) ( $state['enrolment']['student_id'] ?? 0 ),
			'teacher_id'   => (int) ( $state['enrolment']['teacher_id'] ?? 0 ),
			'course_id'    => (int) ( $state['enrolment']['course_id'] ?? 0 ),
		),
		'terms'      => array_map( static fn( array $term ): array => array(
			'id'            => (int) $term['id'],
			'uid'           => (string) ( $term['uid'] ?? '' ),
			'reference'     => (string) ( $term['reference_code'] ?? '' ),
			'status'        => (string) $term['status'],
			'payment_state' => (string) $term['payment_state'],
		), (array) $state['terms'] ),
		'lessons'    => array_map( static fn( array $lesson ): array => array(
			'id'            => (int) $lesson['id'],
			'uid'           => (string) $lesson['uid'],
			'status'        => (string) $lesson['status'],
			'starts_at_utc' => (string) $lesson['starts_at_utc'],
			'ends_at_utc'   => (string) $lesson['ends_at_utc'],
		), (array) $state['lessons'] ),
		'counts'     => array(
			'terms'   => count( (array) $state['terms'] ),
			'lessons' => count( (array) $state['lessons'] ),
		),
	);
	return hash( 'sha256', wp_json_encode( $canonical ) . '|' . (string) ( $state['expected_student_reference'] ?? '' ) );
}

/** @return array<string,int> */
function dzn_retire_preserve_counts( string $plan_file ): array {
	if ( '' === $plan_file || ! is_readable( $plan_file ) || 'purge-test-data-tables.php' !== basename( $plan_file ) ) {
		throw new RuntimeException( 'plan_file_unavailable' );
	}
	$plan = require $plan_file;
	if ( ! is_array( $plan ) || ! isset( $plan['preserve'] ) ) {
		throw new RuntimeException( 'plan_file_invalid' );
	}
	global $wpdb;
	$counts = array();
	foreach ( (array) $plan['preserve'] as $short ) {
		$table = dzn_retire_table( (string) $short );
		$count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . dzn_retire_ident( $table ) );
		if ( $wpdb->last_error !== '' ) {
			throw new RuntimeException( 'preserve_count_failed' );
		}
		$counts[ (string) $short ] = (int) $count;
	}
	return $counts;
}

/** @return array<string,mixed> */
function dzn_retire_public_state( array $state, array $problems, string $fingerprint ): array {
	return array(
		'enrolment'    => $state['enrolment'],
		'student'      => $state['student'],
		'terms'        => $state['terms'],
		'lessons'      => array_map( static fn( array $lesson ): array => array(
			'id'            => $lesson['id'],
			'reference'     => $lesson['reference_code'],
			'status'        => $lesson['status'],
			'starts_at_utc' => $lesson['starts_at_utc'],
		), (array) $state['lessons'] ),
		'refusal_codes' => $problems,
		'fingerprint'   => $fingerprint,
	);
}

/**
 * Archive lessons, then the Term, then the Enrolment, in one transaction, and
 * record one audit event. Re-verifies the pre-state under lock.
 *
 * @return array<string,mixed>
 */
function dzn_retire_execute( string $enrolment_reference, string $student_reference, string $fingerprint ): array {
	global $wpdb;
	$now    = gmdate( 'Y-m-d H:i:s' );
	$state  = dzn_retire_state( $enrolment_reference, $student_reference );
	$locked = dzn_retire_decision( $state );
	if ( $locked !== array() ) {
		throw new RuntimeException( 'preflight_refused:' . implode( ',', $locked ) );
	}
	if ( dzn_retire_fingerprint( $state ) !== $fingerprint ) {
		throw new RuntimeException( 'prestate_changed' );
	}
	$enrolment_id = (int) $state['enrolment']['id'];
	$term_id      = (int) $state['terms'][0]['id'];
	$lesson_ids   = array_map( static fn( array $lesson ): int => (int) $lesson['id'], $state['lessons'] );

	$enrolment_table = dzn_retire_table( 'enrolments' );
	$terms_table     = dzn_retire_table( 'terms' );
	$lessons_table   = dzn_retire_table( 'lessons' );
	$audit           = dzn_retire_table( 'platform_audit_events' );

	if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
		throw new RuntimeException( 'transaction_start_failed' );
	}
	try {
		$live_enrolment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$enrolment_table} WHERE id=%d FOR UPDATE", $enrolment_id ) );
		$live_term      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$terms_table} WHERE id=%d FOR UPDATE", $term_id ) );
		if ( ! $live_enrolment || ! $live_term || null !== $live_enrolment->archived_at || null !== $live_term->archived_at ) {
			throw new RuntimeException( 'prestate_changed' );
		}
		if ( ! in_array( (string) $live_term->payment_state, array( 'unknown', 'not_required', 'pending' ), true ) ) {
			throw new RuntimeException( 'prestate_changed' );
		}
		foreach ( $lesson_ids as $lesson_id ) {
			$live = $wpdb->get_row( $wpdb->prepare( "SELECT id,status,archived_at FROM {$lessons_table} WHERE id=%d FOR UPDATE", $lesson_id ) );
			if ( ! $live || null !== $live->archived_at || 'scheduled' !== (string) $live->status ) {
				throw new RuntimeException( 'prestate_changed' );
			}
		}

		foreach ( $lesson_ids as $lesson_id ) {
			$updated = $wpdb->update(
				$lessons_table,
				array( 'status' => 'archived', 'archived_at' => $now, 'archived_by' => null, 'updated_at' => $now, 'updated_by' => null ),
				array( 'id' => $lesson_id, 'archived_at' => null )
			);
			if ( 1 !== $updated ) {
				throw new RuntimeException( 'lesson_archive_failed' );
			}
		}
		$term_updated = $wpdb->update(
			$terms_table,
			array( 'status' => 'archived', 'archived_at' => $now, 'archived_by' => null, 'updated_at' => $now, 'updated_by' => null ),
			array( 'id' => $term_id, 'archived_at' => null )
		);
		if ( 1 !== $term_updated ) {
			throw new RuntimeException( 'term_archive_failed' );
		}
		$enrolment_updated = $wpdb->update(
			$enrolment_table,
			array( 'status' => 'archived', 'archived_at' => $now, 'archived_by' => null, 'updated_at' => $now, 'updated_by' => null ),
			array( 'id' => $enrolment_id, 'archived_at' => null )
		);
		if ( 1 !== $enrolment_updated ) {
			throw new RuntimeException( 'enrolment_archive_failed' );
		}

		$digest = hash_hmac( 'sha256', 'retire-imported-enrolment:' . $enrolment_reference . ':' . $fingerprint, wp_salt( 'dzn_platform_audit' ) );
		$audited = $wpdb->insert( $audit, array(
			'aggregate_type'  => 'enrolment',
			'aggregate_id'    => $enrolment_id,
			'event_type'      => 'enrolment.imported_retirement',
			'actor_type'      => 'system',
			'actor_id'        => null,
			'reason_code'     => 'retire_imported_enrolment',
			'safe_detail'     => wp_json_encode( array(
				'enrolment_reference' => $enrolment_reference,
				'term_id'             => $term_id,
				'lesson_ids'          => $lesson_ids,
				'fingerprint'         => $fingerprint,
				'payment_state'       => (string) $state['terms'][0]['payment_state'],
			) ),
			'idempotency_key' => $digest,
			'occurred_at'     => $now,
		) );
		if ( false === $audited ) {
			throw new RuntimeException( 'audit_write_failed' );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			throw new RuntimeException( 'commit_failed' );
		}
	} catch ( Throwable $error ) {
		$wpdb->query( 'ROLLBACK' );
		throw $error;
	}

	return array(
		'enrolment_id' => $enrolment_id,
		'term_id'      => $term_id,
		'lesson_ids'   => $lesson_ids,
		'audit_digest' => $digest,
	);
}

/** @return array<string,mixed> */
function dzn_retire_verify( int $enrolment_id, int $term_id, array $lesson_ids, array $preserve_before, string $audit_digest, int $teacher_id ): array {
	global $wpdb;
	$enrolment_table = dzn_retire_table( 'enrolments' );
	$terms_table     = dzn_retire_table( 'terms' );
	$lessons_table   = dzn_retire_table( 'lessons' );
	$versions        = dzn_retire_table( 'lesson_schedule_versions' );
	$audit           = dzn_retire_table( 'platform_audit_events' );

	$enrolment          = $wpdb->get_row( $wpdb->prepare( "SELECT status,archived_at FROM {$enrolment_table} WHERE id=%d", $enrolment_id ) );
	$term               = $wpdb->get_row( $wpdb->prepare( "SELECT status,archived_at FROM {$terms_table} WHERE id=%d", $term_id ) );
	$visible_lessons    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lessons_table} WHERE term_id=" . (int) $term_id . ' AND archived_at IS NULL' );
	$audit_row          = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE idempotency_key=%s", $audit_digest ) );
	$student_intact     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . dzn_retire_table( 'students' ) . ' WHERE id=(SELECT student_id FROM ' . $enrolment_table . ' WHERE id=%d)', $enrolment_id ) );

	$slot_conflicts = 0;
	$lessons_table_name = $lessons_table;
	foreach ( $lesson_ids as $lesson_id ) {
		$window = $wpdb->get_row( $wpdb->prepare(
			"SELECT v.starts_at_utc,v.ends_at_utc FROM {$lessons_table_name} l JOIN {$versions} v ON v.id=l.current_schedule_version_id AND v.superseded_at IS NULL WHERE l.id=%d",
			$lesson_id
		) );
		if ( ! $window ) {
			continue;
		}
		$slot_conflicts += (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$lessons_table_name} l JOIN {$versions} v ON v.id=l.current_schedule_version_id AND v.superseded_at IS NULL WHERE l.teacher_id=%d AND l.status='scheduled' AND l.archived_at IS NULL AND v.starts_at_utc < %s AND v.ends_at_utc > %s",
			$teacher_id,
			(string) $window->ends_at_utc,
			(string) $window->starts_at_utc
		) );
	}

	$preserve_after    = dzn_retire_preserve_counts( __DIR__ . '/purge-test-data-tables.php' );
	$catalogue_unchanged = $preserve_after === $preserve_before;

	return array(
		'enrolment_archived'       => is_object( $enrolment ) && null !== $enrolment->archived_at && 'archived' === (string) $enrolment->status,
		'term_archived'            => is_object( $term ) && null !== $term->archived_at && 'archived' === (string) $term->status,
		'lessons_visible_remaining' => $visible_lessons,
		'teacher_slot_conflicts'   => $slot_conflicts,
		'audit_recorded'           => $audit_row === 1,
		'student_intact'           => $student_intact === 1,
		'catalogue_unchanged'      => $catalogue_unchanged,
	);
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

if ( defined( 'DZN_RETIRE_PAYLOAD_LIBRARY' ) ) {
	return;
}

$dzn_retire_args = isset( $args ) && is_array( $args ) ? array_values( $args ) : array();
$dzn_retire_arg  = static function ( int $index ) use ( $dzn_retire_args ): string {
	$value = (string) ( $dzn_retire_args[ $index ] ?? '' );
	return 'none' === $value ? '' : $value;
};

$dzn_retire_mode       = strtolower( '' !== $dzn_retire_arg( 0 ) ? $dzn_retire_arg( 0 ) : 'report' );
$dzn_retire_reference  = strtoupper( trim( $dzn_retire_arg( 1 ) ) );
$dzn_retire_student    = strtoupper( trim( $dzn_retire_arg( 2 ) ) );
$dzn_retire_confirm    = $dzn_retire_arg( 3 );
$dzn_retire_expected   = strtolower( trim( $dzn_retire_arg( 4 ) ) );
$dzn_retire_backup     = $dzn_retire_arg( 5 );
$dzn_retire_backup_sha = strtolower( trim( $dzn_retire_arg( 6 ) ) );
$dzn_retire_attest     = $dzn_retire_arg( 7 );
$dzn_retire_plan       = '' !== $dzn_retire_arg( 8 ) ? $dzn_retire_arg( 8 ) : __DIR__ . '/purge-test-data-tables.php';

if ( ! in_array( $dzn_retire_mode, array( 'report', 'execute' ), true ) ) {
	dzn_retire_fail( 'mode_invalid', 'args' );
}
if ( ! preg_match( '/\AENR-[A-Z0-9]+\z/', $dzn_retire_reference ) ) {
	dzn_retire_fail( 'enrolment_reference_invalid', 'args' );
}
if ( ! preg_match( '/\ACC[A-Z0-9]+\z/', $dzn_retire_student ) ) {
	dzn_retire_fail( 'student_reference_invalid', 'args' );
}

try {
	$dzn_retire_state = dzn_retire_state( $dzn_retire_reference, $dzn_retire_student );
} catch ( Throwable $error ) {
	dzn_retire_fail( $error->getMessage(), 'prestate' );
}

$dzn_retire_problems    = dzn_retire_decision( $dzn_retire_state );
$dzn_retire_fingerprint = dzn_retire_fingerprint( $dzn_retire_state );
$dzn_retire_public      = dzn_retire_public_state( $dzn_retire_state, $dzn_retire_problems, $dzn_retire_fingerprint );

if ( 'report' === $dzn_retire_mode ) {
	// Report always exits 0 so the workflow can print the refusal codes; the
	// workflow asserts .ok == true before it offers a fingerprint.
	dzn_retire_emit( array_merge(
		array( 'ok' => $dzn_retire_problems === array(), 'mode' => 'report', 'retirement_performed' => false ),
		$dzn_retire_public
	), 0 );
}

if ( DZN_RETIRE_CONFIRM_TOKEN !== $dzn_retire_confirm ) {
	dzn_retire_fail( 'confirm_token_invalid', 'args', $dzn_retire_public );
}
if ( ! preg_match( '/\A[0-9a-f]{64}\z/', $dzn_retire_expected ) || $dzn_retire_expected !== $dzn_retire_fingerprint ) {
	dzn_retire_fail( 'fingerprint_mismatch', 'fingerprint', $dzn_retire_public );
}
if ( '' === $dzn_retire_attest || ! hash_equals( DZN_RETIRE_ATTESTATION_SHA256, hash( 'sha256', $dzn_retire_attest ) ) ) {
	dzn_retire_fail( 'attestation_invalid', 'attestation' );
}
if ( '' === $dzn_retire_backup || ! is_readable( $dzn_retire_backup ) || ! preg_match( '/\A[0-9a-f]{64}\z/', $dzn_retire_backup_sha ) ) {
	dzn_retire_fail( 'backup_unavailable', 'backup' );
}
if ( ! hash_equals( $dzn_retire_backup_sha, (string) hash_file( 'sha256', $dzn_retire_backup ) ) ) {
	dzn_retire_fail( 'backup_checksum_mismatch', 'backup' );
}
if ( filesize( $dzn_retire_backup ) < 65536 ) {
	dzn_retire_fail( 'backup_implausibly_small', 'backup' );
}
if ( $dzn_retire_problems !== array() ) {
	dzn_retire_fail( 'preflight_refused', 'preflight', $dzn_retire_public );
}

try {
	$dzn_retire_preserve = dzn_retire_preserve_counts( $dzn_retire_plan );
	$dzn_retire_result   = dzn_retire_execute( $dzn_retire_reference, $dzn_retire_student, $dzn_retire_fingerprint );
} catch ( Throwable $error ) {
	dzn_retire_fail( $error->getMessage(), 'execute', $dzn_retire_public );
}

$dzn_retire_verification = dzn_retire_verify(
	(int) $dzn_retire_result['enrolment_id'],
	(int) $dzn_retire_result['term_id'],
	(array) $dzn_retire_result['lesson_ids'],
	$dzn_retire_preserve,
	(string) $dzn_retire_result['audit_digest'],
	(int) $dzn_retire_state['enrolment']['teacher_id']
);

$dzn_retire_verified = $dzn_retire_verification['enrolment_archived']
	&& $dzn_retire_verification['term_archived']
	&& 0 === $dzn_retire_verification['lessons_visible_remaining']
	&& 0 === $dzn_retire_verification['teacher_slot_conflicts']
	&& $dzn_retire_verification['audit_recorded']
	&& $dzn_retire_verification['student_intact']
	&& $dzn_retire_verification['catalogue_unchanged'];

dzn_retire_emit( array_merge(
	array(
		'ok'                   => $dzn_retire_verified,
		'mode'                 => 'execute',
		'retirement_performed' => true,
		'retired'              => array(
			'enrolment_id' => (int) $dzn_retire_result['enrolment_id'],
			'term_id'      => (int) $dzn_retire_result['term_id'],
			'lesson_ids'   => array_map( 'intval', (array) $dzn_retire_result['lesson_ids'] ),
		),
		'post_verification'    => $dzn_retire_verification,
		'backup_sha256'        => $dzn_retire_backup_sha,
	),
	$dzn_retire_public
), $dzn_retire_verified ? 0 : 1 );
