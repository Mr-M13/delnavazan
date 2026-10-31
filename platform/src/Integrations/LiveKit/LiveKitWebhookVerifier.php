<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Verifies the HS256 JWT that LiveKit sends in the webhook Authorization header.
 *
 * The body digest is checked when present. LiveKit sends the SHA-256 digest as standard base64;
 * the verifier also accepts base64url and legacy hex encodings for compatibility. It never trusts
 * an unsigned body and never logs the token, body, or API secret.
 */
final class LiveKitWebhookVerifier {
    public function verify(string $authorization, string $rawBody, string $apiKey, string $apiSecret, int $now): array {
        if ($apiKey === '' || $apiSecret === '') throw new \RuntimeException('livekit_not_configured');
        if (!preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches)) throw new \InvalidArgumentException('livekit_webhook_unauthorized');
        $parts = explode('.', $matches[1]);
        if (count($parts) !== 3) throw new \InvalidArgumentException('livekit_webhook_unauthorized');
        [$header64, $payload64, $signature64] = $parts;
        $header = json_decode($this->decode($header64), true);
        $payload = json_decode($this->decode($payload64), true);
        if (!is_array($header) || !is_array($payload) || (string)($header['alg'] ?? '') !== 'HS256') {
            throw new \InvalidArgumentException('livekit_webhook_unauthorized');
        }
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $header64 . '.' . $payload64, $apiSecret, true)), '+/', '-_'), '=');
        if (!hash_equals($expected, $signature64)) throw new \InvalidArgumentException('livekit_webhook_unauthorized');
        if (!hash_equals($apiKey, (string)($payload['iss'] ?? ''))) throw new \InvalidArgumentException('livekit_webhook_unauthorized');
        if (isset($payload['nbf']) && (int)$payload['nbf'] > $now + 5) throw new \InvalidArgumentException('livekit_webhook_unauthorized');
        if (isset($payload['exp']) && (int)$payload['exp'] < $now - 5) throw new \InvalidArgumentException('livekit_webhook_unauthorized');
        if (isset($payload['sha256'])) {
            $rawDigest = hash('sha256', $rawBody, true);
            $digest = (string)$payload['sha256'];
            $base64 = base64_encode($rawDigest);
            $base64url = rtrim(strtr($base64, '+/', '-_'), '=');
            $hex = bin2hex($rawDigest);
            if (!hash_equals($base64, $digest) && !hash_equals($base64url, $digest) && !hash_equals($hex, $digest)) {
                throw new \InvalidArgumentException('livekit_webhook_unauthorized');
            }
        }
        return $payload;
    }

    private function decode(string $value): string {
        $padding = strlen($value) % 4;
        if ($padding) $value .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
