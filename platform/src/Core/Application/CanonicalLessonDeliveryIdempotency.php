<?php
namespace Delnavazan\Platform\Core\Application;

/** Digest-only delivery/attendance command and evidence boundary; raw keys and raw evidence never persist. */
final class CanonicalLessonDeliveryIdempotency {
    public static function key(string $key): string {
        if (trim($key) === '') throw new \InvalidArgumentException('Idempotency key required');
        return hash_hmac('sha256', 'canonical_lesson_delivery_key:' . $key, wp_salt('dzn_canonical_lesson_delivery'));
    }
    public static function payload(array $payload): string {
        ksort($payload);
        return hash_hmac('sha256', wp_json_encode($payload, JSON_UNESCAPED_SLASHES), wp_salt('dzn_canonical_lesson_delivery'));
    }
    public static function evidence(string $reference): string {
        if (trim($reference) === '') throw new \InvalidArgumentException('Evidence reference required');
        return hash_hmac('sha256', 'canonical_lesson_delivery_evidence:' . $reference, wp_salt('dzn_canonical_lesson_delivery'));
    }
}
