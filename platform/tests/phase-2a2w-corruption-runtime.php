<?php
$root=dirname(__DIR__);$source=file_get_contents($root.'/src/Portals/PortalCapabilityService.php').file_get_contents($root.'/src/Portals/PortalPublicActionService.php');
foreach(array('hash_equals','base64_decode','portal_join_target_unavailable','portal_capability_signature_invalid','command_replay_conflict','ROLLBACK') as $needle)if(strpos($source,$needle)===false)throw new RuntimeException('Phase-W corruption preflight missing '.$needle);
echo "Phase-W corruption source preflight passed\n";
if(!defined('ABSPATH')){fwrite(STDERR,"Phase-W corruption runtime requires the disposable WordPress/MariaDB runtime; not executed locally.\n");exit(2);}
