<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/**
 * Telnyx Messaging API v2 webhook authenticity check.
 *
 * Telnyx signs `{timestamp}|{raw_body}` with Ed25519. The verifier deliberately takes the exact raw
 * bytes: parsing and re-serialising JSON before verification would invalidate the signature. A stale
 * timestamp is refused before the signature is trusted, so a captured request cannot be replayed.
 *
 * The public key is public configuration, never a secret. Signature and key material are never
 * included in a returned code or an exception message.
 */
final class TelnyxWebhookSignature {
    public const DEFAULT_TOLERANCE=300;
    private function __construct(private string $publicKey,private int $toleranceSeconds=self::DEFAULT_TOLERANCE){}
    public static function fromSettings(?string $publicKey,int $toleranceSeconds=self::DEFAULT_TOLERANCE):self{
        return new self(trim((string)$publicKey),max(30,min(900,$toleranceSeconds)));
    }
    /**
     * @param array<string,string> $headers lower-case header names
     * @return array{valid:bool,code:string}
     */
    public function verify(string $rawBody,array $headers,int $now):array{
        if($this->publicKey==='')return array('valid'=>false,'code'=>'webhook_public_key_unconfigured');
        $signature=$this->header($headers,'telnyx-signature-ed25519');
        $timestamp=$this->header($headers,'telnyx-timestamp');
        if($signature===''||$timestamp==='')return array('valid'=>false,'code'=>'webhook_signature_missing');
        if(!preg_match('/^[0-9]{1,12}$/',$timestamp))return array('valid'=>false,'code'=>'webhook_timestamp_invalid');
        if(abs($now-(int)$timestamp)>$this->toleranceSeconds)return array('valid'=>false,'code'=>'webhook_timestamp_stale');
        if(!function_exists('sodium_crypto_sign_verify_detached'))return array('valid'=>false,'code'=>'webhook_signature_unavailable');
        $signatureBytes=$this->decode($signature);
        if($signatureBytes===null||strlen($signatureBytes)!==SODIUM_CRYPTO_SIGN_BYTES)return array('valid'=>false,'code'=>'webhook_signature_invalid');
        $publicKeyBytes=$this->publicKeyBytes();
        if($publicKeyBytes===null||strlen($publicKeyBytes)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)return array('valid'=>false,'code'=>'webhook_public_key_invalid');
        $valid=sodium_crypto_sign_verify_detached($signatureBytes,$timestamp.'|'.$rawBody,$publicKeyBytes);
        return array('valid'=>$valid,'code'=>$valid?'verified':'webhook_signature_invalid');
    }
    private function header(array $headers,string $name):string{
        foreach($headers as $key=>$value)if(str_replace('_','-',strtolower((string)$key))===$name)return is_array($value)?(string)reset($value):trim((string)$value);
        return '';
    }
    private function decode(string $value):?string{
        $value=trim($value);
        if($value==='')return null;
        $decoded=base64_decode($value,true);
        if($decoded===false){$decoded=base64_decode(strtr($value,'-_','+/'),true);}
        return is_string($decoded)?$decoded:null;
    }
    /**
     * Telnyx publishes the key as base64 raw Ed25519 bytes. A PEM/SPKI value is also accepted because
     * operators sometimes paste the key in that form; Ed25519 SPKI always ends in the 32 raw bytes.
     */
    private function publicKeyBytes():?string{
        $value=trim($this->publicKey);
        if(str_contains($value,'-----BEGIN')){
            $value=preg_replace('/-----[^-]+-----/','',$value)??'';
            $value=preg_replace('/\s+/','',$value)??'';
        }
        $decoded=$this->decode($value);
        if($decoded===null)return null;
        if(strlen($decoded)===SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)return $decoded;
        if(strlen($decoded)>SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)return substr($decoded,-SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        return null;
    }
}
