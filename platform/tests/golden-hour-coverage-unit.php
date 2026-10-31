<?php
/** Pure unit coverage for golden-hour slot totals, distinct teachers, gaps and DST. */
use Delnavazan\Platform\Core\Application\AcademyCoverageAnalyzer;
use Delnavazan\Platform\Core\Application\AcademyDemandWindowRule;
use Delnavazan\Platform\Core\Application\AvailabilityLocalTime;

$root=dirname(__DIR__);
require_once $root.'/src/Core/Application/AvailabilityLocalTime.php';
require_once $root.'/src/Core/Application/AcademyDemandWindowRule.php';
require_once $root.'/src/Core/Application/AcademyCoverageAnalyzer.php';

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$utc=static fn(string $value):DateTimeImmutable=>new DateTimeImmutable($value,new DateTimeZone('UTC'));
$week=AcademyDemandWindowRule::weekStart($utc('2026-11-04 12:00:00'));
$window=static function(string $timezone,DateTimeImmutable $week,string $state='preferred',array $days=array(1,2,3,4,5,6,7)):array{
    $out=array();
    foreach($days as$day){$date=$week->modify('+'.($day-1).' days')->format('Y-m-d');$out[]=array('state'=>$state)+AvailabilityLocalTime::interval($date,'16:00:00','21:00:00',$timezone);}
    return $out;
};
$instrument=array('id'=>1,'name'=>'Piano');
$baseTeacher=static fn(int $id,string $name,array $availability,array $instruments=array(1)):array=>array('id'=>$id,'name'=>$name,'instrument_ids'=>$instruments,'availability'=>$availability);

$assert(count(AcademyDemandWindowRule::regions())===5,'Five serviced areas must remain defined');
$assert(count(AcademyDemandWindowRule::slots())===350,'Five areas × seven days × ten half-hour slots must total 350 demand slots');
foreach(AcademyDemandWindowRule::regions()as$key=>$region){
    $count=0;foreach(AcademyDemandWindowRule::slots()as$slot)if($slot['region']===$key)$count++;
    $assert($count===70,$key.' must have exactly 70 golden-hour slots per week');
    $assert($region['start']==='16:00'&&$region['end']==='21:00',$key.' must retain its 16:00–21:00 local window');
}
$assert($week->format('Y-m-d')==='2026-11-02','Any date must normalize to its Monday UTC week start');
$assert(AcademyDemandWindowRule::weekLabel($week)==='2 Nov 2026','The selected week must have a readable label');

$empty=AcademyCoverageAnalyzer::analyze(array(),array(),$week);
$assert($empty['status']==='empty'&&$empty['instruments']===array(),'Zero instruments must be an explicit empty atlas, not a fabricated 100%');

$none=AcademyCoverageAnalyzer::analyze(array($instrument),array(),$week);
$noneCell=$none['instruments'][0]['areas']['AU'];
$assert($noneCell['metric_state']==='no_eligible_teachers','An instrument with no eligible teacher must be explicitly no_eligible_teachers');
$assert($noneCell['coverage_percent']===null,'Zero eligible teachers must not be presented as a reassuring 0%');
$assert(count($noneCell['slots'])===70,'The detail grid still has 70 explicitly classified slots');

$one=AcademyCoverageAnalyzer::analyze(array($instrument),array($baseTeacher(10,'Ada',$window('Australia/Brisbane',$week))),$week);
$oneCell=$one['instruments'][0]['areas']['AU'];
$assert($oneCell['metric_state']==='available'&&$oneCell['covered_slots']===70&&$oneCell['single_teacher_slots']===70,'One teacher must cover 70/70 fragile slots');
$assert($oneCell['multi_teacher_slots']===0&&$oneCell['coverage_percent']===100&&$oneCell['resilience']==='fragile','One teacher must remain fragile despite 100% coverage');
$assert($oneCell['teacher_count']===1&&$oneCell['teacher_names']===array('Ada'),'One distinct teacher must be reported once');
$assert($oneCell['longest_gaps']===array(),'A fully covered week must have no gaps');

