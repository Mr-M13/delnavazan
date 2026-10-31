<?php
use Delnavazan\Platform\Core\Application\TeacherAvailabilitySummary;
$root=dirname(__DIR__);require_once $root.'/src/Core/Application/TeacherAvailabilitySummary.php';
$assert=static function(bool$condition,string$message):void{if(!$condition)throw new RuntimeException($message);};
$rules=array(
 array('weekday'=>1,'local_start_time'=>'09:00:00','local_end_time'=>'12:00:00','state'=>'preferred'),
 array('weekday'=>2,'local_start_time'=>'15:00:00','local_end_time'=>'17:00:00','state'=>'requestable'),
 array('weekday'=>3,'local_start_time'=>'08:00:00','local_end_time'=>'18:00:00','state'=>'blocked'),
);
$golden=array('state'=>'active','days'=>array(array('weekday'=>1,'intervals'=>array(array('start'=>'10:00','end'=>'14:00','label'=>'10:00–14:00')))));
$summary=TeacherAvailabilitySummary::build($rules,$golden);
$assert($summary['weekly_minutes']===300,'Only selected available windows may count toward weekly capacity.');
$assert(!isset($summary['selected'][3]),'A historical blocked rule must not be presented as positive availability.');
$assert($summary['golden_minutes']===120,'Only the selected overlap may count as Golden Hours capacity.');
$interval=$summary['golden']['days'][0]['intervals'][0];
$assert($interval['partially_selected']===true&&$interval['fully_selected']===false,'A partial Golden Hours overlap must remain visibly distinct.');
$assert($interval['segments'][0]['selected']===true&&$interval['segments'][0]['label']==='10:00–12:00','The eligible selected portion must be identified exactly.');
$assert($interval['segments'][1]['selected']===false&&$interval['segments'][1]['label']==='12:00–14:00','The unselected Golden Hours portion must remain explicit.');
echo "teacher-availability-summary-unit: OK\n";
