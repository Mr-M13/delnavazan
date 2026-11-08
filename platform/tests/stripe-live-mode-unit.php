<?php
/**
 * Stripe live-mode behaviour against an in-memory database and synthetic Stripe responses.
 *
 * This proves the security boundary, not a real charge: credentials are really encrypted with sodium,
 * test and live scopes are separate, activation is refused until readiness passes, and rollback stops
 * live charging without deleting credentials or historical records.
 */
declare(strict_types=1);
define('DZN_PLATFORM_PAYMENT_TEST_VAULT',true);
define('DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED',true);
define('DZN_PRELAUNCH_PRODUCTION_TEST_MODE_ENABLED',true);
define('DZN_PLATFORM_SCHEMA_VERSION','60');

$source=dirname(__DIR__).'/src/';
spl_autoload_register(static function(string $class)use($source):void{
    $prefix='Delnavazan\\Platform\\';
    if(!str_starts_with($class,$prefix))return;
    $path=$source.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($path))require_once $path;
});

$GLOBALS['options']=array(
    'dzn_platform_schema_version'=>'60',
    'dzn_platform_completed_migrations'=>array('035_provider_reference_vault','036_checkout_session_authority','070_stripe_live_mode'),
    'dzn_platform_payment_worker_principal'=>41,
);
$GLOBALS['environment']='production';
$GLOBALS['authorized']=true;
$GLOBALS['http_calls']=array();
$GLOBALS['http_response']=null;
$GLOBALS['next_id']=1;

