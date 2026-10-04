<?php
namespace Delnavazan\Platform\Integrations\Payment\Stripe;

use Delnavazan\Platform\Core\Application\Checkout\{CheckoutRequest,CheckoutSessionPort};
use Delnavazan\Platform\Core\Application\PaymentExecution\PaymentSecretVault;
use Delnavazan\Platform\Core\Infrastructure\Repository\{PaymentProviderRepository,PaymentSecretRepository};

/**
 * Stripe-hosted Checkout boundary.
 *
 * Outbound Checkout is test-mode only and requires a deliberate non-production activation plus the
 * existing constant-gated disposable secret vault. This adapter cannot load a live key, does not
 * persist provider identifiers or URLs, and cannot settle commercial obligations.
 */
final class StripeCheckoutAdapter implements CheckoutSessionPort {
    public const PROVIDER_KEY='stripe';
    private PaymentProviderRepository $providers;
    private PaymentSecretRepository $secrets;
    private PaymentSecretVault $vault;
    public function __construct(?PaymentProviderRepository $providers=null,?PaymentSecretRepository $secrets=null,?PaymentSecretVault $vault=null){
        $this->providers=$providers??new PaymentProviderRepository();
        $this->secrets=$secrets??new PaymentSecretRepository();
        $this->vault=$vault??new PaymentSecretVault($this->secrets,$this->providers);
    }
    public function key():string{return self::PROVIDER_KEY;}

    public function create(CheckoutRequest $request):array{
        $credentials=$this->testCredentials();
        if($credentials===null)return $this->unavailable('checkout_provider_unconfigured');
        if(!function_exists('wp_remote_post')||!function_exists('is_wp_error')||!function_exists('wp_remote_retrieve_response_code')||!function_exists('wp_remote_retrieve_body'))return $this->unavailable('checkout_provider_unconfigured');
        $success=home_url('/student-portal/?checkout=return&attempt='.rawurlencode($request->attemptUid()));
        $cancel=home_url('/student-portal/?checkout=cancel&attempt='.rawurlencode($request->attemptUid()));
        $response=wp_remote_post('https://api.stripe.com/v1/checkout/sessions',array(
            'timeout'=>15,'redirection'=>0,'sslverify'=>true,
            'headers'=>array(
                'Authorization'=>'Basic '.base64_encode($credentials['api_key'].':'),
                'Idempotency-Key'=>$request->idempotencyKey(),
                'Content-Type'=>'application/x-www-form-urlencoded',
            ),
            'body'=>self::providerFields($request,$success,$cancel,$credentials['account_reference']),
        ));
        if(is_wp_error($response))return $this->unavailable('provider_unavailable');
        $status=(int)wp_remote_retrieve_response_code($response);
        $raw=(string)wp_remote_retrieve_body($response);
        if(strlen($raw)>131072)return $this->unavailable('provider_response_unusable');
        if($status<200||$status>=300){
            if($status>=400&&$status<500&&!in_array($status,array(408,409,425,429),true))return array('state'=>'failed','redirect_url'=>null,'provider_reference'=>null,'expires_at'=>null,'reason_code'=>'provider_request_rejected');
            return $this->unavailable('provider_unavailable');
        }
        $session=json_decode($raw,true);
        if(!is_array($session)||!$this->validSession($session,$request,$credentials['account_reference']))return $this->unavailable('provider_response_unusable');
        return array(
            'state'=>'open','redirect_url'=>(string)$session['url'],'provider_reference'=>(string)$session['id'],
            'expires_at'=>gmdate('Y-m-d H:i:s',(int)$session['expires_at']),'reason_code'=>null,
        );
    }

