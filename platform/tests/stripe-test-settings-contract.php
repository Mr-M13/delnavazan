<?php
$root=dirname(__DIR__);$vault=file_get_contents($root.'/src/Core/Application/PaymentExecution/PaymentSecretVault.php');
$service=file_get_contents($root.'/src/Core/Application/PaymentExecution/StripeTestConfigurationService.php');
$controller=file_get_contents($root.'/src/Admin/Controller/IntegrationControlPlaneController.php');
$readiness=file_get_contents($root.'/src/Core/Application/PaymentExecution/StripeTestModeReadinessService.php');
$adapter=file_get_contents($root.'/src/Integrations/Payment/Stripe/StripeCheckoutAdapter.php');
$navigation=file_get_contents($root.'/src/Admin/Controller/AdminNavigationController.php');
foreach(array('storeStripeTestConfiguration','\'stripe\'','\'test\'','dzn_manage_payment_providers','sodium_crypto_secretbox','PaymentExecutionRule::CIPHER') as $needle)
    if(strpos($vault,$needle)===false)throw new RuntimeException('Narrow encrypted Stripe TEST vault path missing '.$needle);
foreach(array('pk_test_','sk_test_','whsec_',"execution_state'=>'disabled",'complete_stripe_test_configuration_required','sandbox credentials','wp_remote_get','https://api.stripe.com/v1/account',"sslverify'=>true",'livemode') as $needle)
    if(strpos($service,$needle)===false)throw new RuntimeException('Stripe test configuration contract missing '.$needle);
foreach(array('dzn_stripe_test_settings_save','dzn_stripe_test_connection','Test Connection','payment-provider-events/stripe/stripe_test','type="password"','configured; leave blank to keep') as $needle)
    if(strpos($controller,$needle)===false)throw new RuntimeException('Stripe Settings surface contract missing '.$needle);
foreach(array('Configure Stripe test mode','admin.php?page=dzn-stripe-settings','users with payment provider authority') as $needle)
    if(strpos($controller,$needle)===false)throw new RuntimeException('Stripe Integrations card contract missing '.$needle);
if(strpos($navigation,"'dzn-sms-settings', 'dzn-stripe-settings'")!==false)throw new RuntimeException('Capability-checked Stripe settings page must stay reachable from its card');
if(strpos($readiness,'test_publishable_key')===false)throw new RuntimeException('Stripe publishable-key readiness check missing');
if(strpos($adapter,"array('local','development','staging')")===false||strpos($adapter,'testModeActivationAllowed')===false)
    throw new RuntimeException('Production Stripe checkout must remain disabled');
foreach(array('sk_live_','pk_live_','whsec_live_') as $live)if(strpos($service,$live)!==false)throw new RuntimeException('Live Stripe credential support must not be added');
echo "Stripe test settings contract passed\n";
