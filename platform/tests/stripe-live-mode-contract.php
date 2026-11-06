<?php
/**
 * Source contract for the dashboard-managed Stripe Live Mode extension.
 *
 * The test enforces the boundaries that no runtime test can prove on its own: the dashboard never
 * renders a secret, save never activates, live execution remains gated, the webhook URL is derived
 * from the registered account selector, and the readiness checklist covers every required control.
 */
$root = dirname(__DIR__);
$controller = file_get_contents($root . '/src/Admin/Controller/IntegrationControlPlaneController.php');
$liveConfig = file_get_contents($root . '/src/Core/Application/PaymentExecution/StripeLiveConfigurationService.php');
$liveReadiness = file_get_contents($root . '/src/Core/Application/PaymentExecution/StripeLiveReadinessService.php');
$mode = file_get_contents($root . '/src/Core/Application/PaymentExecution/StripeProviderMode.php');
$vault = file_get_contents($root . '/src/Core/Application/PaymentExecution/PaymentSecretVault.php');
$rule = file_get_contents($root . '/src/Core/Application/PaymentExecution/PaymentExecutionRule.php');
$adapter = file_get_contents($root . '/src/Integrations/Payment/Stripe/StripeCheckoutAdapter.php');
$paymentAdapter = file_get_contents($root . '/src/Integrations/Payment/Stripe/StripePaymentAdapter.php');
$translator = file_get_contents($root . '/src/Integrations/Payment/Stripe/StripeEventTranslator.php');
$migrator = file_get_contents($root . '/src/Core/Infrastructure/Migration/Migrator.php');
$credentialSource = file_get_contents($root . '/src/Integrations/Payment/Stripe/StripeVaultCheckoutCredentialSource.php');
$reconciliation = file_get_contents($root . '/src/Core/Application/Checkout/StripeCheckoutReconciliationService.php');
$plugin = file_get_contents($root . '/delnavazan-platform.php');

$contains = static function(string $haystack,array $needles,string $message):void{
    foreach($needles as $needle)if(strpos($haystack,$needle)===false)throw new RuntimeException($message.': '.$needle);
};

// Dashboard surface: environment, credentials, webhook operations, instructions and confirmed activation.
$contains($controller,array(
    'dzn_stripe_live_settings_save','dzn_stripe_live_connection','dzn_stripe_live_webhook_verify','dzn_stripe_live_activation',
    'check_admin_referer','authorizeStripeSettings','dzn_manage_payment_providers',
    'Live publishable key','Live secret key','Live webhook signing secret','account_identifier',
    'Copy URL','Test live connection','Verify live webhook','Activate Live Payments','confirm_live_activation',
    'enable_live_payments',
    'Deactivate live payments (rollback to Test)','Stripe live setup instructions','Snapshot payload format',
    'requiredWebhookEvents','supportedRawTypes','Last successfully processed webhook','Last rejected webhook',
    'Last successful live payment','Last live payment failure','StripeLiveReadinessService::STAGE_ACTIVE',
),'Stripe Live dashboard is incomplete');
if(strpos($controller,"type=\"password\"")===false)throw new RuntimeException('Live secret fields must be write-only password inputs');
if(strpos($controller,"name=\"settings[secret_key]\" value=\"")!==false||strpos($controller,"name=\"settings[webhook_signing_secret]\" value=\"")!==false)
    throw new RuntimeException('The dashboard must never prefill a saved secret value');
if(strpos($controller,"name=\"settings[secret_key]\" value=\"")!==false)throw new RuntimeException('No saved secret may be rendered as an input value');

// Saving credentials is never activation.
$contains($liveConfig,array(
    'storeStripeLiveConfiguration','StripeProviderMode::liveEnvironmentAllowed','ACTIVATION_INACTIVE',
    'execution_state\'=>\'disabled','invalidateVerification','failClosedAfterConfigurationChange',
    'stripe_live_environment_required','invalid_stripe_live_secret_key','complete_stripe_live_configuration_required',
    'function activate','function deactivate','stripe_live_readiness_blocked','deliberate_stripe_live_activation',
    'deliberate_stripe_live_deactivation','authenticated_platform','StripeLiveActivationRepository',
    'stripe_live_account_reachable','stripe_live_mode_mismatch','stripe_live_webhook_verified',
    'enabled_events','api_version','requiredWebhookEvents',
),'Stripe Live configuration service is incomplete');
if(preg_match('/function save\([^}]+activate\(/s',$liveConfig)===1)throw new RuntimeException('Saving credentials must not call activation');
if(strpos($liveConfig,"'status'=>StripeProviderMode::ACTIVATION_ACTIVE")!==false&&strpos($liveConfig,'activated_at')===false)
    throw new RuntimeException('Activation must record its instant');

