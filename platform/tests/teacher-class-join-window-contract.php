<?php
$root=dirname(__DIR__);
require_once $root.'/src/Portals/TeacherClassJoinPolicy.php';
use Delnavazan\Platform\Portals\TeacherClassJoinPolicy;
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$start='2026-10-04 10:00:00';$end='2026-10-04 10:30:00';
$assert(TeacherClassJoinPolicy::available($start,$end,strtotime('2026-10-04 09:00:00 UTC')),'early boundary closed');
$assert(!TeacherClassJoinPolicy::available($start,$end,strtotime('2026-10-04 08:59:59 UTC')),'pre-window accepted');
$assert(TeacherClassJoinPolicy::available($start,$end,strtotime('2026-10-04 12:30:00 UTC')),'late boundary closed');
$assert(!TeacherClassJoinPolicy::available($start,$end,strtotime('2026-10-04 12:30:01 UTC')),'post-window accepted');
$service=file_get_contents($root.'/src/Integrations/TeacherClassJoinService.php');
$reads=file_get_contents($root.'/src/Portals/PortalReadModels.php');
$theme=file_get_contents(dirname($root).'/theme/inc/platform-bridge.php');
foreach(array('TeacherClassJoinPolicy::available','portal_action_outside_window')as$n)$assert(strpos($service,$n)!==false,'service drift '.$n);
foreach(array('teacherJoinAvailability','join_window_open','join_provider_ready')as$n)$assert(strpos($reads,$n)!==false,'read-model drift '.$n);
foreach(array('join_window_open','join_available')as$n)$assert(strpos($theme,$n)!==false,'theme drift '.$n);
$assert(!file_exists($root.'/src/Integrations/TeacherClassJoinPolicy.php'),'duplicate integration policy remains');
echo "Teacher class join-window contract passed\n";
