<?php
$adapter=file_get_contents(dirname(__DIR__).'/src/Integrations/Payment/Stripe/StripeCheckoutAdapter.php');
$credentials=file_get_contents(dirname(__DIR__).'/src/Integrations/Payment/Stripe/StripeVaultCheckoutCredentialSource.php');
$request=file_get_contents(dirname(__DIR__).'/src/Core/Application/Checkout/CheckoutRequest.php');
$events=file_get_contents(dirname(__DIR__).'/src/Integrations/Payment/Stripe/StripeEventTranslator.php');
foreach(array('DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED','PaymentSecretVault::testVaultActive',"array('local','development','staging')",'testModeActivationAllowed') as $needle)
    if(strpos($adapter,$needle)===false)throw new RuntimeException('Stripe test activation contract missing '.$needle);
foreach(array('(string)$account->mode===\'test\'','count($accounts)!==1',"activeSecret(StripeCheckoutAdapter::PROVIDER_KEY,'api_key'","activeSecret(StripeCheckoutAdapter::PROVIDER_KEY,'webhook_signing_secret'",'sk_test_','whsec_') as $needle)
    if(strpos($credentials,$needle)===false)throw new RuntimeException('Stripe test configuration contract missing '.$needle);
foreach(array('https://api.stripe.com/v1/checkout/sessions','Idempotency-Key','Authorization',"'redirection'=>0","'sslverify'=>true","'timeout'=>15",'wp_remote_post','131072','provider_request_rejected','provider_unavailable') as $needle)
    if(strpos($adapter,$needle)===false)throw new RuntimeException('Stripe Checkout request contract missing '.$needle);
foreach(array('livemode','status','amount_total','currency','checkout_attempt_uid','obligation_reference','checkout.stripe.com') as $needle)
    if(strpos($adapter,$needle)===false)throw new RuntimeException('Stripe Checkout response contract missing '.$needle);
foreach(array('metadata[obligation_reference]','metadata[checkout_attempt_uid]','metadata[provider_account_reference]','client_reference_id') as $needle)
    if(strpos($adapter,$needle)===false||strpos($request,'attemptUid')===false)throw new RuntimeException('Checkout correlation metadata missing '.$needle);
if(strpos($adapter,'CommercialPaymentService')!==false||strpos($adapter,'client_secret')!==false||strpos($adapter,"'mode'=>'live'")!==false)
    throw new RuntimeException('Checkout adapter must remain test-only and cannot settle commercial truth');
if(strpos($events,"'payment_intent.succeeded'")===false||strpos($events,'obligation_reference')===false||strpos($events,'provider_account_reference')===false)
    throw new RuntimeException('Checkout PaymentIntent metadata must use the canonical evidence translator');
echo "Stripe Checkout adapter source contract passed\n";
