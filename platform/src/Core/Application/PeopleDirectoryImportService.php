<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Generic, owner-driven People-directory contact import.
 *
 * The importer is deliberately data-free: no real name, phone or email is ever
 * committed to the repository. The owner pastes/uploades a CSV at runtime and the
 * rows are normalised, matched against existing Students and Teachers by normalised
 * phone/email (so one human is not duplicated), and applied only after an explicit
 * confirm. Re-running the same upload is idempotent through an audited command key,
 * and within a batch, duplicate rows collapse to one contact.
 */
final class PeopleDirectoryImportService {
	public const CAPABILITY = 'dzn_view_people_directory';

	/** Accepted CSV columns (header). `country` accepts the ISO code or full name. */
	public const COLUMNS = array( 'name', 'phone', 'email', 'city', 'country', 'instrument', 'teacher_name', 'source' );

	/**
	 * Parse pasted or uploaded CSV text into associative rows keyed by column name.
	 * A header row is expected; column names are matched case-insensitively against
	 * {@see COLUMNS}. Blank lines and rows without a name are dropped.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function parseCsv( string $csv ): array {
		$csv = trim( (string) $csv );
		if ( $csv === '' ) return array();
		$lines = preg_split( '/\r\n|\r|\n/', $csv ) ?: array();
		$lines = array_values( array_filter( $lines, static fn( string $line ): bool => trim( $line ) !== '' ) );
		if ( count( $lines ) < 2 ) return array();
		$header = array_map( static fn( string $cell ): string => strtolower( trim( $cell ) ), str_getcsv( (string) array_shift( $lines ) ) );
		$rows = array();
		foreach ( $lines as $line ) {
			$cells = str_getcsv( $line );
			$row = array();
			foreach ( $header as $index => $column ) {
				if ( $column === '' ) continue;
				$value = $cells[ $index ] ?? '';
				if ( $column === 'country_code' || $column === 'country' ) {
					$row['country'] = $value;
				} elseif ( $column === 'teacher' || $column === 'teachername' ) {
					$row['teacher_name'] = $value;
				} else {
					$row[ $column ] = $value;
				}
			}
			if ( trim( (string) ( $row['name'] ?? '' ) ) === '' ) continue;
			$rows[] = $row;
		}
		return $rows;
	}

	/** Digits-only comparison key so stored and imported numbers match practically. */
	public static function normalisePhone( string $value ): string {
		return preg_replace( '/\D+/', '', (string) $value ) ?: '';
	}

	/** Case/whitespace-insensitive email comparison key. */
	public static function normaliseEmail( string $value ): string {
		return strtolower( trim( (string) $value ) );
	}

	/** @return array<string,string|null> */
	public static function normaliseRow( array $row ): array {
		$name = trim( (string) ( $row['name'] ?? '' ) );
		if ( $name === '' ) throw new \InvalidArgumentException( 'Contact name is required' );
		if ( strlen( $name ) > 191 ) $name = substr( $name, 0, 191 );
		$email = self::normaliseEmail( (string) ( $row['email'] ?? '' ) );
		$phone = self::normalisePhone( (string) ( $row['phone'] ?? '' ) );
		if ( $email === '' && $phone === '' ) throw new \InvalidArgumentException( 'A phone or email is required to match a contact' );
		$city = trim( (string) ( $row['city'] ?? '' ) );
		$country = self::normaliseCountry( (string) ( $row['country'] ?? '' ) );
		$instrument = trim( (string) ( $row['instrument'] ?? '' ) );
		$teacherName = trim( (string) ( $row['teacher_name'] ?? '' ) );
		$source = trim( (string) ( $row['source'] ?? 'csv_import' ) );
		if ( $source === '' ) $source = 'csv_import';
		$source = strlen( $source ) > 32 ? substr( $source, 0, 32 ) : $source;
		return array(
			'name' => $name,
			'phone' => $phone !== '' ? $phone : null,
			'email' => $email !== '' ? $email : null,
			'city' => $city !== '' ? $city : null,
			'country' => $country,
			'instrument' => $instrument !== '' ? $instrument : null,
			'teacher_name' => $teacherName !== '' ? $teacherName : null,
			'source' => $source,
		);
	}

