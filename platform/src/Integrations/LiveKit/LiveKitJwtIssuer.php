<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Small HS256 issuer for LiveKit access and server API tokens.
 *
 * The browser only ever receives a room-scoped participant token. The API secret stays in the
 * PHP process. Participant tokens are deliberately short-lived and cannot create rooms, publish
 * data, record, or administer a room.
 */
final class LiveKitJwtIssuer {
    public const PARTICIPANT_TTL_SECONDS = 300;
    public const ROOM_ADMIN_TTL_SECONDS = 60;

    /** Backwards-compatible participant token used by the prototype contract test. */
    public function issue(string $apiKey, string $apiSecret, string $room, string $identity, int $now): string {
        return $this->issueParticipant($apiKey, $apiSecret, $room, $identity, '', $now, self::PARTICIPANT_TTL_SECONDS);
    }

    public function issueParticipant(string $apiKey, string $apiSecret, string $room, string $identity, string $displayName, int $now, int $ttl = self::PARTICIPANT_TTL_SECONDS): string {
        $this->assertInputs($apiKey, $apiSecret, $room, $identity);
        $ttl = max(60, min(self::PARTICIPANT_TTL_SECONDS, $ttl));
        $payload = array(
            'iss' => $apiKey,
            'sub' => $identity,
            'nbf' => $now - 5,
            'iat' => $now,
            'exp' => $now + $ttl,
            'video' => array(
                'roomJoin' => true,
                'room' => $room,
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => false,
                'canPublishSources' => array('camera', 'microphone'),
                'roomCreate' => false,
                'roomAdmin' => false,
                'roomRecord' => false,
            ),
        );
        $displayName = $this->cleanDisplayName($displayName);
        if ($displayName !== '') $payload['name'] = $displayName;
        return $this->sign($payload, $apiSecret);
    }

    /** Short-lived token used only for LiveKit RoomService control-plane calls. */
    public function issueRoomAdmin(string $apiKey, string $apiSecret, string $room, int $now): string {
        return $this->issueRoomServiceToken($apiKey, $apiSecret, $room, array('roomAdmin' => true), $now);
    }

    /**
     * Short-lived RoomService token with exactly the grants a single control-plane call needs.
     *
     * @param array<string,bool> $grants roomCreate, roomList, or roomAdmin
     */
    public function issueRoomServiceToken(string $apiKey, string $apiSecret, string $room, array $grants, int $now): string {
        if ($apiKey === '' || $apiSecret === '') throw new \InvalidArgumentException('livekit_token_input_invalid');
        if ($room !== '') $this->assertInputs($apiKey, $apiSecret, $room, 'dzn-server');
        $allowed = array('roomCreate', 'roomList', 'roomAdmin');
        $video = array();
        foreach ($allowed as $grant) {
            if (!empty($grants[$grant])) $video[$grant] = true;
        }
        if (!$video) throw new \InvalidArgumentException('livekit_token_input_invalid');
        if ($room !== '') $video['room'] = $room;
        return $this->sign(array(
            'iss' => $apiKey,
            'sub' => 'dzn-server',
            'nbf' => $now - 5,
            'iat' => $now,
            'exp' => $now + self::ROOM_ADMIN_TTL_SECONDS,
            'video' => $video,
        ), $apiSecret);
    }

    private function sign(array $payload, string $apiSecret): string {
        $header = $this->encode(array('alg' => 'HS256', 'typ' => 'JWT'));
        $body = $this->encode($payload);
        $signature = $this->base64url(hash_hmac('sha256', $header . '.' . $body, $apiSecret, true));
        return $header . '.' . $body . '.' . $signature;
    }

    private function assertInputs(string $apiKey, string $apiSecret, string $room, string $identity): void {
        if ($apiKey === '' || $apiSecret === ''
            || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $room)
            || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $identity)) {
            throw new \InvalidArgumentException('livekit_token_input_invalid');
        }
    }

    private function cleanDisplayName(string $value): string {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
        if ($value === '') return '';
        return function_exists('mb_substr') ? mb_substr($value, 0, 64, 'UTF-8') : substr($value, 0, 64);
    }

    private function encode(array $value): string {
        return $this->base64url(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function base64url(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
