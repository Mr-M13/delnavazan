<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

use Delnavazan\Platform\Core\Application\CommercialIdempotency;
use Delnavazan\Platform\Core\Infrastructure\Repository\{CommercialPaymentRepository,PaymentProviderRepository,PaymentSecretRepository};
use Delnavazan\Platform\Integrations\Payment\Stripe\{StripeCheckoutAdapter,StripeWebhookController};

/**
 * Secret-free readiness checklist for a deliberately activated Stripe test run.
 *
 * Configuration readiness and verified end-to-end settlement are reported separately: every local
 * gate can be ready while no test payment has ever settled, and a single closed gate makes the whole
 * configuration unusable. Each check is proved independently — process wiring, environment, vault,
 * account authority binding, sealed credential records, decryption, route registration and activation —
 * so the page can never say "Ready" while a webhook would fail closed or Checkout would refuse.
 */
final class StripeTestModeReadinessService {
    private const VIEW_CAPABILITY='dzn_view_payment_execution_authority';

    public function __construct(
        private ?PaymentProviderRepository $providers=null,
        private ?PaymentSecretRepository $secrets=null,
        private ?PaymentSecretVault $vault=null,
        private ?CommercialPaymentRepository $payments=null
    ){
        $this->providers??=new PaymentProviderRepository();
        $this->secrets??=new PaymentSecretRepository();
        $this->vault??=new PaymentSecretVault($this->secrets,$this->providers);
        $this->payments??=new CommercialPaymentRepository();
    }

