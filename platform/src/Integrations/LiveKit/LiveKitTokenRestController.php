<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Browser-facing session endpoint.
 *
 * The request carries only a signed, user-bound launch ticket. The endpoint re-proves canonical
 * lesson ownership, current schedule, lifecycle and window on every call; it never trusts a room
 * name, role, lesson identifier, or token lifetime supplied by the browser.
 */
final class LiveKitTokenRestController {
    private const RATE_LIMIT = 30;
    private const RATE_WINDOW = 600;

    public static function register(): void {
        register_rest_route('delnavazan-platform/v1', '/livekit/session', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'issue'),
            'permission_callback' => static fn(): bool => is_user_logged_in(),
        ));
    }

    public static function issue(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        nocache_headers();
        if (!self::admit()) {
            return new \WP_Error('livekit_rate_limited', 'Too many classroom token requests.', array('status' => 429));
        }
        try {
            $ticket = (string)$request->get_param('ticket');
            if ($ticket === '') throw new \InvalidArgumentException('livekit_ticket_invalid');
            $session = (new LiveKitClassroomSessionService())->session($ticket);
            $role = (string)$session['role'];
            $session['meet_action_url'] = admin_url('admin-post.php?action=dzn_livekit_meet_fallback');
            $session['meet_nonce'] = wp_create_nonce('dzn_livekit_meet_fallback');
            $session['portal_url'] = home_url($role === 'teacher' ? '/teacher-portal/' : '/student-portal/');
            $response = new \WP_REST_Response($session, 200);
            $response->header('Cache-Control', 'no-store, private, max-age=0');
            $response->header('Referrer-Policy', 'no-referrer');
            return $response;
        } catch (\Throwable $error) {
            $code = $error->getMessage();
            $service = in_array($code, array(
                'livekit_not_configured', 'livekit_room_api_unavailable', 'livekit_room_capacity_unverified',
                'livekit_session_registry_unavailable', 'livekit_authority_unavailable',
            ), true);
            if (str_starts_with($code, 'livekit_ticket_')) {
                return new \WP_Error('livekit_link_expired', 'This classroom link has expired. Return to the portal and open the class again.', array('status' => 403));
            }
            return new \WP_Error(
                $service ? 'livekit_unavailable' : 'livekit_access_denied',
                $service ? 'The live classroom is temporarily unavailable.' : 'This classroom is not available for your account.',
                array('status' => $service ? 503 : 403)
            );
        }
    }

    private static function admit(): bool {
        $userId = (int)get_current_user_id();
        if ($userId < 1) return false;
        $key = 'dzn_livekit_token_' . $userId;
        $count = (int)get_transient($key);
        if ($count >= self::RATE_LIMIT) return false;
        if (!set_transient($key, $count + 1, self::RATE_WINDOW)) return false;
        return true;
    }
}
