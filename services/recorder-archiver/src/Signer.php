<?php
declare(strict_types=1);

namespace Delnavazan\Recorder;

/**
 * The exact signing algorithm used by the WordPress control plane and playback gateway.
 * A keyed envelope binds key id, audience, issued/expiry, nonce and payload digest.
 */
final class Signer {
    public const VERSION = 'dzn-recording-signature-v1';
    public const MAX_LIFETIME = 900;
    public const CLOCK_SKEW = 60;

    public static function sign(array $payload, string $secret, string $keyId, string $audience, int $now, int $ttl = 300): array {
        if (strlen($secret) < 32) throw new \InvalidArgumentException('recording_signing_key_invalid');
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $keyId) !== 1) throw new \InvalidArgumentException('recording_signing_key_id_invalid');
        if (preg_match('/^[a-z0-9._-]{3,96}$/D', $audience) !== 1) throw new \InvalidArgumentException('recording_signing_audience_invalid');
        if ($ttl < 1 || $ttl > self::MAX_LIFETIME) throw new \InvalidArgumentException('recording_signing_ttl_invalid');
        $meta = [
            'v' => 1, 'key_id' => $keyId, 'audience' => $audience, 'issued_at' => $now,
            'expires_at' => $now + $ttl, 'nonce' => bin2hex(random_bytes(16)),
            'digest' => Support::payloadDigest($payload),
        ];
        return ['payload' => $payload, 'meta' => $meta, 'signature' => self::signature($meta, $secret)];
    }

    public static function verify(array $envelope, string $secret, string $expectedAudience, int $now): array {
        if (strlen($secret) < 32) throw new \RuntimeException('recording_signature_unavailable');
        $payload = $envelope['payload'] ?? null;
        $meta = $envelope['meta'] ?? null;
        $signature = $envelope['signature'] ?? null;
        if (!is_array($payload) || !is_array($meta) || !is_string($signature) || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1) {
            throw new \RuntimeException('recording_signature_invalid');
        }
        foreach (['key_id', 'audience', 'issued_at', 'expires_at', 'nonce', 'digest'] as $key) {
            if (!array_key_exists($key, $meta)) throw new \RuntimeException('recording_signature_invalid');
        }
        if ((int) ($meta['v'] ?? 0) !== 1) throw new \RuntimeException('recording_signature_version_unsupported');
        if (!hash_equals($expectedAudience, (string) $meta['audience'])) throw new \RuntimeException('recording_signature_audience_mismatch');
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/D', (string) $meta['key_id']) !== 1) throw new \RuntimeException('recording_signature_invalid');
        if (preg_match('/^[a-f0-9]{32,128}$/D', (string) $meta['nonce']) !== 1) throw new \RuntimeException('recording_signature_nonce_invalid');
        $issued = (int) $meta['issued_at'];
        $expires = (int) $meta['expires_at'];
        if ($issued > $now + self::CLOCK_SKEW) throw new \RuntimeException('recording_signature_not_yet_valid');
        if ($expires < $now - self::CLOCK_SKEW) throw new \RuntimeException('recording_signature_expired');
        if ($expires <= $issued || $expires - $issued > self::MAX_LIFETIME + self::CLOCK_SKEW) throw new \RuntimeException('recording_signature_lifetime_invalid');
        if (!hash_equals((string) $meta['digest'], Support::payloadDigest($payload))) throw new \RuntimeException('recording_signature_payload_mismatch');
        if (!hash_equals(self::signature($meta, $secret), $signature)) throw new \RuntimeException('recording_signature_invalid');
        return ['payload' => $payload, 'meta' => $meta];
    }

    private static function signature(array $meta, string $secret): string {
        $context = self::VERSION . '|' . $meta['key_id'] . '|' . $meta['audience'] . '|' . (int) $meta['issued_at'] . '|' . (int) $meta['expires_at'] . '|' . $meta['nonce'] . '|' . $meta['digest'];
        return hash_hmac('sha256', $context, $secret);
    }
}
