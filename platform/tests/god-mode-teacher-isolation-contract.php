<?php
$root=dirname(__DIR__);
$portal=file_get_contents($root.'/src/Portals/PortalAuthenticatedReadService.php');
$teacher=file_get_contents($root.'/src/Core/Application/GodMode/GodModeTeacherReadService.php');
$finance=file_get_contents($root.'/src/Core/Application/Finance/Read/TeacherFinanceSummaryReadService.php');
$workspace=file_get_contents($root.'/src/Core/Application/TeacherWorkspaceReadService.php');
foreach(compact('portal','teacher','finance','workspace') as $name=>$source)if(!is_string($source)||$source==='')throw new RuntimeException('Unable to read teacher isolation source: '.$name.'.');
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$assert(str_contains($portal,"'god_mode_test_classes'"),'Test classes must be a separate teacher read model.');
$assert(!preg_match('/\$lessons\s*=\s*array_merge\([^\n]*god_mode_test_classes/s',$portal),'Test classes must not be merged into canonical Lessons.');
$assert(str_contains($teacher,"teacher_principal_links")&&str_contains($teacher,"dzn_view_own_portal_schedule"),'Test class visibility must remain teacher-principal and capability bound.');
$assert(!str_contains($teacher,'finance_')&&!str_contains($teacher,'commercial_'),'The teacher test-class read model must not query finance data.');
$assert(str_contains($teacher,"'is_test'=>true")&&str_contains($teacher,"'label'=>'TEST CLASS'"),'Test classes must be explicitly distinguishable.');
if(substr_count($finance,"activity_type<>'test'")<4)throw new RuntimeException('Teacher finance statements and KPIs must exclude test activity.');
if(substr_count($workspace,"activity_type<>'test'")<4)throw new RuntimeException('Teacher workload and capacity metrics must exclude test activity.');
$assert(!str_contains($workspace,'god_mode_sessions'),'Teacher workload metrics must not count test sessions as real classes.');
echo "God Mode teacher isolation contract passed\n";
