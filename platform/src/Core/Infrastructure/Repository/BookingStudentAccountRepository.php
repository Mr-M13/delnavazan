<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Transactional persistence used only by account-aware introductory intake. */
final class BookingStudentAccountRepository {
    private string $prefix;

    public function __construct() { global $wpdb; $this->prefix = $wpdb->prefix . 'dzn_'; }
    public function begin(): void { global $wpdb; if ( $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' ) === false || $wpdb->query( 'START TRANSACTION' ) === false ) throw new \RuntimeException( 'Transaction start failed' ); }
    public function commit(): void { global $wpdb; if ( $wpdb->query( 'COMMIT' ) === false ) throw new \RuntimeException( 'Transaction commit failed' ); }
    public function rollback(): void { global $wpdb; $wpdb->query( 'ROLLBACK' ); }
    public function assertTransactionalStorage(): void {
        global $wpdb;
        $tables = array( $wpdb->users, $wpdb->usermeta, $this->prefix . 'students', $this->prefix . 'student_acceptance_capacity_classifications', $this->prefix . 'student_principal_links', $this->prefix . 'student_schedule_roots', $this->prefix . 'booking_requests', $this->prefix . 'booking_request_identity_resolution_events', $this->prefix . 'booking_request_submission_keys' );
        foreach ( $tables as $table ) {
            $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
            if ( ! is_string( $engine ) || strcasecmp( $engine, 'InnoDB' ) !== 0 ) throw new \RuntimeException( 'Transactional account storage unavailable' );
        }
    }

    /** The lock name contains only a keyed digest, never an email address. */
    public function acquireEmailLock(string $digest): void {
        global $wpdb;
        $name = 'dzn_booking_account_' . substr( $digest, 0, 40 );
        if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) ) !== 1 ) throw new \RuntimeException( 'Account operation unavailable' );
    }
    public function releaseEmailLock(string $digest): void {
        global $wpdb;
        $name = 'dzn_booking_account_' . substr( $digest, 0, 40 );
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
    }

    /** @return object[] */
    public function usersForEmail(string $email): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID,user_login,user_email,user_pass FROM {$wpdb->users} WHERE user_email=%s ORDER BY ID ASC", $email ) );
    }
    /** @return object[] */
    public function usersForEmailForUpdate(string $email): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID,user_login,user_email,user_pass FROM {$wpdb->users} WHERE user_email=%s ORDER BY ID ASC FOR UPDATE", $email ) );
    }
    public function userForUpdate(int $userId): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT ID,user_login,user_email,user_pass FROM {$wpdb->users} WHERE ID=%d FOR UPDATE", $userId ) );
    }
    public function activePrincipalForUserForUpdate(int $userId): ?object {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->prefix}student_principal_links WHERE wordpress_user_id=%d AND status='active' AND active_slot=1 ORDER BY id ASC FOR UPDATE", $userId ) );
        if ( count( $rows ) > 1 ) throw new \RuntimeException( 'Account authority is ambiguous' );
        return $rows[0] ?? null;
    }
    public function activePrincipalForUser(int $userId): ?object {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->prefix}student_principal_links WHERE wordpress_user_id=%d AND status='active' AND active_slot=1 ORDER BY id ASC", $userId ) );
        if ( count( $rows ) > 1 ) throw new \RuntimeException( 'Account authority is ambiguous' );
        return $rows[0] ?? null;
    }
    public function anyPrincipalHistoryForUserForUpdate(int $userId): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->prefix}student_principal_links WHERE wordpress_user_id=%d ORDER BY id ASC LIMIT 1 FOR UPDATE", $userId ) );
    }
    public function activeGuardianForUserForUpdate(int $userId, string $now): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->prefix}student_acceptance_authority_grants WHERE acting_wordpress_user_id=%d AND state='active' AND active_slot=1 AND effective_from<=%s AND (effective_until IS NULL OR effective_until>%s) ORDER BY id ASC LIMIT 1 FOR UPDATE", $userId, $now, $now ) );
    }
    /** A current teacher principal must never be silently made a student principal too. */
    public function activeTeacherForUserForUpdate(int $userId): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->prefix}teacher_principal_links WHERE wordpress_user_id=%d AND status='active' AND revoked_at IS NULL ORDER BY id ASC LIMIT 1 FOR UPDATE", $userId ) );
    }
    public function studentForUpdate(int $studentId): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}students WHERE id=%d FOR UPDATE", $studentId ) );
    }
    /**
     * Existing Student records for one exact email that no portal account owns yet.
     *
     * A booking may adopt one of these instead of creating a second identity for the same person. The
     * caller must still prove contact compatibility and must never adopt across an active principal.
     *
     * @return object[]
     */
    public function adoptableStudentsForEmailForUpdate( string $email ): array {
        global $wpdb;
        $email = trim( $email );
        if ( $email === '' ) return array();
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->prefix}students s
             WHERE s.status='active' AND s.archived_at IS NULL AND s.email<>'' AND LOWER(s.email)=LOWER(%s)
               AND NOT EXISTS (SELECT 1 FROM {$this->prefix}student_principal_links l
                               WHERE l.student_id=s.id AND l.status='active' AND l.active_slot=1 AND l.revoked_at IS NULL)
             ORDER BY s.id ASC LIMIT 3 FOR UPDATE",
            $email
        ) );
    }
    /** A matching Student that is not active cannot be adopted or duplicated silently. */
    public function inactiveStudentExistsForEmailForUpdate( string $email ): bool {
        global $wpdb;
        $email = trim( $email );
        if ( $email === '' ) return false;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->prefix}students WHERE email<>'' AND LOWER(email)=LOWER(%s) AND archived_at IS NULL LIMIT 1 FOR UPDATE",
            $email
        ) );
    }
    /** Any Student identity already bound to an active portal principal for this email. */
    public function linkedStudentExistsForEmailForUpdate( string $email ): bool {
        global $wpdb;
        $email = trim( $email );
        if ( $email === '' ) return false;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT l.id FROM {$this->prefix}student_principal_links l
             INNER JOIN {$this->prefix}students s ON s.id=l.student_id
             WHERE s.email<>'' AND LOWER(s.email)=LOWER(%s) AND s.archived_at IS NULL
               AND l.status='active' AND l.active_slot=1 AND l.revoked_at IS NULL
             LIMIT 1 FOR UPDATE",
            $email
        ) );
    }
    /** Fill only the contact facts an adopted Student was missing; never overwrite academy-reviewed values. */
    public function fillMissingContact( int $studentId, array $contact, string $now, int $actor ): void {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT phone,whatsapp_phone,country_code,city,timezone FROM {$this->prefix}students WHERE id=%d FOR UPDATE", $studentId ) );
        if ( ! $row ) throw new \RuntimeException( 'Adopted Student is unavailable' );
        $data = array();
        foreach ( array( 'phone' => 'mobile', 'whatsapp_phone' => 'whatsapp_number', 'country_code' => 'country', 'city' => 'city', 'timezone' => 'timezone' ) as $column => $key ) {
            $current = trim( (string) ( $row->{$column} ?? '' ) );
            $incoming = trim( (string) ( $contact[ $key ] ?? '' ) );
            if ( $current === '' && $incoming !== '' ) $data[ $column ] = $incoming;
        }
        if ( ! $data ) return;
        $data['updated_at'] = $now;
        $data['updated_by'] = $actor;
        if ( $wpdb->update( $this->prefix . 'students', $data, array( 'id' => $studentId ) ) === false ) throw new \RuntimeException( 'Adopted Student contact fill failed' );
    }
    /** Keep the legacy profile column pointing at the account that now owns the adopted identity. */
    public function attachWordpressUserIfEmpty( int $studentId, int $userId, string $now ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->prefix}students SET wordpress_user_id=%d,updated_at=%s,updated_by=%d
             WHERE id=%d AND (wordpress_user_id IS NULL OR wordpress_user_id=0)",
            $userId, $now, $userId, $studentId
        ) );
    }
    public function currentCapacityIsMissing( int $studentId ): bool {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( "SELECT current_acceptance_capacity_classification_id FROM {$this->prefix}students WHERE id=%d", $studentId ) ) === null;
    }
    public function createStudent(array $data): int {
        global $wpdb;
        if ( $wpdb->insert( $this->prefix . 'students', $data ) === false ) throw new \RuntimeException( 'Student creation failed' );
        return (int) $wpdb->insert_id;
    }
    /** Capture an explicit adult self-attestation within the account/booking transaction. */
    public function recordAdultSelfAttestation(int $studentId, int $userId, string $now, string $origin = 'booking'): void {
        if ( ! in_array( $origin, array( 'booking', 'account_recovery' ), true ) ) throw new \InvalidArgumentException( 'Invalid attestation origin' );
        global $wpdb;
        $id = $wpdb->insert( $this->prefix . 'student_acceptance_capacity_classifications', array(
            'uid' => \Delnavazan\Platform\Core\Support\Identifier::uid(),
            'student_id' => $studentId,
            'classification' => 'adult',
            'verification_basis' => 'self_attestation',
            'evidence_channel' => 'authenticated_platform',
            'evidence_reference' => 'adult-attestation-' . $origin . '-' . \Delnavazan\Platform\Core\Support\Identifier::uid(),
            'evidence_at' => $now,
            'classified_at' => $now,
            'classified_by' => $userId,
            'created_at' => $now,
            'created_by' => $userId,
        ) );
        if ( $id !== 1 || (int) $wpdb->insert_id < 1 ) throw new \RuntimeException( 'Student adult attestation persistence failed' );
        $capacityId = (int) $wpdb->insert_id;
        if ( $wpdb->update( $this->prefix . 'students', array( 'current_acceptance_capacity_classification_id' => $capacityId ), array( 'id' => $studentId, 'current_acceptance_capacity_classification_id' => null ) ) !== 1 ) {
            throw new \RuntimeException( 'Student adult attestation projection failed' );
        }
    }

    public function assignStudentReference(int $studentId, string $reference): void {
        global $wpdb;
        if ( $wpdb->update( $this->prefix . 'students', array( 'reference_code' => $reference ), array( 'id' => $studentId, 'reference_code' => null ) ) !== 1 ) throw new \RuntimeException( 'Student reference assignment failed' );
    }
    public function nextPrincipalSequence(int $studentId): int {
        global $wpdb;
        return 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(link_sequence),0) FROM {$this->prefix}student_principal_links WHERE student_id=%d", $studentId ) );
    }
    public function createPrincipal(array $data): int {
        global $wpdb;
        if ( $wpdb->insert( $this->prefix . 'student_principal_links', $data ) === false ) throw new \RuntimeException( 'Student principal persistence failed' );
        return (int) $wpdb->insert_id;
    }
    public function bookingForUpdate(int $requestId): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}booking_requests WHERE id=%d FOR UPDATE", $requestId ) );
    }
    public function nextResolutionSequence(int $requestId): int {
        global $wpdb;
        return 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(resolution_sequence),0) FROM {$this->prefix}booking_request_identity_resolution_events WHERE booking_request_id=%d", $requestId ) );
    }
    public function createResolution(array $data): int {
        global $wpdb;
        if ( $wpdb->insert( $this->prefix . 'booking_request_identity_resolution_events', $data ) === false ) throw new \RuntimeException( 'Identity resolution persistence failed' );
        return (int) $wpdb->insert_id;
    }
    public function resolutionForUpdate(int $eventId, int $requestId): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}booking_request_identity_resolution_events WHERE id=%d AND booking_request_id=%d FOR UPDATE", $eventId, $requestId ) );
    }
    public function linkBooking(int $requestId, int $studentId, int $eventId, int $actor, string $now): void {
        global $wpdb;
        $changed = $wpdb->query( $wpdb->prepare( "UPDATE {$this->prefix}booking_requests SET student_id=%d,current_identity_resolution_id=%d,updated_at=%s,updated_by=%d WHERE id=%d AND student_id IS NULL AND current_identity_resolution_id IS NULL AND lifecycle_status='submitted' AND resolution_state='unresolved' AND privacy_erased_at IS NULL", $studentId, $eventId, $now, $actor, $requestId ) );
        if ( $changed !== 1 ) throw new \RuntimeException( 'Booking principal linkage failed' );
    }
}
