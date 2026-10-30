<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Ephemeral room registry used for webhook validation and targeted revocation.
 *
 * The registry contains only opaque room names, canonical resource IDs, and the two derived
 * participant identities. It is intentionally short-lived (the same window as the late join
 * allowance) and never stores names, contact details, tokens, or provider links. A missing record
 * fails closed: an unknown `dzn-` room is rejected by the webhook.
 */
final class LiveKitRoomRegistry {
    public const TTL_SECONDS = 28800;
    private const ROOM_PREFIX = 'dzn_lk_room_';
    private const RESOURCE_PREFIX = 'dzn_lk_resource_';

    public function remember(array $session): void {
        $room = (string)($session['room'] ?? '');
        if ($room === '' || (string)($session['teacher_identity'] ?? '') === '' || (string)($session['student_identity'] ?? '') === '') {
            throw new \RuntimeException('livekit_session_registry_unavailable');
        }
        $session['recorded_at'] = gmdate('Y-m-d H:i:s');
        set_transient($this->roomKey($room), $session, self::TTL_SECONDS);
        $stored = get_transient($this->roomKey($room));
        if (!is_array($stored) || (string)($stored['room'] ?? '') !== $room) {
            throw new \RuntimeException('livekit_session_registry_unavailable');
        }
        $resourceKey = $this->resourceKey((string)$session['kind'], (int)$session['resource_id']);
        $index = get_transient($resourceKey);
        $index = is_array($index) ? $index : array();
        $index[$this->roomDigest($room)] = $room;
        set_transient($resourceKey, $index, self::TTL_SECONDS);
        $storedIndex = get_transient($resourceKey);
        if (!is_array($storedIndex) || (string)($storedIndex[$this->roomDigest($room)] ?? '') !== $room) {
            throw new \RuntimeException('livekit_session_registry_unavailable');
        }
    }

    public function get(string $room): ?array {
        if ($room === '') return null;
        $session = get_transient($this->roomKey($room));
        return is_array($session) && (string)($session['room'] ?? '') === $room ? $session : null;
    }

    public function forget(string $room): void {
        if ($room === '') return;
        delete_transient($this->roomKey($room));
    }

    /** @return array<int,array> */
    public function activeForResource(string $kind, int $resourceId): array {
        $index = get_transient($this->resourceKey($kind, $resourceId));
        if (!is_array($index)) return array();
        $sessions = array();
        foreach ($index as $room) {
            $session = $this->get((string)$room);
            if ($session) $sessions[] = $session;
        }
        return $sessions;
    }

    public function forgetResource(string $kind, int $resourceId): void {
        delete_transient($this->resourceKey($kind, $resourceId));
    }

    private function roomKey(string $room): string {
        return self::ROOM_PREFIX . $this->roomDigest($room);
    }

    private function resourceKey(string $kind, int $resourceId): string {
        return self::RESOURCE_PREFIX . hash('sha256', $kind . '|' . $resourceId);
    }

    private function roomDigest(string $room): string {
        return hash('sha256', $room);
    }
}
