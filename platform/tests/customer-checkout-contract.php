<?php
$r=file_get_contents(dirname(__DIR__).'/src/Core/Application/Checkout/CheckoutRequest.php');
$p=file_get_contents(dirname(__DIR__).'/src/Core/Application/Checkout/CheckoutSessionPort.php');
$s=file_get_contents(dirname(__DIR__).'/src/Integrations/Payment/Stripe/StripeCheckoutAdapter.php');
foreach(array('amountMinor','currency','obligationReference','idempotencyKey') as $n)if(strpos($r,$n)===false)throw new RuntimeException('Checkout request missing '.$n);
foreach(array('Customer checkout transport only','cannot settle commercial truth') as $n)if(strpos($p,$n)===false)throw new RuntimeException('Checkout port authority boundary missing '.$n);
foreach(array("'mode'=>'payment'",'unit_amount','amountMinor()','currency','strtolower','metadata][obligation_reference]',"'success_url'","'cancel_url'","checkout_provider_unconfigured") as $n)if(strpos($s,$n)===false)throw new RuntimeException('Stripe Checkout contract missing '.$n);
foreach(array('wp_remote_post','api.stripe.com','CommercialPaymentService','client_secret') as $n)if(strpos($s,$n)!==false)throw new RuntimeException('Checkout foundation must remain network-inert and non-authoritative');
echo "Customer checkout foundation contract passed\n";
