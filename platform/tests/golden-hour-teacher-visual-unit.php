<?php
/** Pure teacher-local golden-hour bonus projection and presentation contract. */
use Delnavazan\Platform\Core\Application\Finance\GoldenHourTeacherWindowProjection;

if(!function_exists('esc_html')){function esc_html($value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}}
if(!function_exists('esc_attr')){function esc_attr($value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}}
if(!function_exists('esc_url')){function esc_url($value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}}

$root=dirname(__DIR__);
require_once $root.'/src/Core/Application/Finance/GoldenHourTeacherWindowProjection.php';

$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$policy=array(
    'enabled'=>true,
    'timezone'=>'Asia/Tehran',
    'weekdays'=>array(1,2,3,4,5,6,7),
    'periods'=>array(array('start'=>'16:00','end'=>'21:00')),
    'start'=>'16:00',
    'end'=>'21:00',
    'bonus_basis_points'=>2000,
    'label'=>'Iran global golden hours',
);

$torontoStart=new DateTimeImmutable('2026-11-02 00:00:00',new DateTimeZone('America/Toronto'));
$toronto=GoldenHourTeacherWindowProjection::forPolicy($policy,'America/Toronto',$torontoStart,7,true);
$assert($toronto['state']==='active','An enabled positive policy must be active.');
$assert($toronto['percentage_label']==='20'&&(float)$toronto['percentage']===20.0,'The bonus percentage must be available for the visual.');
$assert($toronto['has_windows']&&count($toronto['days'])===7,'The projection must cover seven Teacher-local days.');
$assert($toronto['days'][0]['intervals'][0]['label']==='07:30–12:30','The policy window must be shown only in the Teacher local clock.');
$assert(!str_contains($toronto['days'][0]['intervals'][0]['label'],'16:00'),'The academy/policy clock must not leak into the Teacher interval label.');

$tehranStart=new DateTimeImmutable('2026-11-02 00:00:00',new DateTimeZone('Asia/Tehran'));
$tehran=GoldenHourTeacherWindowProjection::forPolicy($policy,'Asia/Tehran',$tehranStart,7,true);
$assert($tehran['days'][0]['intervals'][0]['label']==='16:00–21:00','A Teacher in the policy timezone must see the same local wall clock.');
$assert($tehran['days'][0]['intervals'][0]['percentage_label']==='20','Every rendered interval must carry the bonus percentage.');

$weekdayPolicy=$policy;$weekdayPolicy['weekdays']=array(1,2,3,4,5);
$weekdays=GoldenHourTeacherWindowProjection::forPolicy($weekdayPolicy,'Asia/Tehran',$tehranStart,7,true);
$assert($weekdays['days'][4]['has_window']===true&&$weekdays['days'][5]['has_window']===false,'Configured weekdays must limit the Teacher visual.');

$crossMidnight=$policy;$crossMidnight['periods']=array(array('start'=>'23:00','end'=>'01:00'));
$tokyoStart=new DateTimeImmutable('2026-11-02 00:00:00',new DateTimeZone('Asia/Tokyo'));
$tokyo=GoldenHourTeacherWindowProjection::forPolicy($crossMidnight,'Asia/Tokyo',$tokyoStart,7,true);
$assert($tokyo['days'][0]['intervals'][0]['label']==='04:30–06:30','A policy window crossing midnight must be split into the correct Teacher-local day.');

$dstPolicy=$policy;
$dstStart=new DateTimeImmutable('2026-10-30 00:00:00',new DateTimeZone('America/New_York'));
$dst=GoldenHourTeacherWindowProjection::forPolicy($dstPolicy,'America/New_York',$dstStart,4,true);
$assert($dst['days'][0]['intervals'][0]['label']==='08:30–13:30','The Teacher local window must use daylight time before the transition.');
$assert($dst['days'][2]['intervals'][0]['label']==='07:30–12:30','The Teacher local window must use standard time after the transition.');

$disabled=$policy;$disabled['enabled']=false;
$inactive=GoldenHourTeacherWindowProjection::forPolicy($disabled,'America/Toronto',$torontoStart,7,true);
$assert($inactive['state']==='inactive'&&$inactive['days']===array()&&!$inactive['has_windows'],'A disabled bonus must not be presented as an active earning window.');

$unavailable=GoldenHourTeacherWindowProjection::forPolicy($policy,'Not/AZone',$torontoStart,7,true);
$assert($unavailable['state']==='unavailable','An invalid Teacher timezone must fail closed as unavailable.');

$themeRoot=dirname(dirname(__DIR__)).'/theme';
$bridge=file_get_contents($themeRoot.'/inc/platform-bridge.php');
$account=file_get_contents($themeRoot.'/template-parts/teacher-portal/account.php');
$onboarding=file_get_contents($themeRoot.'/template-parts/teacher-portal/onboarding.php');
$classes=file_get_contents($themeRoot.'/template-parts/teacher-portal/classes.php');
$view=file_get_contents($root.'/src/Admin/View/InstrumentCoverageClockView.php');
$pay=file_get_contents($root.'/src/Core/Application/Finance/GoldenHourPayService.php');
$assert(str_contains($bridge,'dzn_theme_platform_teacher_golden_bonus')&&str_contains($bridge,'GoldenHourTeacherWindowProjection')&&str_contains($bridge,"'golden_bonus' =>"),'The Teacher model must expose the finance-owned projection to the account surfaces.');
$assert(str_contains($account,"dzn_theme_teacher_portal_component( 'coverage-clock'")&&!str_contains($account,'dzn_theme_platform_demand_regions'),'The account must render the unified availability clock, not the academy-wide region list.');
$assert(str_contains($onboarding,"dzn_theme_teacher_portal_component( 'account'")&&!str_contains($onboarding,'dzn_theme_platform_demand_regions'),'Onboarding must use the same unified account workspace.');
$assert(str_contains($view,"'goldenGaps' => 'ساعت‌های طلایی بدون پوشش'")&&str_contains($view,'Golden Hours')&&str_contains($view,'bonus')&&str_contains($view,'percent'),'The shared clock must carry the corrected Golden label and bonus details.');
$assert(str_contains($classes,'dzn-tp-golden-badge')&&str_contains($classes,'golden_hour_window_display'),'Booked Teacher lessons must keep the golden-hour badge and local window disclosure.');
$assert(str_contains($pay,'shows only their own local clock')&&!str_contains($pay,"' · your time '"),'The pay presentation must not append academy-clock text to the Teacher view.');

echo "golden-hour-teacher-visual-unit: OK\n";
