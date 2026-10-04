<?php
$r=file_get_contents(dirname(__DIR__).'/src/Core/Application/Checkout/CheckoutRequest.php');
$p=file_get_contents(dirname(__DIR__).'/src/Core/Application/Checkout/CheckoutSessionPort.php');
$s=file_get_contents(dirname(__DIR__).'/src/Integrations/Payment/Stripe/StripeCheckoutAdapter.php');
$c=file_get_contents(dirname(__DIR__).'/src/Integrations/Payment/Stripe/StripeVaultCheckoutCredentialSource.php');
foreach(array('amountMinor','currency','obligationReference','idempotencyKey','attemptUid') as $n)if(strpos($r,$n)===false)throw new RuntimeException('Checkout request missing '.$n);
foreach(array('Customer checkout transport only','cannot settle commercial truth') as $n)if(strpos($p,$n)===false)throw new RuntimeException('Checkout port authority boundary missing '.$n);
if(strpos($p,'function retrieve(string $providerReference)')===false)throw new RuntimeException('Checkout provider retrieval boundary is missing');
foreach(array("'mode'=>'payment'",'unit_amount','amountMinor()','currency','strtolower','metadata][obligation_reference]','metadata][checkout_attempt_uid]',"'success_url'","'cancel_url'",'checkout_provider_unconfigured','Idempotency-Key','https://api.stripe.com/v1/checkout/sessions') as $n)if(strpos($s,$n)===false)throw new RuntimeException('Stripe Checkout contract missing '.$n);
if(strpos($s,'CommercialPaymentService')!==false||strpos($s,'client_secret')!==false)throw new RuntimeException('Checkout foundation must remain non-authoritative and must not use a client secret');
foreach(array('DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED','wp_get_environment_type','array(\'local\',\'development\',\'staging\')','PaymentSecretVault::testVaultActive') as $n)if(strpos($s,$n)===false)throw new RuntimeException('Stripe Checkout test-mode lockout missing '.$n);
foreach(array('sk_test_','whsec_','(string)$account->mode===\'test\'','activeSecret(StripeCheckoutAdapter::PROVIDER_KEY,\'api_key\'') as $n)if(strpos($c,$n)===false)throw new RuntimeException('Stripe Checkout credential validation missing '.$n);
foreach(array('wp_remote_post','wp_remote_get','/v1/checkout/sessions/','validRetrievedSession','provider_session_not_found') as $n)if(strpos($s,$n)===false)throw new RuntimeException('Stripe Checkout provider lifecycle boundary is missing '.$n);
echo "Customer checkout foundation contract passed\n";
