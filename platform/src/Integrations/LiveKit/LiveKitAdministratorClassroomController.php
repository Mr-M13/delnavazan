<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Administrator-only Google Meet fallback actions for the Academy overview.
 *
 * Opening and copying are separate operations. Opening uses an authenticated POST and redirects
 * with no referrer. Copying returns the vaulted URL to the already-authorised administrator over a
 * nonce-protected REST request. Both paths audit the access before the URL leaves the server.
 */
final class LiveKitAdministratorClassroomController {
    public static function register(): void {
        add_action('admin_post_dzn_admin_classroom_meet', array(__CLASS__, 'openMeet'));
        add_action('rest_api_init', array(__CLASS__, 'registerRest'));
    }

    public static function openMeet(): never {
        if (!is_user_logged_in()) auth_redirect();
        if (!current_user_can(LiveKitAdministratorClassroomService::CAPABILITY)) wp_die('Forbidden', 403);
        check_admin_referer('dzn_admin_classroom_meet');
        $failure = add_query_arg(
            array('page' => 'dzn-academy-dashboard', 'classroom_status' => 'meet_unavailable'),
            admin_url('admin.php')
        ) . '#dzn-academy-today-upcoming';
        try {
            $kind = sanitize_key((string) ($_POST['meet_kind'] ?? 'lesson'));
            $input = $kind === 'booking'
                ? array('confirmation_uid' => sanitize_text_field((string) ($_POST['confirmation_uid'] ?? '')))
                : array('lesson_id' => absint($_POST['lesson_id'] ?? 0));
            $url = (new LiveKitAdministratorClassroomService())->meetUrl($kind, $input);
            nocache_headers();
            header('Referrer-Policy: no-referrer');
            wp_redirect($url, 302, 'Delnavazan');
            exit;
        } catch (\Throwable) {
            wp_safe_redirect($failure, 302, 'Delnavazan');
            exit;
        }
    }

    public static function registerRest(): void {
        register_rest_route('delnavazan-platform/v1', '/admin/classroom/meet-link', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'meetLink'),
            'permission_callback' => static fn(): bool => is_user_logged_in() && current_user_can(LiveKitAdministratorClassroomService::CAPABILITY),
        ));
    }

    public static function meetLink(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        nocache_headers();
        $nonce = (string) ($request->get_header('x_wp_nonce') ?: '');
        if ($nonce === '' || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new \WP_Error('rest_cookie_invalid_nonce', 'Invalid or missing REST nonce.', array('status' => 403));
        }
        try {
            $kind = sanitize_key((string) $request->get_param('kind'));
            $input = $kind === 'booking'
                ? array('confirmation_uid' => sanitize_text_field((string) $request->get_param('confirmation_uid')))
                : array('lesson_id' => absint($request->get_param('lesson_id')));
            $url = (new LiveKitAdministratorClassroomService())->meetUrl($kind, $input);
            $response = new \WP_REST_Response(array('url' => $url), 200);
            $response->header('Cache-Control', 'no-store, private, max-age=0');
            $response->header('Referrer-Policy', 'no-referrer');
            return $response;
        } catch (\InvalidArgumentException) {
            return new \WP_Error('classroom_meet_not_provisioned', 'The backup Meet link is not provisioned for this class.', array('status' => 404));
        } catch (\Throwable) {
            return new \WP_Error('classroom_meet_unavailable', 'The backup Meet link could not be opened.', array('status' => 503));
        }
    }
}
