<?php
define('DZN_PLATFORM_PAYMENT_TEST_VAULT',true);
define('DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED',true);
$source=dirname(__DIR__).'/src/';
spl_autoload_register(static function(string $class)use($source):void{
    $prefix='Delnavazan\\Platform\\';
    if(!str_starts_with($class,$prefix))return;
    $path=$source.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($path))require_once $path;
});
function wp_salt($scheme='auth'){return 'checkout-test-only-fixed-salt-value-'.$scheme;}
function wp_get_environment_type(){return $GLOBALS['checkout_fake_environment']??'development';}
function home_url($path='/'){return 'https://example.invalid'.'/'.ltrim($path,'/');}
function is_wp_error($value){return $value instanceof CheckoutFakeWpError;}
function wp_remote_post($url,$args){$GLOBALS['checkout_fake_calls'][]=array($url,$args);return $GLOBALS['checkout_fake_response'];}
function wp_remote_retrieve_response_code($response){return (int)($response['response']['code']??0);}
function wp_remote_retrieve_body($response){return (string)($response['body']??'');}
final class CheckoutFakeWpError{}
final class CheckoutFakeCredentialSource implements \Delnavazan\Platform\Integrations\Payment\Stripe\StripeCheckoutCredentialSource{
    public ?array $value;
    public function __construct(?array $value=null){$this->value=$value??array('api_key'=>'sk_test_'.str_repeat('A',24),'account_reference'=>'test_account_41');}
    public function credentials():?array{return $this->value;}
}
function checkout_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$GLOBALS['checkout_fake_calls']=array();
$transport=static function(string $url,array $options){$GLOBALS['checkout_fake_calls'][]=array($url,$options);return $GLOBALS['checkout_fake_response'];};
$credentialSource=new CheckoutFakeCredentialSource();
$adapter=new \Delnavazan\Platform\Integrations\Payment\Stripe\StripeCheckoutAdapter($credentialSource,$transport);
$request=new \Delnavazan\Platform\Core\Application\Checkout\CheckoutRequest(7,8,9,15000,'AUD','OFFERUID:1','stable-server-key',str_repeat('A',26));
$response=array(
    'object'=>'checkout.session','id'=>'cs_test_'.str_repeat('C',30),'mode'=>'payment','status'=>'open','livemode'=>false,
    'amount_total'=>15000,'currency'=>'aud','expires_at'=>time()+3600,'url'=>'https://checkout.stripe.com/c/pay/session',
    'metadata'=>array('checkout_attempt_uid'=>$request->attemptUid(),'obligation_reference'=>$request->obligationReference(),'provider_account_reference'=>'test_account_41'),
);
$GLOBALS['checkout_fake_response']=array('response'=>array('code'=>200),'body'=>json_encode($response));
$created=$adapter->create($request);
checkout_assert($created['state']==='open'&&$created['provider_reference']===$response['id'],'Valid test session was not accepted');
checkout_assert($created['redirect_url']===$response['url'],'Validated hosted URL was not returned');
[$url,$options]=$GLOBALS['checkout_fake_calls'][0];
checkout_assert($url==='https://api.stripe.com/v1/checkout/sessions','Adapter used a non-fixed Stripe endpoint');
checkout_assert(($options['headers']['Idempotency-Key']??'')===$request->idempotencyKey(),'Server idempotency key was not sent');
checkout_assert(str_starts_with(base64_decode(substr((string)$options['headers']['Authorization'],6),true)?:'','sk_test_'),'Only a test-mode key may reach the transport');
checkout_assert(($options['body']['line_items[0][price_data][unit_amount]']??null)===15000,'Server amount was not used');
checkout_assert(($options['body']['metadata[checkout_attempt_uid]']??'')===$request->attemptUid(),'Attempt correlation metadata missing');
checkout_assert(($options['body']['metadata[obligation_reference]']??'')===$request->obligationReference(),'Obligation correlation metadata missing');
checkout_assert(($options['body']['metadata[provider_account_reference]']??'')==='test_account_41','Provider account correlation metadata missing');

$response['livemode']=true;
$GLOBALS['checkout_fake_response']=array('response'=>array('code'=>200),'body'=>json_encode($response));
checkout_assert($adapter->create($request)['state']==='unavailable','A live-mode session response was accepted');
$response['livemode']=false;$response['amount_total']=14999;
$GLOBALS['checkout_fake_response']=array('response'=>array('code'=>200),'body'=>json_encode($response));
checkout_assert($adapter->create($request)['state']==='unavailable','A mismatched provider amount was accepted');
$GLOBALS['checkout_fake_response']=new CheckoutFakeWpError();
checkout_assert($adapter->create($request)['state']==='unavailable','An ambiguous transport error was treated as success or definitive failure');
$GLOBALS['checkout_fake_response']=array('response'=>array('code'=>422),'body'=>'{}');
checkout_assert($adapter->create($request)['state']==='failed','A definitive provider rejection was not classified as failed');
$calls=count($GLOBALS['checkout_fake_calls']);
$credentialSource->value=null;
checkout_assert($adapter->create($request)['state']==='unavailable','Missing test credentials did not fail closed');
checkout_assert(count($GLOBALS['checkout_fake_calls'])===$calls,'Adapter sent traffic without test credentials');
$credentialSource->value=array('api_key'=>'sk_live_'.str_repeat('X',24),'account_reference'=>'test_account_41');
checkout_assert($adapter->create($request)['state']==='unavailable','A live credential reached the HTTP transport');
checkout_assert(count($GLOBALS['checkout_fake_calls'])===$calls,'Adapter sent traffic with a live credential');
$credentialSource->value=array('api_key'=>'sk_test_'.str_repeat('A',24),'account_reference'=>'test_account_41');
$GLOBALS['checkout_fake_environment']='production';
checkout_assert($adapter->create($request)['state']==='unavailable','Checkout was activated in a production environment');
checkout_assert(count($GLOBALS['checkout_fake_calls'])===$calls,'Adapter sent traffic from a production environment');
echo "Stripe Checkout adapter fake-provider unit checks passed\n";
