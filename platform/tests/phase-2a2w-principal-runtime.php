<?php
$root=dirname(__DIR__);$source=file_get_contents($root.'/src/Portals/PortalPrincipalResolver.php');
foreach(array('get_current_user_id','portal_principal_unresolved','portal_principal_ambiguous','portal_principal_kind_not_permitted','active','revoked_at') as $needle)if(strpos($source,$needle)===false)throw new RuntimeException('Phase-W principal preflight missing '.$needle);
foreach(array('$_REQUEST','$_COOKIE','$_GET','$_POST','email','meta') as $needle)if(strpos($source,$needle)!==false)throw new RuntimeException('Phase-W principal resolver accepts request input '.$needle);
echo "Phase-W principal source preflight passed\n";
if(!defined('ABSPATH')){fwrite(STDERR,"Phase-W principal runtime requires the disposable WordPress runtime; not executed locally.\n");exit(2);}
