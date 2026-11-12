<?php
$root=dirname(__DIR__);
require_once $root.'/src/Core/Application/GodMode/GodModeRule.php';
use Delnavazan\Platform\Core\Application\GodMode\GodModeRule;

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$assert(!GodModeRule::isBillable(array('activity_type'=>'test')),'Test activity must never be billable.');
$assert(GodModeRule::isBillable(array('activity_type'=>'production')),'Production activity must remain billable.');
try{GodModeRule::assertBillable(array('activity_type'=>'test'));throw new RuntimeException('Finance accepted test activity.');}catch(InvalidArgumentException $error){$assert($error->getMessage()==='finance_test_activity_isolated','The refusal code must be stable.');}
$files=array();
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src/Core/Application/GodMode'));
foreach($iterator as $file)if($file->isFile()&&$file->getExtension()==='php')$files[]=$file->getPathname();
foreach($files as $file){
    $source=file_get_contents($file);
    foreach(array('StripeEventTranslator','StripePaymentAdapter','api.stripe.com','PaymentExecutionService','CheckoutSession') as $forbidden)if(str_contains($source,$forbidden))throw new RuntimeException('God Mode must not create provider charges or checkout sessions: '.basename($file));
    if(preg_match('/wpdb->insert\(\s*\$[a-z]+\.?\s*[\'"]finance_/i',$source)===1)throw new RuntimeException('God Mode must not insert finance rows directly: '.basename($file));
    if(str_contains($source,'finance_lesson_snapshots')&&!str_contains($source,'inventory'))throw new RuntimeException('Only the read-only inventory may name finance snapshots: '.basename($file));
}
$snapshot=file_get_contents($root.'/src/Core/Application/Finance/LessonFinanceSnapshotService.php');
if(!str_contains($snapshot,'GodModeRule::assertBillable'))throw new RuntimeException('Finance snapshot capture must enforce test isolation.');
$summary=file_get_contents($root.'/src/Core/Application/Finance/Read/TeacherFinanceSummaryReadService.php');
if(substr_count($summary,"activity_type<>'test'")<4)throw new RuntimeException('Teacher finance reads must exclude test activity explicitly.');
$runner=file_get_contents($root.'/src/Core/Application/GodMode/GodModeScenarioRunner.php');
if(!str_contains($runner,"'finance_snapshot_created'=>false")||!str_contains($runner,"'billing_impact'=>0"))throw new RuntimeException('Scenario evidence must assert zero financial impact.');
$session=file_get_contents($root.'/src/Core/Application/GodMode/GodModeSessionService.php');
if(!str_contains($session,"'finance_snapshot_created'=>false"))throw new RuntimeException('Completion evidence must state that no finance snapshot is created.');
$automation=file_get_contents($root.'/src/Core/Infrastructure/Repository/CanonicalLessonAuthorityRepository.php');
if(!str_contains($automation,"l.activity_type<>'test'"))throw new RuntimeException('Asynchronous completion candidate discovery must exclude test Lessons.');
$renewal=file_get_contents($root.'/src/Integrations/RenewalReminderWorker.php');
if(substr_count($renewal,"activity_type<>'test'")<3)throw new RuntimeException('Renewal reminders must not count test Lessons, test Enrolments or test term ends.');
$obligations=file_get_contents($root.'/src/Core/Application/CanonicalAcademyObligationService.php');
if(!str_contains($obligations,'canonical_obligation_test_activity_isolated'))throw new RuntimeException('Attendance settlement must refuse to establish academy obligations for test activity.');
$dashboard=file_get_contents($root.'/src/Core/Application/AcademyDashboardReadService.php');
if(substr_count($dashboard,"activity_type<>'test'")<4)throw new RuntimeException('Operational dashboards must exclude test activity.');
$teacherWorkspace=file_get_contents($root.'/src/Core/Application/TeacherWorkspaceReadService.php');
if(substr_count($teacherWorkspace,"activity_type<>'test'")<4)throw new RuntimeException('Teacher workload metrics must exclude test activity.');
echo "God Mode financial isolation unit passed\n";
