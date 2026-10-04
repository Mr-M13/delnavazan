<?php
$s=file_get_contents(dirname(__DIR__).'/src/Core/Application/NotificationDeliveryStateService.php');
foreach(array(
    "NotificationSupport::requireCapability",
    "appliedDeliveries",
    "delivery_state==='delivered'",
    "transition(\$notificationId,'dispatched'",
    "'state'=>'delivered'",
    "'evidence_channel'=>'provider_evidence'",
    "provider_fact_digest",
    "occurred_at",
    "already_applied",
    "not_applicable",
    "no_delivered_fact"
) as $n)if(strpos($s,$n)===false)throw new RuntimeException('Delivery state application missing '.$n);
foreach(array("markDelivered(","closeAny(","closeExhausted(","rearm(") as $n)if(strpos($s,$n)!==false)throw new RuntimeException('Delivery-state application must not rewrite transport outbox: '.$n);
foreach(array("'failed'","'undelivered'","'expired'") as $n)if(strpos($s,"delivery_state===".$n)!==false)throw new RuntimeException('Non-delivered provider facts must not invent aggregate failure transitions');
echo "Notification delivery state application source contract passed\n";
