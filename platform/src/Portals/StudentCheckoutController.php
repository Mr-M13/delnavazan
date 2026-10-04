<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\Checkout\StudentCheckoutInitiationService;

/** Narrow authenticated customer-checkout initiation surface. */
final class StudentCheckoutController {
    public static function register(): void {
        register_rest_route('delnavazan-platform/v1', '/student/checkout', array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'permitted'),
            'callback' => array(__CLASS__, 'initiate'),
        ));
    }

    public static function permitted(\WP_REST_Request $request): bool {
        $nonce = (string) $request->get_header('x_wp_nonce');
        return is_user_logged_in() && $nonce !== '' && wp_verify_nonce($nonce, 'wp_rest');
    }

    public static function initiate(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $params = $request->get_json_params();
            if (!is_array($params) || $request->get_file_params() !== array() || $request->get_url_params() !== array() || $request->get_query_params() !== array()) {
                throw new \InvalidArgumentException('checkout_unavailable');
            }
            $result = (new StudentCheckoutInitiationService())->initiate($params);
            return new \WP_REST_Response($result, 200);
        } catch (\Throwable) {
            return new \WP_REST_Response(array('checkout_state' => 'unavailable', 'redirect_url' => null), 400);
        }
    }
}
