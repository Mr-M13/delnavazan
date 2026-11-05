<?php
namespace Delnavazan\Platform\Integrations\Payment\Stripe;

use Delnavazan\Platform\Core\Application\Checkout\{CheckoutRequest,CheckoutSessionPort};
use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentSecretVault,StripeProviderMode};
use Closure;

/**
 * Stripe-hosted Checkout boundary.
 *
 * Outbound Checkout uses the mode selected by the encrypted credential source: the existing test
 * activation, or a live account whose confirmed live activation is recorded after readiness passes.
 * It is mode-scoped, cannot borrow the other mode's key, does not persist provider identifiers or
 * URLs, and cannot settle commercial obligations.
 */
final class StripeCheckoutAdapter implements CheckoutSessionPort {
    public const PROVIDER_KEY='stripe';
    private StripeCheckoutCredentialSource $credentialSource;
    private ?Closure $http;
    public function __construct(?StripeCheckoutCredentialSource $credentialSource=null,?Closure $http=null){
        $this->credentialSource=$credentialSource??new StripeVaultCheckoutCredentialSource();
        $this->http=$http;
    }
    public function key():string{return self::PROVIDER_KEY;}

    /** The mode the active credential source would use for a new Checkout Session. */
    public function activeMode():?string{
        if(method_exists($this->credentialSource,'activeMode')){
            $mode=$this->credentialSource->activeMode();
            return is_string($mode)&&StripeProviderMode::member($mode)?$mode:null;
        }
        $credentials=$this->credentialSource->credentials();
        if(!is_array($credentials))return null;
        $mode=(string)($credentials['mode']??StripeProviderMode::TEST);
        return StripeProviderMode::member($mode)?$mode:null;
    }

    public function create(CheckoutRequest $request):array{
        $mode=$this->activeMode();
        if($mode===null||!self::activationAllowed($mode))return $this->unavailable('checkout_provider_unconfigured');
        $credentials=$this->credentialsForMode($mode);
        if(!is_array($credentials)||!is_string($credentials['api_key']??null)||!is_string($credentials['account_reference']??null))return $this->unavailable('checkout_provider_unconfigured');
        if(preg_match(StripeProviderMode::apiKeyPattern($mode),$credentials['api_key'])!==1||preg_match('/^[A-Za-z0-9_-]{1,32}$/D',$credentials['account_reference'])!==1)return $this->unavailable('checkout_provider_unconfigured');
        if($this->http===null&&(!function_exists('wp_remote_post')||!function_exists('is_wp_error')||!function_exists('wp_remote_retrieve_response_code')||!function_exists('wp_remote_retrieve_body')))return $this->unavailable('checkout_provider_unconfigured');
        $success=home_url('/student-portal/?portal-view=account&checkout=return&attempt='.rawurlencode($request->attemptUid()));
        $cancel=home_url('/student-portal/?portal-view=account&checkout=cancel&attempt='.rawurlencode($request->attemptUid()));
        $url='https://api.stripe.com/v1/checkout/sessions';
        $options=array(
            'timeout'=>15,'redirection'=>0,'sslverify'=>true,
            'headers'=>array(
                'Authorization'=>'Basic '.base64_encode($credentials['api_key'].':'),
                'Idempotency-Key'=>$request->idempotencyKey(),
                'Content-Type'=>'application/x-www-form-urlencoded',
            ),
            'body'=>self::providerFields($request,$success,$cancel,$credentials['account_reference']),
        );
        $response=$this->http!==null?($this->http)($url,$options):wp_remote_post($url,$options);
        if(is_wp_error($response))return $this->unavailable('provider_unavailable');
        $status=(int)wp_remote_retrieve_response_code($response);
        $raw=(string)wp_remote_retrieve_body($response);
        if(strlen($raw)>131072)return $this->unavailable('provider_response_unusable');
        if($status<200||$status>=300){
            if($status>=400&&$status<500&&!in_array($status,array(408,409,425,429),true))return array('state'=>'failed','redirect_url'=>null,'provider_reference'=>null,'expires_at'=>null,'reason_code'=>'provider_request_rejected');
            return $this->unavailable('provider_unavailable');
        }
        $session=json_decode($raw,true);
        if(!is_array($session)||!$this->validSession($session,$request,$credentials['account_reference'],$mode))return $this->unavailable('provider_response_unusable');
        return array(
            'state'=>'open','mode'=>$mode,'redirect_url'=>(string)$session['url'],'provider_reference'=>(string)$session['id'],
            'expires_at'=>gmdate('Y-m-d H:i:s',(int)$session['expires_at']),'reason_code'=>null,
        );
    }

