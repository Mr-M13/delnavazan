<?php
$s=file_get_contents(dirname(__DIR__).'/src/Core/Application/IntegrationControlPlaneReadService.php');
$c=file_get_contents(dirname(__DIR__).'/src/Core/Application/DiagnosticsReadService.php');
foreach(array('dzn_provider_calendar_event_mappings','projection_state','projection_states') as $n)if(strpos($s,$n)===false)throw new RuntimeException('Google projection diagnostics contract missing '.$n);
if(strpos($c,'dzn_provider_calendar_event_mappings')===false||strpos($c,'projection_state')===false)throw new RuntimeException('Google projection diagnostics presentation missing');
if(substr_count($c,'dzn_provider_calendar_event_mappings')<1)throw new RuntimeException('Google projection states must be rendered from the safe projection');
if(strpos($s,'event_digest')!==false||strpos($s,'conference')!==false)throw new RuntimeException('Google diagnostics must not expose provider object references');
echo "Google projection diagnostics contract passed\n";
