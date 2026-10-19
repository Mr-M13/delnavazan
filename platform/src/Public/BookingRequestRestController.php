<?php
namespace Delnavazan\Platform\Public;

use Delnavazan\Platform\Core\Application\BookingStudentAccountService;
use Delnavazan\Platform\Core\Application\BookingAccountAuthenticationException;
use Delnavazan\Platform\Core\Application\BookingRequestValidationService;
use Delnavazan\Platform\Core\Application\IdempotencyConflictException;
use Delnavazan\Platform\Core\Application\BookingRequestRateLimitException;

final class BookingRequestRestController {
    private const CSRF_COOKIE = 'dzn_booking_csrf';

    public static function register(): void {
        register_rest_route( 'delnavazan-platform/v1', '/booking-requests', array(
            'methods' => 'POST',
            'permission_callback' => array( __CLASS__, 'permission' ),
            'callback' => array( __CLASS__, 'submit' ),
        ) );
    }

    /** A signed value is also used as the double-submit cookie value. */
    public static function issueCsrfToken(): string {
        try {
            $token = bin2hex( random_bytes( 32 ) );
        } catch ( \Throwable ) {
            $token = hash_hmac( 'sha256', wp_generate_password( 64, true, true ), wp_salt( 'dzn_booking_csrf_entropy' ) );
        }
        return $token . '.' . hash_hmac( 'sha256', $token, wp_salt( 'dzn_booking_csrf' ) );
    }

    public static function permission( \WP_REST_Request $request ): bool|\WP_Error {
        try {
            self::assertCsrf( $request );
            return true;
        } catch ( \InvalidArgumentException ) {
            return new \WP_Error( 'dzn_booking_csrf_invalid', 'Booking request rejected.', array( 'status' => 403 ) );
        }
    }

    public static function submit( \WP_REST_Request $request ): \WP_REST_Response {
        $password = '';
        $confirmation = '';
        try {
            // Keep this assertion here too: direct callers used by isolated tests
            // must not accidentally bypass the route permission callback.
            self::assertCsrf( $request );
            $key = $request->get_header( 'idempotency-key' );
            if ( ! is_string( $key ) || $key === '' ) throw new \InvalidArgumentException( 'Idempotency key required' );
            $payload = (array) $request->get_json_params();
            $password = isset( $payload['account_password'] ) && is_string( $payload['account_password'] ) ? $payload['account_password'] : '';
            $confirmation = isset( $payload['account_password_confirmation'] ) && is_string( $payload['account_password_confirmation'] ) ? $payload['account_password_confirmation'] : '';
            unset( $payload['account_password'], $payload['account_password_confirmation'] );
            $limiter = new BookingRequestRateLimiter();
            $result = ( new BookingStudentAccountService() )->submit( $payload, $key, $password, $confirmation, get_current_user_id(), static fn(): bool => $limiter->allow() );
            $user = get_user_by( 'id', (int) $result['user_id'] );
            if ( ! $user ) throw new \RuntimeException( 'Committed account unavailable' );
            wp_set_current_user( (int) $user->ID );
            wp_set_auth_cookie( (int) $user->ID, false, is_ssl() );
            do_action( 'wp_login', (string) $user->user_login, $user );
            return new \WP_REST_Response( array( 'success' => true, 'request_reference' => $result['request_reference'], 'dashboard_url' => home_url( '/student-portal/' ) ), 201 );
        } catch ( BookingAccountAuthenticationException ) {
            return new \WP_REST_Response( array( 'success' => false, 'code' => 'account_authentication_failed', 'login_url' => wp_login_url( home_url( '/enrol/' ) ) ), 401 );
        } catch ( \InvalidArgumentException $e ) {
            if ( $e->getMessage() === 'csrf_invalid' ) return new \WP_REST_Response( array( 'success' => false, 'code' => 'csrf_invalid' ), 403 );
            $code = $e->getMessage() === BookingRequestValidationService::BLOCKED_TIME_MESSAGE ? 'blocked_time' : 'invalid_request';
            return new \WP_REST_Response( array( 'success' => false, 'code' => $code ), 400 );
        } catch ( IdempotencyConflictException ) {
            return new \WP_REST_Response( array( 'success' => false, 'code' => 'idempotency_conflict' ), 409 );
        } catch ( BookingRequestRateLimitException ) {
            return new \WP_REST_Response( array( 'success' => false, 'code' => 'rate_limited' ), 429 );
        } catch ( \Throwable ) {
            return new \WP_REST_Response( array( 'success' => false, 'code' => 'submission_unavailable' ), 500 );
        } finally {
            unset( $password, $confirmation );
        }
    }

    private static function assertCsrf( \WP_REST_Request $request ): void {
        $header = $request->get_header( 'x-dzn-booking-csrf' );
        $cookie = isset( $_COOKIE[ self::CSRF_COOKIE ] ) && is_string( $_COOKIE[ self::CSRF_COOKIE ] ) ? $_COOKIE[ self::CSRF_COOKIE ] : '';
        if ( ! is_string( $header ) || $header === '' || $cookie === '' || ! hash_equals( $cookie, $header ) ) throw new \InvalidArgumentException( 'csrf_invalid' );
        $parts = explode( '.', $header );
        if ( count( $parts ) !== 2 || ! preg_match( '/\\A[a-f0-9]{64}\\z/', $parts[0] ) || ! preg_match( '/\\A[a-f0-9]{64}\\z/', $parts[1] ) ) throw new \InvalidArgumentException( 'csrf_invalid' );
        $expected = hash_hmac( 'sha256', $parts[0], wp_salt( 'dzn_booking_csrf' ) );
        if ( ! hash_equals( $expected, $parts[1] ) ) throw new \InvalidArgumentException( 'csrf_invalid' );
        if ( get_current_user_id() > 0 && ! wp_verify_nonce( (string) $request->get_header( 'x-wp-nonce' ), 'wp_rest' ) ) throw new \InvalidArgumentException( 'csrf_invalid' );
    }
}