function get_option(string $key,$default=false){return array_key_exists($key,$GLOBALS['options'])?$GLOBALS['options'][$key]:$default;}
function update_option(string $key,$value,bool $autoload=true):bool{$GLOBALS['options'][$key]=$value;return true;}
function delete_option(string $key):bool{unset($GLOBALS['options'][$key]);return true;}
function wp_generate_uuid4():string{return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',mt_rand(0,65535),mt_rand(0,65535),mt_rand(0,65535),mt_rand(0,65535),mt_rand(0,65535),mt_rand(0,65535),mt_rand(0,65535),mt_rand(0,65535));}
function wp_salt(string $scheme='auth'):string{return 'stripe-live-unit-salt-'.$scheme;}
function wp_json_encode($value,int $flags=0):string|false{return json_encode($value,$flags);}
function current_user_can(string $capability):bool{return $GLOBALS['authorized'];}
function get_current_user_id():int{return 17;}
function wp_get_environment_type():string{return $GLOBALS['environment'];}
function is_ssl():bool{return true;}
function is_wp_error($value):bool{return $value instanceof RuntimeException;}
function wp_remote_retrieve_response_code($response):int{return (int)($response['response']['code']??0);}
function wp_remote_retrieve_body($response):string{return (string)($response['body']??'');}
function wp_remote_get(string $url,array $options=array()){
    $GLOBALS['http_calls'][]=array($url,$options);
    if($GLOBALS['http_response'] instanceof RuntimeException)return $GLOBALS['http_response'];
    return $GLOBALS['http_response'];
}
function rest_url(string $path=''):string{return 'https://delnavazan.test/wp-json/'.ltrim($path,'/');}
function home_url(string $path=''):string{return 'https://delnavazan.test/'.ltrim($path,'/');}
function has_action(string $hook,$callback=null){return 1;}
function get_user_by(string $field,$id){
    return (object)array('ID'=>$id,'user_status'=>0,'roles'=>array('dzn_payment_worker'),
        'caps'=>array('dzn_payment_worker'=>true),
        'allcaps'=>array('read'=>true,'dzn_ingest_payment_provider_events'=>true,'dzn_ingest_commercial_payment_evidence'=>true,'dzn_manage_collection_intents'=>true,'dzn_manage_renewal_cycles'=>true,'dzn_payment_worker'=>true));
}

$GLOBALS['wpdb']=new class{
    public string $prefix='wp_';public int $insert_id=0;public string $last_error='';
    public array $accounts=array(),$secretRows=array(),$accountEvents=array(),$accountCommands=array(),$activationEvents=array(),$receipts=array(),$events=array(),$evidence=array();
    private function q($query){return is_array($query)?$query:array($query,array());}
    private function findAccountWhere(array $args):?object{
        foreach($this->accounts as $account){
            $match=true;
            if(isset($args['id'])&&(int)$account->id!==(int)$args['id'])$match=false;
            if(isset($args['reference_code'])&&(string)$account->reference_code!==(string)$args['reference_code'])$match=false;
            if(isset($args['provider_key'])&&(string)$account->provider_key!==(string)$args['provider_key'])$match=false;
            if($match)return $account;
        }
        return null;
    }
    private function accountArgs(string $sql,array $args):array{
        $out=array();
        if(str_contains($sql,'WHERE id=%d')&&isset($args[0]))$out['id']=$args[0];
        if(str_contains($sql,'reference_code=%s')&&isset($args[0]))$out['reference_code']=$args[0];
        if(str_contains($sql,'provider_key=%s')&&isset($args[0]))$out['provider_key']=$args[0];
        return $out;
    }
    function prepare($sql,...$args){return array($sql,$args);}
    function get_row($query){
        [$sql,$args]=$this->q($query);
        if(str_contains($sql,'payment_provider_accounts')){
            $where=$this->accountArgs($sql,$args);
            if(isset($args[0])&&str_contains($sql,'reference_code=%s')&&isset($args[1])&&str_contains($sql,'provider_key=%s')){$where['provider_key']=$args[0];$where['reference_code']=$args[1];}
            return $this->findAccountWhere($where);
        }
        if(str_contains($sql,'payment_provider_secrets')){
            if(str_contains($sql,'WHERE id=%d'))foreach($this->secretRows as $row)if((int)$row->id===(int)$args[0])return $row;
            if(str_contains($sql,'active_slot=1')){
                foreach($this->secretRows as $row)if((string)$row->provider_key===(string)$args[0]&&(string)$row->secret_class===(string)$args[1]
                    &&(int)$row->payment_provider_account_id===(int)$args[2]&&(string)$row->mode===(string)$args[3]&&(int)$row->active_slot===1)return $row;
            }
            return null;
        }
        if(str_contains($sql,'payment_provider_account_commands'))foreach($this->accountCommands as $row)if((string)$row->command_key_digest===(string)$args[0])return $row;
        if(str_contains($sql,'payment_provider_event_receipts')){
            $rows=array_values(array_filter($this->receipts,static fn($r)=>(int)$r->payment_provider_account_id===(int)$args[0]));
            if(str_contains($sql,"verification_state='verified'"))$rows=array_values(array_filter($rows,static fn($r)=>(string)$r->verification_state==='verified'));
            if(str_contains($sql,"verification_state='refused'"))$rows=array_values(array_filter($rows,static fn($r)=>(string)$r->verification_state==='refused'));
            usort($rows,static fn($a,$b)=>(int)$b->id<=>(int)$a->id);
            return $rows[0]??null;
        }
        return null;
    }
    function get_results($query){
        [$sql,$args]=$this->q($query);
        if(str_contains($sql,'payment_provider_accounts')){
            $rows=array_values(array_filter($this->accounts,static fn($a)=>(string)$a->provider_key===(string)$args[0]));
            usort($rows,static fn($a,$b)=>(int)$a->id<=>(int)$b->id);
            return $rows;
        }
        if(str_contains($sql,'payment_provider_event_receipts')){
            $rows=array_values(array_filter($this->receipts,static fn($r)=>(int)$r->payment_provider_account_id===(int)$args[0]));
            usort($rows,static fn($a,$b)=>(int)$b->id<=>(int)$a->id);
            return $rows;
        }
        if(str_contains($sql,'payment_provider_events')){
            $rows=array_values(array_filter($this->events,static fn($r)=>(int)$r->payment_provider_account_id===(int)$args[0]));
            usort($rows,static fn($a,$b)=>(int)$b->id<=>(int)$a->id);
            return $rows;
        }
        if(str_contains($sql,'commercial_payment_evidence'))return $this->evidence;
        return array();
    }
    function get_var($query){
        [$sql,$args]=$this->q($query);
        if(str_contains($sql,'MAX(event_sequence)')){$max=0;foreach($this->accountEvents as $e)if((int)$e->payment_provider_account_id===(int)$args[0])$max=max($max,(int)$e->event_sequence);return $max+1;}
        if(str_contains($sql,'MAX(secret_version)')){$max=0;foreach($this->secretRows as $r)if((string)$r->provider_key===(string)$args[0]&&(string)$r->secret_class===(string)$args[1]&&(int)$r->payment_provider_account_id===(int)$args[2]&&(string)$r->mode===(string)$args[3])$max=max($max,(int)$r->secret_version);return $max;}
        if(str_contains($sql,'COUNT(*)')&&str_contains($sql,'payment_provider_event_receipts')){$n=0;foreach($this->receipts as $r)if((int)$r->payment_provider_account_id===(int)$args[0]&&(string)$r->verification_state==='verified')$n++;return $n;}
        if(str_contains($sql,'COUNT(*)'))return 0;
        return null;
    }
    function suppress_errors(bool $value):bool{return false;}
    function begin():void{}
    function commit():void{}
    function rollback():void{}
    function query(string $sql){
        if(preg_match('/^(SET|START|COMMIT|ROLLBACK)/i',ltrim($sql))===1)return 1;
        return 1;
    }
    function insert(string $table,array $data){
        $table=str_replace($this->prefix.'dzn_','',$table);
        $data['id']=$GLOBALS['next_id']++;$this->insert_id=$data['id'];
        $row=(object)$data;
        match($table){
            'payment_provider_accounts'=>$this->accounts[]=$row,
            'payment_provider_secrets'=>$this->secretRows[]=$row,
            'payment_provider_account_events'=>$this->accountEvents[]=$row,
            'payment_provider_account_commands'=>$this->accountCommands[]=$row,
            'stripe_live_activation_events'=>$this->activationEvents[]=$row,
            default=>null,
        };
        return 1;
    }
    function update(string $table,array $changes,array $where):int{
        $table=str_replace($this->prefix.'dzn_','',$table);
        if($table==='payment_provider_accounts')$sets=&$this->accounts;
        elseif($table==='payment_provider_secrets')$sets=&$this->secretRows;
        elseif($table==='payment_provider_event_receipts')$sets=&$this->receipts;
        else return 0;
        $changed=0;
        foreach($sets as $row){
            $match=true;foreach($where as $key=>$value)if(!isset($row->$key)||(string)$row->$key!==(string)$value)$match=false;
            if(!$match)continue;
            foreach($changes as $key=>$value)$row->$key=$value;
            $changed++;
        }
        return $changed;
    }
};

function live_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentExecutionRule,PaymentProviderRegistry,StripeLiveConfigurationService,StripeLiveReadinessService,StripeProviderMode,StripeTestConfigurationService,StripeTestModeReadinessService};
use Delnavazan\Platform\Core\Infrastructure\Repository\{CommercialPaymentRepository,PaymentProviderRepository,PaymentSecretRepository};
use Delnavazan\Platform\Integrations\Payment\Stripe\{StripeCheckoutAdapter,StripeEventTranslator,StripePaymentAdapter,StripeSignatureVerifier};
use Delnavazan\Platform\Core\Application\PaymentExecution\ProviderVerificationContext;

