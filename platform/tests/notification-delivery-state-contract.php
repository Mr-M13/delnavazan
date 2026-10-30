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
    "no_delivered_fact",
    "latestAppliedFailure",
    "authoriseFromVerifiedFailure",
    "cancelForDeliveredSource",
    "whatsapp_failure_fallback_authorised",
    "whatsapp_failure_fallback_blocked"
) as $n)if(strpos($s,$n)===false)throw new RuntimeException('Delivery state application missing '.$n);
foreach(array("markDelivered(","closeAny(","closeExhausted(","rearm(") as $n)if(strpos($s,$n)!==false)throw new RuntimeException('Delivery-state application must not rewrite transport outbox: '.$n);
// A non-delivered fact must never move the WhatsApp aggregate to failed; only the fallback authority
// may create the linked SMS aggregate.
foreach(array("\$this->notifications->transition(\$notificationId,'dispatched',array('state'=>'failed'") as $n)if(strpos($s,$n)!==false)throw new RuntimeException('Non-delivered provider facts must not invent aggregate failure transitions');
echo "Notification delivery state application source contract passed\n";
