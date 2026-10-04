<?php
$p=file_get_contents(dirname(__DIR__).'/src/Core/Application/NotificationDispatchWorker.php');
$ordered=array('recoverExpiredLeases','claimLease','handOff','recordOutcome');$last=-1;
foreach($ordered as$n){$i=strpos($p,$n);if($i===false||$i<=$last)throw new RuntimeException('Worker sequence invalid at '.$n);$last=$i;}
foreach(array("NotificationSystemActor::run","evidence_channel'=>'system'","failure_class'=>'terminal'","failure_class'=>'retryable'") as$n)if(strpos($p,$n)===false)throw new RuntimeException('Worker contract missing '.$n);
if(strpos($p,'wp_schedule_event')!==false||strpos($p,'add_action')!==false)throw new RuntimeException('Worker must remain unregistered until live bindings exist');
echo "Notification dispatch worker source contract passed\n";