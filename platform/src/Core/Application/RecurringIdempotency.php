<?php
namespace Delnavazan\Platform\Core\Application;

/** Digest-only Phase 2A.2-R2 command and evidence boundary; raw keys and references never persist. */
final class RecurringIdempotency {
    public static function key(string $key):string{
        if(trim($key)==='')throw new \InvalidArgumentException('Idempotency key required');
        return hash_hmac('sha256','recurring_key:'.$key,wp_salt('dzn_recurring'));
    }
    public static function payload(array $payload):string{
        ksort($payload);
        return hash_hmac('sha256',wp_json_encode($payload,JSON_UNESCAPED_SLASHES),wp_salt('dzn_recurring'));
    }
    public static function evidence(string $reference):string{
        if(trim($reference)==='')throw new \InvalidArgumentException('Evidence reference required');
        return hash_hmac('sha256','recurring_evidence:'.$reference,wp_salt('dzn_recurring'));
    }
    /** Stable renewal-cycle fingerprint derived only from authoritative facts. */
    public static function boundaryFingerprint(array $facts):string{
        $normalised=array();
        foreach($facts as $key=>$value){$normalised[(string)$key]=$value===null?array('null'=>true):$value;}
        return hash_hmac('sha256','recurring_boundary:'.wp_json_encode($normalised,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),wp_salt('dzn_recurring'));
    }
}
