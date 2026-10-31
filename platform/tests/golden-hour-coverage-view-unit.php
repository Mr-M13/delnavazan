<?php
/** Presentation unit coverage for escaping, permissions, keyboard hooks and honest states. */
use Delnavazan\Platform\Admin\View\AcademyCoverageView;
use Delnavazan\Platform\Core\Application\AcademyCoverageAnalyzer;
use Delnavazan\Platform\Core\Application\AcademyDemandWindowRule;
use Delnavazan\Platform\Core\Application\AvailabilityLocalTime;

if(!function_exists('esc_html')){function esc_html($value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}}
if(!function_exists('esc_attr')){function esc_attr($value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}}
if(!function_exists('esc_url')){function esc_url($value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}}
if(!function_exists('admin_url')){function admin_url(string $path=''):string{return 'https://example.test/wp-admin/'.$path;}}
if(!function_exists('add_query_arg')){function add_query_arg(array $args,string $url=''):string{return $url.(str_contains($url,'?')?'&':'?').http_build_query($args);}}
if(!function_exists('selected')){function selected($selected,$current=true,bool $display=true):string{$result=(string)$selected===(string)$current?' selected="selected"':'';if($display)echo$result;return$result;}}

$root=dirname(__DIR__);
require_once $root.'/src/Core/Application/AvailabilityLocalTime.php';
require_once $root.'/src/Core/Application/AcademyDemandWindowRule.php';
require_once $root.'/src/Core/Application/AcademyCoverageAnalyzer.php';
require_once $root.'/src/Admin/View/AcademyCoverageView.php';

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$utc=static fn(string $value):DateTimeImmutable=>new DateTimeImmutable($value,new DateTimeZone('UTC'));
$week=AcademyDemandWindowRule::weekStart($utc('2026-11-02 00:00:00'));
$window=array();
foreach(array(1,2,3,4,5,6,7)as$day){$date=$week->modify('+'.($day-1).' days')->format('Y-m-d');$window[]=array('state'=>'preferred')+AvailabilityLocalTime::interval($date,'16:00:00','21:00:00','Australia/Brisbane');}
$teacher=array('id'=>10,'name'=>'<b>Ada</b>','instrument_ids'=>array(1),'availability'=>$window);
$matrix=AcademyCoverageAnalyzer::analyze(array(array('id'=>1,'name'=>'<script>alert(1)</script>')),array($teacher),$week);
$filters=array('page'=>'dzn-teachers-menu','week_date'=>$week,'week_value'=>'2026-11-02','instrument'=>0,'area'=>'','sort'=>'gap','detail'=>null);

$render=static function(array $matrix,array $filters,bool $names):string{ob_start();AcademyCoverageView::render($matrix,$filters,$names);return (string)ob_get_clean();};
$atlas=$render($matrix,$filters,false);
$assert(str_contains($atlas,'&lt;script&gt;alert(1)&lt;/script&gt;'),'Instrument names must be HTML escaped');
$assert(!str_contains($atlas,'<script>alert(1)</script>'),'Raw instrument markup must never reach the page');
$assert(!str_contains($atlas,'<b>Ada</b>')&&!str_contains($atlas,'&lt;b&gt;Ada'),'Teacher names must stay hidden when the viewer lacks teacher-detail permission');
$assert(str_contains($atlas,'id="teacher-coverage"'),'The atlas must have a stable section id');
$assert(str_contains($atlas,'dzn-coverage__cell'),'Instrument–area cells must be rendered as links');
$assert(str_contains($atlas,'coverage_detail=1%3AAU'),'Each cell must link to its instrument and area detail');
$assert(str_contains($atlas,'aria-label="')&&str_contains($atlas,'of 70 golden-hour slots covered'),'Cells must carry readable screen-reader labels with covered and total slots');
$assert(str_contains($atlas,'tabindex="0"'),'The horizontal scroll region must be keyboard reachable');
$assert(str_contains($atlas,'screen-reader-text'),'The table and grid must provide screen-reader text');
$assert(str_contains($atlas,'Week of 2 Nov 2026'),'The selected week must be visible');
$assert(str_contains($atlas,'area-local time'),'The page must state that times are area-local');
$assert(str_contains($atlas,'booking-time scheduling authority'),'The view must state that bookings remain the scheduling authority');

$detailFilters=$filters;$detailFilters['detail']=array('instrument'=>1,'area'=>'AU');
$detail=$render($matrix,$detailFilters,true);
$assert(str_contains($detail,'id="coverage-detail"'),'The selected cell must open a stable detail region');
$assert(str_contains($detail,'Times are local to Australia'),'The detail grid must identify its area-local time');
$assert(str_contains($detail,'16:00')&&str_contains($detail,'Monday'),'The detail grid must expose day-by-time headers and rows');
$assert(str_contains($detail,'70/70 slots covered'),'The detail summary must show covered and total slots');
$assert(str_contains($detail,'70 slots rely on one teacher'),'The detail summary must expose single-teacher dependency');
$assert(str_contains($detail,'&lt;b&gt;Ada&lt;/b&gt;')&&!str_contains($detail,'<b>Ada</b>'),'Permitted teacher names must still be escaped and kept behind disclosure');
$assert(str_contains($detail,'dzn-coverage__teachers'),'Teacher names must remain inside a further disclosure');

