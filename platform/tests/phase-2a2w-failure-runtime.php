<?php
$root=dirname(__DIR__);$source=file_get_contents($root.'/src/Portals/PortalCapabilityService.php').file_get_contents($root.'/src/Portals/PortalPublicActionService.php');
foreach(array('recordRefusal','result_state','refused','confirmed_submitting','delegating','DELEGATION_LEASE_SECONDS','recordOutcome','ROLLBACK','portal_action_evidence_persistence_failed','portal_access_denials') as $needle)if(strpos($source,$needle)===false)throw new RuntimeException('Phase-W failure preflight missing '.$needle);
echo "Phase-W failure source preflight passed\n";
if(!defined('ABSPATH')){fwrite(STDERR,"Phase-W failure runtime requires the disposable WordPress/MariaDB runtime; not executed locally.\n");exit(2);}
