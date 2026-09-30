<?php
namespace Delnavazan\Platform\Core\Application;

/** Digest-only identity for one Accepted Service Arrangement conversion command. */
final class EnrolmentConversionIdempotency {
    public static function keyDigest(string $raw): string {
        $raw = trim($raw);
        if (strlen($raw) < 24 || strlen($raw) > 255 || !preg_match('/^[A-Za-z0-9._~-]+$/D', $raw)) {
            throw new \InvalidArgumentException('Valid Enrolment conversion idempotency key required');
        }
        return hash_hmac('sha256', $raw, wp_salt('dzn_enrolment_conversion'));
    }

    public static function payloadDigest(int $arrangementId): string {
        return hash('sha256', wp_json_encode(array(
            'accepted_service_arrangement_id' => $arrangementId,
            'operation' => 'convert_accepted_service_arrangement_to_enrolment_v1',
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
