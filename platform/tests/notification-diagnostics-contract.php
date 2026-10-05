<?php
$s=file_get_contents(dirname(__DIR__).'/src/Core/Application/IntegrationControlPlaneReadService.php');
$c=file_get_contents(dirname(__DIR__).'/src/Admin/Controller/IntegrationControlPlaneController.php');
foreach(array('dzn_notifications','dzn_notification_deliveries','countStates','delivery_states') as $n)if(strpos($s,$n)===false)throw new RuntimeException('Notification diagnostics contract missing '.$n);
foreach(array('Notification state:','Notification delivery evidence:') as $n)if(strpos($c,$n)===false)throw new RuntimeException('Notification operations surface missing '.$n);
if(strpos($s,'recipient_contact_envelope')!==false||strpos($s,'provider_event_reference_digest')!==false)throw new RuntimeException('Diagnostics must not select sensitive notification fields');
echo "Notification diagnostics contract passed\n";
