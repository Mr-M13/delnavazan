<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

use Delnavazan\Platform\Core\Application\CommercialIdempotency;
use Delnavazan\Platform\Core\Infrastructure\Repository\{CommercialPaymentRepository,PaymentProviderRepository,PaymentSecretRepository};
use Delnavazan\Platform\Integrations\Payment\Stripe\{StripeCheckoutAdapter,StripeEventTranslator,StripeWebhookController};

/**
 * Secret-free live-mode checklist for Stripe.
 *
 * Configuration, verification and activation are separate stages:
 *   Not configured -> Configured -> Verified -> Ready -> Active
 *
 * "Ready" is never derived from the presence of an API key. It requires an explicit live balance check,
 * Stripe's own confirmation of the Delnavazan event destination, its enabled event list and payload/API
 * version, the encrypted signing secret, the current schema, the payment worker and reconciliation.
 * Rendering this page never decrypts a secret and never makes a provider call.
 */
final class StripeLiveReadinessService {
    private const VIEW_CAPABILITY='dzn_view_payment_execution_authority';
    public const STAGE_NOT_CONFIGURED='Not configured';
    public const STAGE_CONFIGURED='Configured';
    public const STAGE_VERIFIED='Verified';
    public const STAGE_READY='Ready';
    public const STAGE_ACTIVE='Active';

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

