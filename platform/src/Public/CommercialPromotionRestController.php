<?php
namespace Delnavazan\Platform\Public;

use Delnavazan\Platform\Core\Application\CommercialOfferService;
use Delnavazan\Platform\Core\Infrastructure\Repository\CommercialAuthorityRepository;
use Delnavazan\Platform\Portals\PortalPrincipalResolver;
use Delnavazan\Platform\Portals\PortalRateLimiter;

/**
 * Owner-bound Student promotion surface.
 *
 * `validate` is a non-mutating preview of the server-authoritative discount. `apply` replaces the
 * Student's own unpaid issued offer with a new immutable discounted revision through the canonical
 * commercial offer authority. Neither route accepts an amount, currency, product, offer id or
 * discount from the browser: the obligation UID is the only commercial identifier and every fact is
 * re-read from the canonical offer. Raw promotion codes are never logged or persisted.
 */
final class CommercialPromotionRestController {
    private const OBLIGATION_UID_PATTERN = '/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D';
    private const MAX_CODE_LENGTH = 64;

    public static function register(): void {
        register_rest_route( 'delnavazan-platform/v1', '/commercial/promotion/validate', array(
            'methods'             => 'POST',
            'permission_callback' => array( __CLASS__, 'permitted' ),
            'callback'            => array( __CLASS__, 'validate' ),
        ) );
        register_rest_route( 'delnavazan-platform/v1', '/commercial/promotion/apply', array(
            'methods'             => 'POST',
            'permission_callback' => array( __CLASS__, 'permitted' ),
            'callback'            => array( __CLASS__, 'apply' ),
        ) );
    }

