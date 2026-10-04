<?php
$s=file_get_contents(dirname(__DIR__).'/src/Core/Application/NotificationDeliveryEvidenceService.php');
$r=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Repository/NotificationDeliveryRepository.php');
foreach(array("submit(array \$normalisedFacts)","NotificationRule::deliveryRank","delivery_regression_attempt","delivery_event_stale","NotificationSupport::seconds","provider_fact_digest","provider_event_reference_digest","DELIVERY_NOT_APPLIED","handed_off","acknowledged") as$n)if(strpos($s,$n)===false)throw new RuntimeException('Delivery evidence service missing '.$n);
foreach(array("notification_deliveries","applied=1","provider_event_reference_digest","appliedRank") as$n)if(strpos($r,$n)===false)throw new RuntimeException('Delivery repository missing '.$n);
foreach(array("'delivery_rank'=>\$rank","'provider_fact_digest'=>\$factDigest","'occurred_at'=>\$occurredAt","'recorded_at'=>NotificationSupport::now()") as$n)if(strpos($s,$n)===false)throw new RuntimeException('Delivery evidence schema write missing '.$n);
foreach(array('provider_message_reference_digest','observed_at','created_at') as$n)if(strpos($s,"'".$n."'=>")!==false)throw new RuntimeException('Delivery evidence must not write non-schema column '.$n);
foreach(array('providerEventReference','providerMessageReference','hash_hmac','NotificationSupport::salt') as$n)if(strpos($s,$n)!==false)throw new RuntimeException('Core delivery intake must receive normalised digests, not raw provider references: '.$n);
if(strpos($s,"(int)\$attempt->notification_id!==\$notificationId")===false)throw new RuntimeException('Delivery fact must be bound to its persisted attempt notification');
echo "Notification delivery evidence source contract passed\n";

// Delivery intake is serialised at the §10 notification aggregate root before attempt/evidence mutation.
foreach(array('NotificationRepository','->begin()','find($notificationId,true)','find($attemptId,true)','->commit()','->rollback()','provider_reference') as $n)if(strpos($s,$n)===false)throw new RuntimeException('Delivery evidence serialisation contract missing '.$n);
if(strpos($s,'find($attemptId,true)')<strpos($s,'find($notificationId,true)'))throw new RuntimeException('Delivery evidence lock order must be aggregate then attempt');
