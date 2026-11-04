<?php
declare(strict_types=1);

namespace Delnavazan\Gateway;

/** Independent copy of the keyed envelope verifier; no shared deployment dependency. */
final class Envelope {
    private const VERSION = 'dzn-recording-signature-v1';
    private const MAX_LIFETIME = 900;
    private const CLOCK_SKEW = 60;

    public static function verify(array $envelope, string $secret, string $audience, int $now): array {
        $payload = $envelope['payload'] ?? null;
        $meta = $envelope['meta'] ?? null;
        $signature = $envelope['signature'] ?? null;
        if (!is_array($payload) || !is_array($meta) || !is_string($signature) || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1) throw new \RuntimeException('playback_grant_invalid');
        foreach (['key_id', 'audience', 'issued_at', 'expires_at', 'nonce', 'digest'] as $key) if (!array_key_exists($key, $meta)) throw new \RuntimeException('playback_grant_invalid');
        if ((int) ($meta['v'] ?? 0) !== 1) throw new \RuntimeException('playback_grant_version_invalid');
        if (!hash_equals($audience, (string) $meta['audience'])) throw new \RuntimeException('playback_grant_audience_mismatch');
        if ((int) $meta['issued_at'] > $now + self::CLOCK_SKEW) throw new \RuntimeException('playback_grant_not_yet_valid');
        if ((int) $meta['expires_at'] < $now - self::CLOCK_SKEW) throw new \RuntimeException('playback_grant_expired');
        if ((int) $meta['expires_at'] <= (int) $meta['issued_at'] || (int) $meta['expires_at'] - (int) $meta['issued_at'] > self::MAX_LIFETIME + self::CLOCK_SKEW) throw new \RuntimeException('playback_grant_lifetime_invalid');
        $digest = hash('sha256', self::canonicalJson($payload));
        if (!hash_equals((string) $meta['digest'], $digest)) throw new \RuntimeException('playback_grant_payload_mismatch');
        $context = self::VERSION . '|' . $meta['key_id'] . '|' . $meta['audience'] . '|' . (int) $meta['issued_at'] . '|' . (int) $meta['expires_at'] . '|' . $meta['nonce'] . '|' . $meta['digest'];
        if (!hash_equals(hash_hmac('sha256', $context, $secret), $signature)) throw new \RuntimeException('playback_grant_invalid');
        return ['payload' => $payload, 'meta' => $meta];
    }

    private static function canonicalJson(array $value): string {
        self::sort($value);
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    private static function sort(array &$value): void {
        foreach ($value as &$item) if (is_array($item)) self::sort($item);
        unset($item);
        if (array_is_list($value)) return;
        ksort($value, SORT_STRING);
    }
}
