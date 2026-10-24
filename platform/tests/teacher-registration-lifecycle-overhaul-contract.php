<?php
/** Source contract for the bounded Teacher registration and archive lifecycle. */
$root=dirname(__DIR__);
$read=static fn(string $path):string=>file_get_contents($root.'/'.$path);
$controller=$read('src/Portals/TeacherInvitationController.php');
$claim=$read('src/Core/Application/PrincipalInvitationService.php');
$claimRepo=$read('src/Core/Infrastructure/Repository/PrincipalInvitationRepository.php');
$lifecycle=$read('src/Core/Application/TeacherLifecycleService.php');
$archive=$read('src/Core/Application/ArchiveService.php');
$teacherRepo=$read('src/Core/Infrastructure/Repository/TeacherRepository.php');
$admin=$read('src/Admin/Controller/ScreenController.php');
$record_action=$read('src/Admin/RecordAction/RecordActionRegistry.php');
$route=file_get_contents(dirname($root).'/theme/inc/routes.php');

foreach(array("mb_strlen( \$password )",'$passwordLength < 12',"hash_equals( \$password, \$confirmation )",'requiresExistingAccountAuthentication','recordExistingAccountRequired','wp_create_user( $login, $password, $recipient )','sanitize_user( $recipient, true )','$login !== $recipient','wp_set_auth_cookie','teacher-view',"'onboarding'",'TeacherInvitationAccountMismatch',"'account_mismatch'") as $needle)if(strpos($controller,$needle)===false)throw new RuntimeException('Teacher registration boundary missing: '.$needle);
if(strpos($controller,'wp_new_user_notification')!==false||strpos($controller,'wp_generate_password')!==false)throw new RuntimeException('Registration must neither generate a hidden password nor send a password-reset email.');
foreach(array('account_password','account_password_confirmation','minlength="12"','existing_account','account_mismatch','ورود با حساب موجود','خروج و ورود با حساب درست') as $needle)if(strpos($route,$needle)===false)throw new RuntimeException('Teacher registration presentation missing: '.$needle);

foreach(array('attemptForGeneration','adoptAttemptForAuthenticatedPrincipal','existing_account_required','claimRecipient','establishTeacherLink','teacherLinkForTeacher','teacherLinkForUser',"['status'=>'active'","'revoked_at'=>null") as $needle)if(strpos($claim.$claimRepo,$needle)===false)throw new RuntimeException('Safe existing/re-invitation contract missing: '.$needle);
foreach(array("current_user_can('dzn_manage_teachers')",'hasOperationalEnrolments','hasApplicableTeacherAssignments','activeFutureExists','revokeUnclaimedGenerations','cancelPendingDeliveryOutboxes',"'status'=>'revoked'","'state'=>'offboarded'","'status'=>'archived'",'remove_role(\'dzn_teacher\')',"'status'=>'active'",'access_remains_revoked_reinvitation_required') as $needle)if(strpos($lifecycle,$needle)===false)throw new RuntimeException('Teacher archive lifecycle missing: '.$needle);
if(strpos($lifecycle,'delete(')!==false||strpos($lifecycle,'set_role(')!==false)throw new RuntimeException('Teacher archive must preserve history and unrelated roles.');
foreach(array("if (\$type === 'teacher')",'TeacherLifecycleService())->archive($id)','TeacherLifecycleService())->restore($id)') as $needle)if(strpos($archive,$needle)===false)throw new RuntimeException('Generic archive boundary does not delegate Teacher lifecycle: '.$needle);
foreach(array('invitation_queued','invitation_available','onboarding_required','access_revoked','not_invited','Active and ready','Claim code created; account not yet linked','Expired; issue a replacement','Restore teacher','Archive teacher','does not restore Teacher access automatically','revoke open invitations') as $needle)if(strpos($teacherRepo.$admin.$record_action,$needle)===false)throw new RuntimeException('Honest Teacher lifecycle presentation missing: '.$needle);

echo "Teacher registration and lifecycle overhaul source contract passed\n";
