<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Read-only People directory projection.
 *
 * The directory unifies three non-overlapping populations into one administration
 * surface and, separately, exposes the reusable group filters the follow-up custom
 * notification PR will turn into a recipient selector:
 *
 *   - teachers   (active / former, derived from the Teacher records)
 *   - students   (active / former, derived from a current operational Enrolment,
 *                 with the enrolment/term count shown on every row)
 *   - leads      (submitted a website request, went through the intro, never enrolled
 *                 -- the `leads` group -- plus imported contacts that matched no
 *                 existing Student or Teacher)
 *
 * Every fact here is read from the table that owns it. This service is SELECT-only:
 * it never inserts, updates, deletes, migrates or alters storage, and it holds no
 * write capability of its own. The derivation rules are expressed as pure static
 * helpers so they can be unit-tested without a database.
 */
final class PeopleDirectoryReadService {
	/** Narrow, administrator-only capability that gates the whole screen. */
	public const CAPABILITY = 'dzn_view_people_directory';

	/** Notification-targeting groups. A follow-up PR only adds the selector. */
	public const GROUPS = array(
		'active_students',
		'former_students',
		'active_teachers',
		'former_teachers',
		'leads',
		'imported_contacts',
	);

	public const TYPES = array( 'student', 'teacher', 'lead' );

	/** The derived status vocabulary: `active` / `former` / `lead`. */
	public const STATUSES = array( 'active', 'former', 'lead' );

	/** Lead provenance: derived from the website flow, or an owner-imported contact. */
	public const LEAD_SOURCES = array( 'intro_without_enrolment', 'imported_contact' );

	/**
	 * A Student is active exactly when a current operational Enrolment exists.
	 * Everything else is former: the record went through the academy flow but has
	 * no open Enrolment right now.
	 */
	public static function studentStatus( bool $currentEnrolment ): string {
		return $currentEnrolment ? 'active' : 'former';
	}

	/**
	 * A Teacher is active only when onboarding is complete (state active, readiness
	 * ready) and a live principal link exists. Any other state is former.
	 */
	public static function teacherStatus( bool $activeReady ): string {
		return $activeReady ? 'active' : 'former';
	}

	/** A lead is a lead; there is no active/former split for a prospective person. */
	public static function leadStatus(): string {
		return 'lead';
	}

	/** Plain-English enrolment/term summary for a row ("enrolled 2 terms" / "0 enrolment"). */
	public static function termCountLabel( int $termCount ): string {
		if ( $termCount <= 0 ) return '0 enrolment';
		return $termCount === 1 ? 'enrolled 1 term' : 'enrolled ' . $termCount . ' terms';
	}

	/** Digits-only comparison key so imported and stored numbers match practically. */
	public static function normalisePhone( string $value ): string {
		return preg_replace( '/\D+/', '', $value ) ?: '';
	}

	/** Case/whitespace-insensitive email comparison key. */
	public static function normaliseEmail( string $value ): string {
		return strtolower( trim( (string) $value ) );
	}

	/**
	 * Whether an imported contact matched an existing person by normalised phone/email.
	 * Used by the importer and the read service to keep one human from appearing twice.
	 */
	public static function matched( ?int $studentId, ?int $teacherId ): bool {
		return ( $studentId !== null && $studentId > 0 ) || ( $teacherId !== null && $teacherId > 0 );
	}

