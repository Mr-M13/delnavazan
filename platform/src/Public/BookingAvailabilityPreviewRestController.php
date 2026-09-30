<?php
namespace Delnavazan\Platform\Public;

use Delnavazan\Platform\Core\Application\BookingAvailabilityPreviewService;

/** Public aggregate availability labels only; no Teacher identity or reservation is exposed. */
final class BookingAvailabilityPreviewRestController {
    public static function register(): void {
        register_rest_route( 'delnavazan-platform/v1', '/booking-availability/preview', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'assess' ) ) );
    }
    public static function assess( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! ( new BookingAvailabilityPreviewRateLimiter() )->allow() ) return new \WP_REST_Response( array( 'success' => false, 'code' => 'rate_limited' ), 429 );
        try { return new \WP_REST_Response( ( new BookingAvailabilityPreviewService() )->assess( (array) $request->get_json_params() ), 200 ); }
        catch ( \InvalidArgumentException ) { return new \WP_REST_Response( array( 'success' => false, 'code' => 'invalid_request' ), 400 ); }
        catch ( \Throwable ) { return new \WP_REST_Response( array( 'success' => false, 'code' => 'availability_unavailable' ), 503 ); }
    }
}
