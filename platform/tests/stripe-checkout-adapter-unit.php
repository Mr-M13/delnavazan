<?php
if(!function_exists('sodium_crypto_secretbox'))throw new RuntimeException('Sodium is required for the disposable vault test');
if(!defined('DZN_PLATFORM_PAYMENT_TEST_VAULT'))define('DZN_PLATFORM_PAYMENT_TEST_VAULT',true);
if(!defined('DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED'))define('DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED',true);
$source=dirname(__DIR__).'/src/';
spl_autoload_register(static function(string $class)use($source):void{
    $prefix='Delnavazan\\Platform\\';
    if(!str_starts_with($class,$prefix))return;
    $path=$source.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($path))require_once $path;
});
function wp_salt($scheme='auth'){return 'checkout-test-only-fixed-salt-value-'.$scheme;}
function wp_get_environment_type(){return 'development';}
function home_url($path='/'){return 'https://example.invalid'.'/'.ltrim($path,'/');}
function is_wp_error($value){return $value instanceof CheckoutFakeWpError;}
function wp_remote_post($url,$args){$GLOBALS['checkout_fake_calls'][]=array($url,$args);return $GLOBALS['checkout_fake_response'];}
function wp_remote_retrieve_response_code($response){return (int)($response['response']['code']??0);}
function wp_remote_retrieve_body($response){return (string)($response['body']??'');}
final class CheckoutFakeWpError{}
final class CheckoutFakeProviderRepository extends \Delnavazan\Platform\Core\Infrastructure\Repository\PaymentProviderRepository{
    public array $rows=array();
    public function __construct(){}
    public function accountsForProvider(string $providerKey):array{return $this->rows;}
}
final class CheckoutFakeSecretRepository extends \Delnavazan\Platform\Core\Infrastructure\Repository\PaymentSecretRepository{
    private array $rows=array();
    public function __construct(array $rows){$this->rows=$rows;}
    public function activeSecret(string $providerKey,string $secretClass,int $accountId,string $mode,bool $lock=false):?object{
        foreach($this->rows as $row)if($row->provider_key===$providerKey&&$row->secret_class===$secretClass&&$row->payment_provider_account_id===$accountId&&$row->mode===$mode&&$row->active_slot===1)return $row;
        return null;
    }
    public function secret(int $id,bool $lock=false):?object{return $this->rows[$id]??null;}
}
function checkout_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function checkout_secret_row(int $id,string $class,string $value):object{
    $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $key=\Delnavazan\Platform\Core\Application\PaymentExecution\PaymentExecutionIdempotency::vaultKey($class,'v1');
    return (object)array(
        'id'=>$id,'provider_key'=>'stripe','secret_class'=>$class,'payment_provider_account_id'=>41,'mode'=>'test',
        'active_slot'=>1,'cipher_version'=>'sodium_secretbox_v1','key_version'=>'v1','nonce'=>bin2hex($nonce),
        'ciphertext'=>base64_encode(sodium_crypto_secretbox($value,$nonce,$key)),
    );
}

$providers=new CheckoutFakeProviderRepository();
$providers->rows=array((object)array('id'=>41,'reference_code'=>'test_account_41','mode'=>'test','state'=>'active','execution_state'=>'enabled','credential_state'=>'configured'));
$secrets=new CheckoutFakeSecretRepository(array(
    11=>checkout_secret_row(11,'api_key','sk_test_'.str_repeat('A',24)),
    12=>checkout_secret_row(12,'webhook_signing_secret','whsec_'.str_repeat('B',24)),
));
$vault=new \Delnavazan\Platform\Core\Application\PaymentExecution\PaymentSecretVault($secrets,$providers);
$adapter=new \Delnavazan\Platform\Integrations\Payment\Stripe\StripeCheckoutAdapter($providers,$secrets,$vault);
$request=new \Delnavazan\Platform\Core\Application\Checkout\CheckoutRequest(7,8,9,15000,'AUD','OFFERUID:1','stable-server-key',str_repeat('A',26));
$response=array(
    'object'=>'checkout.session','id'=>'cs_test_'.str_repeat('C',30),'mode'=>'payment','status'=>'open','livemode'=>false,
    'amount_total'=>15000,'currency'=>'aud','expires_at'=>time()+3600,'url'=>'https://checkout.stripe.com/c/pay/session',
    'metadata'=>array('checkout_attempt_uid'=>$request->attemptUid(),'obligation_reference'=>$request->obligationReference(),'provider_account_reference'=>'test_account_41'),
);
$GLOBALS['checkout_fake_response']=array('response'=>array('code'=>200),'body'=>json_encode($response));
$GLOBALS['checkout_fake_calls']=array();
$created=$adapter->create($request);
checkout_assert($created['state']==='open'&&$created['provider_reference']===$response['id'],'Valid test session was not accepted');
checkout_assert($created['redirect_url']===$response['url'],'Validated hosted URL was not returned');
[$url,$options]=$GLOBALS['checkout_fake_calls'][0];
checkout_assert($url==='https://api.stripe.com/v1/checkout/sessions','Adapter used a non-fixed Stripe endpoint');
checkout_assert(($options['headers']['Idempotency-Key']??'')===$request->idempotencyKey(),'Server idempotency key was not sent');
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
$providers->rows=array();
checkout_assert($adapter->create($request)['state']==='unavailable','Missing provider account did not fail closed');
checkout_assert(count($GLOBALS['checkout_fake_calls'])===$calls,'Adapter sent traffic without an eligible test account');
echo "Stripe Checkout adapter fake-provider unit checks passed\n";
