<?php
declare(strict_types=1);

namespace Delnavazan\Gateway;

/**
 * Validates the exact WordPress-issued envelope. The gateway independently enforces audience,
 * lifetime, key id, not-before/expiry, jti shape, one recording/content version and one principal.
 */
final class GrantValidator {
    public const AUDIENCE = 'recording-playback-gateway';
    public const MAX_TTL = 300;
    public const CLOCK_SKEW = 60;

    public function __construct(private KeyStore $keys, private int $now, private string $expectedIssuer = '') {}

    public function validate(array $envelope): array {
        $payload = $envelope['payload'] ?? null;
        $meta = $envelope['meta'] ?? null;
        $signature = $envelope['signature'] ?? null;
        if (!is_array($payload) || !is_array($meta) || !is_string($signature)) throw new \RuntimeException('playback_grant_invalid');
        $kid = (string) ($meta['key_id'] ?? '');
        if ($kid === '') throw new \RuntimeException('playback_grant_key_missing');
        $secret = $this->keys->secret($kid);
        if ((string) ($meta['audience'] ?? '') !== self::AUDIENCE) throw new \RuntimeException('playback_grant_audience_mismatch');
        $issued = (int) ($meta['issued_at'] ?? 0); $expires = (int) ($meta['expires_at'] ?? 0);
        if ($issued > $this->now + self::CLOCK_SKEW) throw new \RuntimeException('playback_grant_not_yet_valid');
        if ($expires <= $this->now) throw new \RuntimeException('playback_grant_expired');
        if ($expires <= $issued || $expires - $issued > self::MAX_TTL + self::CLOCK_SKEW) throw new \RuntimeException('playback_grant_lifetime_invalid');
        if ((string) ($payload['aud'] ?? '') !== self::AUDIENCE) throw new \RuntimeException('playback_grant_audience_mismatch');
        if (trim((string) ($payload['iss'] ?? '')) === '') throw new \RuntimeException('playback_grant_issuer_missing');
        if ($this->expectedIssuer !== '' && !hash_equals($this->expectedIssuer, (string) $payload['iss'])) throw new \RuntimeException('playback_grant_issuer_mismatch');
        if ((string) ($payload['scope'] ?? '') !== 'stream') throw new \RuntimeException('playback_grant_scope_invalid');
        if ((int) ($payload['nbf'] ?? 0) > $this->now + self::CLOCK_SKEW) throw new \RuntimeException('playback_grant_not_yet_valid');
        if ((int) ($payload['exp'] ?? 0) <= $this->now) throw new \RuntimeException('playback_grant_expired');
        foreach (['recording_uid', 'archive_object_key', 'principal_type', 'jti'] as $key) if (trim((string) ($payload[$key] ?? '')) === '') throw new \RuntimeException('playback_grant_incomplete');
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', (string) $payload['recording_uid']) !== 1) throw new \RuntimeException('playback_grant_recording_invalid');
        if ((int) ($payload['content_version'] ?? 0) < 1) throw new \RuntimeException('playback_grant_version_invalid');
        if ((int) ($payload['principal_id'] ?? 0) < 1 || !in_array((string) $payload['principal_type'], ['teacher', 'student', 'guardian'], true)) throw new \RuntimeException('playback_grant_principal_invalid');
        if (preg_match('/^[a-f0-9]{32,64}$/D', (string) $payload['jti']) !== 1) throw new \RuntimeException('playback_grant_jti_invalid');
        if (preg_match('#(^|/)\.\.(/|$)#', (string) $payload['archive_object_key']) === 1 || str_starts_with((string) $payload['archive_object_key'], '/')) throw new \RuntimeException('playback_grant_object_invalid');
        // The generic envelope signature uses the same canonicalisation as the WordPress signer.
        Envelope::verify($envelope, $secret, self::AUDIENCE, $this->now);
        return $payload;
    }
}