PaymentProviderRegistry::registerTranslator(new StripeEventTranslator());
PaymentProviderRegistry::registerExecutionPort(new StripePaymentAdapter(new PaymentProviderRepository(),new PaymentSecretRepository()));

// --- activation is closed before any live record exists -------------------------------------------
live_assert(!StripeProviderMode::liveActive(),'Live mode started active without an activation record');
live_assert(!PaymentExecutionRule::liveExecutionAuthorised('stripe'),'A provider was live-authorised before activation');

// --- test mode remains independent and can be configured first -----------------------------------
$GLOBALS['environment']='staging';
$testService=new StripeTestConfigurationService(new PaymentProviderRepository(),new PaymentSecretRepository());
$testService->save(array('publishable_key'=>'pk_test_'.str_repeat('T',24),'secret_key'=>'sk_test_'.str_repeat('T',24),'webhook_signing_secret'=>'whsec_'.str_repeat('T',24)));
live_assert($testService->savedState()['secret']===true,'The existing test configuration stopped working');
live_assert($testService->savedState()['account_id']>0,'The test account was not registered');
live_assert(!StripeProviderMode::liveActive(),'Configuring test mode activated live mode');

// --- live configuration requires production, complete live keys, and never auto-activates --------
$GLOBALS['environment']='staging';
try{(new StripeLiveConfigurationService())->save(array('publishable_key'=>'pk_live_'.str_repeat('L',24),'secret_key'=>'sk_live_'.str_repeat('L',24),'webhook_signing_secret'=>'whsec_'.str_repeat('L',24)));throw new LogicException('Live credentials were accepted outside production');}
catch(RuntimeException $e){live_assert($e->getMessage()==='stripe_live_environment_required','Unexpected non-production refusal: '.$e->getMessage());}
$GLOBALS['environment']='production';
try{(new StripeLiveConfigurationService())->save(array('publishable_key'=>'pk_live_'.str_repeat('L',24),'secret_key'=>'sk_test_'.str_repeat('L',24),'webhook_signing_secret'=>'whsec_'.str_repeat('L',24)));throw new LogicException('A test secret key was accepted as live');}
catch(InvalidArgumentException $e){live_assert($e->getMessage()==='invalid_stripe_live_secret_key','Unexpected key refusal');}
$GLOBALS['authorized']=false;
$accountsBefore=count($GLOBALS['wpdb']->accounts);
try{(new StripeLiveConfigurationService())->save(array('publishable_key'=>'pk_live_'.str_repeat('L',24),'secret_key'=>'sk_live_'.str_repeat('L',24),'webhook_signing_secret'=>'whsec_'.str_repeat('L',24)));throw new LogicException('An unauthorised administrator saved live credentials');}
catch(RuntimeException $e){live_assert($e->getMessage()==='Unauthorized','Unexpected authorisation refusal: '.$e->getMessage());}
live_assert(count($GLOBALS['wpdb']->accounts)===$accountsBefore,'An unauthorised save created an account');
$GLOBALS['authorized']=true;
$liveService=new StripeLiveConfigurationService();
$liveService->save(array('publishable_key'=>'pk_live_'.str_repeat('L',24),'secret_key'=>'sk_live_'.str_repeat('L',24),'webhook_signing_secret'=>'whsec_'.str_repeat('L',24),'account_identifier'=>'acct_'.str_repeat('A',16)));
$saved=$liveService->savedState();
live_assert($saved['account_id']>0&&$saved['publishable']&&$saved['secret']&&$saved['webhook'],'Live configuration was not persisted');
live_assert($saved['execution_enabled']===false,'Saving live credentials automatically enabled live execution');
live_assert(!StripeProviderMode::liveActive(),'Saving live credentials automatically activated live mode');
$testAccount=null;$liveAccount=null;foreach($GLOBALS['wpdb']->accounts as $account)if((string)$account->mode==='test')$testAccount=$account;elseif((string)$account->mode==='live')$liveAccount=$account;
live_assert($testAccount&&$liveAccount&&(int)$testAccount->id!==(int)$liveAccount->id,'Test and live accounts were not kept separate');
foreach($GLOBALS['wpdb']->secretRows as $row){
    live_assert((string)$row->mode==='test'||(string)$row->mode==='live','A secret row has no controlled mode');
    live_assert(!str_contains((string)$row->ciphertext,'sk_live_')&&!str_contains((string)$row->ciphertext,'whsec_'),'A credential was stored in plaintext');
}
$configured=(new StripeLiveReadinessService())->read();
live_assert($configured['stage']===StripeLiveReadinessService::STAGE_CONFIGURED&&$configured['ready']===false&&$configured['verified']===false,'Credentials alone reached Ready or Verified');
$liveVault=new \Delnavazan\Platform\Core\Application\PaymentExecution\PaymentSecretVault(new PaymentSecretRepository(),new PaymentProviderRepository());
$liveSecretRow=null;foreach($GLOBALS['wpdb']->secretRows as $row)if((string)$row->mode==='live'&&(string)$row->secret_class==='webhook_signing_secret'&&(int)$row->active_slot===1)$liveSecretRow=$row;
$GLOBALS['environment']='staging';
live_assert($liveVault->reveal((int)$liveSecretRow->id,'webhook_signing_secret','stripe')===null,'A live secret was readable outside production');
$GLOBALS['environment']='production';