    /** Retrieve only one exact locally referenced Checkout Session in its own recorded mode. */
    public function retrieve(string $providerReference):array{
        $mode=StripeProviderMode::modeFromCheckoutReference($providerReference);
        if($mode===null)return $this->retrievalUnavailable('provider_reference_invalid');
        if(!self::activationAllowed($mode))return $this->retrievalUnavailable('checkout_provider_unconfigured');
        $credentials=$this->credentialsForMode($mode);
        if(!is_array($credentials)||!is_string($credentials['api_key']??null)||!is_string($credentials['account_reference']??null)
            ||preg_match(StripeProviderMode::apiKeyPattern($mode),$credentials['api_key'])!==1
            ||preg_match('/^[A-Za-z0-9_-]{1,32}$/D',$credentials['account_reference'])!==1)return $this->retrievalUnavailable('checkout_provider_unconfigured');
        if($this->http===null&&(!function_exists('wp_remote_get')||!function_exists('is_wp_error')||!function_exists('wp_remote_retrieve_response_code')||!function_exists('wp_remote_retrieve_body')))return $this->retrievalUnavailable('checkout_provider_unconfigured');
        $url='https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($providerReference);
        $options=array('timeout'=>15,'redirection'=>0,'sslverify'=>true,'headers'=>array('Authorization'=>'Basic '.base64_encode($credentials['api_key'].':')));
        $response=$this->http!==null?($this->http)($url,$options):wp_remote_get($url,$options);
        if(is_wp_error($response))return $this->retrievalUnavailable('provider_unavailable');
        $status=(int)wp_remote_retrieve_response_code($response);$raw=(string)wp_remote_retrieve_body($response);
        if(strlen($raw)>131072)return $this->retrievalUnavailable('provider_response_unusable');
        if($status===404)return array('state'=>'not_found','payment_state'=>null,'provider_reference'=>$providerReference,'amount_minor'=>null,'currency'=>null,'obligation_reference'=>null,'attempt_uid'=>null,'account_reference'=>null,'reason_code'=>'provider_session_not_found');
        if($status<200||$status>=300)return $this->retrievalUnavailable($status>=400&&$status<500?'provider_request_rejected':'provider_unavailable');
        $session=json_decode($raw,true);
        if(!is_array($session)||!$this->validRetrievedSession($session,$providerReference,$credentials['account_reference'],$mode))return $this->retrievalUnavailable('provider_response_unusable');
        $metadata=$session['metadata'];
        return array('state'=>(string)$session['status'],'mode'=>$mode,'payment_state'=>(string)$session['payment_status'],
            'provider_reference'=>(string)$session['id'],'amount_minor'=>(int)$session['amount_total'],
            'session_created_at'=>(int)$session['created'],
            'currency'=>strtoupper((string)$session['currency']),'obligation_reference'=>(string)$metadata['obligation_reference'],
            'attempt_uid'=>(string)$metadata['checkout_attempt_uid'],'account_reference'=>(string)$metadata['provider_account_reference'],
            'reason_code'=>null);
    }

