<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Signed LiveKit webhook intake.
 *
 * The webhook is defence in depth for participant lifecycle: it rejects a third identity and
 * closes a room whose canonical authorisation has since been withdrawn. It is not an attendance
 * authority, does not complete Lessons, and never writes attendance or financial evidence.
 *
 * LiveKit must be configured to send webhooks to
 * `/wp-json/delnavazan-platform/v1/livekit/webhook` with the same API key/secret.
 */
final class LiveKitWebhookController {
    public static function register(): void {
        register_rest_route('delnavazan-platform/v1', '/livekit/webhook', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'receive'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function receive(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        try {
            $config = (new LiveKitConfiguration())->values();
            $raw = (string)$request->get_body();
            $authorization = (string)($request->get_header('authorization') ?: ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
            (new LiveKitWebhookVerifier())->verify($authorization, $raw, (string)$config['key'], (string)$config['secret'], time());
            $event = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($event)) throw new \InvalidArgumentException('livekit_webhook_invalid');
        } catch (\Throwable $error) {
            $status = $error->getMessage() === 'livekit_not_configured' ? 503 : 401;
            return new \WP_Error('livekit_webhook_rejected', 'LiveKit webhook rejected.', array('status' => $status));
        }

        $eventType = (string)($event['event'] ?? '');
        $room = (string)($event['room']['name'] ?? '');
        $identity = (string)($event['participant']['identity'] ?? '');
        if ($room === '' || !str_starts_with($room, 'dzn-')) {
            return new \WP_REST_Response(array('ok' => true, 'ignored' => true), 200);
        }
        $registry = new LiveKitRoomRegistry();
        if ($eventType === 'room_finished') {
            $registry->forget($room);
            return new \WP_REST_Response(array('ok' => true), 200);
        }
        if ($eventType !== 'participant_joined') return new \WP_REST_Response(array('ok' => true), 200);

        $session = $registry->get($room);
        if (!$session) {
            self::remove($room, $identity);
            return new \WP_REST_Response(array('ok' => true, 'action' => 'removed_unknown_room'), 200);
        }
        $allowed = array((string)($session['teacher_identity'] ?? ''), (string)($session['student_identity'] ?? ''));
        if (!in_array($identity, $allowed, true)) {
            self::remove($room, $identity);
            return new \WP_REST_Response(array('ok' => true, 'action' => 'removed_unauthorized_identity'), 200);
        }
        try {
            $active = (new LiveKitClassroomAuthorizer())->grantStillActive($session);
        } catch (\Throwable) {
            $active = false;
        }
        if (!$active) {
            try {
                (new LiveKitRoomRevoker())->revokeRoom($room);
            } catch (\Throwable) {
                self::remove($room, $identity);
            }
            return new \WP_REST_Response(array('ok' => true, 'action' => 'room_revoked'), 200);
        }
        return new \WP_REST_Response(array('ok' => true, 'action' => 'admitted'), 200);
    }

    private static function remove(string $room, string $identity): void {
        if ($identity === '') return;
        try {
            $config = (new LiveKitConfiguration())->values();
            (new LiveKitRoomServiceClient($config))->removeParticipant($room, $identity);
        } catch (\Throwable $error) {
            if (function_exists('do_action')) do_action('dzn_livekit_webhook_removal_failed', $room, get_class($error));
        }
    }
}