	/** ISO-2 country code from a code or a common full name; null when unrecognised. */
	public static function normaliseCountry( string $value ): ?string {
		$value = strtoupper( trim( $value ) );
		if ( $value === '' ) return null;
		if ( preg_match( '/^[A-Z]{2}$/', $value ) ) return $value;
		$map = array(
			'IRAN' => 'IR', 'AUSTRALIA' => 'AU', 'CANADA' => 'CA', 'UNITED KINGDOM' => 'GB',
			'UNITED STATES' => 'US', 'USA' => 'US', 'GERMANY' => 'DE', 'FRANCE' => 'FR',
			'UAE' => 'AE', 'TURKEY' => 'TR', 'NETHERLANDS' => 'NL', 'SWEDEN' => 'SE',
			'NEW ZEALAND' => 'NZ', 'ITALY' => 'IT', 'SPAIN' => 'ES', 'AUSTRIA' => 'AT',
			'SWITZERLAND' => 'CH', 'BELGIUM' => 'BE', 'NORWAY' => 'NO', 'DENMARK' => 'DK',
		);
		return $map[ $value ] ?? null;
	}

	/**
	 * Collapse a batch to one row per normalised phone/email identity. The first
	 * occurrence wins; later occurrences are dropped so one human is not duplicated.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{rows:array<int,array<string,mixed>>,skipped:int}
	 */
	public static function dedupeRows( array $rows ): array {
		$seen = array();
		$out = array();
		$skipped = 0;
		foreach ( $rows as $row ) {
			$phone = self::normalisePhone( (string) ( $row['phone'] ?? '' ) );
			$email = self::normaliseEmail( (string) ( $row['email'] ?? '' ) );
			$keys = array();
			if ( $phone !== '' ) $keys[] = 'p:' . $phone;
			if ( $email !== '' ) $keys[] = 'e:' . $email;
			$duplicate = false;
			foreach ( $keys as $key ) {
				if ( isset( $seen[ $key ] ) ) { $duplicate = true; break; }
			}
			if ( $duplicate ) { $skipped++; continue; }
			foreach ( $keys as $key ) $seen[ $key ] = true;
			$out[] = $row;
		}
		return array( 'rows' => $out, 'skipped' => $skipped );
	}

