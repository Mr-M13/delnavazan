<?php
$s=file_get_contents(dirname(__DIR__).'/src/Core/Application/NotificationDeliveryEvidenceService.php');
$r=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Repository/NotificationDeliveryRepository.php');
foreach(array("NotificationRule::deliveryRank","delivery_regression_attempt","delivery_event_stale","hash_hmac", "NotificationSupport::salt", "NotificationSupport::seconds","provider_event_reference_digest","DELIVERY_NOT_APPLIED","handed_off","acknowledged") as$n)if(strpos($s,$n)===false)throw new RuntimeException('Delivery evidence service missing '.$n);
foreach(array("notification_deliveries","applied=1","provider_event_reference_digest","appliedRank") as$n)if(strpos($r,$n)===false)throw new RuntimeException('Delivery repository missing '.$n);
foreach(array("'delivery_rank'=>\$rank","'provider_fact_digest'=>\$factDigest","'occurred_at'=>\$observedAt","'recorded_at'=>NotificationSupport::now()") as $n)if(strpos($s,$n)===false)throw new RuntimeException('Delivery evidence schema write missing '.$n);
foreach(array('provider_message_reference_digest','observed_at','created_at') as $n)if(strpos($s,"'".$n."'=>")!==false)throw new RuntimeException('Delivery evidence must not write non-schema column '.$n);
if(strpos($s,"'provider_event_reference'=>$providerEventReference")!==false||strpos($s,"'provider_message_reference'=>$providerMessageReference")!==false)throw new RuntimeException('Raw provider references must not be persisted');
echo "Notification delivery evidence source contract passed\n";