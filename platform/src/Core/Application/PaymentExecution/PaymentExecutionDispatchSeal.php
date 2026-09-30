<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * The sealed provider dispatch descriptor's own sealing helper (contract §11.6).
 *
 * It is deliberately not the credential vault of §11: it has its own domain-separated key, its own
 * locked payload and it cannot be reached through that vault. The sealed plaintext must be exactly
 * `DISPATCH_DESCRIPTOR_FIELDS` and nothing else, so the envelope cannot hold an API key, a signing
 * secret or arbitrary configuration. Only the adapter that sealed an envelope may open it, and only an
 * adapter seals or opens one (§13); Core transports a descriptor as an opaque handle.
 */
final class PaymentExecutionDispatchSeal {
    public const CIPHER_VERSION='sodium_secretbox_v1';
    public const KEY_VERSION='v1';

    public static function cipherVersion():string{return self::CIPHER_VERSION;}
    public static function keyVersion():string{return self::KEY_VERSION;}
    public static function cipherAvailable():bool{return function_exists('sodium_crypto_secretbox')&&function_exists('sodium_crypto_secretbox_open');}

    /** The keyed digest of one stored envelope: its cipher version, key version, nonce and ciphertext. */
    public static function digest(ProviderDispatchDescriptor $descriptor):string{
        return PaymentExecutionIdempotency::payload(array(
            'cipher_version'=>$descriptor->cipherVersion(),'key_version'=>$descriptor->keyVersion(),
            'nonce'=>$descriptor->nonce(),'ciphertext'=>$descriptor->ciphertext(),
        ));
    }

    /** Whether a plaintext carries exactly the locked field set with only string or integer values. */
    public static function fieldsMatch(array $fields):bool{
        $expected=PaymentExecutionRule::DISPATCH_DESCRIPTOR_FIELDS;
        $actual=array_keys($fields);
        sort($expected);sort($actual);
        if($expected!==$actual)return false;
        foreach($fields as $key=>$value){
            if($key==='provider_object_references'){
                if(!is_array($value))return false;
                foreach($value as $kind=>$reference)if(!is_string($kind)||!is_string($reference))return false;
                continue;
            }
            if($key==='amount_minor'||$key==='student_id'||$key==='obligation_id'){if(!is_int($value))return false;continue;}
            if($key==='purchase_id'||$key==='collection_intent_id'||$key==='renewal_cycle_id'){if($value!==null&&!is_int($value))return false;continue;}
            if(!is_string($value))return false;
        }
        return true;
    }

    /**
     * Seal one locked-field-set envelope under the dispatch domain.
     *
     * @param array<string,mixed> $fields exactly `DISPATCH_DESCRIPTOR_FIELDS`
     */
    public static function seal(array $fields,string $keyVersion=self::KEY_VERSION):ProviderDispatchDescriptor{
        if(!self::cipherAvailable())throw new \RuntimeException('payment_dispatch_descriptor_unavailable');
        if(!self::fieldsMatch($fields))throw new \InvalidArgumentException('dispatch_descriptor_incomplete');
        $key=PaymentExecutionIdempotency::vaultKey(PaymentExecutionRule::DISPATCH_DESCRIPTOR_DOMAIN,$keyVersion);
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext=wp_json_encode($fields,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($plaintext))throw new \RuntimeException('payment_dispatch_descriptor_unavailable');
        $ciphertext=sodium_crypto_secretbox($plaintext,$nonce,$key);
        $descriptor=new ProviderDispatchDescriptor(
            self::CIPHER_VERSION,$keyVersion,bin2hex($nonce),base64_encode($ciphertext),''
        );
        return new ProviderDispatchDescriptor(
            $descriptor->cipherVersion(),$descriptor->keyVersion(),$descriptor->nonce(),$descriptor->ciphertext(),self::digest($descriptor)
        );
    }

    /**
     * Open one envelope. Returns null — never a partial, guessed or re-derived value — when the cipher
     * or key version is unknown, the salt is missing, authentication fails, or the sealed field set is
     * not exactly the locked list.
     *
     * @return array<string,mixed>|null
     */
    public static function open(ProviderDispatchDescriptor $descriptor,string $keyVersion=self::KEY_VERSION):?array{
        if(!$descriptor->complete())return null;
        if($descriptor->cipherVersion()!==self::CIPHER_VERSION||$descriptor->keyVersion()!==$keyVersion)return null;
        if(!self::cipherAvailable())return null;
        if(!hash_equals($descriptor->digest(),self::digest($descriptor)))return null;
        $raw=base64_decode($descriptor->ciphertext(),true);
        if(!is_string($raw))return null;
        $nonce=@hex2bin($descriptor->nonce());
        if(!is_string($nonce)||strlen($nonce)!==SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)return null;
        $key=PaymentExecutionIdempotency::vaultKey(PaymentExecutionRule::DISPATCH_DESCRIPTOR_DOMAIN,$descriptor->keyVersion());
        $plaintext=@sodium_crypto_secretbox_open($raw,$nonce,$key);
        if(!is_string($plaintext))return null;
        $fields=json_decode($plaintext,true);
        if(!is_array($fields)||!self::fieldsMatch($fields))return null;
        return $fields;
    }
}
