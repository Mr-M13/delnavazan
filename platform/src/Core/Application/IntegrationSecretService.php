<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Authenticated encryption at rest for one class of integration secret (Phase 2A.2-V, V-D4).
 *
 * Only this service may decrypt the integration credential class. A secret is sealed with a unique
 * random nonce per row, a stored `key_version`/`cipher_version`, and an envelope that binds the
 * ciphertext to exactly one connection and provider code, so a row moved to another connection does
 * not silently decrypt. A missing key material version, a malformed nonce, a truncated ciphertext or
 * a failed authentication all fail closed and visibly: nothing here ever returns partial plaintext,
 * a decryption oracle message, or a value that could be logged or exported.
 *
 * The application key material is derived from the WordPress secure salts with domain separation for
 * the integration secret class; changing the salts therefore makes existing ciphertext undecryptable
 * instead of silently decrypting it with the wrong key, and a rotation is an explicit, recorded act.
 */
final class IntegrationSecretService {
    public const CIPHER_VERSION='sodium_secretbox_v1';
    public const CIPHER_VERSION_FALLBACK='aes-256-gcm_v1';
    public const KEY_VERSION='dzn_provider_integration_v1';
    public const SALT_DOMAIN='dzn_provider_integration';
    private const NONCE_BYTES=24;
    private const KEY_BYTES=32;

    /** Cipher identity of this build; a stored cipher version must be one this build can open. */
    public function cipherVersion():string{
        return self::sodiumAvailable()?self::CIPHER_VERSION:self::CIPHER_VERSION_FALLBACK;
    }

    public function keyVersion():string{
        return self::KEY_VERSION;
    }

    public function supports(string $keyVersion,string $cipherVersion):bool{
        return $keyVersion===self::KEY_VERSION&&in_array($cipherVersion,array(self::CIPHER_VERSION,self::CIPHER_VERSION_FALLBACK),true);
    }

    /**
     * Seal one renewable credential material for exactly one connection.
     *
     * @return array{key_version:string,cipher_version:string,nonce:string,ciphertext:string}
     */
    public function seal(int $connectionId,string $providerCode,string $material):array{
        if($connectionId<1)throw new \InvalidArgumentException('Integration connection identity required');
        ProviderIntegrationRule::providerCode($providerCode);
        if(trim($material)==='')throw new \InvalidArgumentException('Integration credential material required');
        $envelope=(string)wp_json_encode(array('connection_id'=>$connectionId,'provider_code'=>$providerCode,'material'=>$material),JSON_UNESCAPED_SLASHES);
        $nonce=random_bytes(self::NONCE_BYTES);
        if(self::sodiumAvailable()){
            $ciphertext=sodium_crypto_secretbox($envelope,$nonce,$this->key());
            return array('key_version'=>self::KEY_VERSION,'cipher_version'=>self::CIPHER_VERSION,'nonce'=>base64_encode($nonce),'ciphertext'=>base64_encode($ciphertext));
        }
        $iv=substr($nonce,0,12);$tag='';
        $ciphertext=openssl_encrypt($envelope,'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$iv,$tag);
        if(!is_string($ciphertext))throw new \RuntimeException('Integration credential sealing unavailable');
        return array('key_version'=>self::KEY_VERSION,'cipher_version'=>self::CIPHER_VERSION_FALLBACK,'nonce'=>base64_encode($nonce),'ciphertext'=>base64_encode($ciphertext.$tag));
    }

    /**
     * Open exactly one sealed credential or fail closed.
     *
     * @param object|array $row the credential row as stored
     */
    public function open(int $connectionId,string $providerCode,object|array $row):string{
        $row=(array)$row;
        $keyVersion=(string)($row['key_version']??'');$cipherVersion=(string)($row['cipher_version']??'');
        if(!$this->supports($keyVersion,$cipherVersion))throw new \RuntimeException('Integration credential key material unavailable');
        $nonce=$this->decode((string)($row['nonce']??''));
        $ciphertext=$this->decode((string)($row['ciphertext']??''));
        if($nonce===null||$ciphertext===null||strlen($nonce)!==self::NONCE_BYTES||strlen($ciphertext)<self::NONCE_BYTES+1)throw new \RuntimeException('Integration credential material malformed');
        if($cipherVersion===self::CIPHER_VERSION){
            if(!self::sodiumAvailable())throw new \RuntimeException('Integration credential cipher unavailable');
            $envelope=sodium_crypto_secretbox_open($ciphertext,$nonce,$this->key());
            if(!is_string($envelope))throw new \RuntimeException('Integration credential failed authentication');
        }else{
            $tag=substr($ciphertext,-16);$body=substr($ciphertext,0,-16);
            $envelope=openssl_decrypt($body,'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,substr($nonce,0,12),$tag);
            if(!is_string($envelope))throw new \RuntimeException('Integration credential failed authentication');
        }
        $decoded=json_decode($envelope,true);
        if(!is_array($decoded)
            ||(int)($decoded['connection_id']??0)!==$connectionId
            ||(string)($decoded['provider_code']??'')!==$providerCode
            ||!is_string($decoded['material']??null)
            ||$decoded['material']==='')throw new \RuntimeException('Integration credential binding mismatch');
        return (string)$decoded['material'];
    }

    /** A sealed credential is never rendered; only its shape is ever reportable. */
    public static function redacted(object|array $row):array{
        $row=(array)$row;
        return array(
            'credential_id'=>(int)($row['id']??0),
            'connection_id'=>(int)($row['connection_id']??0),
            'state'=>(string)($row['state']??''),
            'key_version'=>(string)($row['key_version']??''),
            'cipher_version'=>(string)($row['cipher_version']??''),
            'sealed'=>(string)($row['ciphertext']??'')!==''&&(string)($row['nonce']??'')!=='',
        );
    }

    /** Rotation re-seals with the current key version; the caller decides when a re-authorisation is required. */
    public function rotate(int $connectionId,string $providerCode,object|array $row):array{
        return $this->seal($connectionId,$providerCode,$this->open($connectionId,$providerCode,$row));
    }

    private function decode(string $value):?string{
        if($value==='')return null;
        $decoded=base64_decode($value,true);
        return is_string($decoded)?$decoded:null;
    }

    private function key():string{
        $material=wp_salt(self::SALT_DOMAIN);
        if(!is_string($material)||strlen($material)<16)throw new \RuntimeException('Integration secret key material unavailable');
        return substr(hash_hmac('sha256','integration_credential:'.self::KEY_VERSION,$material,true),0,self::KEY_BYTES);
    }

    private static function sodiumAvailable():bool{
        return function_exists('sodium_crypto_secretbox')&&function_exists('sodium_crypto_secretbox_open');
    }
}
