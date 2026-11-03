<?php
/** Authorised failed-booking-notification recovery contract. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$service = $read('src/Core/Application/BookingNotificationRecoveryService.php');
$relevance = $read('src/Core/Application/BookingNotificationRelevance.php');
$recipient = $read('src/Core/Application/BookingNotificationRecipient.php');
$worker = $read('src/Integrations/BookingStudentNotificationWorker.php');
$repository = $read('src/Core/Infrastructure/Repository/BookingTeacherResponseRepository.php');
$controller = $read('src/Admin/Controller/EnrolmentWorkspaceController.php');
$view = $read('src/Admin/View/EnrolmentWorkspaceView.php');
$readModel = $read('src/Core/Application/EnrolmentWorkspaceReadService.php');
$expect = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

$expect(str_contains($service, "CAPABILITY='dzn_operate_notification_dispatch'") && str_contains($service, 'current_user_can'), 'Recovery must be capability-gated.');
$expect(str_contains($service, 'notificationForUpdate') && str_contains($service, "state!=='failed'") && str_contains($service, 'BookingNotificationRelevance::isStillRelevant') && str_contains($service, 'BookingNotificationRecipient::email'), 'Recovery must lock, revalidate relevance and resolve the current recipient.');
$expect(str_contains($service, 'auditByDigest') && str_contains($service, 'insertAudit') && str_contains($service, "'queued'") && str_contains($service, 'notification_not_retryable') && str_contains($service, 'invitation_stale') && str_contains($service, 'recipient_contact_unusable'), 'Recovery must be idempotent, audited and refuse stale/unusable recipients.');
$expect(str_contains($service, 'updateNotification') && !str_contains($service, 'transitionDelivery') && !str_contains($service, 'closeRequest'), 'Recovery must never change booking or Teacher delivery state.');
$expect(str_contains($relevance, "pending_request") && str_contains($relevance, "cancelled") && str_contains($relevance, "teacher_declined") && str_contains($relevance, "'confirmed'"), 'The shared relevance rule must cover pending, cancelled and declined invitations.');
$expect(str_contains($recipient, 'student_account_email') && str_contains($recipient, 'student_email') && str_contains($recipient, 'teacher_email'), 'Recipient resolution must prefer current canonical contact while retaining legacy fallback.');
$expect(str_contains($worker, 'BookingNotificationRelevance::isStillRelevant') && str_contains($worker, 'BookingNotificationRecipient::email') && !str_contains($worker, 'transitionDelivery('), 'Worker must use shared relevance/recipient rules and never mutate delivery state.');
$expect(str_contains($repository, 'notificationForUpdate') && str_contains($repository, 'auditByDigest') && str_contains($repository, 'insertAudit') && str_contains($repository, 'student_account_email'), 'Recovery persistence seams are missing.');
$expect(str_contains($controller, "'recover_booking_notification'") && str_contains($view, 'Recover notification') && str_contains($readModel, 'recovery_available'), 'Admin workspace recovery action is missing.');
echo "Booking notification recovery contract passed\n";
