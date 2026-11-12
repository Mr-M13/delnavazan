<?php
$root=dirname(__DIR__);
require_once $root.'/src/Core/Application/GodMode/GodModeReadService.php';
$controller=file_get_contents($root.'/src/Admin/Controller/GodModeController.php');
if(!is_string($controller)||$controller==='')throw new RuntimeException('Unable to read God Mode controller.');
preg_match_all('/\$read->([a-zA-Z0-9_]+)\(/',$controller,$matches);
$called=array_values(array_unique($matches[1]??array()));
if($called===array())throw new RuntimeException('No read-service calls found in the God Mode controller.');
foreach($called as $method){
    if(!method_exists(Delnavazan\Platform\Core\Application\GodMode\GodModeReadService::class,$method)){
        throw new RuntimeException('God Mode controller calls missing read-service method: '.$method);
    }
}
foreach(array('scenarioRuns','metrics','recentActivity','inspector','history','historyCount','teachers') as $required){
    if(!method_exists(Delnavazan\Platform\Core\Application\GodMode\GodModeReadService::class,$required)){
        throw new RuntimeException('God Mode read service is missing required method: '.$required);
    }
}
echo "God Mode controller contract passed\n";
