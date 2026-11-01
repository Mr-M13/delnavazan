<?php
$root=dirname(__DIR__);
// The only sanctioned session boundary is the Teacher invitation claim, which authenticates the
// account it has just finalised. Every other portal source must remain theme/provider isolated.
$sessionBoundary=realpath($root.'/src/Portals/TeacherInvitationController.php');
if($sessionBoundary===false)throw new RuntimeException('Missing the Teacher invitation claim boundary');
$paths=array_merge(glob($root.'/src/Portals/*.php'),glob($root.'/src/Core/Application/Portal*.php'),glob($root.'/src/Admin/Controller/PortalCapabilityController.php'));
foreach($paths as $path){
    $s=file_get_contents($path);
    foreach(array('wp_remote_','curl_','platform_outbox','shortcode_','register_block_type','Amelia') as $needle)if(stripos($s,$needle)!==false)throw new RuntimeException('Phase-W isolation violation: '.$needle.' in '.$path);
    if(realpath($path)===$sessionBoundary)continue;
    foreach(array('wp_set_current_user','wp_set_auth_cookie') as $needle)if(stripos($s,$needle)!==false)throw new RuntimeException('Phase-W isolation violation: '.$needle.' in '.$path);
}
$invitationController=file_get_contents($sessionBoundary);
$finalizeClaim=strrpos($invitationController,'finalizeClaim(');
foreach(array('wp_set_current_user','wp_set_auth_cookie') as $needle){
    $authenticate=strpos($invitationController,$needle.'(');
    if($finalizeClaim===false||$authenticate===false||$authenticate<$finalizeClaim)throw new RuntimeException('Teacher invitation must authenticate only after finalizeClaim: '.$needle);
}
echo "Phase-W Theme/provider isolation contract passed\n";