// --- live connection proves the authenticated mode ------------------------------------------------
$GLOBALS['http_response']=array('response'=>array('code'=>200),'body'=>json_encode(array('object'=>'balance','livemode'=>true,'available'=>array(),'pending'=>array())));
$connection=$liveService->testConnection();
live_assert($connection['state']==='success'&&$connection['code']==='stripe_live_account_reachable','Live balance check did not succeed: '.json_encode($connection));
$GLOBALS['http_response']=array('response'=>array('code'=>200),'body'=>json_encode(array('object'=>'balance','livemode'=>false)));
live_assert($liveService->testConnection()['code']==='stripe_live_mode_mismatch','A test balance response was accepted for live mode');
$GLOBALS['http_response']=array('response'=>array('code'=>200),'body'=>json_encode(array('object'=>'balance','livemode'=>true)));
$liveService->testConnection();
$afterConnection=(new StripeLiveReadinessService())->read();
live_assert($afterConnection['stage']===StripeLiveReadinessService::STAGE_CONFIGURED&&$afterConnection['verified']===false,'A successful connection alone reached Verified');

// --- webhook destination is verified from Stripe's own event-destination list --------------------
$url=$liveService->webhookUrl();
$events=StripeEventTranslator::requiredWebhookEvents();
$GLOBALS['http_response']=array('response'=>array('code'=>200),'body'=>json_encode(array('object'=>'list','has_more'=>false,'data'=>array(array(
    'id'=>'we_'.str_repeat('W',16),'url'=>$url,'status'=>'enabled','livemode'=>true,'api_version'=>'2026-10-28.endive','enabled_events'=>$events,
)))));
$verification=$liveService->verifyWebhookDestination();
live_assert($verification['state']==='success'&&$verification['code']==='stripe_live_webhook_verified','Live webhook verification failed: '.json_encode($verification));
$GLOBALS['http_response']=array('response'=>array('code'=>200),'body'=>json_encode(array('object'=>'list','has_more'=>false,'data'=>array(array(
    'id'=>'we_'.str_repeat('U',16),'url'=>$url,'status'=>'enabled','livemode'=>true,'api_version'=>'2025-06-30.basil','enabled_events'=>$events,
)))));
live_assert($liveService->verifyWebhookDestination()['code']==='stripe_live_webhook_api_version_unsupported','An unreviewed Stripe API version passed readiness');
$GLOBALS['http_response']=array('response'=>array('code'=>200),'body'=>json_encode(array('object'=>'list','has_more'=>false,'data'=>array(array(
    'id'=>'we_'.str_repeat('X',16),'url'=>$url,'status'=>'enabled','livemode'=>true,'api_version'=>'2026-10-28.endive','enabled_events'=>array('checkout.session.completed'),
)))));
live_assert($liveService->verifyWebhookDestination()['code']==='stripe_live_webhook_events_incomplete','An incomplete live event subscription was accepted');
$GLOBALS['http_response']=array('response'=>array('code'=>200),'body'=>json_encode(array('object'=>'list','has_more'=>false,'data'=>array(array(
    'id'=>'we_'.str_repeat('W',16),'url'=>$url,'status'=>'enabled','livemode'=>true,'api_version'=>'2026-10-28.endive','enabled_events'=>$events,
)))));
$liveService->verifyWebhookDestination();