    public static function permitted( \WP_REST_Request $request ): bool|\WP_Error {
        if ( ! is_user_logged_in() ) {
            return new \WP_Error( 'dzn_promotion_not_authenticated', 'Authentication required.', array( 'status' => 401 ) );
        }
        $nonce = (string) $request->get_header( 'x_wp_nonce' );
        if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new \WP_Error( 'dzn_promotion_nonce_invalid', 'Invalid request nonce.', array( 'status' => 403 ) );
        }
        return true;
    }

    public static function validate( \WP_REST_Request $request ): \WP_REST_Response {
        return self::handle( $request, 'validate' );
    }

    public static function apply( \WP_REST_Request $request ): \WP_REST_Response {
        return self::handle( $request, 'apply' );
    }

    private static function handle( \WP_REST_Request $request, string $operation ): \WP_REST_Response {
        $apply = $operation === 'apply';
        $studentId = self::studentId();
        if ( $studentId < 1 ) {
            return self::error( 'promotion_student_required', 403, $apply );
        }
        if ( ! ( new PortalRateLimiter() )->allow( 'commercial_promotion_' . $operation, (string) get_current_user_id() ) ) {
            return self::error( 'promotion_rate_limited', 429, $apply );
        }

        try {
            $payload = self::payload( $request );
            $authority = new CommercialAuthorityRepository();
            $obligation = $authority->obligationByUid( (string) $payload['obligation_uid'] );
            if ( $obligation === null ) {
                return self::error( 'promotion_obligation_unavailable', 404, $apply );
            }
            $offer = $authority->offer( (int) $obligation->offer_id );
            // Object-level ownership is proved before the service sees the obligation. The service
            // repeats the check inside the lock so a changed relationship cannot slip through.
            if ( $offer === null
                || (int) $offer->beneficiary_student_id !== $studentId
                || (int) $offer->student_id !== $studentId ) {
                return self::error( 'promotion_obligation_unavailable', 404, $apply );
            }

            $service = new CommercialOfferService();
            if ( ! $apply ) {
                return new \WP_REST_Response( $service->previewPromotion( (int) $obligation->id, (string) $payload['promotion_code'], $studentId ), 200 );
            }
            return new \WP_REST_Response( $service->applyPromotion( (int) $obligation->id, (string) $payload['promotion_code'], $studentId ), 200 );
        } catch ( \InvalidArgumentException $error ) {
            $reason = self::safeReason( $error->getMessage() );
            $extra = array( 'refresh_required' => $reason === 'promotion_offer_superseded' );
            return self::error( $reason, self::status( $reason ), $apply, $extra );
        } catch ( \Throwable ) {
            // Never expose a database, integrity or provider implementation detail to the browser.
            return self::error( 'promotion_unavailable', 503, $apply );
        }
    }

    /** @return array{obligation_uid:string,promotion_code:string} */
    private static function payload( \WP_REST_Request $request ): array {
        if ( $request->get_file_params() !== array()
            || $request->get_query_params() !== array()
            || $request->get_url_params() !== array() ) {
            throw new \InvalidArgumentException( 'promotion_request_invalid' );
        }
        $payload = $request->get_json_params();
        if ( ! is_array( $payload ) || array_diff( array_keys( $payload ), array( 'promotion_code', 'obligation_uid' ) ) !== array() ) {
            throw new \InvalidArgumentException( 'promotion_request_invalid' );
        }
        $obligationUid = isset( $payload['obligation_uid'] ) && is_string( $payload['obligation_uid'] ) ? trim( $payload['obligation_uid'] ) : '';
        if ( preg_match( self::OBLIGATION_UID_PATTERN, $obligationUid ) !== 1 ) {
            throw new \InvalidArgumentException( 'promotion_obligation_unavailable' );
        }
        $code = isset( $payload['promotion_code'] ) && is_string( $payload['promotion_code'] ) ? trim( $payload['promotion_code'] ) : '';
        if ( $code === '' ) {
            throw new \InvalidArgumentException( 'promotion_code_required' );
        }
        if ( strlen( $code ) > self::MAX_CODE_LENGTH || preg_match( '/[\x00-\x1F\x7F]/D', $code ) === 1 ) {
            throw new \InvalidArgumentException( 'promotion_code_invalid' );
        }
        return array( 'obligation_uid' => $obligationUid, 'promotion_code' => $code );
    }

    private static function studentId(): int {
        try {
            $principal = ( new PortalPrincipalResolver() )->resolve( 'student' );
            return (int) ( $principal['id'] ?? 0 );
        } catch ( \Throwable ) {
            return 0;
        }
    }

    /** Reduce an internal refusal to a controlled, non-sensitive public reason code. */
    private static function safeReason( string $message ): string {
        if ( str_starts_with( $message, 'promotion_ineligible:' ) ) {
            $message = substr( $message, strlen( 'promotion_ineligible:' ) );
        }
        if ( $message === 'commercial_offer_amount_invalid' ) {
            return 'promotion_discount_not_applicable';
        }
        $allowed = array(
            'promotion_request_invalid','promotion_code_required','promotion_code_invalid',
            'promotion_student_required','promotion_obligation_unavailable','promotion_rate_limited',
            'promotion_not_found','promotion_unavailable','promotion_inactive','promotion_not_yet_valid',
            'promotion_expired','promotion_product_mismatch','promotion_first_term_only',
            'promotion_usage_limit_reached','promotion_beneficiary_limit_reached','promotion_currency_mismatch',
            'promotion_discount_too_small','promotion_kind_unsupported','promotion_already_applied',
            'promotion_offer_already_paid','promotion_offer_unavailable','promotion_offer_expired',
            'promotion_offer_superseded','promotion_adjustment_unavailable','promotion_discount_not_applicable',
        );
        return in_array( $message, $allowed, true ) ? $message : 'promotion_unavailable';
    }

    private static function status( string $reason ): int {
        return match ( $reason ) {
            'promotion_request_invalid', 'promotion_code_required', 'promotion_code_invalid' => 400,
            'promotion_student_required' => 403,
            'promotion_obligation_unavailable' => 404,
            'promotion_rate_limited' => 429,
            'promotion_already_applied', 'promotion_offer_already_paid', 'promotion_offer_superseded',
            'promotion_offer_unavailable', 'promotion_offer_expired' => 409,
            'promotion_not_found', 'promotion_inactive', 'promotion_not_yet_valid', 'promotion_expired',
            'promotion_product_mismatch', 'promotion_first_term_only', 'promotion_usage_limit_reached',
            'promotion_beneficiary_limit_reached', 'promotion_currency_mismatch', 'promotion_discount_too_small',
            'promotion_kind_unsupported', 'promotion_adjustment_unavailable', 'promotion_discount_not_applicable' => 422,
            default => 503,
        };
    }

    private static function error( string $reason, int $status, bool $apply, array $extra = array() ): \WP_REST_Response {
        $payload = array( 'valid' => false, 'applied' => false, 'reason' => $reason );
        if ( $apply ) {
            $payload['refresh_required'] = ! empty( $extra['refresh_required'] );
        }
        return new \WP_REST_Response( $payload, $status );
    }
}
