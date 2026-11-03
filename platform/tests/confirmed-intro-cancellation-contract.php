<?php
/** Source contract for the confirmed introductory booking cancellation authority. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$migration = $read('src/Core/Infrastructure/Migration/Migrator.php');
$service = $read('src/Core/Application/IntroLessonLifecycleService.php');
$repository = $read('src/Core/Infrastructure/Repository/ConfirmedIntroBookingCancellationRepository.php');
$worker = $read('src/Integrations/BookingCancellationRetractionWorker.php');
$transport = $read('src/Integrations/GoogleCalendarProjectionTransport.php');
$controller = $read('src/Admin/Controller/EnrolmentWorkspaceController.php');
$view = $read('src/Admin/View/EnrolmentWorkspaceView.php');
$coordinationController = $read('src/Admin/Controller/CoordinationCaseController.php');
$coordinationView = $read('src/Admin/View/CoordinationWorkspaceView.php');
$readModel = $read('src/Core/Application/EnrolmentWorkspaceReadService.php');
$notification = $read('src/Integrations/BookingStudentNotificationWorker.php');
$catalogue = $read('src/Integrations/Notifications/NotificationCatalogue.php');
$plugin = $read('delnavazan-platform.php');
$expect = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

foreach (array('booking_request_cancellations', 'booking_request_cancellation_commands', 'booking_request_provider_retraction_jobs', '065_confirmed_intro_cancellation') as $needle) {
    $expect(str_contains($migration, $needle), 'Missing cancellation migration: ' . $needle);
}
$expect(str_contains($plugin, "DZN_PLATFORM_SCHEMA_VERSION', '57'") && str_contains($plugin, 'BookingCancellationRetractionWorker::register()'), 'Schema/worker registration missing');
$expect(str_contains($service, 'public function cancel(') && str_contains($service, 'PortalPrincipalResolver') && str_contains($service, "\$role === 'admin'") && str_contains($service, "in_array(\$role, array('teacher', 'student')"), 'Cancellation authority is missing admin/Student/Teacher ownership checks');
$expect(str_contains($service, 'ensureAndLockStudentRoot') && str_contains($service, 'insertCancellation') && str_contains($service, 'insertRetractionJob') && str_contains($service, 'insertCommand') && str_contains($service, 'closeCoordinationCase') && str_contains($service, 'audit('), 'Cancellation transaction is missing Student lock, immutable evidence, outbox, command or audit');
$expect(str_contains($service, "'duplicate_request'") && str_contains($service, "'student_requested'") && str_contains($service, "'teacher_requested'"), 'Cancellation reason vocabulary is incomplete');
$expect(str_contains($repository, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED') && str_contains($repository, 'FOR UPDATE') && str_contains($repository, 'command_key_digest') && str_contains($repository, 'ON DUPLICATE KEY UPDATE id=id'), 'Cancellation persistence is not version-safe/idempotent');
$expect(str_contains($worker, 'MAX_ATTEMPTS') && str_contains($worker, 'lease_token_digest') && str_contains($worker, "'retry'") && str_contains($worker, "'failed'") && str_contains($worker, 'retireSecret') && str_contains($worker, '->cancel('), 'Retraction worker is missing bounded retry, lease, cancellation or secret retirement');
$expect(str_contains($transport, 'function cancel(') && str_contains($transport, "'DELETE'") && str_contains($transport, '404') && str_contains($transport, '410'), 'Google cancellation transport is missing idempotent DELETE handling');
$expect(str_contains($controller, "'intro_cancel'") && str_contains($view, 'introLifecycleForms') && str_contains($coordinationController, "'intro_cancel'") && str_contains($coordinationView, 'cancelConfirmedIntroForm'), 'Cancellation control is missing from the Booking Coordination dashboards');
$expect(str_contains($readModel, "'cancelled' => 'Cancelled'") && str_contains($readModel, "'Intro cancelled'"), 'Cancelled state is missing from the workspace read model');
$expect(str_contains($notification, "notification_kind==='cancelled'") && str_contains($notification, "notification_kind==='cancelled_teacher'") && str_contains($catalogue, 'booking.cancelled.student') && str_contains($catalogue, 'booking.cancelled.teacher'), 'Student/Teacher cancellation notifications are not wired');
$expect(!str_contains($repository . $worker . $service, 'provider_object_reference=') && !str_contains($service . $worker, 'join_uri_reference'), 'Cancellation must not persist raw provider references or join URLs');
echo "Confirmed introductory booking cancellation contract passed\n";
