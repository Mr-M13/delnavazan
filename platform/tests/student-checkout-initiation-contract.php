<?php
$service=file_get_contents(dirname(__DIR__).'/src/Core/Application/Checkout/StudentCheckoutInitiationService.php');
$controller=file_get_contents(dirname(__DIR__).'/src/Portals/StudentCheckoutController.php');
$repository=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Repository/CommercialAuthorityRepository.php');
$bootstrap=file_get_contents(dirname(__DIR__).'/delnavazan-platform.php');
foreach(array('PortalPrincipalResolver','resolve(\'student\')','lockAccountRoot','settlementForObligation','CommercialSupport::obligationReference','activeForObligation','latestForObligation','request_key_digest','checkout_provider_persistence_required') as $needle)if(strpos($service,$needle)===false)throw new RuntimeException('Student checkout authority contract missing '.$needle);
foreach(array('amount_minor','currency','student_id','offer_id','obligation_reference') as $forbidden)if(strpos($controller,$forbidden)!==false)throw new RuntimeException('Student checkout controller must not accept '.$forbidden);
foreach(array("'methods' => 'POST'",'wp_verify_nonce','get_json_params','checkout_state','redirect_url') as $needle)if(strpos($controller,$needle)===false)throw new RuntimeException('Student checkout endpoint contract missing '.$needle);
foreach(array('obligationByUid','latestForObligation') as $needle)if(strpos($repository,$needle)===false)throw new RuntimeException('Checkout authority repository contract missing '.$needle);
if(strpos($bootstrap,"StudentCheckoutController', 'register'")===false)throw new RuntimeException('Student checkout controller is not registered');
echo "Student checkout initiation contract passed\n";