    /** Exact future Stripe Checkout fields; values come only from the authoritative request. */
    public static function providerFields(CheckoutRequest $request,string $successUrl,string $cancelUrl,string $providerAccountReference):array{
        return array(
            'mode'=>'payment',
            'line_items[0][price_data][currency]'=>strtolower($request->currency()),
            'line_items[0][price_data][unit_amount]'=>$request->amountMinor(),
            'line_items[0][price_data][product_data][name]'=>'Delnavazan tuition',
            'line_items[0][quantity]'=>1,
            'payment_intent_data[metadata][obligation_reference]'=>$request->obligationReference(),
            'payment_intent_data[metadata][checkout_attempt_uid]'=>$request->attemptUid(),
            'payment_intent_data[metadata][provider_account_reference]'=>$providerAccountReference,
            'metadata[obligation_reference]'=>$request->obligationReference(),
            'metadata[checkout_attempt_uid]'=>$request->attemptUid(),
            'metadata[provider_account_reference]'=>$providerAccountReference,
            'client_reference_id'=>$request->attemptUid(),
            'success_url'=>$successUrl,
            'cancel_url'=>$cancelUrl,
        );
    }

    /** Return decrypted credentials only to this adapter and only under explicit test-mode activation. */
    private function testCredentials():?array{
        if(!defined('DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED')||constant('DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED')!==true)return null;
        if(!PaymentSecretVault::testVaultActive())return null;
        $environment=function_exists('wp_get_environment_type')?(string)wp_get_environment_type():'production';
        if(!in_array($environment,array('local','development','staging'),true))return null;
        $accounts=array_values(array_filter($this->providers->accountsForProvider(self::PROVIDER_KEY),static fn($account)=>(string)$account->mode==='test'&&(string)$account->state==='active'&&(string)$account->execution_state==='enabled'&&(string)$account->credential_state==='configured'));
        if(count($accounts)!==1)return null;
        $accountId=(int)$accounts[0]->id;
        $accountReference=(string)($accounts[0]->reference_code??'');
        if(preg_match('/^[A-Za-z0-9_-]{1,32}$/D',$accountReference)!==1)return null;
        $apiSecret=$this->secrets->activeSecret(self::PROVIDER_KEY,'api_key',$accountId,'test');
        $webhookSecret=$this->secrets->activeSecret(self::PROVIDER_KEY,'webhook_signing_secret',$accountId,'test');
        if(!$apiSecret||!$webhookSecret)return null;
        $apiKey=$this->vault->reveal((int)$apiSecret->id,'api_key',self::PROVIDER_KEY);
        $signingSecret=$this->vault->reveal((int)$webhookSecret->id,'webhook_signing_secret',self::PROVIDER_KEY);
        if(!is_string($apiKey)||preg_match('/^sk_test_[A-Za-z0-9]{16,}$/D',$apiKey)!==1)return null;
        if(!is_string($signingSecret)||preg_match('/^whsec_[A-Za-z0-9]{16,}$/D',$signingSecret)!==1)return null;
        return array('api_key'=>$apiKey,'account_reference'=>$accountReference);
    }

    private function validSession(array $session,CheckoutRequest $request,string $providerAccountReference):bool{
        if(($session['object']??'')!=='checkout.session'||($session['mode']??'')!=='payment'||($session['status']??'')!=='open'||($session['livemode']??null)!==false)return false;
        if(!is_string($session['id']??null)||preg_match('/^cs_test_[A-Za-z0-9]+$/D',$session['id'])!==1)return false;
        if(!is_string($session['url']??null)||!$this->safeUrl($session['url']))return false;
        if(!is_int($session['expires_at']??null)||(int)$session['expires_at']<=time()||(int)$session['expires_at']>time()+86460)return false;
        if(!is_int($session['amount_total']??null)||(int)$session['amount_total']!==$request->amountMinor())return false;
        if(strtoupper((string)($session['currency']??''))!==$request->currency())return false;
        $metadata=is_array($session['metadata']??null)?$session['metadata']:array();
        return hash_equals($request->attemptUid(),(string)($metadata['checkout_attempt_uid']??''))
            &&hash_equals($request->obligationReference(),(string)($metadata['obligation_reference']??''))
            &&hash_equals($providerAccountReference,(string)($metadata['provider_account_reference']??''));
    }

    private function safeUrl(string $url):bool{
        $parts=parse_url($url);
        return is_array($parts)&&($parts['scheme']??'')==='https'&&strtolower((string)($parts['host']??''))==='checkout.stripe.com'&&!isset($parts['user'])&&!isset($parts['pass']);
    }

    private function unavailable(string $reason):array{return array('state'=>'unavailable','redirect_url'=>null,'provider_reference'=>null,'expires_at'=>null,'reason_code'=>$reason);}
}
