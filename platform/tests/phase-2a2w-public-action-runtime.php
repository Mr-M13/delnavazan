<?php
$root=dirname(__DIR__);$service=file_get_contents($root.'/src/Portals/PortalPublicActionService.php');$controller=file_get_contents($root.'/src/Portals/PortalPublicActionController.php');
foreach(array('GET is deliberately non-mutating','confirmationSignature','portal_absence_confirmation_v1','confirmed_submitting','delegating','submitted','refused','consumed_action_event_id','public_capability_on_behalf','portal_absence_submission_pending','underLessonRoot','Cache-Control','no-store') as $needle)if(strpos($service.$controller,$needle)===false)throw new RuntimeException('Phase-W public-action preflight missing '.$needle);
$rendererStart=strpos($service,'public function renderAbsenceConfirmation');$confirmStart=strpos($service,'public function confirmAbsence');
if($rendererStart===false||$confirmStart===false||$confirmStart<=$rendererStart)throw new RuntimeException('Phase-W GET preflight cannot locate the renderer and the confirmation service');
$getSection=substr($service,$rendererStart,$confirmStart-$rendererStart);
if(strpos($getSection,'$wpdb->insert')!==false)throw new RuntimeException('Phase-W GET preflight found a write');
if(strpos($getSection,'START TRANSACTION')!==false)throw new RuntimeException('Phase-W GET preflight found a transaction');
echo "Phase-W public-action source preflight passed\n";
if(!defined('ABSPATH')){fwrite(STDERR,"Phase-W public-action runtime requires the disposable WordPress/MariaDB runtime; not executed locally.\n");exit(2);}
