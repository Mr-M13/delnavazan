<?php
namespace Delnavazan\Platform\Core\Application;

/** Digest-only, domain-separated command evidence for canonical Term authority. */
final class CanonicalTermIdempotency {
    public static function keyDigest(string $key): string {
        if (strlen($key) < 24 || strlen($key) > 255 || preg_match('/^[A-Za-z0-9._:\-]+$/D', $key) !== 1) {
            throw new \InvalidArgumentException('Valid idempotency key required');
        }
        return hash_hmac('sha256', 'canonical-term-command:' . $key, wp_salt('dzn_canonical_term_command'));
    }

    public static function payloadDigest(array $payload): string {
        self::sortRecursive($payload);
        return hash('sha256', (string) wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function evidenceDigest(string $reference): string {
        $reference = trim($reference);
        if ($reference === '' || strlen($reference) > 255) throw new \InvalidArgumentException('Bounded evidence reference required');
        return hash_hmac('sha256', 'canonical-term-evidence:' . $reference, wp_salt('dzn_canonical_term_evidence'));
    }

    private static function sortRecursive(array &$value): void {
        foreach ($value as &$item) if (is_array($item)) self::sortRecursive($item);
        unset($item);
        if (!array_is_list($value)) ksort($value, SORT_STRING);
    }
}