// --- readiness is Ready, never Active, before the explicit action --------------------------------
$readiness=(new StripeLiveReadinessService())->read();
live_assert($readiness['stage']===StripeLiveReadinessService::STAGE_READY,'Expected Ready, got '.$readiness['stage'].' '.json_encode($readiness['checks']));
live_assert($readiness['active']===false,'Readiness reported Active before activation');
live_assert(!PaymentExecutionRule::liveExecutionAuthorised('stripe'),'Readiness alone authorised live execution');

// --- activation is the only transition that enables live charging --------------------------------
$activation=$liveService->activate();
live_assert(($activation['activation_status']??'')===StripeProviderMode::ACTIVATION_ACTIVE,'Activation did not record an active state');
live_assert(StripeProviderMode::liveActive()&&PaymentExecutionRule::liveExecutionAuthorised('stripe'),'Activation did not authorise live execution');
$after=(new StripeLiveReadinessService())->read();
live_assert($after['stage']===StripeLiveReadinessService::STAGE_ACTIVE&&$after['active']===true,'Activated readiness did not reach Active');
$liveAccount=null;foreach($GLOBALS['wpdb']->accounts as $account)if((string)$account->mode==='live')$liveAccount=$account;
live_assert($liveAccount&&(string)$liveAccount->execution_state==='enabled','The live account was not enabled by activation');