    /** @return array{ready:bool,checkout_ready:bool,settlement_verified:bool,settlement_detail:string,checks:array<string,array{state:string,detail:string}>} */
    public function read():array{
        PaymentExecutionSupport::requireCapability(self::VIEW_CAPABILITY);
        $checks=array();
        $add=static function(string $key,bool $ready,string $detail)use(&$checks):void{
            $checks[$key]=array('state'=>$ready?'ready':'blocked','detail'=>$detail);
        };

        // --- process wiring ---------------------------------------------------------------------
        $add('adapter',class_exists(StripeCheckoutAdapter::class),'Stripe Checkout test adapter is loaded.');
        $translatorReady=PaymentProviderRegistry::hasTranslator(StripeCheckoutAdapter::PROVIDER_KEY);
        $add('translator_registered',$translatorReady,$translatorReady?'The Stripe provider-event translator is registered in this process.':'The Stripe provider-event translator is not registered; every webhook would fail closed.');
        $portReady=PaymentProviderRegistry::hasExecutionPort(StripeCheckoutAdapter::PROVIDER_KEY);
        $add('execution_port_registered',$portReady,$portReady?'The Stripe payment-execution port is registered in this process.':'The Stripe payment-execution port is not registered; the payment seam cannot resolve.');
        $liveClosed=!PaymentExecutionRule::liveExecutionAuthorised(StripeCheckoutAdapter::PROVIDER_KEY);
        $add('live_execution_closed',$liveClosed,$liveClosed?'Live Stripe execution remains closed by the locked provider set.':'Live Stripe execution must never be authorised in this build.');

        // --- explicit gates ---------------------------------------------------------------------
        $environment=function_exists('wp_get_environment_type')?(string)wp_get_environment_type():'unknown';
        $production=$environment==='production';
        $environmentAllowed=StripeTestConfigurationService::testEnvironmentAllowed();
        $environmentDetail=$environmentAllowed
            ?($production?'Pre-launch production TEST mode is explicitly enabled; only Stripe test credentials and test Checkout are permitted.':'Environment is recognised as non-production.')
            :'Environment must be local, development, staging, or explicitly enabled pre-launch production TEST mode.';
        $add('environment',$environmentAllowed,$environmentDetail);

        $vaultActive=PaymentSecretVault::testVaultActive();
        $add('test_vault',$vaultActive,$vaultActive?'The constant-gated disposable test vault is active.':'The disposable test vault constant is not active, so no Stripe test secret can be read.');
        $operatorEnabled=defined('DZN_PRELAUNCH_PRODUCTION_TEST_MODE_ENABLED')&&constant('DZN_PRELAUNCH_PRODUCTION_TEST_MODE_ENABLED')===true;
        $checkoutAuthorized=defined('DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED')&&constant('DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED')===true;
        $add('production_operator_gate',!$production||$operatorEnabled,$production
            ?($operatorEnabled?'The pre-launch production switch is explicitly enabled.':'The pre-launch production switch is not enabled; production credential entry is closed.')
            :'Not required outside production.');
        $add('checkout_authorization',$checkoutAuthorized,$checkoutAuthorized?'The outbound test Checkout operator authorization is enabled.':'The outbound test Checkout operator authorization is not enabled.');

        // --- account authority ------------------------------------------------------------------
        $testAccounts=array_values(array_filter($this->providers->accountsForProvider(StripeCheckoutAdapter::PROVIDER_KEY),static fn($account)=>(string)($account->mode??'')==='test'
            &&(string)($account->state??'')==='active'));
        $account=count($testAccounts)===1?$testAccounts[0]:null;
        $accountId=$account?(int)$account->id:0;
        $canonical=$account?PaymentProviderAccountService::canonicalReference($account):null;
        $add('test_account',$accountId>0,$accountId>0?'Exactly one active Stripe test account is configured.':'Configure exactly one active Stripe test account.');
        $add('account_reference_binding',$canonical!==null,$canonical!==null
            ?'The account reference presented in Checkout metadata hashes to the stored account authority digest.'
            :'The account reference does not match the stored authority digest, so verified webhooks would be refused.');
        $checkoutEnabled=$account&&(string)$account->execution_state==='enabled'&&$canonical!==null;
        $add('checkout_enabled',$checkoutEnabled,$checkoutEnabled?'Deliberate test Checkout execution is enabled.':'Deliberate test Checkout execution is not enabled.');

        // --- sealed credentials -----------------------------------------------------------------
        $apiSecret=$accountId>0?$this->secrets->activeSecret(StripeCheckoutAdapter::PROVIDER_KEY,'api_key',$accountId,'test'):null;
        $webhookSecret=$accountId>0?$this->secrets->activeSecret(StripeCheckoutAdapter::PROVIDER_KEY,'webhook_signing_secret',$accountId,'test'):null;
        $apiReady=self::sealedSecretReady($apiSecret,'api_key');
        $webhookReady=self::sealedSecretReady($webhookSecret,'webhook_signing_secret');
        $publishableReady=is_string(get_option('dzn_stripe_test_publishable_key',''))&&preg_match('/^pk_test_[A-Za-z0-9]{16,}$/D',(string)get_option('dzn_stripe_test_publishable_key',''))===1;
        $add('test_publishable_key',$publishableReady,$publishableReady?'A Stripe test publishable key is configured.':'A Stripe test publishable key is required.');
        $add('test_api_key',$apiReady,$apiReady?'An active encrypted test API-key record is present.':'An active encrypted Stripe test API key is required.');
        $add('test_webhook_secret',$webhookReady,$webhookReady?'An active encrypted test webhook-signing record is present.':'An active encrypted test webhook-signing secret is required.');
        $apiDecryptable=$apiReady&&$this->vault->canDecrypt((int)$apiSecret->id,'api_key',StripeCheckoutAdapter::PROVIDER_KEY);
        $webhookDecryptable=$webhookReady&&$this->vault->canDecrypt((int)$webhookSecret->id,'webhook_signing_secret',StripeCheckoutAdapter::PROVIDER_KEY);
        $retrievable=$apiDecryptable&&$webhookDecryptable;
        $add('credentials_retrievable',$retrievable,$retrievable?'Both encrypted test credentials decrypt to valid test-mode values.':'A stored test credential cannot be decrypted or is not a valid test-mode value.');

        // --- route and platform prerequisites ----------------------------------------------------
        $webhookHook=array('Delnavazan\\Platform\\Integrations\\Payment\\Stripe\\StripeWebhookController','register');
        $webhookRegistered=function_exists('has_action')&&has_action('rest_api_init',$webhookHook)!==false
            &&class_exists(StripeWebhookController::class)&&function_exists('rest_url')
            &&PaymentProviderRegistry::hasTranslator(StripeCheckoutAdapter::PROVIDER_KEY);
        $add('webhook_endpoint',$webhookRegistered,$webhookRegistered?'Stripe webhook REST routes and their translator are registered in this site.':'Stripe webhook route registration or provider translation is unavailable.');

        $activationAuthorized=$checkoutAuthorized&&$vaultActive&&$environmentAllowed;
        $add('deliberate_test_activation',$activationAuthorized,$activationAuthorized?'The explicit test vault, environment, and operator activation gates are enabled.':'Test traffic stays disabled until the test vault, environment, and operator activation gates are enabled.');

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

        // --- verified end-to-end settlement (reported separately from configuration) -------------
        $settlementCount=0;
        if($accountId>0&&$canonical!==null){
            $settlementCount=$this->payments->acceptedSettlementCountForProviderAccount(
                StripeCheckoutAdapter::PROVIDER_KEY,CommercialIdempotency::evidence($canonical)
            );
        }
        $settlementVerified=$settlementCount>0;
        $settlementDetail=$settlementVerified
            ?'At least one accepted Stripe test payment is settled for this account.'
            :'No accepted Stripe test settlement is recorded for this account; configuration readiness does not prove a payment works end to end.';

        $configurationChecks=array_filter($checks,static fn(string $key):bool=>$key!=='checkout_enabled',ARRAY_FILTER_USE_KEY);
        $configurationReady=count(array_filter($configurationChecks,static fn($check)=>$check['state']!=='ready'))===0;
        return array(
            'ready'=>$configurationReady,
            'checkout_ready'=>$configurationReady&&$checkoutEnabled,
            'settlement_verified'=>$settlementVerified,
            'settlement_detail'=>$settlementDetail,
            'checks'=>$checks,
        );
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
