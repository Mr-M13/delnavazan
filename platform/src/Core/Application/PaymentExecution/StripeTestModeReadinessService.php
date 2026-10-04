<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

use Delnavazan\Platform\Core\Infrastructure\Repository\{PaymentProviderRepository,PaymentSecretRepository};
use Delnavazan\Platform\Integrations\Payment\Stripe\{StripeCheckoutAdapter,StripeWebhookController};

/** Secret-free readiness checklist for an eventual, deliberately activated Stripe test run. */
final class StripeTestModeReadinessService {
    private const VIEW_CAPABILITY='dzn_view_payment_execution_authority';

    public function __construct(
        private ?PaymentProviderRepository $providers=null,
        private ?PaymentSecretRepository $secrets=null
    ){
        $this->providers??=new PaymentProviderRepository();
        $this->secrets??=new PaymentSecretRepository();
    }

    /** @return array{ready:bool,checks:array<string,array{state:string,detail:string}>} */
    public function read():array{
        PaymentExecutionSupport::requireCapability(self::VIEW_CAPABILITY);
        $checks=array();
        $add=static function(string $key,bool $ready,string $detail)use(&$checks):void{
            $checks[$key]=array('state'=>$ready?'ready':'blocked','detail'=>$detail);
        };

        $add('adapter',class_exists(StripeCheckoutAdapter::class),'Stripe Checkout test adapter is loaded.');
        $environment=function_exists('wp_get_environment_type')?(string)wp_get_environment_type():'production';
        $environmentAllowed=in_array($environment,array('local','development','staging'),true);
        $add('environment',$environmentAllowed,$environmentAllowed?'Environment is recognised as non-production.':'Environment must be local, development, or staging.');

        $all=array_values(array_filter($this->providers->accountsForProvider(StripeCheckoutAdapter::PROVIDER_KEY),static fn($account)=>(string)($account->mode??'')==='test'
            &&(string)($account->state??'')==='active'&&(string)($account->execution_state??'')==='enabled'
            &&(string)($account->credential_state??'')==='configured'));
        $account=count($all)===1?$all[0]:null;
        $accountId=$account?(int)$account->id:0;
        $accountRef=$account?(string)($account->reference_code??''):'';
        $accountReady=$accountId>0&&preg_match('/^[A-Za-z0-9_-]{1,32}$/D',$accountRef)===1;
        $add('test_account',$accountReady,$accountReady?'Exactly one active, enabled Stripe test account is configured.':'Configure exactly one active, enabled Stripe test account.');

        $apiSecret=$accountReady?$this->secrets->activeSecret(StripeCheckoutAdapter::PROVIDER_KEY,'api_key',$accountId,'test'):null;
        $webhookSecret=$accountReady?$this->secrets->activeSecret(StripeCheckoutAdapter::PROVIDER_KEY,'webhook_signing_secret',$accountId,'test'):null;
        $apiReady=self::sealedSecretReady($apiSecret,'api_key');
        $webhookReady=self::sealedSecretReady($webhookSecret,'webhook_signing_secret');
        $add('test_api_key',$apiReady,$apiReady?'An active encrypted test API-key record is present.':'An active encrypted Stripe test API key is required.');
        $add('test_webhook_secret',$webhookReady,$webhookReady?'An active encrypted test webhook-signing record is present.':'An active encrypted test webhook-signing secret is required.');

        $webhookHook=array('Delnavazan\\Platform\\Integrations\\Payment\\Stripe\\StripeWebhookController','register');
        $webhookRegistered=function_exists('has_action')&&has_action('rest_api_init',$webhookHook)!==false
            &&class_exists(StripeWebhookController::class)&&function_exists('rest_url');
        $add('webhook_endpoint',$webhookRegistered,$webhookRegistered?'Stripe webhook REST routes are registered in this site.':'Stripe webhook REST route registration is unavailable.');

        $activationAuthorized=defined('DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED')
            &&constant('DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED')===true
            &&PaymentSecretVault::testVaultActive()&&$environmentAllowed;
        $add('deliberate_test_activation',$activationAuthorized,$activationAuthorized?'The explicit non-production test activation gate is enabled.':'Test traffic stays disabled until the non-production test vault and explicit activation gate are enabled.');

        $completed=(array)get_option('dzn_platform_completed_migrations',array());
        $schema=(string)get_option('dzn_platform_schema_version','');
        $schemaReady=defined('DZN_PLATFORM_SCHEMA_VERSION')&&$schema===(string)DZN_PLATFORM_SCHEMA_VERSION
            &&in_array('035_provider_reference_vault',$completed,true)&&in_array('036_checkout_session_authority',$completed,true);
        $add('checkout_schema',$schemaReady,$schemaReady?'The current Platform schema and checkout migrations are installed.':'The current Platform schema and checkout migrations must be installed.');

        $reconciliationHook=array('Delnavazan\\Platform\\Admin\\Controller\\PaymentExecutionController','registerRestRoutes');
        $reconciliationRegistered=function_exists('has_action')&&has_action('rest_api_init',$reconciliationHook)!==false
            &&class_exists(\Delnavazan\Platform\Core\Application\Checkout\StripeCheckoutReconciliationService::class);
        $add('reconciliation',$reconciliationRegistered,$reconciliationRegistered?'The guarded operator reconciliation endpoint is registered.':'The guarded checkout reconciliation endpoint is unavailable.');

        $workerReady=PaymentExecutionSupport::workerPrincipalId()>0;
        $add('worker_principal',$workerReady,$workerReady?'The configured least-privilege payment worker principal is available.':'Configure an active least-privilege payment worker principal.');

        return array('ready'=>count(array_filter($checks,static fn($check)=>$check['state']!=='ready'))===0,'checks'=>$checks);
    }

    private static function sealedSecretReady(?object $secret,string $class):bool{
        return $secret!==null&&(string)($secret->provider_key??'')===StripeCheckoutAdapter::PROVIDER_KEY
            &&(string)($secret->secret_class??'')===$class&&(string)($secret->mode??'')==='test'
            &&(string)($secret->state??'')==='active'&&(int)($secret->active_slot??0)===1
            &&(string)($secret->cipher_version??'')===PaymentSecretVault::cipherVersion()
            &&PaymentSecretVault::supportedKeyVersion((string)($secret->key_version??''))
            &&trim((string)($secret->nonce??''))!==''&&trim((string)($secret->ciphertext??''))!=='';
    }
}
