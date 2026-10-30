<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Targeted room revocation.
 *
 * LiveKit participant JWTs are stateless: an already-issued five-minute token cannot be
 * individually invalidated. This service reduces that unavoidable exposure by deleting the room
 * and removing its participants as soon as the canonical lesson is cancelled, completed, or
 * rescheduled. A stale token then has no current room to join. LiveKit's own token lifetime is the
 * final bound (currently five minutes).
 */
final class LiveKitRoomRevoker {
    public function __construct(
        private ?LiveKitRoomRegistry $registry = null,
        private ?LiveKitConfiguration $configuration = null,
        private ?LiveKitRoomServiceClient $rooms = null
    ) {
        $this->registry ??= new LiveKitRoomRegistry();
    }

    public function revokeResource(string $kind, int $resourceId): void {
        if (!in_array($kind, array('lesson', 'booking'), true) || $resourceId < 1) return;
        $sessions = $this->registry->activeForResource($kind, $resourceId);
        $this->registry->forgetResource($kind, $resourceId);
        foreach ($sessions as $session) {
            $room = (string)($session['room'] ?? '');
            if ($room === '') continue;
            $this->registry->forget($room);
            try {
                $this->client()->deleteRoom($room);
            } catch (\Throwable $error) {
                if (function_exists('do_action')) do_action('dzn_livekit_room_revocation_failed', $kind, $resourceId, get_class($error));
            }
        }
    }

    public function revokeRoom(string $room): void {
        if ($room === '') return;
        $this->registry->forget($room);
        try {
            $this->client()->deleteRoom($room);
        } catch (\Throwable $error) {
            if (function_exists('do_action')) do_action('dzn_livekit_room_revocation_failed', 'room', 0, get_class($error));
        }
    }

    private function client(): LiveKitRoomServiceClient {
        if ($this->rooms === null) {
            $config = ($this->configuration ?? new LiveKitConfiguration())->values();
            $this->rooms = new LiveKitRoomServiceClient($config);
        }
        return $this->rooms;
    }
}
