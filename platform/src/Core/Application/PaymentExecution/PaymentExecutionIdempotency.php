<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * Digest-only Phase 2A.2-T key, payload, reference and fact boundary (contract §5.3, §9.5, §11).
 *
 * Raw keys, raw provider references, raw signatures, raw source addresses and raw payloads never
 * persist: every one of them is keyed-hashed here before it can reach storage, a log or a diagnostic.
 */
final class PaymentExecutionIdempotency {
    private const SALT_DOMAIN='dzn_payment_execution';
    private const SALT_VAULT='dzn_payment_secret_vault';

    public static function key(string $key):string{
        if(trim($key)==='')throw new \InvalidArgumentException('Idempotency key required');
        return hash_hmac('sha256','payment_execution_key:'.$key,wp_salt(self::SALT_DOMAIN));
    }
    public static function payload(array $payload):string{
        ksort($payload);
        return hash_hmac('sha256',wp_json_encode($payload,JSON_UNESCAPED_SLASHES),wp_salt(self::SALT_DOMAIN));
    }
    /** The digest of the deterministically re-derivable provider idempotency key of one command. */
    public static function idempotencyKey(string $providerKey,string $idempotencyKey):string{
        if(trim($idempotencyKey)==='')throw new \InvalidArgumentException('Idempotency key required');
        return hash_hmac('sha256','payment_execution_idempotency:'.$providerKey.':'.$idempotencyKey,wp_salt(self::SALT_DOMAIN));
    }
    /** Raw provider/account/object references are only ever stored in this digested form. */
    public static function reference(string $reference):string{
        if(trim($reference)==='')throw new \InvalidArgumentException('Provider reference required');
        return hash_hmac('sha256','payment_execution_reference:'.$reference,wp_salt(self::SALT_DOMAIN));
    }
    /** The provider event identity digest (§9.5). */
    public static function eventReference(string $eventReference):string{
        if(trim($eventReference)==='')throw new \InvalidArgumentException('Provider event reference required');
        return hash_hmac('sha256','payment_event_reference:'.$eventReference,wp_salt(self::SALT_DOMAIN));
    }
    public static function payloadDigest(string $rawBody):string{
        return hash_hmac('sha256','payment_event_payload:'.$rawBody,wp_salt(self::SALT_DOMAIN));
    }
    public static function rawType(string $rawType):string{
        return hash_hmac('sha256','payment_event_type:'.$rawType,wp_salt(self::SALT_DOMAIN));
    }
    public static function signature(string $signatureHeader):string{
        if(trim($signatureHeader)==='')throw new \InvalidArgumentException('Signature required');
        return hash_hmac('sha256','payment_event_signature:'.$signatureHeader,wp_salt(self::SALT_DOMAIN));
    }
    public static function source(string $source):string{
        return hash_hmac('sha256','payment_event_source:'.$source,wp_salt(self::SALT_DOMAIN));
    }
    public static function accountSelector(string $selector):string{
        return hash_hmac('sha256','payment_account_selector:'.$selector,wp_salt(self::SALT_DOMAIN));
    }
    public static function claimToken(string $token):string{
        return hash_hmac('sha256','payment_dispatch_claim_token:'.$token,wp_salt(self::SALT_DOMAIN));
    }
    public static function capability(string $material):string{
        return hash_hmac('sha256','payment_dispatch_capability:'.$material,wp_salt(self::SALT_DOMAIN));
    }
    /** The canonical immutable fact identity of one normalised provider event (§9.5). */
    public static function eventFact(array $facts):string{
        $normalised=array();
        foreach($facts as $key=>$value)$normalised[(string)$key]=$value===null?array('null'=>true):$value;
        return hash_hmac('sha256','payment_event_fact:'.wp_json_encode($normalised,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),wp_salt(self::SALT_DOMAIN));
    }
    /** The domain-separated key material used by the secret vault and the dispatch seal. */
    public static function vaultKey(string $scope,string $keyVersion):string{
        return hash_hmac('sha256','dzn_payment_secret_vault:'.$scope.':'.$keyVersion,wp_salt(self::SALT_VAULT),true);
    }
}
