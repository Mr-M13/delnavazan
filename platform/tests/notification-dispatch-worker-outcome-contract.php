<?php
$w=file_get_contents(dirname(__DIR__).'/src/Core/Application/NotificationDispatchWorker.php');
foreach(array('$runId=NotificationSupport::uid(); $stamp=NotificationSupport::uid();','worker:outcome:','.$stamp','acknowledged','permanent_failure','retryable') as $needle)if(strpos($w,$needle)===false)throw new RuntimeException('Notification worker outcome contract missing '.$needle);
if(preg_match('/(?<![A-Za-z0-9_])\$stamp\b/',$w,$m,PREG_OFFSET_CAPTURE)!==1)throw new RuntimeException('Notification worker stamp missing');
echo "Notification worker outcome contract passed\n";
