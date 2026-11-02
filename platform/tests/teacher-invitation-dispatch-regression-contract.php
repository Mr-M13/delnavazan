<?php
/** Dispatch must always close a prepared invitation with an honest terminal status. */
$root=dirname(__DIR__);
$worker=(string)file_get_contents($root.'/src/Core/Application/TeacherInvitationDeliveryWorker.php');
$repository=(string)file_get_contents($root.'/src/Core/Infrastructure/Repository/PrincipalInvitationRepository.php');
$screen=(string)file_get_contents($root.'/src/Admin/Controller/ScreenController.php').(string)file_get_contents($root.'/src/Admin/View/TeacherWorkspaceView.php').(string)file_get_contents($root.'/src/Core/Application/TeacherWorkspaceReadService.php');
$renderer=(string)file_get_contents($root.'/src/Integrations/Notifications/DelnavazanEmailRenderer.php');
foreach(['dispatchNow','DelnavazanEmailRenderer::send','finishPreparedDelivery',"failPreparedDelivery(\$generationId,'mail_transport_exception')",'return \'failed\''] as $needle)if(!str_contains($worker,$needle))throw new RuntimeException('Invitation dispatch terminal-outcome regression: '.$needle);
if(!str_contains($renderer,'wp_mail'))throw new RuntimeException('Invitation email transport regression: wp_mail');
foreach(['function failPreparedDelivery','delivery_failure_reason','processed_at'] as $needle)if(!str_contains($repository,$needle))throw new RuntimeException('Invitation failure persistence regression: '.$needle);
foreach(['Send invitation','invitation_dispatch_failed','recorded status below is authoritative','Onboarding and invitation status'] as $needle)if(!str_contains($screen,$needle))throw new RuntimeException('Invitation admin truthfulness regression: '.$needle);
echo "teacher-invitation-dispatch-regression-contract: pass\n";