// --- the adapter accepts a live session only while activation is active --------------------------
final class LiveFakeCredentialSource implements \Delnavazan\Platform\Integrations\Payment\Stripe\StripeCheckoutCredentialSource{
    public function activeMode():?string{return StripeProviderMode::LIVE;}
    public function credentials():?array{return $this->credentialsForMode(StripeProviderMode::LIVE);}
    public function credentialsForMode(string $mode):?array{return $mode===StripeProviderMode::LIVE?array('api_key'=>'sk_live_'.str_repeat('L',24),'account_reference'=>StripeProviderMode::LIVE_ACCOUNT_REFERENCE,'mode'=>StripeProviderMode::LIVE):null;}
}
$request=new \Delnavazan\Platform\Core\Application\Checkout\CheckoutRequest(1,2,3,100,'AUD','SYNTHETIC:1','live-test-key',str_repeat('A',26));
$session=array('object'=>'checkout.session','id'=>'cs_live_'.str_repeat('S',20),'mode'=>'payment','status'=>'open','payment_status'=>'unpaid','livemode'=>true,
    'amount_total'=>100,'currency'=>'aud','created'=>time(),'expires_at'=>time()+3600,'url'=>'https://checkout.stripe.com/c/pay/live',
    'metadata'=>array('checkout_attempt_uid'=>$request->attemptUid(),'obligation_reference'=>$request->obligationReference(),'provider_account_reference'=>StripeProviderMode::LIVE_ACCOUNT_REFERENCE));
$calls=0;
$adapter=new StripeCheckoutAdapter(new LiveFakeCredentialSource(),static function(string $url,array $options)use(&$calls,$session):array{$calls++;return array('response'=>array('code'=>200),'body'=>json_encode($session));});
$created=$adapter->create($request);
live_assert($created['state']==='open'&&$created['mode']==='live'&&$created['provider_reference']===$session['id'],'Active live Checkout was not accepted');
live_assert($calls===1,'Active live Checkout did not make exactly one provider request');

// --- signature verification is exact-body, mode scoped, and supports multiple v1 values ----------
$liveSecret=null;foreach($GLOBALS['wpdb']->secretRows as $row)if((string)$row->provider_key==='stripe'&&(string)$row->secret_class==='webhook_signing_secret'&&(string)$row->mode==='live'&&(int)$row->active_slot===1)$liveSecret=$row;
live_assert($liveSecret!==null,'Live signing secret record missing');
$plain=(new ReflectionClass(\Delnavazan\Platform\Core\Application\PaymentExecution\PaymentSecretVault::class))->newInstanceWithoutConstructor();
$vault=new \Delnavazan\Platform\Core\Application\PaymentExecution\PaymentSecretVault(new PaymentSecretRepository(),new PaymentProviderRepository());
$decrypted=$vault->reveal((int)$liveSecret->id,'webhook_signing_secret','stripe');
live_assert(is_string($decrypted)&&str_starts_with($decrypted,'whsec_'),'Live signing secret could not be decrypted in production');
$body=json_encode(array('id'=>'evt_live_1','type'=>'checkout.session.completed','livemode'=>true,'created'=>time(),'data'=>array('object'=>$session)));
$timestamp=time();
$signature=hash_hmac('sha256',$timestamp.'.'.$body,$decrypted);
$header='t='.$timestamp.',v1='.str_repeat('0',64).',v1='.$signature;
$context=new ProviderVerificationContext('stripe',(int)$liveAccount->id,'live',(string)$liveSecret->key_version);
$verifier=new StripeSignatureVerifier($vault);
live_assert($verifier->verify($body,array('stripe-signature'=>$header),$context)->isVerified(),'A valid live signature with multiple v1 values was rejected');
live_assert(!$verifier->verify($body.' ',array('stripe-signature'=>$header),$context)->isVerified(),'A modified live body was accepted');
$testContext=new ProviderVerificationContext('stripe',(int)$testAccount->id,'test',(string)$liveSecret->key_version);
$testVerdict=$verifier->verify($body,array('stripe-signature'=>$header),$testContext);
live_assert(!$testVerdict->isVerified()&&in_array($testVerdict->reason(),array('signature_invalid','webhook_secret_unconfigured'),true),'A live signature reached the test webhook scope');

