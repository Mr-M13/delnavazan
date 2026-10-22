<?php
require_once dirname(__DIR__).'/src/Admin/Controller/IntegrationControlPlaneController.php';
use Delnavazan\Platform\Admin\Controller\IntegrationControlPlaneController as Controller;
foreach([
    [false,false,'ready','Setup is incomplete · Checkout enabled · Test payment readiness: blocked'],
    [true,true,'ready','Configuration ready · Checkout enabled · Test payment readiness: ready'],
    [true,false,'blocked','Configuration ready · Checkout not enabled · Test payment readiness: blocked'],
    [false,false,'blocked','Setup is incomplete · Checkout not enabled · Test payment readiness: blocked'],
] as [$ready,$effective,$enabled,$expected]){
    $result=Controller::stripeReadinessSummary(['ready'=>$ready,'checkout_ready'=>$effective,'checks'=>['checkout_enabled'=>['state'=>$enabled]]]);
    if($result!==$expected)throw new RuntimeException('Checkout activation and readiness were conflated');
}
echo "Stripe readiness summary: 4 scenarios passed\n";
