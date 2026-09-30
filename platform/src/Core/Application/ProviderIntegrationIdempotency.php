<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Digest-only integration boundary.
 *
 * Raw command keys, raw provider keys and raw provider reference values are digested with a
 * domain-separated salt and never persist. Provider `occurred_at` and local `received_at` are kept
 * as separate facts, and the payload digest is computed over a canonicalised fact set so an exact
 * replay reconstructs the identical digest and a changed context cannot converge.
 */
final class ProviderIntegrationIdempotency {
    private const SALT='dzn_provider_integration';

    public static function key(string $key):string{
        if(trim($key)==='')throw new \InvalidArgumentException('Idempotency key required');
        return hash_hmac('sha256','provider_integration_key:'.$key,wp_salt(self::SALT));
    }

    /** Canonical payload digest: the complete incoming facts, in a deterministic order. */
    public static function payload(array $payload):string{
        ksort($payload);
        return hash_hmac('sha256',(string)wp_json_encode(self::canonical($payload),JSON_UNESCAPED_SLASHES),wp_salt(self::SALT));
    }

    /** A provider subject/account/calendar/event/conference reference is a value, never an identity. */
    public static function subject(string $reference,string $purpose):string{
        if(trim($reference)==='')throw new \InvalidArgumentException('Provider reference required');
        ProviderIntegrationRule::purpose($purpose);
        return hash_hmac('sha256','provider_integration_subject:'.$purpose.':'.$reference,wp_salt(self::SALT));
    }

    /** Provider event identity: the Phase-P seam recomputes its own digest from the same raw key. */
    public static function providerEventKey(string $key):string{
        if(trim($key)==='')throw new \InvalidArgumentException('Provider event key required');
        return hash_hmac('sha256','provider_integration_event_key:'.$key,wp_salt(self::SALT));
    }

    public static function providerPayload(string $key):string{
        if(trim($key)==='')throw new \InvalidArgumentException('Provider payload key required');
        return hash_hmac('sha256','provider_integration_payload:'.$key,wp_salt(self::SALT));
    }

    public static function evidence(string $reference):string{
        if(trim($reference)==='')throw new \InvalidArgumentException('Evidence reference required');
        return hash_hmac('sha256','provider_integration_evidence:'.$reference,wp_salt(self::SALT));
    }

    /** The proof reference of an authenticating transport is stored only as a keyed digest. */
    public static function proof(string $reference):string{
        if(trim($reference)==='')throw new \InvalidArgumentException('Transport proof reference required');
        return hash_hmac('sha256','provider_integration_proof:'.$reference,wp_salt(self::SALT));
    }

    /** Nested arrays are canonicalised so a replayed fact set always produces one digest. */
    private static function canonical(array $value):array{
        foreach($value as $key=>$item){
            if(is_array($item))$value[$key]=self::canonical($item);
            elseif(is_bool($item))$value[$key]=$item?1:0;
            elseif($item===null)$value[$key]=null;
            elseif(is_object($item))$value[$key]=self::canonical((array)$item);
        }
        return $value;
    }
}