$noNames=$render($matrix,$detailFilters,false);
$assert(str_contains($noNames,'Teacher names are hidden'),'Lacking permission must produce an explicit names-hidden note');
$assert(!str_contains($noNames,'Ada'),'Lacking permission must not leak teacher names anywhere in the detail');

$weekdayWindows=array();$weekdayAvailability=array();
foreach(array(1,2,3,4,5)as$day){$date=$week->modify('+'.($day-1).' days')->format('Y-m-d');$weekdayWindows[]=array('region'=>'AU','label'=>'Australia','timezone'=>'Australia/Brisbane','date'=>$date,'weekday'=>$day,'start_minute'=>960,'end_minute'=>1260);$weekdayAvailability[]=array('state'=>'preferred')+AvailabilityLocalTime::interval($date,'16:00:00','21:00:00','Australia/Brisbane');}
$weekdayTeacher=$teacher;$weekdayTeacher['availability']=$weekdayAvailability;
$weekdayMatrix=AcademyCoverageAnalyzer::analyze(array(array('id'=>1,'name'=>'Piano')),array($weekdayTeacher),$week,array(),'gap',$weekdayWindows);
$weekdayFilters=$filters;$weekdayFilters['detail']=array('instrument'=>1,'area'=>'AU');
$weekdayHtml=$render($weekdayMatrix,$weekdayFilters,true);
$assert(str_contains($weekdayHtml,'50 half-hour slots per area'),'The Atlas header must reflect the dynamic effective slot total');
$assert(str_contains($weekdayHtml,'50/50 slots covered'),'The detail summary must use the dynamic effective total');
$assert(str_contains($weekdayHtml,'No configured window'),'Weekdays outside the configured windows must be explicit, not fabricated gaps');

$unknownMatrix=AcademyCoverageAnalyzer::analyze(array(array('id'=>1,'name'=>'Piano')),array($teacher),$week,array(1));
$unknown=$render($unknownMatrix,$filters,false);
$assert(str_contains($unknown,'Unknown')&&!str_contains($unknown,'0%'),'Unknown data must render as Unknown, never as 0%');

$noEligible=AcademyCoverageAnalyzer::analyze(array(array('id'=>1,'name'=>'Piano')),array(),$week);
$noEligibleHtml=$render($noEligible,$filters,false);
$assert(str_contains($noEligibleHtml,'No eligible')&&!str_contains($noEligibleHtml,'>0%<'),'Zero eligible teachers must be explicit and must not appear as a 0% coverage cell');

$empty=AcademyCoverageAnalyzer::analyze(array(),array(),$week);
$assert(str_contains($render($empty,$filters,false),'No active instruments are configured'),'Zero instruments must produce an explicit empty state');

$unavailable=array('status'=>'unavailable','message'=>'Coverage could not be calculated because the availability data could not be read.','week_label'=>'2 Nov 2026','week_start'=>'2026-11-02','regions'=>AcademyDemandWindowRule::regions(),'instruments'=>array());
$unavailableHtml=$render($unavailable,$filters,false);
$assert(str_contains($unavailableHtml,'could not be calculated')&&!str_contains($unavailableHtml,'0%'),'Failed data must not be presented as a reassuring percentage');

$partial=$unknownMatrix;$partial['status']='partial';$partial['errors']=array('One teacher availability record could not be read. Affected cells are marked Unknown.');
$assert(str_contains($render($partial,$filters,false),'Affected cells are marked Unknown'),'Partial failures must be disclosed and localized to affected cells');

$css=file_get_contents($root.'/src/Admin/Assets/admin.css');
$assert(str_contains($css,'.dzn-coverage__scroll')&&str_contains($css,'overflow-x: auto'),'The atlas must scroll inside its component instead of forcing page-level horizontal scrolling');
$assert(str_contains($css,'position: sticky'),'Instrument labels must remain visible while area columns scroll');
$assert(str_contains($css,'.dzn-coverage__cell:focus-visible'),'Coverage cells must have a visible keyboard focus style');
$assert(str_contains($css,'.dzn-coverage__slot--gap')&&str_contains($css,'.dzn-coverage__slot--fragile')&&str_contains($css,'.dzn-coverage__slot--covered'),'All three grid states must have distinct non-colour styling hooks');
$assert(str_contains($css,'@media (max-width: 900px)')&&str_contains($css,'@media (max-width: 782px)'),'The atlas must include narrow-screen adaptations');

echo "golden-hour-coverage-view-unit: OK\n";
