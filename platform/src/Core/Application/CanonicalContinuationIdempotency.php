<?php
namespace Delnavazan\Platform\Core\Application;

/** Digest-only Phase-Q continuation command/evidence boundary; raw keys and raw references never persist. */
final class CanonicalContinuationIdempotency {
    public static function key(string $key):string{
        if(trim($key)==='')throw new \InvalidArgumentException('Idempotency key required');
        return hash_hmac('sha256','canonical_continuation_key:'.$key,wp_salt('dzn_canonical_continuation'));
    }
    public static function payload(array $payload):string{
        ksort($payload);
        return hash_hmac('sha256',wp_json_encode($payload,JSON_UNESCAPED_SLASHES),wp_salt('dzn_canonical_continuation'));
    }
    /** Evidence references (channels, authority evidence, provenance) persist only as keyed digests. */
    public static function evidence(string $reference):string{
        if(trim($reference)==='')throw new \InvalidArgumentException('Evidence reference required');
        return hash_hmac('sha256','canonical_continuation_evidence:'.$reference,wp_salt('dzn_canonical_continuation'));
    }
}
