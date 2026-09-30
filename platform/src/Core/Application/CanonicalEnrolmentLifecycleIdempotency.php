<?php
namespace Delnavazan\Platform\Core\Application;

final class CanonicalEnrolmentLifecycleIdempotency {
    public static function keyDigest(string $key): string {
        $key = trim($key);
        if ($key === '' || strlen($key) > 255) throw new \InvalidArgumentException('Valid idempotency key required');
        return hash_hmac('sha256', 'canonical-enrolment-lifecycle-key:' . $key, wp_salt('dzn_enrolment_lifecycle_key'));
    }
    public static function payloadDigest(array $payload): string {
        return hash('sha256', (string) wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    public static function evidenceDigest(string $reference): string {
        $reference = trim($reference);
        if ($reference === '' || strlen($reference) > 512) throw new \InvalidArgumentException('Evidence reference required');
        return hash_hmac('sha256', 'canonical-enrolment-lifecycle-evidence:' . $reference, wp_salt('dzn_enrolment_lifecycle_evidence'));
    }
}
