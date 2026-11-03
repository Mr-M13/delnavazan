<?php
namespace Delnavazan\Platform\Core\Application\Recording;

/**
 * Keyed, bounded signed envelope shared by the WordPress control plane, recorder service and
 * playback gateway. It is deliberately dependency-free so the same source can be deployed to the
 * OVH service tree and exercised by its tests.
 *
 * Only keyed digests and non-sensitive metadata are signed. A caller must keep the key outside
 * WordPress uploads, the web root and every log.
 */
final class RecordingSignature {
    public const VERSION='dzn-recording-signature-v1';
    public const MAX_LIFETIME=900;
    public const CLOCK_SKEW=60;

    /** Canonical JSON: sorted object keys, no locale-dependent formatting. */
    public static function canonicalJson(array $value):string{
        self::sortKeys($value);
        $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        return $json;
    }

    public static function payloadDigest(array $payload):string{
        return hash('sha256',self::canonicalJson($payload));
    }

    /**
     * @return array{payload:array,meta:array,signature:string}
     */
    public static function sign(array $payload,string $secret,string $keyId,string $audience,int $now,int $ttl=300):array{
        if(strlen($secret)<32)throw new \InvalidArgumentException('recording_signing_key_invalid');
        if(preg_match('/^[A-Za-z0-9._-]{1,64}$/D',$keyId)!==1)throw new \InvalidArgumentException('recording_signing_key_id_invalid');
        if(preg_match('/^[a-z0-9._-]{3,96}$/D',$audience)!==1)throw new \InvalidArgumentException('recording_signing_audience_invalid');
        if($ttl<1||$ttl>self::MAX_LIFETIME)throw new \InvalidArgumentException('recording_signing_ttl_invalid');
        $meta=array(
            'v'=>1,'key_id'=>$keyId,'audience'=>$audience,'issued_at'=>$now,
            'expires_at'=>$now+$ttl,'nonce'=>bin2hex(random_bytes(16)),
            'digest'=>self::payloadDigest($payload),
        );
        $context=self::VERSION.'|'.$keyId.'|'.$audience.'|'.$meta['issued_at'].'|'.$meta['expires_at'].'|'.$meta['nonce'].'|'.$meta['digest'];
        return array('payload'=>$payload,'meta'=>$meta,'signature'=>hash_hmac('sha256',$context,$secret));
    }

    /**
     * @param array{payload?:mixed,meta?:mixed,signature?:mixed} $envelope
     * @return array{payload:array,meta:array}
     */
    public static function verify(array $envelope,string $secret,string $expectedAudience,int $now):array{
        if(strlen($secret)<32)throw new \RuntimeException('recording_signature_unavailable');
        $payload=$envelope['payload']??null;$meta=$envelope['meta']??null;$signature=$envelope['signature']??null;
        if(!is_array($payload)||!is_array($meta)||!is_string($signature)||preg_match('/^[a-f0-9]{64}$/D',$signature)!==1)throw new \RuntimeException('recording_signature_invalid');
        foreach(array('key_id','audience','issued_at','expires_at','nonce','digest') as $key)if(!array_key_exists($key,$meta))throw new \RuntimeException('recording_signature_invalid');
        if((int)($meta['v']??0)!==1)throw new \RuntimeException('recording_signature_version_unsupported');
        if(!hash_equals($expectedAudience,(string)$meta['audience']))throw new \RuntimeException('recording_signature_audience_mismatch');
        if(preg_match('/^[A-Za-z0-9._-]{1,64}$/D',(string)$meta['key_id'])!==1)throw new \RuntimeException('recording_signature_invalid');
        if(preg_match('/^[a-f0-9]{32,128}$/D',(string)$meta['nonce'])!==1)throw new \RuntimeException('recording_signature_nonce_invalid');
        $issued=(int)$meta['issued_at'];$expires=(int)$meta['expires_at'];
        if($issued>$now+self::CLOCK_SKEW)throw new \RuntimeException('recording_signature_not_yet_valid');
        if($expires<$now-self::CLOCK_SKEW)throw new \RuntimeException('recording_signature_expired');
        if($expires<=$issued||$expires-$issued>self::MAX_LIFETIME+self::CLOCK_SKEW)throw new \RuntimeException('recording_signature_lifetime_invalid');
        $digest=self::payloadDigest($payload);
        if(!hash_equals((string)$meta['digest'],$digest))throw new \RuntimeException('recording_signature_payload_mismatch');
        $context=self::VERSION.'|'.$meta['key_id'].'|'.$meta['audience'].'|'.$issued.'|'.$expires.'|'.$meta['nonce'].'|'.$meta['digest'];
        $expected=hash_hmac('sha256',$context,$secret);
        if(!hash_equals($expected,$signature))throw new \RuntimeException('recording_signature_invalid');
        return array('payload'=>$payload,'meta'=>$meta);
    }

    private static function sortKeys(array &$value):void{
        foreach($value as &$item)if(is_array($item))self::sortKeys($item);
        unset($item);
        if(array_is_list($value))return;
        ksort($value,SORT_STRING);
    }
}
