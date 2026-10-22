<?php
/** Actual configuration/vault/repositories against an in-memory database and synthetic HTTP only. */
define('DZN_PLATFORM_PAYMENT_TEST_VAULT',true);
$root=dirname(__DIR__).'/src/';
spl_autoload_register(static function($class)use($root){$prefix='Delnavazan\\Platform\\';if(str_starts_with($class,$prefix))require_once $root.str_replace('\\','/',substr($class,strlen($prefix))).'.php';});
use Delnavazan\Platform\Core\Application\PaymentExecution\{StripeTestConfigurationService,PaymentSecretVault,PaymentExecutionIdempotency};
function current_user_can($cap){return $GLOBALS['authorized']??true;}
function get_current_user_id(){return 17;}
function wp_get_environment_type(){return $GLOBALS['environment']??'staging';}
function wp_salt($domain){if($GLOBALS['throwSalt']??false)throw new RuntimeException('SENSITIVE VAULT TEXT');return 'synthetic-unit-salt-'.$domain;}
function is_wp_error($value){return $value instanceof Exception;}
function wp_remote_retrieve_response_code($response){return $response['response']['code'];}
function wp_remote_retrieve_body($response){return $response['body'];}
function wp_remote_get($url,$options){
    $GLOBALS['calls']++;
    if($url!=='https://api.stripe.com/v1/balance'||$options['sslverify']!==true||$options['redirection']!==0||$options['timeout']!==15)throw new LogicException('Unsafe request');
    if(($options['headers']['Authorization']??'')!=='Bearer sk_test_'.str_repeat('A',24))throw new LogicException('Unexpected key');
    if($GLOBALS['throwTransport']??false)throw new RuntimeException('SENSITIVE TRANSPORT TEXT');
    return $GLOBALS['response'];
}
$wpdb=new class{
    public $prefix='wp_',$insert_id=0,$last_error='';public array $rows=[],$audits=[];
    function prepare($sql,...$args){return [$sql,$args];}
    function get_results($query){return [(object)['id'=>1,'mode'=>'test','state'=>'active']];}
    function get_row($query){
        [$sql,$args]=$query;
        if(str_contains($sql,'secret_class=%s'))return $this->rows[$args[1]==='api_key'?1:2]??null;
        return $this->rows[$args[0]]??null;
    }
    function suppress_errors($value){return false;}
    function insert($table,$data){$this->audits[]=$data;$this->insert_id++;return 1;}
};
foreach([1=>'api_key',2=>'webhook_signing_secret'] as $id=>$class){
    $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain=($id===1?'sk_test_':'whsec_').str_repeat('A',24);
    $wpdb->rows[$id]=(object)['id'=>$id,'provider_key'=>'stripe','secret_class'=>$class,'payment_provider_account_id'=>1,'mode'=>'test','active_slot'=>1,'cipher_version'=>PaymentSecretVault::cipherVersion(),'key_version'=>PaymentSecretVault::keyVersion(),'nonce'=>bin2hex($nonce),'ciphertext'=>base64_encode(sodium_crypto_secretbox($plain,$nonce,PaymentExecutionIdempotency::vaultKey($class,PaymentSecretVault::keyVersion())))];
}
$service=new StripeTestConfigurationService();$cases=0;
function check_connection($status,$body,$expected){
    global $service,$response,$calls,$cases,$wpdb;
    $response=['response'=>['code'=>$status],'body'=>is_string($body)?$body:json_encode($body)];$calls=0;
    ob_start();$actual=$service->testConnection();$output=ob_get_clean();
    if($actual['code']!==$expected)throw new RuntimeException('Unexpected classification: '.$actual['code'].' expected '.$expected);
    if($output!==''||str_contains(json_encode([$actual,$wpdb->audits]),'sk_test_')||str_contains(json_encode($actual),'SENSITIVE'))throw new RuntimeException('Credential disclosure');
    $cases++;
}
check_connection(200,['object'=>'balance','livemode'=>false,'available'=>[],'pending'=>[]],'stripe_test_account_reachable');
if($calls!==1)throw new RuntimeException('Expected one read-only request');
check_connection(200,['object'=>'account','id'=>'acct_synthetic'],'stripe_response_invalid');
check_connection(200,['object'=>'balance','livemode'=>true],'stripe_test_mode_mismatch');
foreach([null,'false',0] as $mode)check_connection(200,['object'=>'balance','livemode'=>$mode],'stripe_response_invalid');
check_connection(200,'invalid json','stripe_response_invalid');
foreach([401=>'stripe_test_authentication_failed',403=>'stripe_test_permission_denied',429=>'stripe_rate_limited',500=>'stripe_api_unavailable',503=>'stripe_api_unavailable',302=>'stripe_api_request_failed',400=>'stripe_api_request_failed'] as $status=>$code)check_connection($status,['error'=>['message'=>'SENSITIVE PROVIDER TEXT']],$code);
$response=new RuntimeException('SENSITIVE NETWORK TEXT');$calls=0;
if($service->testConnection()['code']!=='stripe_unreachable')throw new RuntimeException('Network error misclassified');$cases++;
$throwTransport=true;
if($service->testConnection()['code']!=='stripe_transport_failed')throw new RuntimeException('Transport exception escaped');$cases++;$throwTransport=false;
$valid=$wpdb->rows[1]->ciphertext;$wpdb->rows[1]->ciphertext='corrupt';
check_connection(200,[],'test_secret_unavailable');if($calls!==0)throw new RuntimeException('Vault failure reached Stripe');
$wpdb->rows[1]->ciphertext=$valid;$saved=$wpdb->rows[2];unset($wpdb->rows[2]);
check_connection(200,[],'test_secret_unconfigured');if($calls!==0)throw new RuntimeException('Missing secret reached Stripe');$wpdb->rows[2]=$saved;
$throwSalt=true;check_connection(200,[],'test_secret_validation_failed');if($calls!==0)throw new RuntimeException('Vault exception reached Stripe');$throwSalt=false;
$environment='production';check_connection(200,[],'stripe_test_environment_required');if($calls!==0)throw new RuntimeException('Closed production gate reached Stripe');
$environment='staging';$authorized=false;
try{$service->testConnection();throw new LogicException('Unauthorized caller accepted');}catch(RuntimeException $e){if($e->getMessage()!=='Unauthorized')throw $e;}$cases++;
echo "Stripe Test Connection: $cases scenarios passed (synthetic HTTP, real encryption)\n";
