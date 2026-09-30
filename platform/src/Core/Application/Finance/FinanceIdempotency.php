<?php
namespace Delnavazan\Platform\Core\Application\Finance;

/**
 * Phase 2A.2-U digest boundary (U-D18).
 *
 * Every Finance command is arbitrated by a keyed `command_key_digest` plus a `command_payload_digest`;
 * every derivation carries a keyed digest over the exact canonical inputs it named; every audit and
 * outbox row is keyed by a derived digest so an identical replay converges on one durable row instead
 * of duplicating evidence. No raw key, request body, evidence reference or provider reference is ever
 * persisted — only the digest.
 */
final class FinanceIdempotency {
    private const DOMAIN='finance_v1';

    /** The keyed digest of a caller-supplied idempotency key. */
    public static function commandKey(string $key):string{
        $key=trim($key);
        if($key==='')throw new \InvalidArgumentException('Valid idempotency key required');
        return hash_hmac('sha256','command:'.$key,wp_salt(self::DOMAIN));
    }
    /** The keyed digest of a command's material payload. */
    public static function payload(array $facts):string{
        return hash_hmac('sha256','payload:'.self::canonical($facts),wp_salt(self::DOMAIN));
    }
    /** The keyed digest of one evidence reference (never the reference itself). */
    public static function evidence(string $reference):string{
        $reference=trim($reference);
        if($reference==='')throw new \InvalidArgumentException('Evidence reference required');
        return hash_hmac('sha256','evidence:'.$reference,wp_salt(self::DOMAIN));
    }
    /** §8.3: the keyed digest over one declared field order. */
    public static function derivation(array $fields,array $values):string{
        $ordered=array();
        foreach($fields as $field)$ordered[$field]=$values[$field]??null;
        return hash_hmac('sha256','derivation:'.self::canonical($ordered),wp_salt(self::DOMAIN));
    }
    /** §15.7: the derived audit key of one audited Finance row. */
    public static function auditKey(string $commandKeyDigest,string $aggregate,int $aggregateId):string{
        return hash_hmac('sha256','audit:'.$commandKeyDigest.':'.$aggregate.':'.$aggregateId,wp_salt(self::DOMAIN));
    }
    /** §15.7/§17: the derived outbox key, the same idiom `RecurringOutboxRepository::intentKey()` uses. */
    public static function intentKey(string $aggregate,int $aggregateId,string $intent):string{
        return hash_hmac('sha256','finance_intent:'.$aggregate.':'.$aggregateId.':'.$intent,wp_salt(self::DOMAIN));
    }
    /** §13.2: the derived fingerprint that converges a repeated exception on one durable row. */
    public static function fingerprint(string $reasonCode,array $scope):string{
        return hash_hmac('sha256','exception:'.$reasonCode.':'.self::canonical($scope),wp_salt(self::DOMAIN));
    }
    /** A deterministic, order-stable serialisation of a fact array. */
    private static function canonical(array $facts):string{
        $normalise=static function(mixed $value)use(&$normalise):mixed{
            if(is_array($value)){$out=array();foreach($value as $key=>$item)$out[(string)$key]=$normalise($item);if(!array_is_list($out))ksort($out);return $out;}
            if(is_bool($value))return $value?'1':'0';
            if($value===null)return null;
            return is_int($value)?$value:(string)$value;
        };
        return json_encode($normalise($facts),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?: '';
    }
}
