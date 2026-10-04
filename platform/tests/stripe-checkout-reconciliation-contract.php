<?php
$service=file_get_contents(dirname(__DIR__).'/src/Core/Application/Checkout/StripeCheckoutReconciliationService.php');
$controller=file_get_contents(dirname(__DIR__).'/src/Admin/Controller/PaymentExecutionController.php');
$references=file_get_contents(dirname(__DIR__).'/src/Core/Application/Checkout/CheckoutSessionReferenceService.php');
foreach(array('array_keys($input)!==array(\'attempt_uid\')','sessions->byUid($attemptUid)','references((int)$session->id)','PaymentExecutionIdempotency::reference($providerReference)','beneficiary_student_id','stripe->retrieve($providerReference)','stripe->completionEvent($providerReference','sameProviderCorrelation','CommercialPaymentService','PaymentExecutionWorkerContext','provider_occurred_at',"'evidence_channel'=>'provider_evidence'",'settlementForObligation') as $needle)
    if(strpos($service,$needle)===false)throw new RuntimeException('Checkout reconciliation authority boundary missing '.$needle);
foreach(array("'/admin/payment-execution/checkout-reconcile'",'RECONCILE_CAPABILITY=\'dzn_manage_payment_execution\'','wp_verify_nonce($nonce,\'wp_rest\')','StripeCheckoutReconciliationService','get_query_params()!==array()') as $needle)
    if(strpos($controller,$needle)===false)throw new RuntimeException('Operator reconciliation access boundary missing '.$needle);
if(strpos($service,'StripeWebhookController')!==false||strpos($service,'checkout_state\'=>\'paid')!==false)
    throw new RuntimeException('Manual checkout reconciliation must not impersonate webhook verification or make browser state authoritative');
foreach(array("'provider_object_reference' => $providerReference",'$this->vault->open') as $needle)
    if(strpos($references,$needle)===false)throw new RuntimeException('Checkout reconciliation must use the existing encrypted provider-reference vault');
echo "Stripe Checkout reconciliation source contract passed\n";
