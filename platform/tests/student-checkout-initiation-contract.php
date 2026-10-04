<?php
$s=file_get_contents(dirname(__DIR__).'/src/Core/Application/Checkout/CheckoutInitiationService.php');
$c=file_get_contents(dirname(__DIR__).'/src/Portals/StudentCheckoutController.php');
$b=file_get_contents(dirname(__DIR__).'/delnavazan-platform.php');
foreach(array("resolve('student')",'lockAccountRoot','settlementForObligation','activeForObligation','beneficiary_student_id','checkout_unavailable',"state'=>'creating'") as $n)if(strpos($s,$n)===false)throw new RuntimeException('Checkout initiation authority missing '.$n);
foreach(array('amount_minor','currency','obligation_reference','student_id','provider_reference','payment_success') as $n)if(strpos($c,"\$_POST['".$n."']")!==false)throw new RuntimeException('Browser may not supply checkout authority '.$n);
foreach(array('is_user_logged_in','check_ajax_referer',"'action','nonce','obligation_id'","wp_send_json") as $n)if(strpos($c,$n)===false)throw new RuntimeException('Checkout controller boundary missing '.$n);
foreach(array('wp_remote_post','api.stripe.com','client_secret','CommercialPaymentService') as $n)if(strpos($s,$n)!==false||strpos($c,$n)!==false)throw new RuntimeException('Initiation slice crossed provider/settlement boundary');
if(strpos($b,'StudentCheckoutController::register()')===false)throw new RuntimeException('Checkout controller not registered');
echo "Student checkout initiation source contract passed\n";
