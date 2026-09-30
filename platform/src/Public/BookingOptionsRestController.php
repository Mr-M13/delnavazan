<?php
namespace Delnavazan\Platform\Public;

use Delnavazan\Platform\Core\Application\PublicBookingOptionsReadService;

/** Safe public projection of active introductory-course choices. */
final class BookingOptionsRestController {
    public static function register(): void {
        register_rest_route( 'delnavazan-platform/v1', '/booking-options', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'read' ) ) );
    }
    public static function read(): \WP_REST_Response {
        try { return new \WP_REST_Response( array( 'instruments' => ( new PublicBookingOptionsReadService() )->introductoryInstruments() ), 200 ); }
        catch ( \Throwable ) { return new \WP_REST_Response( array( 'success' => false, 'code' => 'options_unavailable' ), 503 ); }
    }
}
