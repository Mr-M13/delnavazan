<?php
namespace Delnavazan\Platform\Core\Application;

/** Final-acceptance command identity. Raw keys never enter persistence. */
final class FinalAcceptanceIdempotency {
    public static function keyDigest(string $raw): string {
        $raw = trim($raw);
        if (strlen($raw) < 24 || strlen($raw) > 255 || !preg_match('/^[A-Za-z0-9._~-]+$/D', $raw)) {
            throw new \InvalidArgumentException('Valid final-acceptance idempotency key required');
        }
        return hash_hmac('sha256', $raw, wp_salt('dzn_final_acceptance'));
    }

    public static function payloadDigest(array $command): string {
        return hash('sha256', wp_json_encode(self::canonical($command), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(array(self::class, 'canonical'), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonical($item);
        return $value;
    }
}
