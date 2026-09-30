<?php
namespace Delnavazan\Platform\Core\Application;

/** Digest-only attendance intake/command/evidence boundary; raw keys and raw references never persist. */
final class CanonicalAttendanceIdempotency {
    public static function key(string $key):string{
        if(trim($key)==='')throw new \InvalidArgumentException('Idempotency key required');
        return hash_hmac('sha256','canonical_attendance_key:'.$key,wp_salt('dzn_canonical_attendance'));
    }
    public static function payload(array $payload):string{
        ksort($payload);
        return hash_hmac('sha256',wp_json_encode($payload,JSON_UNESCAPED_SLASHES),wp_salt('dzn_canonical_attendance'));
    }
    public static function evidence(string $reference):string{
        if(trim($reference)==='')throw new \InvalidArgumentException('Evidence reference required');
        return hash_hmac('sha256','canonical_attendance_evidence:'.$reference,wp_salt('dzn_canonical_attendance'));
    }
    /** Provider event keys and payloads are digested independently so conflicts are detectable. */
    public static function providerEventKey(string $key):string{
        if(trim($key)==='')throw new \InvalidArgumentException('Provider event key required');
        return hash_hmac('sha256','canonical_attendance_event_key:'.$key,wp_salt('dzn_canonical_attendance'));
    }
    public static function providerPayload(string $key):string{
        if(trim($key)==='')throw new \InvalidArgumentException('Provider payload key required');
        return hash_hmac('sha256','canonical_attendance_payload:'.$key,wp_salt('dzn_canonical_attendance'));
    }
}
