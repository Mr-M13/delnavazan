<?php
/** Source contract for the operator-facing notification redesign and independent mobile policy. */
$root = dirname(__DIR__);
$shell = file_get_contents($root . '/src/Admin/Controller/AdminShellController.php');
$controller = file_get_contents($root . '/src/Admin/Controller/NotificationManagementController.php');
$student = file_get_contents($root . '/src/Integrations/BookingStudentNotificationWorker.php');
$mobile = file_get_contents($root . '/src/Integrations/BookingNotificationMobileDispatcher.php');
$whatsapp = file_get_contents($root . '/src/Integrations/TelnyxWhatsAppAdapter.php');
$config = file_get_contents($root . '/src/Integrations/Notifications/TelnyxConfiguration.php');
$fail = static function (string $name, bool $ok): void {
    if (!$ok) throw new RuntimeException('Notification redesign contract failed: ' . $name);
};

$fail('primary notification tab exists', str_contains($shell, "'notifications' => ['Notifications'"));
$fail('notifications is not a Settings section', !str_contains($shell, "'notifications' => ['Notifications', ['dzn-notifications'"));
$fail('single event table columns', str_contains($controller, '<th>Category</th>') && str_contains($controller, '<th>Notification and purpose</th>') && str_contains($controller, '<th>Portal</th>') && str_contains($controller, '<th>Email</th>') && str_contains($controller, '<th>WhatsApp</th>') && str_contains($controller, '<th>SMS</th>'));
$fail('search includes copy', str_contains($controller, '$library->activeCopy') && str_contains($controller, 'name="search"'));
$fail('compact filters', str_contains($controller, 'name="category"') && str_contains($controller, 'name="recipient"') && str_contains($controller, 'name="channel"') && str_contains($controller, 'name="status"'));
$fail('effective state fails closed', str_contains($controller, 'effectiveChannelState') && str_contains($controller, 'missing_provider') && str_contains($controller, 'missing_template'));
$fail('global email appearance removed', !str_contains($controller, 'EmailAppearance') && !str_contains($controller, 'dzn_notification_email_appearance'));
$fail('booking email consults event switch', str_contains($student, "eventChannelEnabled(\$eventKey,'email')") && str_contains($student, 'email_disabled_by_administrator'));
$fail('student WhatsApp failure never fans out SMS', str_contains($mobile, '$studentWhatsAppPreferred') && str_contains($mobile, '!$studentWhatsAppPreferred'));
$fail('WhatsApp has no SMS profile fallback', !str_contains($whatsapp, "value('sms','messaging_profile_id')") && !str_contains($config, "value('sms','messaging_profile_id'))}"));
