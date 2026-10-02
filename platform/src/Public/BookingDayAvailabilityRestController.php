<?php
namespace Delnavazan\Platform\Public;

use Delnavazan\Platform\Core\Application\BookingDayAvailabilityService;

final class BookingDayAvailabilityRestController {
    public static function register(): void {
        register_rest_route( 'delnavazan-platform/v1', '/booking-availability/day', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'handle' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public static function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        try {
            $body = $request->get_json_params();
            if ( ! is_array( $body ) ) throw new \InvalidArgumentException( 'JSON object required' );
            return new \WP_REST_Response( ( new BookingDayAvailabilityService() )->day( $body ), 200 );
        } catch ( \InvalidArgumentException $e ) {
            return new \WP_Error( 'invalid_request', $e->getMessage(), array( 'status' => 400 ) );
        } catch ( \Throwable ) {
            return new \WP_Error( 'availability_unavailable', 'Availability is temporarily unavailable.', array( 'status' => 503 ) );
        }
    }
}
