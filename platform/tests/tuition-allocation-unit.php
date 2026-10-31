<?php
use Delnavazan\Platform\Core\Application\Finance\TuitionAllocationEstimator;
$root=dirname(__DIR__);require_once $root.'/src/Core/Application/Finance/TuitionAllocationEstimator.php';
$assert=static function(bool$condition,string$message):void{if(!$condition)throw new RuntimeException($message);};
$estimate=TuitionAllocationEstimator::estimate(25000);$amounts=array_column($estimate['items'],'amount_minor','key');
$expected=array('teacher'=>7000,'tax'=>7000,'advertisement'=>5000,'gst'=>2500,'transaction'=>800,'staff_admin'=>500,'server_app'=>500,'messaging'=>250,'project'=>700,'delnavazan'=>500,'unallocated'=>250);
$assert($amounts===$expected,'The $250 example must match the approved allocation exactly.');
$assert($estimate['total_basis_points']===10000&&$estimate['total_minor']===25000,'The allocation must explicitly total 100% and reconcile to the term amount.');
$assert(array_sum(array_column($estimate['items'],'basis_points'))===10000,'The explicit unallocated 1% must close the supplied 99% categories.');
foreach(array(0,1,7,101,999,10001)as$minor){$row=TuitionAllocationEstimator::estimate($minor);$assert(array_sum(array_column($row['items'],'amount_minor'))===$minor,'Largest-remainder rounding must reconcile every minor-unit amount.');}
try{TuitionAllocationEstimator::estimate(-1);throw new RuntimeException('Negative terms must be rejected.');}catch(InvalidArgumentException){}
echo "tuition-allocation-unit: OK\n";
