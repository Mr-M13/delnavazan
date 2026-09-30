<?php
$root=dirname(__DIR__);$migration=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
foreach(array('031_portal_facing_services_principal_authorization','portal_lesson_capability_roots','portal_public_capabilities','portal_public_capability_events','portal_public_capability_commands','portal_public_action_events','portal_access_denials','verify_portal_facing_services_schema') as $needle)if(strpos($migration,$needle)===false)throw new RuntimeException('Phase-W migration preflight missing '.$needle);
if(strpos($migration,"dzn_platform_portal_actions")!==false)throw new RuntimeException('Phase-W migration preflight found a public-action option write');
echo "Phase-W migration source preflight passed\n";
if(!defined('ABSPATH')){fwrite(STDERR,"Phase-W migration runtime requires the disposable WordPress/MariaDB runtime; not executed locally.\n");exit(2);}
