<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * A short-lived, user-bound launch credential.
 *
 * The ticket is carried in the URL fragment of the classroom page (never sent in Referer or to the
 * web server) and is exchanged for a LiveKit token. It is signed with a server-only salt and bound
 * to the logged-in WordPress user, so a copied link does not authorise a different account. Every
 * exchange still re-proves canonical lesson ownership, the current schedule version, lifecycle and
 * joining window; the ticket is not an independent booking authority.
 */
final class LiveKitLaunchTicket {
    public const TTL_SECONDS = 21600;
    private const VERSION = 1;

    public function __construct(private ?string $secret = null, private $clock = null) {}

    public function issue(string $role, array $resource): string {
        $now = $this->now();
        $payload = array(
            'v' => self::VERSION,
            'role' => $role,
            'kind' => (string)($resource['kind'] ?? ''),
            'lesson_uid' => (string)($resource['lesson_uid'] ?? ''),
            'schedule_version_uid' => (string)($resource['schedule_version_uid'] ?? ''),
            'confirmation_uid' => (string)($resource['confirmation_uid'] ?? ''),
            'meet_available' => ($resource['meet_available'] ?? false) === true,
            'user_id' => (int)get_current_user_id(),
            'iat' => $now,
            'exp' => $now + self::TTL_SECONDS,
        );
        $this->validatePayload($payload, false);
        $body = $this->base64url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $body . '.' . $this->signature($body);
    }

    public function verify(string $ticket): array {
        $ticket = trim($ticket);
        if ($ticket === '' || strlen($ticket) > 4096 || substr_count($ticket, '.') !== 1) {
            throw new \InvalidArgumentException('livekit_ticket_invalid');
        }
        [$body, $signature] = explode('.', $ticket, 2);
        if (!hash_equals($this->signature($body), $signature)) throw new \InvalidArgumentException('livekit_ticket_invalid');
        $decoded = $this->base64urlDecode($body);
        if ($decoded === '') throw new \InvalidArgumentException('livekit_ticket_invalid');
        try {
            $payload = json_decode($decoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('livekit_ticket_invalid');
        }
        if (!is_array($payload)) throw new \InvalidArgumentException('livekit_ticket_invalid');
        $this->validatePayload($payload, true);
        return $payload;
    }

    private function validatePayload(array $payload, bool $checkExpiry): void {
        if ((int)($payload['v'] ?? 0) !== self::VERSION
            || !in_array((string)($payload['role'] ?? ''), array('teacher', 'student', 'administrator'), true)
            || !in_array((string)($payload['kind'] ?? ''), array('lesson', 'booking'), true)
            || (int)($payload['user_id'] ?? 0) < 1) {
            throw new \InvalidArgumentException('livekit_ticket_invalid');
        }
        if ((string)$payload['kind'] === 'lesson') {
            if (!preg_match('/^[0-9A-Z]{26}$/D', (string)($payload['lesson_uid'] ?? ''))
                || !preg_match('/^[0-9A-Z]{26}$/D', (string)($payload['schedule_version_uid'] ?? ''))) {
                throw new \InvalidArgumentException('livekit_ticket_invalid');
            }
        } elseif (!preg_match('/^[0-9A-Z]{26}$/D', (string)($payload['confirmation_uid'] ?? ''))) {
            throw new \InvalidArgumentException('livekit_ticket_invalid');
        }
        if (function_exists('get_current_user_id') && (int)get_current_user_id() !== (int)$payload['user_id']) {
            throw new \InvalidArgumentException('livekit_ticket_user_mismatch');
        }
        if ($checkExpiry && ((int)($payload['exp'] ?? 0) < $this->now() || (int)($payload['iat'] ?? 0) > $this->now() + 60)) {
            throw new \InvalidArgumentException('livekit_ticket_expired');
        }
    }

    private function signature(string $body): string {
        return $this->base64url(hash_hmac('sha256', $body, $this->secret(), true));
    }

    private function secret(): string {
        if ($this->secret !== null && $this->secret !== '') return $this->secret;
        if (!function_exists('wp_salt')) throw new \RuntimeException('livekit_ticket_not_configured');
        return (string)wp_salt('dzn_livekit_ticket');
    }

    private function now(): int {
        return $this->clock !== null ? (int)($this->clock)() : time();
    }

    private function base64url(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $value): string {
        $padding = strlen($value) % 4;
        if ($padding) $value .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