    /** Find the exact retained success event to supply Stripe's provider occurrence time. */
    public function completionEvent(string $providerReference,int $sessionCreatedAt):array{
        $mode=StripeProviderMode::modeFromCheckoutReference($providerReference);
        if($mode===null||$sessionCreatedAt<1||$sessionCreatedAt>time()+300)return $this->eventUnavailable('provider_reference_invalid');
        if(!self::activationAllowed($mode))return $this->eventUnavailable('checkout_provider_unconfigured');
        $credentials=$this->credentialsForMode($mode);
        if(!is_array($credentials)||!is_string($credentials['api_key']??null)||!is_string($credentials['account_reference']??null)
            ||preg_match(StripeProviderMode::apiKeyPattern($mode),$credentials['api_key'])!==1
            ||preg_match('/^[A-Za-z0-9_-]{1,32}$/D',$credentials['account_reference'])!==1)return $this->eventUnavailable('checkout_provider_unconfigured');
        if($this->http===null&&(!function_exists('wp_remote_get')||!function_exists('is_wp_error')||!function_exists('wp_remote_retrieve_response_code')||!function_exists('wp_remote_retrieve_body')))return $this->eventUnavailable('checkout_provider_unconfigured');
        $oldest=max($sessionCreatedAt,time()-(29*86400));$cursor=null;
        for($page=0;$page<10;$page++){
            $query=array('types'=>array('checkout.session.completed','checkout.session.async_payment_succeeded'),'created'=>array('gte'=>$oldest),'limit'=>100);
            if($cursor!==null)$query['starting_after']=$cursor;
            $url='https://api.stripe.com/v1/events?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
            $options=array('timeout'=>15,'redirection'=>0,'sslverify'=>true,'headers'=>array('Authorization'=>'Basic '.base64_encode($credentials['api_key'].':')));
            $response=$this->http!==null?($this->http)($url,$options):wp_remote_get($url,$options);
            if(is_wp_error($response))return $this->eventUnavailable('provider_unavailable');
            $status=(int)wp_remote_retrieve_response_code($response);$raw=(string)wp_remote_retrieve_body($response);
            if(strlen($raw)>1048576)return $this->eventUnavailable('provider_response_unusable');
            if($status<200||$status>=300)return $this->eventUnavailable($status>=400&&$status<500?'provider_request_rejected':'provider_unavailable');
            $list=json_decode($raw,true);
            if(!is_array($list)||!is_array($list['data']??null)||!is_bool($list['has_more']??null))return $this->eventUnavailable('provider_response_unusable');
            foreach($list['data'] as $event){
                if(!is_array($event)||!in_array((string)($event['type']??''),array('checkout.session.completed','checkout.session.async_payment_succeeded'),true))continue;
                $object=is_array($event['data']['object']??null)?$event['data']['object']:array();
                $liveExpectation=$mode===StripeProviderMode::LIVE;
                if(($event['livemode']??null)!==$liveExpectation||($object['livemode']??null)!==$liveExpectation||($object['object']??'')!=='checkout.session'
                    ||($object['mode']??'')!=='payment'||($object['status']??'')!=='complete'||($object['payment_status']??'')!=='paid'
                    ||!hash_equals($providerReference,(string)($object['id']??'')))continue;
                $metadata=is_array($object['metadata']??null)?$object['metadata']:array();
                if(!is_string($event['id']??null)||preg_match('/^evt_[A-Za-z0-9]+$/D',$event['id'])!==1||!is_int($event['created']??null)
                    ||!is_int($object['amount_total']??null)||preg_match('/^[A-Z]{3}$/Di',(string)($object['currency']??''))!==1
                    ||preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D',(string)($metadata['checkout_attempt_uid']??''))!==1
                    ||trim((string)($metadata['obligation_reference']??''))===''||!hash_equals($credentials['account_reference'],(string)($metadata['provider_account_reference']??'')))continue;
                return array('state'=>'found','mode'=>$mode,'provider_reference'=>(string)$event['id'],'provider_occurred_at'=>gmdate('Y-m-d H:i:s',(int)$event['created']),
                    'amount_minor'=>(int)$object['amount_total'],'currency'=>strtoupper((string)$object['currency']),
                    'obligation_reference'=>(string)$metadata['obligation_reference'],'attempt_uid'=>(string)$metadata['checkout_attempt_uid'],
                    'account_reference'=>(string)$metadata['provider_account_reference'],'reason_code'=>null);
            }
            if($list['has_more']!==true)return $this->eventUnavailable('provider_success_event_not_found');
            $last=end($list['data']);$cursor=is_array($last)&&is_string($last['id']??null)?$last['id']:null;
            if($cursor===null)return $this->eventUnavailable('provider_response_unusable');
        }
        return $this->eventUnavailable('provider_event_search_incomplete');
    }

    /** This code path is restricted to an explicitly authorised test environment. */
    public static function testModeActivationAllowed():bool{
        if(!defined('DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED')||constant('DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED')!==true)return false;
        if(!PaymentSecretVault::testVaultActive())return false;
        return \Delnavazan\Platform\Core\Application\PaymentExecution\StripeTestConfigurationService::testEnvironmentAllowed();
    }

