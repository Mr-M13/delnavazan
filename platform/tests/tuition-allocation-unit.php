<?php
use Delnavazan\Platform\Core\Application\Finance\TuitionAllocationEstimator;
$root=dirname(__DIR__);require_once $root.'/src/Core/Application/Finance/TuitionAllocationEstimator.php';
$assert=static function(bool$condition,string$message):void{if(!$condition)throw new RuntimeException($message);};
$categories=TuitionAllocationEstimator::categories();
$expectedCategories=array(
    'teacher'=>array('پرداختی به اساتید',2800),
    'tax'=>array('مالیات',2800),
    'advertisement'=>array('هزینه تبلیغات',2000),
    'gst'=>array('مالیات کالا و خدمات (GST – Goods and Services Tax)',1000),
    'transaction'=>array('کارمزد تراکنش‌های اینترنتی و بانکی',320),
    'staff_admin'=>array('پرداختی به کارکنان و مدیریت',200),
    'server_app'=>array('هزینه سرور، اپلیکیشن و وب‌سایت',200),
    'messaging'=>array('هزینه پیامک و واتساپ',100),
    'project'=>array('پروژه‌ها (نام موقت)',280),
    'delnavazan'=>array('سهم دلنوازان — ۳٪',300),
);
$assert(count($categories)===count($expectedCategories),'The allocation must expose exactly the approved categories, with no unallocated row.');
foreach($categories as$category){
    $key=(string)($category['key']??'');
    $assert(isset($expectedCategories[$key]),'Unexpected allocation category: '.$key);
    $assert((string)($category['label_fa']??'')===$expectedCategories[$key][0],'The label for '.$key.' must match the approved Persian label.');
    $assert((int)($category['basis_points']??0)===$expectedCategories[$key][1],'The share for '.$key.' must match the approved percentage.');
}
$estimate=TuitionAllocationEstimator::estimate(25000);$amounts=array_column($estimate['items'],'amount_minor','key');
$expected=array('teacher'=>7000,'tax'=>7000,'advertisement'=>5000,'gst'=>2500,'transaction'=>800,'staff_admin'=>500,'server_app'=>500,'messaging'=>250,'project'=>700,'delnavazan'=>750);
$assert($amounts===$expected,'The $250 example must match the approved allocation exactly.');
$assert($estimate['total_basis_points']===10000&&$estimate['total_minor']===25000,'The allocation must explicitly total 100% and reconcile to the term amount.');
$assert(array_sum(array_column($estimate['items'],'basis_points'))===10000,'All approved categories must total 100% without an unallocated row.');
$assert(!isset($amounts['unallocated']),'The former unallocated row must be removed.');
foreach(array(0,1,7,101,999,10001)as$minor){$row=TuitionAllocationEstimator::estimate($minor);$assert(array_sum(array_column($row['items'],'amount_minor'))===$minor,'Largest-remainder rounding must reconcile every minor-unit amount.');}
try{TuitionAllocationEstimator::estimate(-1);throw new RuntimeException('Negative terms must be rejected.');}catch(InvalidArgumentException){}
echo "tuition-allocation-unit: OK\n";