    /** @return array{ready:bool,verified:bool,active:bool,stage:string,checks:array<string,array{state:string,detail:string}>,settlement_verified:bool,settlement_detail:string} */
    public function read():array{
        PaymentExecutionSupport::requireCapability(self::VIEW_CAPABILITY);
        $checks=array();
        $add=static function(string $key,bool $ok,string $detail)use(&$checks):void{
            $checks[$key]=array('state'=>$ok?'ready':'blocked','detail'=>$detail);
        };

        $add('environment',StripeProviderMode::liveEnvironmentAllowed(),StripeProviderMode::liveEnvironmentAllowed()
            ?'Live credentials are being managed in a production environment.'
            :'Live credentials can only be configured in a production environment.');

        // A webhook is refused with `https_required` when WordPress cannot see TLS. That is a common
        // hosting/Cloudflare failure, so readiness reports it explicitly instead of blaming Stripe.
        $localEnvironment=function_exists('wp_get_environment_type')&&(string)wp_get_environment_type()==='local';
        $httpsIngress=$localEnvironment||(function_exists('is_ssl')&&is_ssl())||PaymentExecutionRule::trustedProxyHeaders()!==array();
        $add('https_ingress',$httpsIngress,$httpsIngress
            ?'The webhook can observe a TLS request (direct TLS, local environment, or a configured trusted proxy header).'
            :'WordPress cannot see HTTPS and no trusted proxy header is configured. Stripe deliveries would be refused with https_required.');

        $add('adapter',class_exists(StripeCheckoutAdapter::class),'The Stripe Checkout adapter is loaded.');
        $translatorReady=PaymentProviderRegistry::hasTranslator(StripeCheckoutAdapter::PROVIDER_KEY);
        $add('translator_registered',$translatorReady,$translatorReady?'The Stripe provider-event translator is registered in this process.':'The Stripe provider-event translator is not registered; every webhook would fail closed.');
        $portReady=PaymentProviderRegistry::hasExecutionPort(StripeCheckoutAdapter::PROVIDER_KEY);
        $add('execution_port_registered',$portReady,$portReady?'The Stripe payment-execution port is registered in this process.':'The Stripe payment-execution port is not registered; the payment seam cannot resolve.');

        $accounts=array_values(array_filter($this->providers->accountsForProvider(StripeCheckoutAdapter::PROVIDER_KEY),
            static fn($account)=>(string)($account->mode??'')==='live'&&(string)($account->state??'')==='active'));
        $account=count($accounts)===1?$accounts[0]:null;
        $accountId=$account?(int)$account->id:0;
        $canonical=$account?PaymentProviderAccountService::canonicalReference($account):null;
        $add('live_account',$accountId>0,$accountId>0?'Exactly one active Stripe live account is registered as '.StripeProviderMode::LIVE_ACCOUNT_REFERENCE.'.':'Register exactly one active Stripe live account.');
        $add('account_reference_binding',$canonical!==null,$canonical!==null
            ?'The live account reference hashes to the stored account authority digest.'
            :'The live account reference does not match the stored authority digest, so its webhooks would be refused.');

        $publishable=$this->publishableKey();
        $apiSecret=$accountId>0?$this->secrets->activeSecret('stripe','api_key',$accountId,'live'):null;
        $webhookSecret=$accountId>0?$this->secrets->activeSecret('stripe','webhook_signing_secret',$accountId,'live'):null;
        $apiReady=self::sealedSecretReady($apiSecret,'api_key');
        $webhookReady=self::sealedSecretReady($webhookSecret,'webhook_signing_secret');
        $add('live_publishable_key',$publishable!=='','A Stripe live publishable key is configured.');
        $add('live_api_key',$apiReady,$apiReady?'An active encrypted Stripe live API-key record is present.':'An active encrypted Stripe live API key is required.');
        $add('live_webhook_secret',$webhookReady,$webhookReady?'An active encrypted Stripe live webhook-signing record is present.':'An active encrypted Stripe live webhook-signing secret is required.');

        $connection=StripeLiveConfigurationService::connectionResult();
        $connectionOk=($connection['state']??'')==='success'
            &&($connection['code']??'')==='stripe_live_account_reachable'
            &&(int)($connection['account_id']??0)===$accountId
            &&$accountId>0;
        $add('credentials_verified',$connectionOk,$connectionOk
            ?'Stripe accepted the live secret key and confirmed a live balance response.'
            :'Run Test live connection after the live account and credentials are present.');

        $webhookHook=array(StripeWebhookController::class,'register');
        $webhookRegistered=function_exists('has_action')&&has_action('rest_api_init',$webhookHook)!==false
            &&class_exists(StripeWebhookController::class)&&function_exists('rest_url')
            &&$translatorReady;
        $add('webhook_endpoint',$webhookRegistered,$webhookRegistered?'The live webhook REST route and its translator are registered in this site.':'Stripe webhook route registration or provider translation is unavailable.');

        $verification=StripeLiveConfigurationService::webhookVerification();
        $expectedUrl=$this->webhookUrl($canonical);
        $enabledEvents=is_array($verification['enabled_events']??null)?$verification['enabled_events']:array();
        $eventsVerified=in_array('*',$enabledEvents,true)
            ||array_values(array_diff(StripeEventTranslator::requiredWebhookEvents(),$enabledEvents))===array();
        $webhookVerified=($verification['state']??'')==='success'
            &&($verification['code']??'')==='stripe_live_webhook_verified'
            &&(int)($verification['account_id']??0)===$accountId
            &&$accountId>0
            &&hash_equals($expectedUrl,(string)($verification['url']??''))
            &&$eventsVerified
            &&StripeProviderMode::supportedWebhookApiVersion((string)($verification['api_version']??''));
        $add('webhook_destination_verified',$webhookVerified,$webhookVerified
            ?'Stripe confirms the Delnavazan live destination, its required events and payload/API version.'
            :'Run Verify live webhook after creating the Stripe live event destination and saving its signing secret.');

        $verifiedReceipts=$accountId>0?$this->providers->verifiedReceiptCountForAccount($accountId):0;
        $add('webhook_verified_event',$verifiedReceipts>0,$verifiedReceipts>0
            ?'At least one live webhook delivery has been signature-verified for this account.'
            :'No live webhook delivery has been verified yet. This is not required to activate, but it is the end-to-end webhook proof.');

        $completed=(array)get_option('dzn_platform_completed_migrations',array());
        $schema=(string)get_option('dzn_platform_schema_version','');
        $schemaReady=defined('DZN_PLATFORM_SCHEMA_VERSION')&&$schema===(string)DZN_PLATFORM_SCHEMA_VERSION
            &&in_array('070_stripe_live_mode',$completed,true);
        $add('checkout_schema',$schemaReady,$schemaReady?'The current Platform schema (including live-mode isolation) is installed.':'The current Platform schema and migration 070 must be installed.');

        $reconciliationHook=array('Delnavazan\\Platform\\Admin\\Controller\\PaymentExecutionController','registerRestRoutes');
        $reconciliationRegistered=function_exists('has_action')&&has_action('rest_api_init',$reconciliationHook)!==false
            &&class_exists(\Delnavazan\Platform\Core\Application\Checkout\StripeCheckoutReconciliationService::class);
        $add('reconciliation',$reconciliationRegistered,$reconciliationRegistered?'The guarded operator reconciliation endpoint is registered.':'The checkout reconciliation endpoint is unavailable.');

        $workerReady=PaymentExecutionSupport::workerPrincipalId()>0;
        $add('worker_principal',$workerReady,$workerReady?'The configured least-privilege payment worker principal is available.':'Configure an active least-privilege payment worker principal.');
        $automaticLiveClosed=!in_array(StripeCheckoutAdapter::PROVIDER_KEY,PaymentExecutionRule::LIVE_EXECUTION_PROVIDERS,true);
        $add('automatic_execution_closed',$automaticLiveClosed,$automaticLiveClosed
            ?'Automatic/background Stripe live collection remains fail-closed; this release enables hosted Checkout only.'
            :'Automatic Stripe live collection has separate code authority.');

        $activation=StripeProviderMode::activation();
        $activationConsistent=true;
        $activationDetail='Live activation is not recorded, and live execution is disabled.';
        if($activation['status']===StripeProviderMode::ACTIVATION_ACTIVATING){
            $activationConsistent=$accountId>0&&$activation['account_id']===$accountId
                &&$activation['account_reference']===StripeProviderMode::LIVE_ACCOUNT_REFERENCE&&$account
                &&in_array((string)$account->execution_state,array('disabled','enabled'),true);
            $activationDetail=$activationConsistent?'Live activation is in its fail-closed transition.':'The live activation transition no longer matches the configured account.';
        }elseif($activation['status']===StripeProviderMode::ACTIVATION_ACTIVE){
            $activationConsistent=$accountId>0&&$activation['account_id']===$accountId
                &&$activation['account_reference']===StripeProviderMode::LIVE_ACCOUNT_REFERENCE
                &&$account&&(string)$account->execution_state==='enabled';
            $activationDetail=$activationConsistent?'Live activation is recorded and the live account is enabled.':'Live activation state does not match the live account, so live charging is blocked.';
        }elseif($account&&(string)$account->execution_state==='enabled'){
            $activationConsistent=false;
            $activationDetail='The live account is enabled without a recorded live activation. Disable it and re-run activation.';
        }
        $add('activation_consistent',$activationConsistent,$activationDetail);

        $settlementCount=0;
        if($accountId>0&&$canonical!==null){
            $settlementCount=$this->payments->acceptedSettlementCountForProviderAccount(
                StripeCheckoutAdapter::PROVIDER_KEY,CommercialIdempotency::evidence($canonical)
            );
        }
        $settlementVerified=$settlementCount>0;
        $settlementDetail=$settlementVerified
            ?'At least one accepted Stripe live payment is settled for this account.'
            :'No accepted Stripe live settlement is recorded yet; configuration readiness does not prove a payment works end to end.';
        $add('settlement_verified',$settlementVerified,$settlementDetail);

        $nonBlocking=array('webhook_verified_event','settlement_verified');
        $required=array_filter($checks,static fn(string $key):bool=>!in_array($key,$nonBlocking,true),ARRAY_FILTER_USE_KEY);
        $ready=count(array_filter($required,static fn($check)=>$check['state']!=='ready'))===0;
        $verified=$accountId>0&&$connectionOk&&$webhookVerified;
        $active=$accountId>0&&$activation['status']===StripeProviderMode::ACTIVATION_ACTIVE
            &&$activation['account_id']===$accountId&&$account&&(string)$account->execution_state==='enabled'
            &&StripeProviderMode::liveActive();

        $stage=self::STAGE_NOT_CONFIGURED;
        if($accountId>0){
            $stage=self::STAGE_CONFIGURED;
            if($verified)$stage=self::STAGE_VERIFIED;
            if($ready)$stage=self::STAGE_READY;
            if($active)$stage=self::STAGE_ACTIVE;
        }
        return array(
            'ready'=>$ready,'verified'=>$verified,'active'=>$active,'stage'=>$stage,
            'checks'=>$checks,'settlement_verified'=>$settlementVerified,'settlement_detail'=>$settlementDetail,
            'settlement_count'=>$settlementCount,'account_id'=>$accountId,'activation'=>$activation,
        );
    }

    private function publishableKey():string{
        $key=function_exists('get_option')?(string)get_option(StripeProviderMode::LIVE_PUBLISHABLE_OPTION,''):'';
        return preg_match(StripeProviderMode::publishablePattern('live'),$key)===1?$key:'';
    }
    private function webhookUrl(?string $canonical):string{
        if(!function_exists('rest_url'))return '';
        return (string)rest_url('delnavazan-platform/v1/payment-provider-events/stripe/'.($canonical??StripeProviderMode::LIVE_ACCOUNT_REFERENCE));
    }
    private static function sealedSecretReady(?object $secret,string $class):bool{
        return $secret!==null&&(string)($secret->provider_key??'')===StripeCheckoutAdapter::PROVIDER_KEY
            &&(string)($secret->secret_class??'')===$class&&(string)($secret->mode??'')==='live'
            &&(string)($secret->state??'')==='active'&&(int)($secret->active_slot??0)===1
            &&(string)($secret->cipher_version??'')===PaymentSecretVault::cipherVersion()
            &&PaymentSecretVault::supportedKeyVersion((string)($secret->key_version??''))
            &&trim((string)($secret->nonce??''))!==''&&trim((string)($secret->ciphertext??''))!=='';
    }
}
