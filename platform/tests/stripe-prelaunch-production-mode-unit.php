<?php
/** Positive production-gate contract using only an in-memory fake Stripe TEST response. */
define('DZN_PRELAUNCH_PRODUCTION_TEST_MODE_ENABLED',true);
define('DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED',true);
define('DZN_PLATFORM_PAYMENT_TEST_VAULT',true);
$source=dirname(__DIR__).'/src/';
spl_autoload_register(static function(string $class)use($source):void{
    $prefix='Delnavazan\\Platform\\';
    if(!str_starts_with($class,$prefix))return;
    $path=$source.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($path))require_once $path;
});
function wp_get_environment_type(){return 'production';}
function home_url($path='/'){return 'https://delnavazan.example'.'/'.ltrim($path,'/');}
function is_wp_error($value){return false;}
function wp_remote_retrieve_response_code($response){return (int)($response['response']['code']??0);}
function wp_remote_retrieve_body($response){return (string)($response['body']??'');}
function prelaunch_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$configuration='Delnavazan\\Platform\\Core\\Application\\PaymentExecution\\StripeTestConfigurationService';
$adapterClass='Delnavazan\\Platform\\Integrations\\Payment\\Stripe\\StripeCheckoutAdapter';
prelaunch_assert($configuration::testEnvironmentAllowed(),'Explicitly gated production TEST configuration was not allowed.');
prelaunch_assert($adapterClass::testModeActivationAllowed(),'Explicitly gated production TEST Checkout was not allowed.');

$credentials=new class implements \Delnavazan\Platform\Integrations\Payment\Stripe\StripeCheckoutCredentialSource {
    public function credentials():?array{return array('api_key'=>'sk_test_'.str_repeat('A',24),'account_reference'=>'prelaunch_test_account');}
};
$attempt='0123456789ABCDEFGHJKMNPQRS';
$request=new \Delnavazan\Platform\Core\Application\Checkout\CheckoutRequest(1,2,3,100,'AUD','SYNTHETIC:1','prelaunch-test-idempotency',$attempt);
$session=array('object'=>'checkout.session','id'=>'cs_test_'.str_repeat('B',24),'mode'=>'payment','status'=>'open','payment_status'=>'unpaid','livemode'=>false,
    'amount_total'=>100,'currency'=>'aud','created'=>time(),'expires_at'=>time()+3600,'url'=>'https://checkout.stripe.com/c/pay/prelaunch-test',
    'metadata'=>array('checkout_attempt_uid'=>$request->attemptUid(),'obligation_reference'=>$request->obligationReference(),'provider_account_reference'=>'prelaunch_test_account'));
$transport=static function(string $url,array $options)use($session):array{
    if($url!=='https://api.stripe.com/v1/checkout/sessions')throw new RuntimeException('Unexpected provider destination.');
    $authorization=(string)($options['headers']['Authorization']??'');
    $encoded=str_starts_with($authorization,'Basic ')?substr($authorization,6):'';
    if(!str_starts_with(base64_decode($encoded,true)?:'','sk_test_'))throw new RuntimeException('Non-test credential reached provider transport.');
    return array('response'=>array('code'=>200),'body'=>json_encode($session));
};
$adapter=new $adapterClass($credentials,$transport);
$result=$adapter->create($request);
prelaunch_assert(($result['state']??'')==='open','Fake non-live Checkout session was not accepted in explicitly gated pre-launch mode.');
prelaunch_assert(str_starts_with((string)($result['provider_reference']??''),'cs_test_'),'The fake test Checkout identity was not retained.');
echo "Pre-launch production Stripe TEST-mode fake-provider checks passed\n";