$two=AcademyCoverageAnalyzer::analyze(array($instrument),array(
    $baseTeacher(10,'Ada',$window('Australia/Brisbane',$week)),
    $baseTeacher(11,'Bob',$window('Australia/Brisbane',$week)),
),$week);
$twoCell=$two['instruments'][0]['areas']['AU'];
$assert($twoCell['covered_slots']===70&&$twoCell['multi_teacher_slots']===70&&$twoCell['single_teacher_slots']===0,'Two teachers must cover 70 resilient slots');
$assert($twoCell['coverage_percent']===100&&$twoCell['resilience']==='covered','Two teachers must be labelled covered');

$duplicate=$baseTeacher(10,'Ada duplicate',$window('Australia/Brisbane',$week));
$duplicate['instrument_ids']=array(1,1);
$duplicate['availability']=array_merge($duplicate['availability'],$window('Australia/Brisbane',$week));
$distinct=AcademyCoverageAnalyzer::analyze(array($instrument),array($baseTeacher(10,'Ada',$window('Australia/Brisbane',$week)),$duplicate),$week);
$distinctCell=$distinct['instruments'][0]['areas']['AU'];
$assert($distinctCell['teacher_count']===1&&$distinctCell['single_teacher_slots']===70,'Duplicate courses or overlapping rules must never inflate one teacher into two');
$assert($distinctCell['teacher_names']===array('Ada'),'Distinct-teacher identity must be the teacher id, not a duplicated row');

$overlap=AcademyCoverageAnalyzer::analyze(array($instrument),array($baseTeacher(10,'Ada',array_merge($window('Australia/Brisbane',$week),$window('Australia/Brisbane',$week,'requestable',array(1))))),$week);
$overlapCell=$overlap['instruments'][0]['areas']['AU'];
$assert($overlapCell['teacher_count']===1&&$overlapCell['single_teacher_slots']===70,'Overlapping preferred and requestable rules for one teacher must stay fragile');

$monday=$week->format('Y-m-d');
$blockedTeacher=$baseTeacher(10,'Ada',array_merge($window('Australia/Brisbane',$week),array(array('state'=>'blocked')+AvailabilityLocalTime::interval($monday,'16:00:00','18:00:00','Australia/Brisbane'))));
$blocked=AcademyCoverageAnalyzer::analyze(array($instrument),array($blockedTeacher),$week);
$blockedCell=$blocked['instruments'][0]['areas']['AU'];
$assert($blockedCell['covered_slots']===66&&$blockedCell['gap_slots']===4,'A blocked interval must not be shown as covered because another rule overlaps it');
$assert($blockedCell['slots'][0]['state']==='gap'&&$blockedCell['slots'][4]['state']==='fragile','Monday 16:00–18:00 must remain a four-slot gap');
$assert(count($blockedCell['longest_gaps'])===1&&$blockedCell['longest_gaps'][0]['label']==='Monday 16:00–18:00'&&$blockedCell['longest_gaps'][0]['slots']===4,'Contiguous gaps must be grouped by day and time');

$crossWeek=AcademyDemandWindowRule::weekStart($utc('2026-11-02 00:00:00'));
$toronto=array();
foreach(array(1,2,3,4,5,6,7)as$day){$date=$crossWeek->modify('+'.($day-1).' days')->format('Y-m-d');$toronto[]=array('state'=>'preferred')+AvailabilityLocalTime::interval($date,'10:00:00','15:00:00','America/Toronto');}
$cross=AcademyCoverageAnalyzer::analyze(array($instrument),array($baseTeacher(12,'Toronto Teacher',$toronto)),$crossWeek);
$assert($cross['instruments'][0]['areas']['EU']['covered_slots']===70,'A teacher in Toronto must cover the Europe window after IANA conversion');
$assert($cross['instruments'][0]['areas']['AU']['covered_slots']===0&&$cross['instruments'][0]['areas']['AU']['metric_state']==='available','A real zero-coverage area must remain a measured 0%, distinct from missing data');

