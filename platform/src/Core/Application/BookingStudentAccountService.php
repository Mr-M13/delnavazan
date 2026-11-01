<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingStudentAccountRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/** Creates or proves a WordPress account and binds it to the same canonical booking principal. */
final class BookingStudentAccountService {
    public function __construct(
        private ?BookingStudentAccountRepository $accounts = null,
        private ?BookingRequestSubmissionService $submissions = null,
        private ?BookingRequestValidationService $validator = null
    ) {
        $this->accounts ??= new BookingStudentAccountRepository();
        $this->submissions ??= new BookingRequestSubmissionService();
        $this->validator ??= new BookingRequestValidationService();
    }

    /** @return array{success:bool,request_reference:string,user_id:int,replayed:bool} */
    public function submit(array $input, string $idempotencyKey, string $password, string $confirmation, int $sessionUserId = 0, ?callable $newSubmissionGate = null): array {
        $normalized = $this->validator->normalizePublic( $input );
        $email = (string) $normalized['contact']['email'];
        $this->assertPasswordShape( $password, $confirmation, $sessionUserId );
        $emailDigest = BookingRequestIdempotency::privateDigest( $email );
        if ( ! is_string( $emailDigest ) ) throw new BookingAccountAuthenticationException( 'Account authentication failed' );
        $createdUserId = 0;
        $this->accounts->acquireEmailLock( $emailDigest );
        try {
            $this->accounts->assertTransactionalStorage();
            $this->accounts->begin();
            try {
                $user = $this->resolveAccount( $email, $password, $normalized['contact']['full_name'], $sessionUserId, $createdUserId );
                $userId = (int) $user->ID;
                $studentId = $this->resolveStudent( $userId, $normalized['contact'] );
                $result = $this->submissions->submitWithinTransaction( $input, $idempotencyKey, $newSubmissionGate );
                $this->bindBooking( (int) $result['request_id'], $studentId, $userId );
                $this->accounts->commit();
                return array( 'success' => true, 'request_reference' => (string) $result['request_reference'], 'user_id' => $userId, 'replayed' => ! empty( $result['replayed'] ) );
            } catch ( \Throwable $e ) {
                $this->accounts->rollback();
                if ( $createdUserId > 0 ) clean_user_cache( $createdUserId );
                throw $e;
            }
        } finally {
            $this->accounts->releaseEmailLock( $emailDigest );
            unset( $password, $confirmation );
        }
    }