	/**
	 * Searchable, filterable, paginated directory listing.
	 *
	 * When a `group` filter is supplied the result is that group's homogeneous
	 * projection. Without a group, the three populations (teachers, students,
	 * unmatched imported leads) are merged into one list and paged in PHP because
	 * their columns differ.
	 *
	 * @param array<string,mixed> $filters
	 * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
	 */
	public function directory( array $filters ): array {
		$group = isset( $filters['group'] ) && is_scalar( $filters['group'] ) ? sanitize_key( (string) $filters['group'] ) : '';
		if ( in_array( $group, self::GROUPS, true ) ) {
			return $this->groupPage( $group, $filters );
		}

		$query  = $this->queryTerm( $filters );
		$type   = $this->type( $filters );
		$status = $this->status( $filters );
		$perPage = $this->perPage( $filters );
		$page    = $this->page( $filters );

		$teachers = $this->teacherRows( $query, $type, $status );
		$students = $this->studentRows( $query, $type, $status );
		$leads    = $this->importedLeadRows( $query, $type, $status );

		$rows = array_merge( $teachers, $students, $leads );
		$rows = $this->applyTypeStatusFilter( $rows, $type, $status );

		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / $perPage ) );
		$page  = min( max( 1, $page ), $pages );
		$offset = ( $page - 1 ) * $perPage;
		$rows = array_slice( $rows, $offset, $perPage );

		return array( 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages );
	}

	/**
	 * Reusable recipient projection for one group. The follow-up notification PR
	 * calls this to turn a selected group into custom-notification recipients
	 * without repeating the derivation SQL.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recipientsForGroup( string $group, int $limit = 0 ): array {
		if ( ! in_array( $group, self::GROUPS, true ) ) return array();
		global $wpdb;
		$p = $wpdb->prefix . 'dzn_';
		$limitSql = $limit > 0 ? ' LIMIT ' . max( 1, min( 10000, $limit ) ) : '';

		if ( $group === 'active_students' || $group === 'former_students' ) {
			$active = $this->studentActiveSql( $p );
			$where = $group === 'active_students' ? "(s.archived_at IS NULL AND s.status<>'archived' AND ({$active}))" : "(s.archived_at IS NULL AND s.status<>'archived' AND NOT ({$active}))";
			return $this->mapRecipients( $wpdb->get_results(
				"SELECT s.id, 'student' type, COALESCE(NULLIF(s.display_name,''),NULLIF(TRIM(CONCAT_WS(' ',s.first_name,s.last_name)),''),'Student') name,
				        s.email, COALESCE(NULLIF(s.phone,''),NULLIF(s.whatsapp_phone,'')) phone,
				        {$this->studentTermCountSql($p)} term_count,
				        IF(({$active}),'active','former') status
				 FROM {$p}students s WHERE {$where} ORDER BY name ASC{$limitSql}"
			) ?: array() );
		}
		if ( $group === 'active_teachers' || $group === 'former_teachers' ) {
			$active = $this->teacherActiveSql( $p );
			$where = $group === 'active_teachers' ? "(t.archived_at IS NULL AND t.status<>'archived' AND ({$active}))" : "(t.archived_at IS NULL AND t.status<>'archived' AND NOT ({$active}))";
			return $this->mapRecipients( $wpdb->get_results(
				"SELECT t.id, 'teacher' type, COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) name,
				        t.email, COALESCE(NULLIF(t.phone,''),NULLIF(t.whatsapp_phone,'')) phone,
				        0 term_count, IF(({$active}),'active','former') status
				 FROM {$p}teachers t WHERE {$where} ORDER BY name ASC{$limitSql}"
			) ?: array() );
		}
		if ( $group === 'leads' ) {
			return $this->mapRecipients( $wpdb->get_results(
				"SELECT s.id, 'lead' type, COALESCE(NULLIF(s.display_name,''),NULLIF(TRIM(CONCAT_WS(' ',s.first_name,s.last_name)),''),'Lead') name,
				        s.email, COALESCE(NULLIF(s.phone,''),NULLIF(s.whatsapp_phone,'')) phone,
				        {$this->studentTermCountSql($p)} term_count, 'lead' status
				 FROM {$p}students s
				 WHERE s.archived_at IS NULL AND s.status<>'archived'
				   AND NOT ({$this->studentActiveSql($p)})
				   AND NOT EXISTS (SELECT 1 FROM {$p}enrolments e WHERE e.student_id=s.id AND e.archived_at IS NULL)
				   AND EXISTS (SELECT 1 FROM {$p}booking_requests br WHERE br.student_id=s.id AND br.privacy_erased_at IS NULL)
				   AND EXISTS (SELECT 1 FROM {$p}booking_request_intro_outcomes o INNER JOIN {$p}booking_requests br2 ON br2.id=o.booking_request_id WHERE br2.student_id=s.id AND o.outcome='delivered')
				 ORDER BY name ASC{$limitSql}"
			) ?: array() );
		}
		// imported_contacts: matched contacts resolve to the existing person's contact.
		$rows = $wpdb->get_results(
			"SELECT c.id, c.name, c.phone, c.email, c.city, c.country_code, c.instrument, c.teacher_name, c.source,
			        c.matched_student_id, c.matched_teacher_id, c.created_at,
			        COALESCE(NULLIF(s.display_name,''),NULLIF(TRIM(CONCAT_WS(' ',s.first_name,s.last_name)),''),NULL) student_name,
			        s.email student_email, COALESCE(NULLIF(s.phone,''),NULLIF(s.whatsapp_phone,'')) student_phone,
			        COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name_matched,
			        t.email teacher_email, COALESCE(NULLIF(t.phone,''),NULLIF(t.whatsapp_phone,'')) teacher_phone
			 FROM {$p}academy_directory_contacts c
			 LEFT JOIN {$p}students s ON s.id=c.matched_student_id
			 LEFT JOIN {$p}teachers t ON t.id=c.matched_teacher_id
			 ORDER BY c.id DESC{$limitSql}"
		) ?: array();
		$out = array();
		foreach ( $rows as $row ) {
			if ( (int) $row->matched_student_id > 0 ) {
				$out[] = array( 'id' => (int) $row->matched_student_id, 'type' => 'student', 'name' => (string) ( $row->student_name ?: $row->name ), 'email' => (string) ( $row->student_email ?: $row->email ), 'phone' => (string) ( $row->student_phone ?: $row->phone ), 'term_count' => 0, 'status' => 'lead', 'contact_id' => (int) $row->id, 'matched' => true );
			} elseif ( (int) $row->matched_teacher_id > 0 ) {
				$out[] = array( 'id' => (int) $row->matched_teacher_id, 'type' => 'teacher', 'name' => (string) ( $row->teacher_name_matched ?: $row->name ), 'email' => (string) ( $row->teacher_email ?: $row->email ), 'phone' => (string) ( $row->teacher_phone ?: $row->phone ), 'term_count' => 0, 'status' => 'lead', 'contact_id' => (int) $row->id, 'matched' => true );
			} else {
				$out[] = array( 'id' => (int) $row->id, 'type' => 'lead', 'name' => (string) $row->name, 'email' => (string) $row->email, 'phone' => (string) $row->phone, 'term_count' => 0, 'status' => 'lead', 'contact_id' => (int) $row->id, 'matched' => false );
			}
		}
		return $out;
	}

	/** @return array<string,int> */
	public function groupCounts(): array {
		$counts = array();
		foreach ( self::GROUPS as $group ) $counts[ $group ] = count( $this->recipientsForGroup( $group ) );
		return $counts;
	}

	/** @param array<string,mixed> $filters */
	private function groupPage( string $group, array $filters ): array {
		$perPage = $this->perPage( $filters );
		$page    = $this->page( $filters );
		$all = $this->recipientsForGroup( $group );
		$total = count( $all );
		$pages = max( 1, (int) ceil( $total / $perPage ) );
		$page  = min( max( 1, $page ), $pages );
		$rows  = array_slice( $all, ( $page - 1 ) * $perPage, $perPage );
		return array( 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages );
	}

	/** @return array<int,array<string,mixed>> */
	private function teacherRows( string $query, string $type, string $status ): array {
		if ( $type !== '' && $type !== 'teacher' ) return array();
		global $wpdb;
		$p = $wpdb->prefix . 'dzn_';
		$where = "t.archived_at IS NULL AND t.status<>'archived'";
		$args = array();
		if ( $query !== '' ) {
			$like = '%' . $wpdb->esc_like( $query ) . '%';
			$where .= ' AND (t.display_name LIKE %s OR t.persian_name LIKE %s OR t.english_name LIKE %s OR t.email LIKE %s OR t.phone LIKE %s)';
			array_push( $args, $like, $like, $like, $like, $like );
		}
		$active = $this->teacherActiveSql( $p );
		$statusClause = 'IF(( ' . $active . '),\'active\',\'former\')';
		if ( $status === 'active' ) $where .= ' AND ( ' . $active . ' )';
		elseif ( $status === 'former' ) $where .= ' AND NOT ( ' . $active . ' )';
		$sql = "SELECT t.id, 'teacher' type, COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) name,
		               t.email, COALESCE(NULLIF(t.phone,''),NULLIF(t.whatsapp_phone,'')) phone,
		               CONCAT_WS(', ', NULLIF(t.city,''), NULLIF(t.country_code,'')) location,
		               (SELECT GROUP_CONCAT(DISTINCT COALESCE(NULLIF(i.name_en,''),i.name_fa) ORDER BY COALESCE(NULLIF(i.name_en,''),i.name_fa) SEPARATOR ', ') FROM {$p}teacher_course_eligibilities e INNER JOIN {$p}courses c ON c.id=e.course_id INNER JOIN {$p}instruments i ON i.id=c.instrument_id WHERE e.teacher_id=t.id AND e.status='active') instrument,
		               {$statusClause} status, 0 term_count, NULL matched_id,
		               COALESCE((SELECT MAX(updated_at) FROM {$p}teacher_onboarding_states o WHERE o.teacher_id=t.id), t.created_at) last_activity
		        FROM {$p}teachers t WHERE {$where} ORDER BY name ASC";
		return $this->mapRows( $args ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) ) : $wpdb->get_results( $sql ) );
	}

	/** @return array<int,array<string,mixed>> */
	private function studentRows( string $query, string $type, string $status ): array {
		if ( $type !== '' && $type !== 'student' ) return array();
		global $wpdb;
		$p = $wpdb->prefix . 'dzn_';
		$where = "s.archived_at IS NULL AND s.status<>'archived'";
		$args = array();
		if ( $query !== '' ) {
			$like = '%' . $wpdb->esc_like( $query ) . '%';
			$where .= ' AND (s.display_name LIKE %s OR s.first_name LIKE %s OR s.last_name LIKE %s OR s.email LIKE %s OR s.phone LIKE %s OR s.whatsapp_phone LIKE %s)';
			array_push( $args, $like, $like, $like, $like, $like, $like );
		}
		$active = $this->studentActiveSql( $p );
		$statusClause = 'IF(( ' . $active . '),\'active\',\'former\')';
		if ( $status === 'active' ) $where .= ' AND ( ' . $active . ' )';
		elseif ( $status === 'former' ) $where .= ' AND NOT ( ' . $active . ' )';
		$sql = "SELECT s.id, 'student' type, COALESCE(NULLIF(s.display_name,''),NULLIF(TRIM(CONCAT_WS(' ',s.first_name,s.last_name)),''),'Student') name,
		               s.email, COALESCE(NULLIF(s.phone,''),NULLIF(s.whatsapp_phone,'')) phone,
		               CONCAT_WS(', ', NULLIF(s.city,''), NULLIF(s.country_code,'')) location,
		               (SELECT COALESCE(NULLIF(i.name_en,''),i.name_fa) FROM {$p}enrolments e INNER JOIN {$p}courses c ON c.id=e.course_id INNER JOIN {$p}instruments i ON i.id=c.instrument_id WHERE e.student_id=s.id AND e.archived_at IS NULL ORDER BY e.id DESC LIMIT 1) instrument,
		               {$statusClause} status, {$this->studentTermCountSql($p)} term_count, NULL matched_id,
		               GREATEST(COALESCE((SELECT MAX(updated_at) FROM {$p}enrolments e WHERE e.student_id=s.id),'1970-01-01 00:00:00'), COALESCE(s.created_at,'1970-01-01 00:00:00')) last_activity
		        FROM {$p}students s WHERE {$where} ORDER BY name ASC";
		return $this->mapRows( $args ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) ) : $wpdb->get_results( $sql ) );
	}

	/** @return array<int,array<string,mixed>> */
	private function importedLeadRows( string $query, string $type, string $status ): array {
		if ( $type !== '' && $type !== 'lead' ) return array();
		if ( $status !== '' && $status !== 'lead' ) return array();
		global $wpdb;
		$p = $wpdb->prefix . 'dzn_';
		$where = 'c.matched_student_id IS NULL AND c.matched_teacher_id IS NULL';
		$args = array();
		if ( $query !== '' ) {
			$like = '%' . $wpdb->esc_like( $query ) . '%';
			$where .= ' AND (c.name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s OR c.teacher_name LIKE %s OR c.instrument LIKE %s)';
			array_push( $args, $like, $like, $like, $like, $like );
		}
		$sql = "SELECT c.id, 'lead' type, c.name, c.email, c.phone,
		               CONCAT_WS(', ', NULLIF(c.city,''), NULLIF(c.country_code,'')) location,
		               c.instrument, 'lead' status, 0 term_count,
		               c.created_at last_activity, c.source
		        FROM {$p}academy_directory_contacts c WHERE {$where} ORDER BY c.id DESC";
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) ) : $wpdb->get_results( $sql );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id' => (int) $row->id, 'type' => 'lead', 'name' => (string) $row->name, 'email' => (string) $row->email,
				'phone' => (string) $row->phone, 'location' => (string) $row->location, 'instrument' => (string) $row->instrument,
				'status' => 'lead', 'term_count' => 0, 'matched_id' => null, 'last_activity' => (string) $row->last_activity,
				'source' => (string) ( $row->source ?: 'imported_contact' ),
			);
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	private function mapRows( ?array $rows ): array {
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id' => (int) $row->id, 'type' => (string) $row->type, 'name' => (string) $row->name,
				'email' => (string) $row->email, 'phone' => (string) $row->phone, 'location' => (string) $row->location,
				'instrument' => (string) $row->instrument, 'status' => (string) $row->status, 'term_count' => (int) $row->term_count,
				'matched_id' => $row->matched_id !== null ? (string) $row->matched_id : null,
				'last_activity' => (string) $row->last_activity,
			);
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	private function mapRecipients( ?array $rows ): array {
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id' => (int) $row->id, 'type' => (string) $row->type, 'name' => (string) $row->name,
				'email' => (string) $row->email, 'phone' => (string) $row->phone,
				'term_count' => (int) $row->term_count, 'status' => (string) $row->status,
			);
		}
		return $out;
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function applyTypeStatusFilter( array $rows, string $type, string $status ): array {
		return array_values( array_filter( $rows, static function ( array $row ) use ( $type, $status ): bool {
			if ( $type !== '' && $row['type'] !== $type ) return false;
			if ( $status !== '' && $row['status'] !== $status ) return false;
			return true;
		} ) );
	}

	/** Current operational Enrolment existence, inlined per-query for the Students projection. */
	private function studentActiveSql( string $p ): string {
		return "EXISTS (SELECT 1 FROM {$p}enrolments e WHERE e.student_id=s.id AND e.archived_at IS NULL AND COALESCE(e.lifecycle_state,e.status) IN ('authorised','current','paused','active'))";
	}

	/** Non-cancelled/non-archived Term count across a Student's Enrolments. */
	private function studentTermCountSql( string $p ): string {
		return "(SELECT COUNT(*) FROM {$p}enrolments e INNER JOIN {$p}terms t ON t.enrolment_id=e.id WHERE e.student_id=s.id AND e.archived_at IS NULL AND t.archived_at IS NULL AND COALESCE(t.lifecycle_state,t.status) NOT IN ('cancelled','archived'))";
	}

	/** Onboarded, ready and principal-linked Teacher existence. */
	private function teacherActiveSql( string $p ): string {
		return "EXISTS (SELECT 1 FROM {$p}teacher_onboarding_states ao INNER JOIN {$p}teacher_principal_links al ON al.teacher_id=ao.teacher_id AND al.status='active' AND al.revoked_at IS NULL WHERE ao.teacher_id=t.id AND ao.state='active' AND ao.readiness_state='ready')";
	}

	private function queryTerm( array $filters ): string {
		$raw = $filters['q'] ?? ( $filters['s'] ?? '' );
		$q = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( function_exists( 'sanitize_text_field' ) ) $q = sanitize_text_field( $q );
		return strlen( $q ) > 191 ? substr( $q, 0, 191 ) : $q;
	}

	private function type( array $filters ): string {
		$raw = $filters['type'] ?? '';
		$type = is_scalar( $raw ) ? strtolower( trim( (string) $raw ) ) : '';
		return in_array( $type, self::TYPES, true ) ? $type : '';
	}

	private function status( array $filters ): string {
		$raw = $filters['status'] ?? '';
		$status = is_scalar( $raw ) ? strtolower( trim( (string) $raw ) ) : '';
		return in_array( $status, self::STATUSES, true ) ? $status : '';
	}

	private function page( array $filters ): int {
		$raw = $filters['page'] ?? 1;
		$page = function_exists( 'absint' ) ? absint( $raw ) : max( 0, (int) $raw );
		return max( 1, $page );
	}

	private function perPage( array $filters ): int {
		$raw = $filters['per_page'] ?? 25;
		$per = function_exists( 'absint' ) ? absint( $raw ) : max( 0, (int) $raw );
		return max( 10, min( 100, $per ) );
	}
}