$windowRows=static function(DateTimeImmutable $windowWeek,array $days,int $start,int $end,bool $enabled=false):array{
    $out=array();
    foreach($days as$day)$out[]=array('region'=>'AU','label'=>'Australia','timezone'=>'Australia/Brisbane','date'=>$windowWeek->modify('+'.($day-1).' days')->format('Y-m-d'),'weekday'=>$day,'start_minute'=>$start,'end_minute'=>$end,'version'=>2,'enabled'=>$enabled);
    return $out;
};
$availabilityForWindows=static function(array $windows):array{
    $out=array();
    foreach($windows as$window){$start=sprintf('%02d:%02d:00',intdiv((int)$window['start_minute'],60),(int)$window['start_minute']%60);$end=sprintf('%02d:%02d:00',intdiv((int)$window['end_minute'],60),(int)$window['end_minute']%60);$out[]=array('state'=>'preferred')+AvailabilityLocalTime::interval((string)$window['date'],$start,$end,(string)$window['timezone']);}
    return $out;
};
$midweekWindows=array_merge($windowRows($week,array(1,2),960,1260),$windowRows($week,array(3,4,5,6,7),1020,1200));
$midweek=AcademyCoverageAnalyzer::analyze(array($instrument),array($baseTeacher(30,'Midweek Teacher',$availabilityForWindows($midweekWindows))),$week,array(),'gap',$midweekWindows);
$midweekCell=$midweek['instruments'][0]['areas']['AU'];
$assert($midweekCell['total_slots']===50,'A window change during the week must produce a dynamic effective slot total');
$assert($midweekCell['covered_slots']===50&&$midweekCell['coverage_percent']===100,'Coverage must use the effective start and end for each date');
$assert($midweekCell['days'][1]['start_minute']===960&&$midweekCell['days'][3]['start_minute']===1020,'The detail grid must retain the per-date window change');
$assert(in_array(960,$midweekCell['times'],true)&&in_array(1020,$midweekCell['times'],true),'The detail grid must expose the union of effective slot times');
$assert($midweek['total_slots_per_area']===50,'The matrix must expose the dynamic slot total for the selected week');
$assert($midweekCell['total_slots']===50,'A disabled pay bonus must not remove or shrink the coverage window');

$weekdayWindows=$windowRows($week,array(1,2,3,4,5),960,1260);
$weekdayMatrix=AcademyCoverageAnalyzer::analyze(array($instrument),array($baseTeacher(31,'Weekday Teacher',$availabilityForWindows($weekdayWindows))),$week,array(),'gap',$weekdayWindows);
$weekdayCell=$weekdayMatrix['instruments'][0]['areas']['AU'];
$assert($weekdayCell['total_slots']===50&&$weekdayCell['covered_slots']===50,'Configured weekdays must define the effective slot denominator');
$assert($weekdayCell['days'][6]['has_window']===false&&$weekdayCell['days'][7]['has_window']===false,'Unconfigured weekdays must be explicit rather than invented gaps');

$oldWeek=AcademyDemandWindowRule::weekStart($utc('2026-10-05 00:00:00'));
$oldWindows=$windowRows($oldWeek,array(1,2,3,4,5,6,7),960,1260);
$newWindows=$windowRows($week,array(1,2,3,4,5,6,7),1020,1200);
$oldMatrix=AcademyCoverageAnalyzer::analyze(array($instrument),array(),$oldWeek,array(),'gap',$oldWindows);
$newMatrix=AcademyCoverageAnalyzer::analyze(array($instrument),array(),$week,array(),'gap',$newWindows);
$assert($oldMatrix['instruments'][0]['areas']['AU']['total_slots']===70,'An older week must retain its applicable 16:00–21:00 window');
$assert($newMatrix['instruments'][0]['areas']['AU']['total_slots']===42,'A newer week must use its applicable 17:00–20:00 window');

