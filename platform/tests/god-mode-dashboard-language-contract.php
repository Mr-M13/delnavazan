<?php
$root=dirname(__DIR__);
$view=file_get_contents($root.'/src/Admin/View/GodModeView.php');
$controller=file_get_contents($root.'/src/Admin/Controller/GodModeController.php');
$css=file_get_contents($root.'/src/Admin/Assets/god-mode.css');
$menu=file_get_contents($root.'/src/Admin/Controller/Menu.php');
$shell=file_get_contents($root.'/src/Admin/Controller/AdminShellController.php');
foreach(array('view'=>$view,'controller'=>$controller,'css'=>$css,'menu'=>$menu,'shell'=>$shell) as $name=>$source)if(!is_string($source)||$source==='')throw new RuntimeException('Unable to read '.$name.'.');
if(preg_match('/\p{Arabic}/u',$view)===1)throw new RuntimeException('The God Mode administrator dashboard must not contain Persian/Arabic labels.');
if(preg_match('/\p{Arabic}/u',$controller)===1)throw new RuntimeException('God Mode administrator messages must be English.');
if(!str_contains($menu,"'God Mode'")||!str_contains($shell,"['God Mode'"))throw new RuntimeException('God Mode navigation labels must be English.');
if(!str_contains($view,'dir="ltr"'))throw new RuntimeException('God Mode must use an explicit left-to-right layout.');
$tabs=array('Overview','Book Test Class','Test Scenarios','Test History','Session Inspector','Settings');
foreach($tabs as $label)if(!str_contains($view,$label))throw new RuntimeException('Missing dashboard tab or heading: '.$label);
if(substr_count($view,'private function ') < 7)throw new RuntimeException('The view must implement all six tabs as dedicated renderers.');
foreach(array('Notification test','Export diagnostics','Verify recording','Confirm attendance and complete','Test History','No test records match these filters.','Participant identity mapping','Remove all eligible archived test data','Run dry-run inventory','Write recoverable snapshot') as $english)if(!str_contains($view,$english))throw new RuntimeException('Missing English-only administration copy: '.$english);
if(!str_contains($controller,'humanizeError')||!str_contains($controller,'confirmation dialogs')&&!str_contains($view,'confirm('))throw new RuntimeException('Confirmation or error copy must be present.');
if(str_contains($view,'wp_editor('))throw new RuntimeException('God Mode must not introduce an unrestricted content editor.');
echo "God Mode dashboard language contract passed\n";
