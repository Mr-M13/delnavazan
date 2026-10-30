<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Minimal LiveKit RoomService client for the server-side control plane.
 *
 * Room creation with max_participants=2 is the server-enforced one-to-one boundary. Tokens are
 * minted with roomCreate=false and roomAdmin=false, so a participant cannot bypass the room
 * capacity by creating or reconfiguring a room.
 */
final class LiveKitRoomServiceClient {
    private const MAX_PARTICIPANTS = 2;

    /** @var callable(string,array,array):array{status:int,body:string} */
    private $transport;

    public function __construct(
        private array $config,
        private ?LiveKitJwtIssuer $issuer = null,
        ?callable $transport = null,
        private $clock = null
    ) {
        $this->issuer ??= new LiveKitJwtIssuer();
        $this->transport = $transport ?? static function (string $url, array $body, array $headers): array {
            $response = wp_remote_post($url, array(
                'timeout' => 5,
                'redirection' => 0,
                'sslverify' => true,
                'headers' => $headers,
                'body' => wp_json_encode($body),
            ));
            if (is_wp_error($response)) return array('status' => 0, 'body' => '');
            return array('status' => (int)wp_remote_retrieve_response_code($response), 'body' => (string)wp_remote_retrieve_body($response));
        };
    }

    public function ensureRoom(string $room): void {
        $this->assertRoom($room);
        $existing = $this->room($room);
        if ($existing) {
            if ((int)($existing['max_participants'] ?? 0) !== self::MAX_PARTICIPANTS) {
                $this->updateRoom($room);
            }
            return;
        }
        try {
            $this->createRoom($room);
        } catch (\RuntimeException $error) {
            // A concurrent token request may have created the room between ListRooms and CreateRoom.
            if (!$this->room($room)) throw $error;
        }
        $existing = $this->room($room);
        if (!$existing || (int)($existing['max_participants'] ?? 0) !== self::MAX_PARTICIPANTS) {
            throw new \RuntimeException('livekit_room_capacity_unverified');
        }
    }

    public function room(string $room): ?array {
        $response = $this->call('ListRooms', array('names' => array($room)), 30, false, '');
        $rooms = $response['rooms'] ?? array();
        if (!is_array($rooms)) return null;
        foreach ($rooms as $candidate) {
            if (is_array($candidate) && (string)($candidate['name'] ?? '') === $room) return $candidate;
        }
        return null;
    }

    public function createRoom(string $room): array {
        return $this->call('CreateRoom', array(
            'name' => $room,
            'empty_timeout' => (int)($this->config['room_empty_timeout'] ?? 300),
            'max_participants' => self::MAX_PARTICIPANTS,
        ), 60);
    }

    public function updateRoom(string $room): array {
        return $this->call('UpdateRoom', array('room' => $room, 'max_participants' => self::MAX_PARTICIPANTS), 60);
    }

    public function deleteRoom(string $room): void {
        $this->call('DeleteRoom', array('room' => $room), 60, true);
    }

    public function removeParticipant(string $room, string $identity): void {
        $this->call('RemoveParticipant', array('room' => $room, 'identity' => $identity), 60, true);
    }

    public function listParticipants(string $room): array {
        $response = $this->call('ListParticipants', array('room' => $room), 30, false, $room);
        return is_array($response['participants'] ?? null) ? $response['participants'] : array();
    }

    private function call(string $method, array $body, int $ttl, bool $allowMissing = false, ?string $room = null): array {
        $base = rtrim((string)($this->config['api_url'] ?? ''), '/');
        if ($base === '') throw new \RuntimeException('livekit_not_configured');
        $now = $this->clock !== null ? (int)($this->clock)() : time();
        $room = $room ?? (string)($body['room'] ?? $body['name'] ?? '');
        $token = $this->issuer->issueRoomAdmin((string)$this->config['key'], (string)$this->config['secret'], $room, $now);
        $headers = array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json');
        $response = ($this->transport)($base . '/twirp/livekit.RoomService/' . $method, $body, $headers);
        $status = (int)($response['status'] ?? 0);
        if ($status >= 200 && $status < 300) {
            $decoded = json_decode((string)($response['body'] ?? ''), true);
            if (is_array($decoded)) return $decoded;
            throw new \RuntimeException('livekit_room_api_invalid_response');
        }
        if ($allowMissing && in_array($status, array(404, 400), true)) {
            $decoded = json_decode((string)($response['body'] ?? ''), true);
            $code = is_array($decoded) ? strtolower((string)($decoded['code'] ?? '')) : '';
            if ($status === 404 || in_array($code, array('not_found', 'notfound'), true)) return array();
            if ($status === 400 && str_contains(strtolower((string)($decoded['msg'] ?? '')), 'not found')) return array();
        }
        if (function_exists('do_action')) do_action('dzn_livekit_room_api_failed', $method, $status);
        throw new \RuntimeException('livekit_room_api_unavailable');
    }

    private function assertRoom(string $room): void {
        if (!preg_match('/^dzn-[A-Za-z0-9_-]{8,64}$/D', $room)) throw new \InvalidArgumentException('livekit_room_name_invalid');
    }
}
