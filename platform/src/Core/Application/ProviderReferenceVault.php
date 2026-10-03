<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Authenticated encryption for provider references that must later be rendered or dereferenced.
 * Digest columns remain the identity/idempotency authority; this vault preserves only the minimum
 * reversible reference needed for an authorised runtime action such as joining a Meet space.
 */
final class ProviderReferenceVault {
    private const DOMAIN='dzn_provider_reference_v1';
    private const KEY_VERSION='dzn_provider_reference_v1';
    private const CIPHER='sodium_secretbox_v1';

    public function seal(string $kind,int $mappingId,array $references):array{
        $this->binding($kind,$mappingId);
        if(!function_exists('sodium_crypto_secretbox'))throw new \RuntimeException('provider_reference_cipher_unavailable');
        $clean=array();
        foreach($references as $name=>$value){
            if(!in_array($name,array('provider_object_reference','join_uri_reference'),true))continue;
            $value=trim((string)$value);
            if($value!=='')$clean[$name]=$value;
        }
        if(empty($clean))throw new \InvalidArgumentException('provider_reference_required');
        $plain=wp_json_encode(array('kind'=>$kind,'mapping_id'=>$mappingId,'references'=>$clean),JSON_UNESCAPED_SLASHES);
        if(!is_string($plain))throw new \RuntimeException('provider_reference_encode_failed');
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return array('key_version'=>self::KEY_VERSION,'cipher_version'=>self::CIPHER,'nonce'=>base64_encode($nonce),'ciphertext'=>base64_encode(sodium_crypto_secretbox($plain,$nonce,$this->key())));
    }

    public function open(string $kind,int $mappingId,object|array $row):array{
        $this->binding($kind,$mappingId);$row=(array)$row;
        if(!function_exists('sodium_crypto_secretbox_open'))throw new \RuntimeException('provider_reference_cipher_unavailable');
        if((string)($row['key_version']??'')!==self::KEY_VERSION||(string)($row['cipher_version']??'')!==self::CIPHER)throw new \RuntimeException('provider_reference_key_unavailable');
        $nonce=base64_decode((string)($row['nonce']??''),true);$cipher=base64_decode((string)($row['ciphertext']??''),true);
        if(!is_string($nonce)||!is_string($cipher)||strlen($nonce)!==SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new \RuntimeException('provider_reference_material_malformed');
        $plain=sodium_crypto_secretbox_open($cipher,$nonce,$this->key());
        if(!is_string($plain))throw new \RuntimeException('provider_reference_authentication_failed');
        $decoded=json_decode($plain,true);
        if(!is_array($decoded)||(string)($decoded['kind']??'')!==$kind||(int)($decoded['mapping_id']??0)!==$mappingId||!is_array($decoded['references']??null))throw new \RuntimeException('provider_reference_binding_mismatch');
        return $decoded['references'];
    }

    private function binding(string $kind,int $mappingId):void{
        if(!in_array($kind,array('calendar_event','meeting_conference'),true)||$mappingId<1)throw new \InvalidArgumentException('provider_reference_binding_required');
    }
    private function key():string{
        $salt=wp_salt(self::DOMAIN);
        if(!is_string($salt)||strlen($salt)<16)throw new \RuntimeException('provider_reference_key_unavailable');
        return substr(hash_hmac('sha256',self::KEY_VERSION,$salt,true),0,SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
