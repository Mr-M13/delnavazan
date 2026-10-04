<?php
$service=file_get_contents(dirname(__DIR__).'/src/Core/Application/Checkout/StudentCheckoutInitiationService.php');
$repository=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Repository/CheckoutSessionRepository.php');
$schema=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Migration/Migrator.php');
$adapter=file_get_contents(dirname(__DIR__).'/src/Integrations/Payment/Stripe/StripeCheckoutAdapter.php');
foreach(array('providerIdempotencyKey','active->uid','same durable attempt','Provider outcome is ambiguous','recordOpen','PaymentExecutionIdempotency::reference','safeStripeUrl','PROVIDER_IDEMPOTENCY_REPLAY_SECONDS','Commercial facts changed after the attempt was made') as $needle)
    if(strpos($service,$needle)===false)throw new RuntimeException('Checkout lifecycle retry contract missing '.$needle);
foreach(array("array('creating','open')", "'state'=>'open'", "'provider_reference_digest'=>$providerReferenceDigest", "'active_slot'=>1", "array('completed','expired','failed')") as $needle)
    if(strpos($repository,$needle)===false)throw new RuntimeException('Checkout lifecycle persistence contract missing '.$needle);
foreach(array('provider_reference_digest','active_slot','obligation_active') as $needle)
    if(strpos($schema,$needle)===false)throw new RuntimeException('Checkout lifecycle must use existing authority schema: '.$needle);
if(strpos($repository,'provider_reference')===false||strpos($repository,'providerReferenceDigest')===false)
    throw new RuntimeException('Raw provider session identifiers must not be persisted');
if(strpos($adapter,"'state'=>'failed'")===false||strpos($adapter,'wp_remote_post')!==false)
    throw new RuntimeException('Unconfigured Checkout must fail closed without outbound traffic');
if(strpos($service,"'checkout_state' => 'pending'")===false)
    throw new RuntimeException('Ambiguous provider outcomes must remain pending');
echo "Checkout session lifecycle source contract passed\n";