	/**
	 * Normalise and match raw CSV rows without writing anything. Returns the preview
	 * the owner reviews before confirming the import.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	public function preview( array $rows ): array {
		$this->guard();
		$deduped = self::dedupeRows( array_map( array( self::class, 'normaliseRow' ), $rows ) );
		$preview = array();
		foreach ( $deduped['rows'] as $row ) {
			$match = $this->match( $row['phone'], $row['email'] );
			$preview[] = array_merge( $row, array(
				'matched_student_id' => $match['student_id'],
				'matched_teacher_id' => $match['teacher_id'],
				'matched_label' => $match['label'],
			) );
		}
		return array( 'rows' => $preview, 'total' => count( $preview ), 'skipped_duplicates' => $deduped['skipped'] );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{imported:int,matched:int,skipped:int,idempotent:bool}
	 */
	public function import( array $rows, string $idempotencyKey ): array {
		$this->guard();
		$key = trim( $idempotencyKey );
		if ( $key === '' || strlen( $key ) > 191 ) throw new \InvalidArgumentException( 'Import idempotency key required' );
		$digest = hash_hmac( 'sha256', 'people-directory-contact-import:' . $key, wp_salt( 'dzn_platform_audit' ) );
		global $wpdb;
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}dzn_platform_audit_events WHERE idempotency_key=%s",
			$digest
		) );
		if ( $existing ) {
			$detail = json_decode( (string) $existing->safe_detail, true );
			return array(
				'imported' => (int) ( $detail['imported'] ?? 0 ),
				'matched' => (int) ( $detail['matched'] ?? 0 ),
				'skipped' => (int) ( $detail['skipped'] ?? 0 ),
				'idempotent' => true,
			);
		}
		$deduped = self::dedupeRows( array_map( array( self::class, 'normaliseRow' ), $rows ) );
		$now = gmdate( 'Y-m-d H:i:s' );
		$actor = (int) get_current_user_id();
		$imported = 0;
		$matched = 0;
		$skipped = $deduped['skipped'];
		$wpdb->query( 'START TRANSACTION' );
		try {
			foreach ( $deduped['rows'] as $row ) {
				$match = $this->match( $row['phone'], $row['email'] );
				if ( $match['student_id'] !== null || $match['teacher_id'] !== null ) { $matched++; continue; }
				if ( $this->alreadyImported( $row['phone'], $row['email'] ) ) { $skipped++; continue; }
				$inserted = $wpdb->insert( $wpdb->prefix . 'dzn_academy_directory_contacts', array(
					'uid' => Identifier::uid(),
					'name' => $row['name'],
					'phone' => $row['phone'],
					'email' => $row['email'],
					'city' => $row['city'],
					'country_code' => $row['country'],
					'instrument' => $row['instrument'],
					'teacher_name' => $row['teacher_name'],
					'source' => $row['source'],
					'matched_student_id' => null,
					'matched_teacher_id' => null,
					'created_at' => $now,
					'created_by' => $actor > 0 ? $actor : null,
				) );
				if ( $inserted === false ) throw new \RuntimeException( 'People directory contact import failed: ' . $wpdb->last_error );
				$imported++;
			}
			$audited = $wpdb->insert( $wpdb->prefix . 'dzn_platform_audit_events', array(
				'aggregate_type' => 'people_directory',
				'aggregate_id' => 0,
				'event_type' => 'people_directory.contact_import',
				'actor_type' => 'administrator',
				'actor_id' => $actor > 0 ? $actor : null,
				'reason_code' => 'contact_csv_import',
				'safe_detail' => wp_json_encode( array( 'imported' => $imported, 'matched' => $matched, 'skipped' => $skipped ) ),
				'idempotency_key' => $digest,
				'occurred_at' => $now,
			) );
			if ( $audited === false ) throw new \RuntimeException( 'People directory import evidence could not be recorded' );
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
		return array( 'imported' => $imported, 'matched' => $matched, 'skipped' => $skipped, 'idempotent' => false );
	}

	/**
	 * @return array{student_id:int|null,teacher_id:int|null,label:string}
	 */
	private function match( ?string $phone, ?string $email ): array {
		global $wpdb;
		$phone = self::normalisePhone( (string) $phone );
		$email = self::normaliseEmail( (string) $email );
		$phoneSql = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''),' ',''),'-',''),'+',''),'(',''),')','')";
		$student = null;
		if ( $phone !== '' ) {
			$student = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, display_name, first_name, last_name FROM {$wpdb->prefix}dzn_students WHERE archived_at IS NULL AND {$phoneSql}=%s LIMIT 1",
				$phone
			) );
		}
		if ( ! $student && $email !== '' ) {
			$student = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, display_name, first_name, last_name FROM {$wpdb->prefix}dzn_students WHERE archived_at IS NULL AND LOWER(email)=%s LIMIT 1",
				$email
			) );
		}
		if ( $student ) {
			return array( 'student_id' => (int) $student->id, 'teacher_id' => null, 'label' => (string) ( $student->display_name ?: trim( ( $student->first_name ?? '' ) . ' ' . ( $student->last_name ?? '' ) ) ) );
		}
		$teacher = null;
		if ( $phone !== '' ) {
			$teacher = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, display_name, english_name, persian_name FROM {$wpdb->prefix}dzn_teachers WHERE archived_at IS NULL AND {$phoneSql}=%s LIMIT 1",
				$phone
			) );
		}
		if ( ! $teacher && $email !== '' ) {
			$teacher = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, display_name, english_name, persian_name FROM {$wpdb->prefix}dzn_teachers WHERE archived_at IS NULL AND LOWER(email)=%s LIMIT 1",
				$email
			) );
		}
		if ( $teacher ) {
			return array( 'student_id' => null, 'teacher_id' => (int) $teacher->id, 'label' => (string) ( $teacher->display_name ?: $teacher->english_name ?: $teacher->persian_name ) );
		}
		return array( 'student_id' => null, 'teacher_id' => null, 'label' => '' );
	}

	private function alreadyImported( ?string $phone, ?string $email ): bool {
		global $wpdb;
		$phone = self::normalisePhone( (string) $phone );
		$email = self::normaliseEmail( (string) $email );
		if ( $phone === '' && $email === '' ) return true;
		$where = array();
		$args = array();
		if ( $phone !== '' ) {
			$where[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''),' ',''),'-',''),'+',''),'(',''),')','')=%s";
			$args[] = $phone;
		}
		if ( $email !== '' ) {
			$where[] = 'LOWER(email)=%s';
			$args[] = $email;
		}
		$sql = "SELECT id FROM {$wpdb->prefix}dzn_academy_directory_contacts WHERE (" . implode( ' OR ', $where ) . ') LIMIT 1';
		return (bool) $wpdb->get_var( $args ? $wpdb->prepare( $sql, ...$args ) : $sql );
	}

	private function guard(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) throw new \RuntimeException( 'Unauthorized' );
	}
}