// Readiness must independently prove every blocking control and distinguish all five stages.
$contains($liveReadiness,array(
    'STAGE_NOT_CONFIGURED','STAGE_CONFIGURED','STAGE_VERIFIED','STAGE_READY','STAGE_ACTIVE',
    'environment','https_ingress','translator_registered','execution_port_registered','live_account',
    'account_reference_binding','live_publishable_key','live_api_key','live_webhook_secret',
    'credentials_verified','webhook_endpoint','webhook_destination_verified','webhook_verified_event',
    'checkout_schema','reconciliation','worker_principal','activation_consistent',
    'automatic_execution_closed','070_stripe_live_mode','requiredWebhookEvents','supportedWebhookApiVersion',
),'Stripe Live readiness is incomplete');
if(strpos($liveReadiness,'canDecrypt')!==false)throw new RuntimeException('Rendering readiness must not decrypt stored credentials');
if(strpos($liveReadiness,'acceptedSettlementCountForProviderAccount')===false)
    throw new RuntimeException('Readiness must separate configuration from verified settlement');

// Mode and execution authority.
$contains($mode,array(
    'TEST_ACCOUNT_REFERENCE','LIVE_ACCOUNT_REFERENCE','LIVE_ACTIVATION_OPTION','LIVE_PUBLISHABLE_OPTION',
    'liveEnvironmentAllowed','liveActive','liveAuthorising','sk_live_','pk_live_','cs_live_','cs_test_',
    'SUPPORTED_WEBHOOK_API_VERSIONS',"'2026-10-28.endive'",'supportedWebhookApiVersion',
),'Stripe mode contract is incomplete');
$contains($rule,array("LIVE_EXECUTION_PROVIDERS=array()","ACTIVATABLE_LIVE_PROVIDERS=array('stripe')",'StripeProviderMode::liveAuthorising'),'Live execution is not gated through the activation record');
$contains($vault,array('storeStripeLiveConfiguration','stripe_live_credential_invalid','revealForStripeLiveConnection','validateStripeLiveCredentials','sodium_crypto_secretbox'),'Encrypted live vault path is incomplete');

// Adapter/translator/reconciliation are mode-aware and cannot cross modes.
$contains($adapter,array('activeMode','credentialsForMode','liveModeActivationAllowed','StripeProviderMode::liveActive','StripeProviderMode::apiKeyPattern','StripeProviderMode::checkoutReference'),'Checkout adapter is not mode-safe');
$contains($adapter,array('readAccessAllowed','liveSecretReadable'),'Deactivation would interrupt historical live reconciliation');
$contains($paymentAdapter,array('PaymentExecutionRule::LIVE_EXECUTION_PROVIDERS','live_execution_not_authorised'),'The background live collection port is not compile-time closed');
$contains($translator,array('requiredWebhookEvents','supportedRawTypes','StripeProviderMode::member','eventLive','StripeProviderMode::checkoutReference','provider_recurring_semantics_unresolved'),'Webhook translator is not mode-safe');
$contains($credentialSource,array('activeMode','credentialsForMode','StripeProviderMode::LIVE','liveModeActivationAllowed','canonicalReference'),'Credential source is not mode-safe');
$contains($reconciliation,array('provider_mode','StripeProviderMode::checkoutReference','provider_session_mismatch','provider_event_mismatch'),'Reconciliation does not preserve the recorded mode');

// Migration and schema version.
$contains($migrator,array('install_stripe_live_mode','verify_stripe_live_mode_schema','070_stripe_live_mode','provider_mode','stripe_live_activation_events'),'Stripe live migration is incomplete');
$contains($migrator,array(
    "'request_key_digest','provider_key','provider_reference_digest','created_at'",
    "'intro_checkout_sessions'=>array('uid','intro_obligation_id','student_id','amount_minor','currency','state','active_slot','request_key_digest','provider_key','provider_reference_digest'",
),'Pre-070 schema verifiers would block migration 070');
if(strpos($migrator,'ADD COLUMN provider_mode varchar(8) NOT NULL DEFAULT \'test\'')===false)
    throw new RuntimeException('Historical Checkout rows must default to test mode');
if(!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/",$plugin,$schema)||(int)$schema[1]<59)throw new RuntimeException('Schema identity was not advanced for migration 070');

// The public webhook selector must be derived from the registered account, never hardcoded as live.
if(strpos($liveConfig,'function webhookUrl')===false||strpos($liveConfig,'webhookReference')===false)
    throw new RuntimeException('The live webhook URL must be derived from the registered selector');
if(strpos($controller,'$live->webhookUrl()')===false)
    throw new RuntimeException('The dashboard must display the registered live webhook URL');

echo "Stripe live mode contract passed\n";
