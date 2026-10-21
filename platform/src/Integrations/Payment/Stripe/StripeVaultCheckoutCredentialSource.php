<?php
namespace Delnavazan\Platform\Integrations\Payment\Stripe;

use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentProviderAccountService,PaymentSecretVault};
use Delnavazan\Platform\Core\Infrastructure\Repository\{PaymentProviderRepository,PaymentSecretRepository};

/** Resolves one deliberately enabled non-production Stripe test account from the existing secret vault. */
final class StripeVaultCheckoutCredentialSource implements StripeCheckoutCredentialSource {
    public function __construct(
        private ?PaymentProviderRepository $providers=null,
        private ?PaymentSecretRepository $secrets=null,
        private ?PaymentSecretVault $vault=null
    ){
        $this->providers??=new PaymentProviderRepository();
        $this->secrets??=new PaymentSecretRepository();
        $this->vault??=new PaymentSecretVault($this->secrets,$this->providers);
    }

    public function credentials():?array{
        if(!StripeCheckoutAdapter::testModeActivationAllowed())return null;
        $accounts=array_values(array_filter($this->providers->accountsForProvider(StripeCheckoutAdapter::PROVIDER_KEY),static fn($account)=>(string)$account->mode==='test'&&(string)$account->state==='active'&&(string)$account->execution_state==='enabled'&&(string)$account->credential_state==='configured'));
        if(count($accounts)!==1)return null;
        $accountId=(int)$accounts[0]->id;
        // One canonical reference: the value that hashes to the account's authority digest. Never
        // send a selector the intake cannot prove against `account_reference_digest`.
        $accountReference=PaymentProviderAccountService::canonicalReference($accounts[0]);
        if($accountReference===null)return null;
        $apiSecret=$this->secrets->activeSecret(StripeCheckoutAdapter::PROVIDER_KEY,'api_key',$accountId,'test');
        $webhookSecret=$this->secrets->activeSecret(StripeCheckoutAdapter::PROVIDER_KEY,'webhook_signing_secret',$accountId,'test');
        if(!$apiSecret||!$webhookSecret)return null;
        $apiKey=$this->vault->reveal((int)$apiSecret->id,'api_key',StripeCheckoutAdapter::PROVIDER_KEY);
        $signingSecret=$this->vault->reveal((int)$webhookSecret->id,'webhook_signing_secret',StripeCheckoutAdapter::PROVIDER_KEY);
        if(!is_string($apiKey)||preg_match('/^sk_test_[A-Za-z0-9]{16,}$/D',$apiKey)!==1)return null;
        if(!is_string($signingSecret)||preg_match('/^whsec_[A-Za-z0-9]{16,}$/D',$signingSecret)!==1)return null;
        return array('api_key'=>$apiKey,'account_reference'=>$accountReference);
    }
}
