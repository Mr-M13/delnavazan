<?php
$root=dirname(__DIR__);$source=file_get_contents($root.'/src/Portals/PortalCapabilityService.php');
foreach(array('public function mint','public function rotate','public function revoke','command_key_digest','command_payload_digest','expected_capability_id','hash_equals','aes-256-gcm','superseded_by_capability_id','portal_capability_signature_invalid') as $needle)if(strpos($source,$needle)===false)throw new RuntimeException('Phase-W capability preflight missing '.$needle);
if(strpos($source,'$this->mint(')!==false)throw new RuntimeException('Phase-W rotate preflight found a spurious mint command path');
echo "Phase-W capability source preflight passed\n";
if(!defined('ABSPATH')){fwrite(STDERR,"Phase-W capability runtime requires the disposable WordPress/MariaDB runtime; not executed locally.\n");exit(2);}