$euFall=AcademyDemandWindowRule::weekStart($utc('2026-10-19 00:00:00'));
$assert(AcademyDemandWindowRule::slotUtc($euFall,'EU',1,960)->format('Y-m-d H:i:s')==='2026-10-19 14:00:00','Europe must use CEST before its 25 October transition');
$assert(AcademyDemandWindowRule::slotUtc($euFall,'EU',7,960)->format('Y-m-d H:i:s')==='2026-10-25 15:00:00','Europe must use CET after its 25 October transition');
$naFall=AcademyDemandWindowRule::weekStart($utc('2026-10-26 00:00:00'));
$assert(AcademyDemandWindowRule::slotUtc($naFall,'NA_EAST',1,960)->format('Y-m-d H:i:s')==='2026-10-26 20:00:00','North America East must use EDT before its 1 November transition');
$assert(AcademyDemandWindowRule::slotUtc($naFall,'NA_EAST',7,960)->format('Y-m-d H:i:s')==='2026-11-01 21:00:00','North America East must use EST after its 1 November transition');
$spring=AcademyDemandWindowRule::weekStart($utc('2026-03-23 00:00:00'));
$assert(AcademyDemandWindowRule::slotUtc($spring,'EU',1,960)->format('Y-m-d H:i:s')==='2026-03-23 15:00:00','Europe must use CET before its 29 March spring transition');
$assert(AcademyDemandWindowRule::slotUtc($spring,'EU',7,960)->format('Y-m-d H:i:s')==='2026-03-29 14:00:00','Europe must use CEST after its 29 March spring transition');
$assert(AcademyDemandWindowRule::slotUtc($spring,'GB',7,960)->format('Y-m-d H:i:s')==='2026-03-29 15:00:00','The UK transition must be evaluated independently of Europe');
$assert(AcademyDemandWindowRule::slotUtc($spring,'NA_EAST',7,960)->format('Y-m-d H:i:s')==='2026-03-29 20:00:00','North America East must use its own daylight-saving dates');

$unknown=AcademyCoverageAnalyzer::analyze(array($instrument),array($baseTeacher(10,'Ada',$window('Australia/Brisbane',$week))),$week,array(1));
$unknownCell=$unknown['instruments'][0]['areas']['AU'];
$assert($unknownCell['metric_state']==='unknown'&&$unknownCell['coverage_percent']===null&&$unknownCell['covered_slots']===null,'A failed data source must be marked Unknown rather than 0% or 100%');
$assert($unknownCell['slots'][0]['state']==='unknown','Unknown data must propagate into every detail slot');
$malformed=$baseTeacher(10,'Ada',array(array('state'=>'preferred','starts_at_utc'=>'not-a-date','ends_at_utc'=>'2026-11-02 07:00:00')));
$bad=AcademyCoverageAnalyzer::analyze(array($instrument),array($malformed),$week);
$assert($bad['instruments'][0]['areas']['AU']['metric_state']==='unknown','Malformed availability intervals must fail closed as Unknown');

$ordered=AcademyCoverageAnalyzer::analyze(array(
    array('id'=>1,'name'=>'Zither'),
    array('id'=>2,'name'=>'Piano'),
),array($baseTeacher(20,'Piano Teacher',$window('Australia/Brisbane',$week),array(2))),$week);
$assert($ordered['instruments'][0]['name']==='Zither','Default sorting must put the instrument with the largest gaps first');
$alpha=AcademyCoverageAnalyzer::analyze(array(
    array('id'=>1,'name'=>'Zither'),
    array('id'=>2,'name'=>'Piano'),
),array($baseTeacher(20,'Piano Teacher',$window('Australia/Brisbane',$week),array(2))),$week,array(),'alpha');
$assert($alpha['instruments'][0]['name']==='Piano','Alphabetical sorting must be available');

$service=file_get_contents($root.'/src/Core/Application/AcademyCoverageReadService.php');
$assert(str_contains($service,'catch(\\Throwable)')&&str_contains($service,"'unavailable'")&&str_contains($service,'availability records could not be read'),'The read service must fail closed on unreadable availability data');
foreach(array("o.state='active'","o.readiness_state='ready'","a.state IN ('accepting','limited')","e.status='active'","c.status='active'","ap.status='active'","r.state IN ('preferred','requestable')","new TeacherAvailabilityService()","->effective(")as$needle)$assert(str_contains($service,$needle),'Eligibility and effective-availability authority must be preserved: '.$needle);
foreach(array('GoldenHourPolicyService','coverageWindows','resolveAt','windowsForWeek')as$needle)$assert(str_contains($service,$needle),'Coverage must use the shared finance window authority: '.$needle);
$analyzer=file_get_contents($root.'/src/Core/Application/AcademyCoverageAnalyzer.php');
$assert(str_contains($analyzer,'$blocked=true')&&str_contains($analyzer,'$available[$teacherId]'),'Blocked time must win while availability is counted by distinct teacher id');

echo "golden-hour-coverage-unit: OK\n";
