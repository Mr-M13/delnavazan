<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Transactional persistence used only by account-aware introductory intake. */
final class BookingStudentAccountRepository {
    private string $prefix;

    public function __construct() { global $wpdb; $this->prefix = $wpdb->prefix . 'dzn_'; }
    public function begin(): void { global $wpdb; if ( $wpdb->query( 'START TRANSACTION' ) === false ) throw new \RuntimeException( 'Transaction start failed' ); }
    public function commit(): void { global $wpdb; if ( $wpdb->query( 'COMMIT' ) === false ) throw new \RuntimeException( 'Transaction commit failed' ); }
    public function rollback(): void { global $wpdb; $wpdb->query( 'ROLLBACK' ); }
    public function assertTransactionalStorage(): void {
        global $wpdb;
        $tables = array( $wpdb->users, $wpdb->usermeta, $this->prefix . 'students', $this->prefix . 'student_principal_links', $this->prefix . 'booking_requests', $this->prefix . 'booking_request_identity_resolution_events', $this->prefix . 'booking_request_submission_keys' );
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
    public function createStudent(array $data): int {
        global $wpdb;
        if ( $wpdb->insert( $this->prefix . 'students', $data ) === false ) throw new \RuntimeException( 'Student creation failed' );
        return (int) $wpdb->insert_id;
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
