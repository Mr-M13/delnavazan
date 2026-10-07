<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

use Delnavazan\Platform\Core\Infrastructure\Repository\{PaymentProviderRepository,PaymentSecretRepository};

/** Production may store Stripe sandbox credentials, while the checkout adapter keeps its non-production gate. */
final class StripeTestConfigurationService {
    private const PUBLISHABLE_OPTION='dzn_stripe_test_publishable_key';
    private PaymentProviderRepository $providers;
    private PaymentSecretRepository $secrets;
    private PaymentSecretVault $vault;

    public function __construct(?PaymentProviderRepository $providers=null,?PaymentSecretRepository $secrets=null,?PaymentSecretVault $vault=null){
        $this->providers=$providers??new PaymentProviderRepository();$this->secrets=$secrets??new PaymentSecretRepository();
        $this->vault=$vault??new PaymentSecretVault($this->secrets,$this->providers);
    }

    public function save(array $input):array{
        PaymentExecutionSupport::requireCapability('dzn_manage_payment_providers');
        $publishable=trim((string)($input['publishable_key']??''));$apiKey=trim((string)($input['secret_key']??''));$webhook=trim((string)($input['webhook_signing_secret']??''));
        if($publishable!==''&&preg_match('/^pk_test_[A-Za-z0-9]{16,}$/D',$publishable)!==1)throw new \InvalidArgumentException('invalid_stripe_test_publishable_key');
        if($apiKey!==''&&preg_match('/^sk_test_[A-Za-z0-9]{16,}$/D',$apiKey)!==1)throw new \InvalidArgumentException('invalid_stripe_test_secret_key');
        if($webhook!==''&&preg_match('/^whsec_[A-Za-z0-9]{16,}$/D',$webhook)!==1)throw new \InvalidArgumentException('invalid_stripe_test_webhook_secret');
        $accounts=$this->providers->accountsForProvider('stripe');
        $test=array_values(array_filter($accounts,static fn($a)=>(string)$a->mode==='test'&&(string)$a->state==='active'));
        if(count($test)>1||count($accounts)!==count($test))throw new \RuntimeException('stripe_account_scope_ambiguous');
        if(!$test){
            if($publishable===''||$apiKey===''||$webhook==='')throw new \InvalidArgumentException('complete_stripe_test_configuration_required');
            $created=(new PaymentProviderAccountService($this->providers))->register(array(
                'provider_key'=>'stripe','mode'=>'test','reference_code'=>'stripe_test','account_reference'=>'delnavazan-stripe-test',
                'execution_state'=>'disabled','credential_state'=>'configured','evidence_channel'=>'staff_record',
                'evidence_reference'=>'stripe_test_settings','evidence_at'=>gmdate('Y-m-d H:i:s'),
            ),'stripe-settings-account-'.wp_generate_uuid4());
            $accountId=(int)$created['provider_account_id'];
        }else{
            if((string)$test[0]->credential_state!=='configured')throw new \RuntimeException('stripe_test_account_state_unavailable');
            $accountId=(int)$test[0]->id;
        }
        $nonce=wp_generate_uuid4();$stored=[];
        foreach(array('api_key'=>$apiKey,'webhook_signing_secret'=>$webhook) as $class=>$value){
            if($value==='')continue;
            $result=$this->vault->storeStripeTestConfiguration($accountId,$class,$value,array('nonce'=>$nonce),'stripe-settings-'.$class.'-'.wp_generate_uuid4());
            if(empty($result['stored']))throw new \RuntimeException('stripe_test_secret_store_refused');
            $stored[]=$class;
        }
        if($publishable!=='')update_option(self::PUBLISHABLE_OPTION,$publishable,false);
        return array('account_id'=>$accountId,'stored'=>$stored,'publishable_key_saved'=>$publishable!=='');
    }

    /** A bounded authenticated read of Stripe's sandbox account; provider text and credentials are never surfaced. */
    public function testConnection():array{
        PaymentExecutionSupport::requireCapability('dzn_manage_payment_providers');
        $accounts=array_values(array_filter($this->providers->accountsForProvider('stripe'),static fn($a)=>(string)$a->mode==='test'&&(string)$a->state==='active'));
        if(count($accounts)!==1)return array('state'=>'blocked','code'=>'test_account_unavailable');
        $secret=$this->secrets->activeSecret('stripe','api_key',(int)$accounts[0]->id,'test');
        if(!$secret)return array('state'=>'blocked','code'=>'test_secret_unconfigured');
        $key=$this->vault->revealForStripeTestConnection((int)$secret->id);
        if(!is_string($key)||preg_match('/^sk_test_[A-Za-z0-9]{16,}$/D',$key)!==1)return array('state'=>'blocked','code'=>'test_secret_unavailable');
        if(!function_exists('wp_remote_get'))return array('state'=>'blocked','code'=>'http_unavailable');
        $response=wp_remote_get('https://api.stripe.com/v1/account',array('timeout'=>15,'redirection'=>0,'sslverify'=>true,'headers'=>array('Authorization'=>'Bearer '.$key,'Accept'=>'application/json')));
        unset($key);
        if(is_wp_error($response))return array('state'=>'failed','code'=>'stripe_unreachable');
        $status=(int)wp_remote_retrieve_response_code($response);$body=json_decode((string)wp_remote_retrieve_body($response),true);
        if($status!==200||!is_array($body)||!array_key_exists('livemode',$body)||$body['livemode']!==false)return array('state'=>'failed','code'=>'stripe_test_authentication_failed');
        return array('state'=>'success','code'=>'stripe_test_account_reachable');
    }

    public function publishableKey():string{return (string)get_option(self::PUBLISHABLE_OPTION,'');}
    public function savedState():array{
        $accounts=array_values(array_filter($this->providers->accountsForProvider('stripe'),static fn($a)=>(string)$a->mode==='test'&&(string)$a->state==='active'));
        if(count($accounts)!==1)return array('account_id'=>0,'publishable'=>false,'secret'=>false,'webhook'=>false);
        $id=(int)$accounts[0]->id;
        return array('account_id'=>$id,'publishable'=>$this->publishableKey()!=='',
            'secret'=>$this->secrets->activeSecret('stripe','api_key',$id,'test')!==null,
            'webhook'=>$this->secrets->activeSecret('stripe','webhook_signing_secret',$id,'test')!==null);
    }
}