// All required snapshot event names translate to the intended controlled type. Events that do not
// carry local attribution remain controlled review facts; they never silently settle an obligation.
$expectedTypes=array(
    'checkout.session.completed'=>'payment_succeeded','checkout.session.async_payment_succeeded'=>'payment_succeeded',
    'checkout.session.async_payment_failed'=>'payment_failed','payment_intent.succeeded'=>'payment_succeeded',
    'payment_intent.payment_failed'=>'payment_failed','charge.refunded'=>'refund_recorded',
    'refund.created'=>'refund_recorded','refund.updated'=>'refund_recorded',
);
$translator=new StripeEventTranslator($verifier);
foreach($expectedTypes as $rawType=>$expectedType){
    $object=array('id'=>str_starts_with($rawType,'checkout.session.')?$session['id']:'obj_'.str_repeat('R',16),
        'object'=>str_starts_with($rawType,'checkout.session.')?'checkout.session':str_replace(array('.succeeded','.payment_failed','.refunded','.created','.updated'),'',$rawType),
        'livemode'=>true,'metadata'=>array('obligation_reference'=>'SYNTHETIC:1','provider_account_reference'=>StripeProviderMode::LIVE_ACCOUNT_REFERENCE),
        'amount'=>100,'currency'=>'aud');
    if(str_starts_with($rawType,'checkout.session.'))$object=array_merge($object,array('mode'=>'payment','status'=>'complete','payment_status'=>$rawType==='checkout.session.async_payment_failed'?'unpaid':'paid','amount_total'=>100));
    $raw=json_encode(array('id'=>'evt_'.hash('sha256',$rawType),'type'=>$rawType,'livemode'=>true,'created'=>time(),'data'=>array('object'=>$object)));
    $translated=$translator->translate($raw,array(),$context,gmdate('Y-m-d H:i:s'));
    live_assert(count($translated)===1&&$translated[0]->eventType()===$expectedType,'Required event did not translate: '.$rawType);
}

// --- rollback stops live charging and preserves credentials and history --------------------------
$liveService->deactivate();
live_assert(!StripeProviderMode::liveActive()&&!PaymentExecutionRule::liveExecutionAuthorised('stripe'),'Deactivation left live execution authorised');
$deactivated=$liveService->savedState();
live_assert($deactivated['secret']===true&&$deactivated['webhook']===true,'Deactivation deleted live credentials');
live_assert($deactivated['execution_enabled']===false,'Deactivation left the live account enabled');
$calls=0;
$blocked=$adapter->create($request);
live_assert($blocked['state']==='unavailable'&&$calls===0,'Live Checkout still reached Stripe after deactivation');
$calls=0;
$retrieved=$adapter->retrieve($session['id']);
live_assert($retrieved['state']==='open'&&$retrieved['mode']==='live'&&$calls===1,'Deactivation interrupted read-only reconciliation of an existing live Session');
live_assert(count($GLOBALS['wpdb']->activationEvents)>=2,'Activation/deactivation evidence was not recorded');
$rolledBack=(new StripeLiveReadinessService())->read();
live_assert($rolledBack['stage']===StripeLiveReadinessService::STAGE_READY&&$rolledBack['active']===false,'Rollback did not return to a non-active, still-verified configuration');
$liveService->save(array('secret_key'=>'sk_live_'.str_repeat('N',24),'webhook_signing_secret'=>'whsec_'.str_repeat('N',24)));
$rotated=$liveService->savedState();
live_assert(!in_array($rotated['activation']['status'],array(StripeProviderMode::ACTIVATION_ACTIVE,StripeProviderMode::ACTIVATION_ACTIVATING),true),'Credential rotation left activation active');
live_assert($rotated['execution_enabled']===false&&!StripeProviderMode::liveActive(),'Credential rotation left live charging enabled');
live_assert($rotated['secret']===true&&$rotated['webhook']===true,'Credential rotation lost the credential records');
$calls=0;
live_assert($adapter->create($request)['state']==='unavailable'&&$calls===0,'Rotated but unverified credentials reached Stripe');

// --- the separate test readiness path still reports its own checks -------------------------------
$testReadiness=(new StripeTestModeReadinessService())->read();
live_assert(isset($testReadiness['checks']['test_account'])&&$testReadiness['checks']['test_account']['state']==='ready','Test readiness stopped observing the test account');

echo "Stripe live mode unit: activation gating, encrypted isolation, webhook verification, exact-body signatures, rollback passed\n";
