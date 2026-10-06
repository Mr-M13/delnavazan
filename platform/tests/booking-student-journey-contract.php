<?php

if (str_contains($booking, 'برای ورود به حساب هنرجویی و پیگیری درخواست شما استفاده خواهد شد')) throw new RuntimeException('Booking must not promise that the submitted email is already the student-account login');
if (str_contains($booking, 'راه اصلی ارتباط دلنوازان در واتساپ')) throw new RuntimeException('Booking must not promise WhatsApp as the primary contact channel before transport readiness');
if (!str_contains($booking, 'اگر حساب هنرجویی برای شما فعال شود')) throw new RuntimeException('Booking must make account activation conditional');
if (!str_contains($booking, 'واتساپ یا پیامک فقط در صورت فعال بودن آن کانال')) throw new RuntimeException('Booking must keep mobile-channel delivery conditional on configured transport');
$root=dirname(__DIR__);
$read=file_get_contents($root.'/src/Core/Application/AcademyJourneyReadService.php');
$dashboard=file_get_contents($root.'/src/Admin/Controller/AcademyOperationsController.php');
$issue=file_get_contents($root.'/src/Admin/Controller/CoreDatasetReadinessController.php');
$notification=file_get_contents($root.'/src/Core/Application/StudentAccountClaimNotificationService.php');
$claim=file_get_contents($root.'/src/Portals/StudentAccountClaimController.php');
$validation=file_get_contents($root.'/src/Core/Application/StudentAccountClaimValidationService.php');
$booking=file_get_contents(dirname($root).'/theme/inc/booking.php');
$routes=file_get_contents(dirname($root).'/theme/inc/routes.php');
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$assert(str_contains($dashboard,"if(\$r->enrolment_id&&\$r->claim_state!=='claimed'") && str_contains($dashboard,"'dzn_issue_student_account_claims'") ,'Dashboard must offer account claim only in the canonical enrolment stage with the existing narrow capability.');
$assert(str_contains($read,'student_account_claim_invitations claim ON claim.student_id=br.student_id'),'Journey read model must expose existing invitation lifecycle for contextual operator decisions.');
$assert(str_contains($issue,"'canonical_student_course_v1'") && str_contains($issue,'StudentAccountClaimNotificationService') && str_contains($issue,"'dzn_issue_student_account_claims'"),'Claim issuance must require canonical enrolment and reuse existing notification authority.');
$assert(str_contains($notification,'unset($issued[\'secret\'])') && !str_contains($notification,"'secret'=>"),'Notification result must not expose the raw secret.');
$assert(str_contains($validation,'account_claim_expired') && str_contains($validation,'activePrincipalForStudentForUpdate') && str_contains($validation,'activePrincipalForUserForUpdate'),'Claim must distinguish expiry and refuse an existing canonical principal link.');
$assert(str_contains($claim,'/student-portal/?account_claim=success') && str_contains($claim,"?'expired':") && str_contains($claim,"?'login':"),'Student claim controller must provide success and recovery states without putting the secret in a URL.');
$assert(str_contains($routes,"'student-account-claim'") && str_contains($routes,'name="claim_code"') && str_contains($routes,'account_claim') ,'Student-facing claim route must offer authenticated redemption and post-claim handoff.');
$assert(!str_contains($booking,'اعلان‌های کلاس را از طریق واتساپ دریافت می‌کنید') && !str_contains($booking,'همین ایمیل برای ورود به حساب شما استفاده می‌شود'),'Booking confirmation must not promise an account or notification channel before its authority exists.');
echo "Booking to student journey contract passed\n";
