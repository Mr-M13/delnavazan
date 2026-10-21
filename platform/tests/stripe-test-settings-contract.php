<?php
$root=dirname(__DIR__);$vault=file_get_contents($root.'/src/Core/Application/PaymentExecution/PaymentSecretVault.php');
$service=file_get_contents($root.'/src/Core/Application/PaymentExecution/StripeTestConfigurationService.php');
$controller=file_get_contents($root.'/src/Admin/Controller/IntegrationControlPlaneController.php');
$readiness=file_get_contents($root.'/src/Core/Application/PaymentExecution/StripeTestModeReadinessService.php');
$adapter=file_get_contents($root.'/src/Integrations/Payment/Stripe/StripeCheckoutAdapter.php');
$vault=file_get_contents($root.'/src/Core/Application/PaymentExecution/PaymentSecretVault.php');
$navigation=file_get_contents($root.'/src/Admin/Controller/AdminNavigationController.php');
foreach(array('storeStripeTestConfiguration','\'stripe\'','\'test\'','dzn_manage_payment_providers','sodium_crypto_secretbox','PaymentExecutionRule::CIPHER','sk_test_','whsec_','stripe_test_credential_invalid','testEnvironmentAllowed') as $needle)
    if(strpos($vault,$needle)===false)throw new RuntimeException('Narrow encrypted Stripe TEST vault path missing '.$needle);
foreach(array('pk_test_','sk_test_','whsec_',"execution_state'=>'disabled",'complete_stripe_test_configuration_required','sandbox credentials','wp_remote_get','https://api.stripe.com/v1/account',"sslverify'=>true",'livemode','stripe_test_environment_required','testEnvironmentAllowed','activateTestCheckout','deactivateTestCheckout','testModeActivationAllowed') as $needle)
    if(strpos($service,$needle)===false)throw new RuntimeException('Stripe test configuration contract missing '.$needle);
foreach(array('dzn_stripe_test_settings_save','dzn_stripe_test_connection','Test Connection','dzn_stripe_test_activation','Enable Stripe test Checkout','Disable Stripe test Checkout','payment-provider-events/stripe/','webhookReference','type="password"','configured; leave blank to keep','Credential entry and outbound test requests are unavailable in production','safeStripeSaveResult','invalid_stripe_test_secret_key','credential values are never shown here') as $needle)
    if(strpos($controller,$needle)===false)throw new RuntimeException('Stripe Settings surface contract missing '.$needle);
foreach(array('Configure Stripe test mode','admin.php?page=dzn-stripe-settings','users with payment provider authority') as $needle)
    if(strpos($controller,$needle)===false)throw new RuntimeException('Stripe Integrations card contract missing '.$needle);
if(strpos($navigation,"'dzn-sms-settings', 'dzn-stripe-settings'")!==false)throw new RuntimeException('Capability-checked Stripe settings page must stay reachable from its card');
if(strpos($readiness,'test_publishable_key')===false)throw new RuntimeException('Stripe publishable-key readiness check missing');
if(strpos($adapter,'testModeActivationAllowed')===false||strpos($service,'preLaunchProductionTestModeActive')===false)
    throw new RuntimeException('Production Stripe checkout must remain behind its explicit gate');
foreach(array('DZN_PRELAUNCH_PRODUCTION_TEST_MODE_ENABLED','DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED','preLaunchProductionTestModeActive') as $needle)
    if(strpos($service,$needle)===false)throw new RuntimeException('Pre-launch production TEST mode gate is incomplete: '.$needle);
if(strpos($vault,'DZN_PLATFORM_PAYMENT_TEST_VAULT')===false)throw new RuntimeException('The disposable payment test-vault gate is missing');
foreach(array('PRE-LAUNCH PRODUCTION TEST MODE IS ACTIVE','DZN_PRELAUNCH_PRODUCTION_TEST_MODE_ENABLED','DZN_PLATFORM_PAYMENT_TEST_VAULT','Only Stripe TEST credentials and test Checkout') as $needle)
    if(strpos($controller,$needle)===false)throw new RuntimeException('Conspicuous production test-mode warning or disable path missing: '.$needle);
foreach(array('sk_live_','pk_live_','whsec_live_') as $live)if(strpos($service,$live)!==false)throw new RuntimeException('Live Stripe credential support must not be added');
echo "Stripe test settings contract passed\n";
