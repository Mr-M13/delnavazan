<?php
/** The production audit must stay aggregate-only and read-only. */
$path = dirname(__DIR__, 2) . '/deployment/qa/production-notification-delivery-diagnostic.php';
$source = (string) file_get_contents($path);
$expect = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$expect($source !== '', 'Notification delivery diagnostic is missing.');
$expect(str_contains($source, 'telnyx_messages') && str_contains($source, 'telnyx_events'), 'Diagnostic must cover Telnyx hand-off and webhook evidence.');
$expect(str_contains($source, 'notification_attempts') && str_contains($source, 'notification_deliveries'), 'Diagnostic must cover canonical queue and delivery evidence.');
$expect(str_contains($source, 'booking_request_student_notifications'), 'Diagnostic must cover booking notification outcomes.');
$expect(str_contains($source, 'whatsapp_ready') && str_contains($source, 'sms_ready'), 'Diagnostic must report provider readiness without credentials.');
$expect(!str_contains($source, 'INSERT ') && !str_contains($source, 'UPDATE ') && !str_contains($source, 'DELETE ') && !str_contains($source, 'wp_remote_'), 'Diagnostic must remain read-only and offline.');
$expect(!str_contains($source, 'email ') && !str_contains($source, 'phone ') && !str_contains($source, 'whatsapp_number'), 'Diagnostic must not query or print contact data.');

echo "Production notification delivery diagnostic contract passed\n";
