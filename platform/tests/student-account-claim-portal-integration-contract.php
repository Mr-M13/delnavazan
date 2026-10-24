<?php
$r=file_get_contents(dirname(__DIR__).'/src/Portals/PortalPrincipalResolver.php');
$s=file_get_contents(dirname(__DIR__).'/src/Portals/PortalAuthenticatedReadService.php');
$b=file_get_contents(dirname(__DIR__).'/../theme/inc/platform-bridge.php');
$a=file_get_contents(dirname(__DIR__).'/src/Core/Application/StudentAcceptanceAuthorityService.php');
foreach(array("student_principal_links WHERE wordpress_user_id=%d AND status='active' AND revoked_at IS NULL AND active_slot=1",'portal_principal_unresolved','portal_principal_ambiguous') as $n)if(strpos($r,$n)===false)throw new RuntimeException('Student portal resolver contract missing '.$n);
foreach(array("resolve('student')",'studentEnrolments','PortalAccessPolicy::assertObject(\'student_portal\', \'enrolment\'') as $n)if(strpos($s,$n)===false)throw new RuntimeException('Student portal access-policy contract missing '.$n);
foreach(array("'no_data'",'! $lessons && ! $enrolments','! $booking_classes','portal_principal_unresolved') as $n)if(strpos($b,$n)===false)throw new RuntimeException('Booked-but-not-enrolled portal state contract missing '.$n);
if(strpos($a,'PortalAuthenticatedReadService')!==false||strpos($a,'PortalPrincipalResolver')!==false)throw new RuntimeException('Invitation claim must not grant portal data itself');
echo "Student claim portal integration contract passed\n";
