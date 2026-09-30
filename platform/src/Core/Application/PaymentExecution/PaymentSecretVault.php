<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

use Delnavazan\Platform\Core\Infrastructure\Repository\{PaymentProviderRepository,PaymentSecretRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Provider-secret isolation boundary (contract §11).
 *
 * Signing secrets and API keys exist only as authenticated ciphertext under a domain-separated key;
 * only the adapter registered for the provider may decrypt one, and nothing else may read, log, export
 * or expose a value. This build ships the vault, the encryption, the rotation/retire lifecycle, the
 * audit and the fail-closed behaviour — and **no path that can write a Stripe credential**:
 * `PROVISIONABLE_PROVIDERS` is empty, so every write path refuses, before any capability check, with
 * `provider_secret_write_not_authorised` and an auditable `write_refused` row. The only storage path
 * that exists is the constant-gated disposable test vault.
 */
final class PaymentSecretVault {
    private const CAPABILITY='dzn_manage_payment_providers';
    /** The test-only constant gate: the override is unresolvable while the constant is undefined. */
    public const TEST_VAULT_CONSTANT='DZN_PLATFORM_PAYMENT_TEST_VAULT';
    private const KEY_VERSION='v1';

    public function __construct(
        private ?PaymentSecretRepository $repository=null,
        private ?PaymentProviderRepository $providers=null
    ){
        $this->repository??=new PaymentSecretRepository();
        $this->providers??=new PaymentProviderRepository();
    }

    /** Whether the constant-gated disposable test vault is active. Compile-time, never a setting. */
    public static function testVaultActive():bool{
        return defined(self::TEST_VAULT_CONSTANT)&&constant(self::TEST_VAULT_CONSTANT)===true;
    }
    public static function cipherVersion():string{return PaymentExecutionRule::CIPHER;}
    public static function keyVersion():string{return self::KEY_VERSION;}
    public static function supportedKeyVersion(string $keyVersion):bool{return $keyVersion===self::KEY_VERSION;}

    /** Store one secret. Returns a classified result; a refusal is durable and auditable, never silent. */
    public function store(string $providerKey,int $accountId,string $secretClass,string $mode,string $value,array $input,string $key):array{
        return $this->write('stored',$providerKey,$accountId,$secretClass,$mode,$value,$input,$key);
    }
    public function rotate(string $providerKey,int $accountId,string $secretClass,string $mode,string $value,array $input,string $key):array{
        return $this->write('rotated',$providerKey,$accountId,$secretClass,$mode,$value,$input,$key);
    }
    public function retire(string $providerKey,int $accountId,string $secretClass,string $mode,array $input,string $key):array{
        return $this->retireOrRevoke('retired',$providerKey,$accountId,$secretClass,$mode,$input,$key);
    }
    public function revoke(string $providerKey,int $accountId,string $secretClass,string $mode,array $input,string $key):array{
        return $this->retireOrRevoke('revoked',$providerKey,$accountId,$secretClass,$mode,$input,$key);
    }

    /**
     * Reveal one secret to the adapter that owns the provider key.
     *
     * Returns null and records a `decrypt_failed` audit row on any failure — an unknown cipher or key
     * version, a missing salt, an unavailable sodium implementation, an authentication failure or a
     * scope mismatch. It never returns a partial, guessed or re-derived value.
     */
    public function reveal(int $secretId,string $secretClass,string $adapterScope):?string{
        $row=$this->repository->secret($secretId);
        if(!$row||(string)$row->secret_class!==$secretClass||(string)$row->provider_key!==$adapterScope||(int)$row->active_slot!==1){
            $this->audit('decrypt_failed',(string)($row->provider_key??$adapterScope),$secretClass,(int)($row->payment_provider_account_id??0),(string)($row->mode??''),'secret_scope_mismatch',(string)($row->key_version??self::KEY_VERSION),null,0);
            return null;
        }
        if(!self::testVaultActive()&&!PaymentExecutionRule::provisionableProvider((string)$row->provider_key)){
            $this->audit('decrypt_failed',(string)$row->provider_key,$secretClass,(int)$row->payment_provider_account_id,(string)$row->mode,'provider_secret_write_not_authorised',(string)$row->key_version,null,0);
            return null;
        }
        if((string)$row->cipher_version!==PaymentExecutionRule::CIPHER){$this->audit('decrypt_failed',(string)$row->provider_key,$secretClass,(int)$row->payment_provider_account_id,(string)$row->mode,'unknown_cipher_version',(string)$row->key_version,null,0);return null;}
        if(!self::supportedKeyVersion((string)$row->key_version)){$this->audit('decrypt_failed',(string)$row->provider_key,$secretClass,(int)$row->payment_provider_account_id,(string)$row->mode,'unknown_key_version',(string)$row->key_version,null,0);return null;}
        if(!function_exists('sodium_crypto_secretbox_open')){$this->audit('decrypt_failed',(string)$row->provider_key,$secretClass,(int)$row->payment_provider_account_id,(string)$row->mode,'payment_secret_unavailable',(string)$row->key_version,null,0);return null;}
        $raw=base64_decode((string)$row->ciphertext,true);
        $nonce=@hex2bin((string)$row->nonce);
        if(!is_string($raw)||!is_string($nonce)||strlen($nonce)!==SODIUM_CRYPTO_SECRETBOX_NONCEBYTES){
            $this->audit('decrypt_failed',(string)$row->provider_key,$secretClass,(int)$row->payment_provider_account_id,(string)$row->mode,'payment_secret_unavailable',(string)$row->key_version,null,0);
            return null;
        }
        $keyMaterial=PaymentExecutionIdempotency::vaultKey($secretClass,(string)$row->key_version);
        $plaintext=@sodium_crypto_secretbox_open($raw,$nonce,$keyMaterial);
        if(!is_string($plaintext)){
            $this->audit('decrypt_failed',(string)$row->provider_key,$secretClass,(int)$row->payment_provider_account_id,(string)$row->mode,'payment_secret_unavailable',(string)$row->key_version,null,0);
            return null;
        }
        return $plaintext;
    }

    private function write(string $auditType,string $providerKey,int $accountId,string $secretClass,string $mode,string $value,array $input,string $key):array{
        $providerKey=strtolower(trim($providerKey));
        $accountId=PaymentExecutionSupport::positiveInt($accountId,'Valid provider account required');
        if(trim($value)==='')throw new \InvalidArgumentException('Provider secret value required');
        $secretClass=$this->secretClass($secretClass);
        $mode=$this->mode($mode);
        // §11.3 [C2-5]: the constant gate and the capability are necessary but never sufficient, and
        // the refusal happens first, before any capability check, nonce or scope resolution.
        if(!self::testVaultActive()&&!PaymentExecutionRule::provisionableProvider($providerKey)){
            $audit=$this->audit('write_refused',$providerKey,$secretClass,0,$mode,'provider_secret_write_not_authorised',self::KEY_VERSION,null,0);
            return array('stored'=>false,'reason_code'=>'provider_secret_write_not_authorised','audit_entry_id'=>$audit,'secret_id'=>null);
        }
        PaymentExecutionSupport::requireCapability(self::CAPABILITY);
        $actor=PaymentExecutionSupport::actor();
        $this->requireNonce($input);
        $command=$this->keyString($key);
        if(!function_exists('sodium_crypto_secretbox')||!function_exists('random_bytes'))throw new \RuntimeException('provider_secret_write_not_authorised');
        $this->repository->begin();
        try{
            $account=$this->providers->account($accountId,true);
            if(!$account)throw new \InvalidArgumentException('payment_provider_account_required');
            PaymentExecutionIntegrity::account($account);
            if((string)$account->provider_key!==$providerKey||(string)$account->mode!==$mode)throw new \InvalidArgumentException('secret_scope_mismatch');
            if((string)$account->state==='closed')throw new \InvalidArgumentException('payment_provider_account_inactive');
            $active=$this->repository->activeSecret($providerKey,$secretClass,$accountId,$mode,true);
            $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $keyMaterial=PaymentExecutionIdempotency::vaultKey($secretClass,self::KEY_VERSION);
            $ciphertext=base64_encode(sodium_crypto_secretbox($value,$nonce,$keyMaterial));
            $now=PaymentExecutionSupport::now();
            if($active)$this->repository->updateSecret((int)$active->id,array('state'=>'retired','active_slot'=>null,'retired_at'=>$now,'retired_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor),array('active_slot'=>1));
            $secretId=$this->repository->insertSecret(array(
                'uid'=>Identifier::uid(),'provider_key'=>$providerKey,'payment_provider_account_id'=>$accountId,
                'secret_class'=>$secretClass,'mode'=>$mode,'cipher_version'=>PaymentExecutionRule::CIPHER,
                'key_version'=>self::KEY_VERSION,'nonce'=>bin2hex($nonce),'ciphertext'=>$ciphertext,
                'state'=>'active','active_slot'=>1,'secret_version'=>$this->repository->maxSecretVersion($providerKey,$secretClass,$accountId,$mode)+1,
                'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,'retired_at'=>null,'retired_by'=>null,
            ));
            $audit=$this->audit($auditType,$providerKey,$secretClass,$accountId,$mode,self::testVaultActive()?'test_vault_override_active':'stored',self::KEY_VERSION,$command,$actor);
            $this->repository->commit();
            return array('stored'=>true,'reason_code'=>$auditType,'audit_entry_id'=>$audit,'secret_id'=>$secretId,'key_version'=>self::KEY_VERSION);
        }catch(\Throwable $e){
            $this->repository->rollback();
            throw $e;
        }
    }

    private function retireOrRevoke(string $auditType,string $providerKey,int $accountId,string $secretClass,string $mode,array $input,string $key):array{
        $providerKey=strtolower(trim($providerKey));
        $accountId=PaymentExecutionSupport::positiveInt($accountId,'Valid provider account required');
        $secretClass=$this->secretClass($secretClass);
        $mode=$this->mode($mode);
        if(!self::testVaultActive()&&!PaymentExecutionRule::provisionableProvider($providerKey)){
            $audit=$this->audit('write_refused',$providerKey,$secretClass,0,$mode,'provider_secret_write_not_authorised',self::KEY_VERSION,null,0);
            return array('retired'=>false,'reason_code'=>'provider_secret_write_not_authorised','audit_entry_id'=>$audit);
        }
        PaymentExecutionSupport::requireCapability(self::CAPABILITY);
        $actor=PaymentExecutionSupport::actor();
        $this->requireNonce($input);
        $command=$this->keyString($key);
        $this->repository->begin();
        try{
            $active=$this->repository->activeSecret($providerKey,$secretClass,$accountId,$mode,true);
            if(!$active){$this->repository->rollback();return array('retired'=>false,'reason_code'=>'secret_scope_mismatch','audit_entry_id'=>null);}
            $now=PaymentExecutionSupport::now();
            $this->repository->updateSecret((int)$active->id,array('state'=>$auditType,'active_slot'=>null,'retired_at'=>$now,'retired_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor),array('active_slot'=>1));
            $audit=$this->audit($auditType,$providerKey,$secretClass,$accountId,$mode,$auditType,self::KEY_VERSION,$command,$actor);
            $this->repository->commit();
            return array('retired'=>true,'reason_code'=>$auditType,'audit_entry_id'=>$audit);
        }catch(\Throwable $e){
            $this->repository->rollback();
            throw $e;
        }
    }

    private function audit(string $auditType,string $providerKey,string $secretClass,int $accountId,string $mode,string $reason,string $keyVersion,?string $commandKeyDigest,int $actor):int{
        if(!PaymentExecutionRule::member($auditType,PaymentExecutionRule::SECRET_AUDIT_TYPES))throw new \InvalidArgumentException('Controlled secret audit type required');
        $now=PaymentExecutionSupport::now();
        return $this->repository->insertAuditEvent(array(
            'uid'=>Identifier::uid(),'provider_key'=>$providerKey,
            'payment_provider_account_id'=>$accountId>0?$accountId:null,
            'secret_class'=>$secretClass,'mode'=>$mode===''?null:$mode,'audit_type'=>$auditType,
            'key_version'=>$keyVersion===''?null:$keyVersion,'command_key_digest'=>$commandKeyDigest,
            'reason_code'=>$reason,'occurred_at'=>$now,'recorded_at'=>$now,
            'recorded_by'=>$actor>0?$actor:0,'created_at'=>$now,'created_by'=>$actor>0?$actor:0,
        ));
    }
    private function requireNonce(array $input):string{
        $nonce=trim((string)($input['nonce']??''));
        if(strlen($nonce)<8||preg_match('/^[A-Za-z0-9_-]+$/D',$nonce)!==1)throw new \InvalidArgumentException('Secret write intent nonce required');
        return $nonce;
    }
    private function secretClass(string $secretClass):string{
        if(!PaymentExecutionRule::member($secretClass,PaymentExecutionRule::SECRET_CLASSES))throw new \InvalidArgumentException('Controlled secret class required');
        return $secretClass;
    }
    private function mode(string $mode):string{
        $mode=PaymentExecutionRule::mode($mode);
        if($mode===null)throw new \InvalidArgumentException('Controlled account mode required');
        return $mode;
    }
    private function keyString(string $key):string{return PaymentExecutionIdempotency::key($key);}
}
