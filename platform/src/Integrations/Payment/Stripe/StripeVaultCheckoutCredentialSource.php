<?php
namespace Delnavazan\Platform\Integrations\Payment\Stripe;

use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentProviderAccountService,PaymentSecretVault,StripeProviderMode};
use Delnavazan\Platform\Core\Infrastructure\Repository\{PaymentProviderRepository,PaymentSecretRepository};

/**
 * Resolves one deliberately enabled Stripe account from the existing encrypted secret vault.
 *
 * Live credentials are only returned for a live account whose confirmed activation is recorded; test
 * credentials retain their independent activation gates. A historical Checkout Session may request its
 * own mode explicitly so reconciliation never borrows the other mode's key.
 */
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

    /** Live activation takes precedence; otherwise the existing test activation is used. */
    public function activeMode():?string{
        if($this->modeAvailable(StripeProviderMode::LIVE))return StripeProviderMode::LIVE;
        if($this->modeAvailable(StripeProviderMode::TEST))return StripeProviderMode::TEST;
        return null;
    }

    public function credentials():?array{
        $mode=$this->activeMode();
        return $mode===null?null:$this->credentialsForMode($mode);
    }

    /** @return array{api_key:string,account_reference:string,mode:string}|null */
    public function credentialsForMode(string $mode):?array{
        if(!$this->modeAvailable($mode))return null;
        $accounts=$this->accountsForMode($mode);
        $accountId=(int)$accounts[0]->id;
        $accountReference=PaymentProviderAccountService::canonicalReference($accounts[0]);
        if($accountReference===null)return null;
        $apiSecret=$this->secrets->activeSecret(StripeCheckoutAdapter::PROVIDER_KEY,'api_key',$accountId,$mode);
        $webhookSecret=$this->secrets->activeSecret(StripeCheckoutAdapter::PROVIDER_KEY,'webhook_signing_secret',$accountId,$mode);
        if(!$apiSecret||!$webhookSecret)return null;
        $apiKey=$this->vault->reveal((int)$apiSecret->id,'api_key',StripeCheckoutAdapter::PROVIDER_KEY);
        $signingSecret=$this->vault->reveal((int)$webhookSecret->id,'webhook_signing_secret',StripeCheckoutAdapter::PROVIDER_KEY);
        if(!is_string($apiKey)||preg_match(StripeProviderMode::apiKeyPattern($mode),$apiKey)!==1)return null;
        if(!is_string($signingSecret)||preg_match(StripeProviderMode::webhookSecretPattern(),$signingSecret)!==1)return null;
        return array('api_key'=>$apiKey,'account_reference'=>$accountReference,'mode'=>$mode);
    }

    /** Presence-only availability check; it never decrypts a stored secret. */
    private function modeAvailable(string $mode):bool{
        if(!StripeProviderMode::member($mode))return false;
        $allowed=$mode===StripeProviderMode::LIVE
            ?StripeCheckoutAdapter::liveModeActivationAllowed()
            :StripeCheckoutAdapter::testModeActivationAllowed();
        if(!$allowed)return false;
        $accounts=$this->accountsForMode($mode);
        if(count($accounts)!==1)return false;
        if(PaymentProviderAccountService::canonicalReference($accounts[0])===null)return false;
        if($mode===StripeProviderMode::LIVE){
            $activation=StripeProviderMode::activation();
            if($activation['status']!==StripeProviderMode::ACTIVATION_ACTIVE||$activation['account_id']!==(int)$accounts[0]->id)return false;
        }
        $accountId=(int)$accounts[0]->id;
        return $this->secrets->activeSecret(StripeCheckoutAdapter::PROVIDER_KEY,'api_key',$accountId,$mode)!==null
            &&$this->secrets->activeSecret(StripeCheckoutAdapter::PROVIDER_KEY,'webhook_signing_secret',$accountId,$mode)!==null;
    }

    /** @return list<object> */
    private function accountsForMode(string $mode):array{
        return array_values(array_filter($this->providers->accountsForProvider(StripeCheckoutAdapter::PROVIDER_KEY),static fn($account)=>(string)$account->mode===$mode&&(string)$account->state==='active'&&(string)$account->execution_state==='enabled'&&(string)$account->credential_state==='configured'));
    }
}
