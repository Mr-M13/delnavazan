<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\Checkout\{StudentCheckoutInitiationService,StudentCheckoutReturnReadService,StudentCommercialCheckoutReadService};
use Delnavazan\Platform\Core\Application\Finance\Intro\{IntroCheckoutInitiationService,IntroCheckoutReturnReadService};

/** Narrow authenticated customer-checkout initiation surface. */
final class StudentCheckoutController {
    public static function register(): void {
        register_rest_route('delnavazan-platform/v1', '/student/checkout', array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'permitted'),
            'callback' => array(__CLASS__, 'initiate'),
        ));
        register_rest_route('delnavazan-platform/v1', '/student/checkout-status', array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'permitted'),
            'callback' => array(__CLASS__, 'status'),
        ));
        register_rest_route('delnavazan-platform/v1', '/student/commercial-checkout', array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'permitted'),
            'callback' => array(__CLASS__, 'commercialCheckout'),
        ));
        register_rest_route('delnavazan-platform/v1', '/student/intro-checkout', array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'permitted'),
            'callback' => array(__CLASS__, 'introCheckout'),
        ));
        register_rest_route('delnavazan-platform/v1', '/student/intro-checkout-status', array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'permitted'),
            'callback' => array(__CLASS__, 'introStatus'),
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
        } catch (\Throwable $error) {
            $state = trim($error->getMessage()) === 'checkout_prerequisite_pending' ? 'prerequisite_pending' : 'unavailable';
            return new \WP_REST_Response(array('checkout_state' => $state, 'redirect_url' => null), 400);
        }
    }

    public static function status(\WP_REST_Request $request): \WP_REST_Response {
        try {
            if ($request->get_body() !== '' || $request->get_file_params() !== array()
                || $request->get_url_params() !== array() || $request->get_json_params() !== null) {
                throw new \InvalidArgumentException('checkout_unavailable');
            }
            $query = $request->get_query_params();
            if (!is_array($query) || array_keys($query) !== array('attempt_uid')) throw new \InvalidArgumentException('checkout_unavailable');
            try {
                $result = (new StudentCheckoutReturnReadService())->read(array('attempt_uid' => $query['attempt_uid']));
            } catch (\InvalidArgumentException) {
                $result = (new IntroCheckoutReturnReadService())->read(array('attempt_uid' => $query['attempt_uid']));
            }
            return new \WP_REST_Response($result, 200);
        } catch (\InvalidArgumentException) {
            return new \WP_REST_Response(array('payment_state' => 'unavailable', 'checkout_state' => 'unavailable', 'action' => null, 'retry_allowed' => false, 'obligation_uid' => null), 404);
        } catch (\Throwable) {
            return new \WP_REST_Response(array('payment_state' => 'unavailable', 'checkout_state' => 'unavailable', 'action' => null, 'retry_allowed' => false, 'obligation_uid' => null), 503);
        }
    }

    public static function introCheckout(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $params = $request->get_json_params();
            if (!is_array($params) || $request->get_file_params() !== array() || $request->get_url_params() !== array() || $request->get_query_params() !== array()) throw new \InvalidArgumentException('checkout_unavailable');
            return new \WP_REST_Response((new IntroCheckoutInitiationService())->initiate($params), 200);
        } catch (\InvalidArgumentException) {
            return new \WP_REST_Response(array('checkout_state' => 'unavailable', 'redirect_url' => null), 400);
        } catch (\Throwable) {
            return new \WP_REST_Response(array('checkout_state' => 'unavailable', 'redirect_url' => null), 503);
        }
    }

    public static function introStatus(\WP_REST_Request $request): \WP_REST_Response {
        try {
            if ($request->get_body() !== '' || $request->get_file_params() !== array() || $request->get_url_params() !== array() || $request->get_json_params() !== null) throw new \InvalidArgumentException('checkout_unavailable');
            $query = $request->get_query_params();
            if (!is_array($query) || array_keys($query) !== array('attempt_uid')) throw new \InvalidArgumentException('checkout_unavailable');
            return new \WP_REST_Response((new IntroCheckoutReturnReadService())->read(array('attempt_uid' => $query['attempt_uid'])), 200);
        } catch (\InvalidArgumentException) {
            return new \WP_REST_Response(array('payment_state' => 'unavailable', 'checkout_state' => 'unavailable', 'action' => null, 'retry_allowed' => false, 'obligation_uid' => null), 404);
        } catch (\Throwable) {
            return new \WP_REST_Response(array('payment_state' => 'unavailable', 'checkout_state' => 'unavailable', 'action' => null, 'retry_allowed' => false, 'obligation_uid' => null), 503);
        }
    }

    public static function commercialCheckout(\WP_REST_Request $request): \WP_REST_Response {
        try {
            if ($request->get_body() !== '' || $request->get_file_params() !== array()
                || $request->get_url_params() !== array() || $request->get_json_params() !== null
                || $request->get_query_params() !== array()) {
                throw new \InvalidArgumentException('checkout_unavailable');
            }
            return new \WP_REST_Response((new StudentCommercialCheckoutReadService())->read(), 200);
        } catch (\InvalidArgumentException) {
            return new \WP_REST_Response(array('state' => 'unavailable', 'obligations' => array()), 404);
        } catch (\Throwable) {
            return new \WP_REST_Response(array('state' => 'unavailable', 'obligations' => array()), 503);
        }
    }
}