    /** Live Checkout exists only after the confirmed, readiness-gated live activation. */
    public static function liveModeActivationAllowed():bool{
        return StripeProviderMode::liveActive()&&StripeProviderMode::liveEnvironmentAllowed();
    }

    private static function activationAllowed(string $mode):bool{
        return $mode===StripeProviderMode::LIVE?self::liveModeActivationAllowed():self::testModeActivationAllowed();
    }

    /** Exact mode-scoped credentials; test and live can never borrow each other's key. */
    private function credentialsForMode(string $mode):?array{
        if(method_exists($this->credentialSource,'credentialsForMode'))return $this->credentialSource->credentialsForMode($mode);
        return $mode===StripeProviderMode::TEST?$this->credentialSource->credentials():null;
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

    private function validSession(array $session,CheckoutRequest $request,string $providerAccountReference,string $mode):bool{
        if(($session['object']??'')!=='checkout.session'||($session['mode']??'')!=='payment'||($session['status']??'')!=='open'
            ||($session['livemode']??null)!==($mode===StripeProviderMode::LIVE))return false;
        if(!is_string($session['id']??null)||!StripeProviderMode::checkoutReference($session['id'],$mode))return false;
        if(!is_string($session['url']??null)||!$this->safeUrl($session['url']))return false;
        if(!is_int($session['expires_at']??null)||(int)$session['expires_at']<=time()||(int)$session['expires_at']>time()+86460)return false;
        if(!is_int($session['amount_total']??null)||(int)$session['amount_total']!==$request->amountMinor())return false;
        if(strtoupper((string)($session['currency']??''))!==$request->currency())return false;
        $metadata=is_array($session['metadata']??null)?$session['metadata']:array();
        return hash_equals($request->attemptUid(),(string)($metadata['checkout_attempt_uid']??''))
            &&hash_equals($request->obligationReference(),(string)($metadata['obligation_reference']??''))
            &&hash_equals($providerAccountReference,(string)($metadata['provider_account_reference']??''));
    }

    private function validRetrievedSession(array $session,string $providerReference,string $providerAccountReference,string $mode):bool{
        if(($session['object']??'')!=='checkout.session'||($session['mode']??'')!=='payment'
            ||($session['livemode']??null)!==($mode===StripeProviderMode::LIVE)
            ||!hash_equals($providerReference,(string)($session['id']??''))
            ||!in_array((string)($session['status']??''),array('open','complete','expired'),true)
            ||!in_array((string)($session['payment_status']??''),array('paid','unpaid','no_payment_required'),true)
            ||!is_int($session['created']??null)||$session['created']<1||$session['created']>time()+300
            ||!is_int($session['amount_total']??null)||$session['amount_total']<0
            ||preg_match('/^[A-Z]{3}$/Di',(string)($session['currency']??''))!==1)return false;
        $metadata=is_array($session['metadata']??null)?$session['metadata']:array();
        return preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D',(string)($metadata['checkout_attempt_uid']??''))===1
            &&trim((string)($metadata['obligation_reference']??''))!==''
            &&hash_equals($providerAccountReference,(string)($metadata['provider_account_reference']??''));
    }

    private function safeUrl(string $url):bool{
        $parts=parse_url($url);
        return is_array($parts)&&($parts['scheme']??'')==='https'&&strtolower((string)($parts['host']??''))==='checkout.stripe.com'&&!isset($parts['user'])&&!isset($parts['pass']);
    }

    private function unavailable(string $reason):array{return array('state'=>'unavailable','redirect_url'=>null,'provider_reference'=>null,'expires_at'=>null,'reason_code'=>$reason);}
    private function retrievalUnavailable(string $reason):array{return array('state'=>'unavailable','payment_state'=>null,'provider_reference'=>null,'amount_minor'=>null,'currency'=>null,'obligation_reference'=>null,'attempt_uid'=>null,'account_reference'=>null,'reason_code'=>$reason);}
    private function eventUnavailable(string $reason):array{return array('state'=>'unavailable','provider_reference'=>null,'provider_occurred_at'=>null,'amount_minor'=>null,'currency'=>null,'obligation_reference'=>null,'attempt_uid'=>null,'account_reference'=>null,'reason_code'=>$reason);}
}
