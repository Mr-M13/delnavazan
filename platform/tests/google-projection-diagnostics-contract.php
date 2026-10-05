<?php
$s=file_get_contents(dirname(__DIR__).'/src/Core/Application/IntegrationControlPlaneReadService.php');
$c=file_get_contents(dirname(__DIR__).'/src/Admin/Controller/IntegrationControlPlaneController.php');
foreach(array('dzn_provider_calendar_event_mappings','projection_state','projection_states') as $n)if(strpos($s,$n)===false)throw new RuntimeException('Google projection diagnostics contract missing '.$n);
if(strpos($c,'Calendar projection:')===false)throw new RuntimeException('Google projection diagnostics presentation missing');
if(substr_count($c,"foreach(\$r['google_calendar']['projection_states'] as \$state=>\$count)")!==1)throw new RuntimeException('Google projection states must be rendered exactly once');
if(strpos($s,'event_digest')!==false||strpos($s,'conference')!==false)throw new RuntimeException('Google diagnostics must not expose provider object references');
echo "Google projection diagnostics contract passed\n";
