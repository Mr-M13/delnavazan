<?php
/** Notification hand-off failure must never remove a match or change booking allocation. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$worker = $read('src/Integrations/BookingStudentNotificationWorker.php');
$deliveryRepository = $read('src/Core/Infrastructure/Repository/BookingTeacherDeliveryRepository.php');
$response = $read('src/Core/Application/BookingTeacherResponseService.php');
$workspace = $read('src/Core/Application/EnrolmentWorkspaceReadService.php');
$expect = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

$expect(str_contains($worker, "updateNotification(") && !str_contains($worker, "transitionDelivery(") && !str_contains($worker, "closeRequest(") && !str_contains($worker, 'UPDATE {\$this->p}booking_requests'), 'Notification dispatch must only change notification state, never the request or Teacher delivery.');
$expect(str_contains($deliveryRepository, "d.state='pending_request'") && str_contains($deliveryRepository, "r.lifecycle_status='submitted'") && !str_contains($deliveryRepository, 'booking_request_student_notifications'), 'The Teacher inbox must depend on request/delivery state, not notification state.');
$expect(str_contains($response, 'deliveryByUid') && str_contains($response, "state!=='pending_request'") && str_contains($response, "resolution_state!=='unresolved'"), 'Acceptance must be based on the delivery/request state.');
$expect(str_contains($workspace, 'pendingDelivery(') && str_contains($workspace, "d.state='pending_request'") && str_contains($workspace, "Awaiting teacher response"), 'Workspace matching visibility must come from the pending delivery, not notification success.');
echo "Booking notification independence contract passed\n";
