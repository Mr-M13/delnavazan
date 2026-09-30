<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

use Delnavazan\Platform\Core\Infrastructure\Repository\{PaymentProviderRepository,PaymentSecretRepository};

/**
 * Provider-account, mapping and secret-vault read model (contract §13).
 *
 * Digest-only and secret-free: it reports credential *states*, counts and instants, and never a
 * credential value, a ciphertext, a nonce, a raw reference or a signing secret.
 */
final class PaymentProviderReadService {
    private const VIEW_CAPABILITY='dzn_view_payment_execution_authority';
    public function __construct(
        private ?PaymentProviderRepository $repository=null,
        private ?PaymentSecretRepository $secrets=null
    ){
        $this->repository??=new PaymentProviderRepository();
        $this->secrets??=new PaymentSecretRepository();
    }

    public function accounts():array{
        PaymentExecutionSupport::requireCapability(self::VIEW_CAPABILITY);
        $rows=array();
        foreach($this->repository->allAccounts() as $account){
            PaymentExecutionIntegrity::account($account);
            $rows[]=array('provider_account_id'=>(int)$account->id,'reference_code'=>$account->reference_code,
                'provider_key'=>(string)$account->provider_key,'mode'=>(string)$account->mode,
                'state'=>(string)$account->state,'execution_state'=>(string)$account->execution_state,
                'credential_state'=>(string)$account->credential_state,'credential_key_version'=>$account->credential_key_version,
                'account_version'=>(int)$account->account_version);
        }
        return $rows;
    }
    /** Accounts by state and mode, and by credential state (§13 diagnostics). */
    public function accountStates():array{
        PaymentExecutionSupport::requireCapability(self::VIEW_CAPABILITY);
        $counts=array();
        foreach($this->accounts() as $account){
            $key=$account['state'].'|'.$account['mode'];
            $counts[$key]=($counts[$key]??0)+1;
        }
        return $counts;
    }
    public function objects():array{
        PaymentExecutionSupport::requireCapability(self::VIEW_CAPABILITY);
        $rows=array();
        foreach($this->repository->allObjects() as $object){
            PaymentExecutionIntegrity::mapping($object);
            $rows[]=array('provider_object_id'=>(int)$object->id,'payment_provider_account_id'=>(int)$object->payment_provider_account_id,
                'object_kind'=>(string)$object->object_kind,'canonical_kind'=>(string)$object->canonical_kind,
                'canonical_id'=>(int)$object->canonical_id,'state'=>(string)$object->state,'link_version'=>(int)$object->link_version);
        }
        return $rows;
    }
    /** Refused provider-secret writes and decrypt failures (§13 diagnostics). */
    public function secretDiagnostics():array{
        PaymentExecutionSupport::requireCapability(self::VIEW_CAPABILITY);
        return array('refused_writes'=>$this->secrets->refusedWrites(),'decrypt_failures'=>$this->secrets->decryptFailures());
    }
}
