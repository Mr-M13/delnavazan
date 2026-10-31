<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Signed LiveKit webhook intake.
 *
 * The webhook remains defence in depth for participant lifecycle: it rejects a third identity and
 * closes a room whose canonical authorisation has since been withdrawn. When the server-owned
 * attendance adapter is explicitly enabled, it additionally records normalized join/leave facts in
 * durable correlation storage and submits *closed* intervals to the existing Phase-P authority.
 * It never completes a Lesson or writes delivery/finance truth directly.
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
        $roomName = (string)($event['room']['name'] ?? '');
        $identity = (string)($event['participant']['identity'] ?? '');
        if ($roomName === '' || !str_starts_with($roomName, 'dzn-')) {
            return new \WP_REST_Response(array('ok' => true, 'ignored' => true), 200);
        }

        $registry = new LiveKitRoomRegistry();
        $capture = LiveKitAttendanceIntegration::enabled();
        $attendance = $capture ? new LiveKitAttendanceIntegration() : null;

        if ($eventType === 'room_finished') {
            // Resolve (and, if necessary, bootstrap) the durable binding before the transient
            // registry entry is discarded; attendance must never depend on that cache.
            $room = $capture ? $attendance->roomForWebhook($roomName) : null;
            $registry->forget($roomName);
            if (!$capture) return new \WP_REST_Response(array('ok' => true), 200);
            if (!$room) return new \WP_REST_Response(array('ok' => true, 'ignored' => true), 200);
            try {
                $result = $attendance->recordRoomFinished($event, $room);
            } catch (\Throwable $error) {
                return self::retry($error);
            }
            if (!empty($result['retry'])) return self::retry(null);
            return new \WP_REST_Response(array('ok' => true, 'action' => 'room_finished'), 200);
        }

        if (!in_array($eventType, array('participant_joined', 'participant_left'), true)) {
            return new \WP_REST_Response(array('ok' => true), 200);
        }

        $room = $capture ? $attendance->roomForWebhook($roomName) : null;

        if ($eventType === 'participant_joined') {
            if (!$room) {
                return self::legacyJoined($registry, $roomName, $identity);
            }
            try {
                $result = $attendance->recordJoined($event, $room);
            } catch (\Throwable $error) {
                return self::retry($error);
            }
            if (($result['state'] ?? '') === 'unauthorized_identity' || $identity === '') {
                self::remove($roomName, $identity);
                return new \WP_REST_Response(array('ok' => true, 'action' => 'removed_unauthorized_identity'), 200);
            }
            if (!empty($result['retry'])) return self::retry(null);
            try {
                $active = (new LiveKitClassroomAuthorizer())->grantStillActive($attendance->lifecycleSession($room));
            } catch (\Throwable) {
                $active = false;
            }
            if (!$active) {
                try {
                    (new LiveKitRoomRevoker())->revokeRoom($roomName);
                } catch (\Throwable) {
                    self::remove($roomName, $identity);
                }
                return new \WP_REST_Response(array('ok' => true, 'action' => 'room_revoked'), 200);
            }
            return new \WP_REST_Response(array('ok' => true, 'action' => 'admitted'), 200);
        }

        // participant_left
        if (!$room) return new \WP_REST_Response(array('ok' => true, 'ignored' => true), 200);
        try {
            $result = $attendance->recordLeft($event, $room);
        } catch (\Throwable $error) {
            return self::retry($error);
        }
        if (!empty($result['retry'])) return self::retry(null);
        return new \WP_REST_Response(array('ok' => true, 'action' => (string)($result['state'] ?? 'left')), 200);
    }

    /** Preserve the pre-adapter lifecycle behaviour for rooms with no durable attendance binding. */
    private static function legacyJoined(LiveKitRoomRegistry $registry, string $room, string $identity): \WP_REST_Response {
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

    /** Only a genuinely transient failure is answered non-2xx so LiveKit retries the delivery. */
    private static function retry(?\Throwable $error): \WP_Error {
        if ($error !== null && function_exists('do_action')) {
            do_action('dzn_livekit_webhook_retry_requested', get_class($error));
        }
        return new \WP_Error('livekit_webhook_retry', 'LiveKit webhook will be retried.', array('status' => 503));
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