    private function assertPasswordShape(string $password, string $confirmation, int $sessionUserId): void {
        if ( $sessionUserId > 0 && $password === '' && $confirmation === '' ) return;
        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $password ) : strlen( $password );
        if ( $length < 12 || $length > 4096 || ! hash_equals( $password, $confirmation ) ) throw new BookingAccountAuthenticationException( 'Account authentication failed' );
    }

    private function resolveAccount(string $email, string $password, string $displayName, int $sessionUserId, int &$createdUserId): object {
        $discovered = $this->accounts->usersForEmail( $email );
        if ( count( $discovered ) > 1 ) throw new BookingAccountAuthenticationException( 'Account authentication failed' );
        if ( $discovered ) {
            $discoveredUser = $discovered[0];
            $discoveredPrincipal = $this->accounts->activePrincipalForUser( (int) $discoveredUser->ID );
            if ( $discoveredPrincipal ) {
                $student = $this->accounts->studentForUpdate( (int) $discoveredPrincipal->student_id );
                if ( ! $student || $student->status !== 'active' || $student->archived_at !== null ) throw new BookingAccountAuthenticationException( 'Account authentication failed' );
            }
            $users = $this->accounts->usersForEmailForUpdate( $email );
            if ( count( $users ) !== 1 || (int) $users[0]->ID !== (int) $discoveredUser->ID ) throw new BookingAccountAuthenticationException( 'Account authentication failed' );
            $user = $users[0];
            if ( $discoveredPrincipal ) {
                $lockedPrincipal = $this->accounts->activePrincipalForUserForUpdate( (int) $user->ID );
                if ( ! $lockedPrincipal || (int) $lockedPrincipal->id !== (int) $discoveredPrincipal->id || (int) $lockedPrincipal->student_id !== (int) $discoveredPrincipal->student_id ) throw new BookingAccountAuthenticationException( 'Account authentication failed' );
            }
            if ( $sessionUserId > 0 ) {
                if ( (int) $user->ID !== $sessionUserId ) throw new BookingAccountAuthenticationException( 'Account authentication failed' );
                return $user;
            }
            $authenticated = wp_authenticate( $email, $password );
            if ( is_wp_error( $authenticated ) || (int) $authenticated->ID !== (int) $user->ID ) throw new BookingAccountAuthenticationException( 'Account authentication failed' );
            return $user;
        }
        $users = $this->accounts->usersForEmailForUpdate( $email );
        if ( $users ) throw new BookingAccountAuthenticationException( 'Account authentication failed' );
        if ( $sessionUserId > 0 ) throw new BookingAccountAuthenticationException( 'Account authentication failed' );
        if ( ! function_exists( 'wp_insert_user' ) ) require_once ABSPATH . 'wp-admin/includes/user.php';
        $login = $this->availableLogin( $email );
        $created = wp_insert_user( array( 'user_login' => $login, 'user_pass' => $password, 'user_email' => $email, 'display_name' => $displayName, 'role' => 'subscriber' ) );
        if ( is_wp_error( $created ) ) {
            // Record only a bounded WordPress error code; never log credentials or email.
            $codes = $created->get_error_codes();
            $code = isset( $codes[0] ) && is_string( $codes[0] ) && preg_match( '/\\A[a-zA-Z0-9_\\-]{1,80}\\z/', $codes[0] ) ? $codes[0] : 'unknown';
            error_log( '[delnavazan booking] new_account_insert_rejected code=' . $code );
            throw new BookingAccountAuthenticationException( 'Account authentication failed' );
        }
        $createdUserId = (int) $created;
        $user = $this->accounts->userForUpdate( $createdUserId );
        if ( ! $user || strcasecmp( (string) $user->user_email, $email ) !== 0 ) throw new \RuntimeException( 'Created account verification failed' );
        return $user;
    }

    private function availableLogin(string $email): string {
        if ( strlen( $email ) <= 60 && ! username_exists( $email ) ) return $email;
        $base = 'dzn-' . substr( hash_hmac( 'sha256', $email, wp_salt( 'auth' ) ), 0, 24 );
        if ( ! username_exists( $base ) ) return $base;
        for ( $attempt = 0; $attempt < 5; $attempt++ ) {
            $candidate = $base . '-' . wp_rand( 1000, 999999 );
            if ( ! username_exists( $candidate ) ) return $candidate;
        }
        throw new BookingAccountAuthenticationException( 'Account authentication failed' );
    }

    private function resolveStudent(int $userId, array $contact): int {
        $now = current_time( 'mysql', true );
        $principal = $this->accounts->activePrincipalForUserForUpdate( $userId );
        if ( $principal ) {
            $student = $this->accounts->studentForUpdate( (int) $principal->student_id );
            if ( ! $student || $student->status !== 'active' || $student->archived_at !== null || ! is_email( (string) $student->email ) || strcasecmp( (string) $student->email, (string) $contact['email'] ) !== 0 ) throw new BookingAccountAuthenticationException( 'Account authentication failed' );
            return (int) $student->id;
        }
        if ( $this->accounts->anyPrincipalHistoryForUserForUpdate( $userId ) || $this->accounts->activeGuardianForUserForUpdate( $userId, $now ) || $this->accounts->activeTeacherForUserForUpdate( $userId ) ) throw new BookingAccountAuthenticationException( 'Account authentication failed' );
        $studentId = $this->accounts->createStudent( array(
            'uid' => Identifier::uid(), 'reference_code' => null, 'status' => 'active', 'display_name' => $contact['full_name'], 'email' => $contact['email'], 'phone' => $contact['mobile'], 'whatsapp_phone' => $contact['whatsapp_number'], 'country_code' => $contact['country'], 'city' => $contact['city'], 'timezone' => $contact['timezone'], 'timezone_source' => 'booking_account', 'locale' => $contact['communication_language'], 'calendar_preference' => 'auto', 'wordpress_user_id' => $userId, 'created_at' => $now, 'updated_at' => $now, 'created_by' => $userId, 'updated_by' => $userId,
        ) );
        $this->accounts->assignStudentReference( $studentId, Identifier::reference( 'DZN-STU-', $studentId ) );
        $this->accounts->createPrincipal( array(
            'student_id' => $studentId, 'wordpress_user_id' => $userId, 'status' => 'active', 'link_sequence' => $this->accounts->nextPrincipalSequence( $studentId ), 'active_slot' => 1, 'version' => 1, 'verification_basis' => 'verified_internal_record', 'evidence_channel' => 'verified_internal_record', 'evidence_reference' => 'booking-account-' . Identifier::uid(), 'evidence_at' => $now, 'linked_at' => $now, 'linked_by' => $userId, 'updated_at' => $now, 'updated_by' => $userId,
        ) );
        return $studentId;
    }

    private function bindBooking(int $requestId, int $studentId, int $userId): void {
        $request = $this->accounts->bookingForUpdate( $requestId );
        if ( ! $request ) throw new \RuntimeException( 'Booking request unavailable' );
        if ( $request->student_id !== null || $request->current_identity_resolution_id !== null ) {
            if ( (int) $request->student_id !== $studentId || $request->current_identity_resolution_id === null ) throw new IdempotencyConflictException( 'Booking principal conflict' );
            $event = $this->accounts->resolutionForUpdate( (int) $request->current_identity_resolution_id, $requestId );
            if ( ! $event || $event->outcome !== 'resolved' || (int) $event->student_id !== $studentId ) throw new IdempotencyConflictException( 'Booking principal conflict' );
            return;
        }
        $now = current_time( 'mysql', true );
        $eventId = $this->accounts->createResolution( array(
            'uid' => Identifier::uid(), 'reference_code' => null, 'booking_request_id' => $requestId, 'prospective_subject_ref' => 'booking_request:' . $requestId, 'resolution_sequence' => $this->accounts->nextResolutionSequence( $requestId ), 'outcome' => 'resolved', 'student_id' => $studentId, 'verification_basis' => 'verified_internal_record', 'evidence_channel' => 'verified_internal_record', 'evidence_reference' => 'booking-account-resolution-' . Identifier::uid(), 'evidence_at' => $now, 'verified_at' => $now, 'verified_by' => $userId, 'created_at' => $now, 'created_by' => $userId,
        ) );
        $this->accounts->linkBooking( $requestId, $studentId, $eventId, $userId, $now );
    }
}
