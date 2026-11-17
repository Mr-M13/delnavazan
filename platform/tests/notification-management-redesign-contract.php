<?php
/** Source contract for the operator-facing notification redesign and independent mobile policy. */
$root = dirname(__DIR__);
$shell = file_get_contents($root . '/src/Admin/Controller/AdminShellController.php');
$controller = file_get_contents($root . '/src/Admin/Controller/NotificationManagementController.php');
$student = file_get_contents($root . '/src/Integrations/BookingStudentNotificationWorker.php');
$mobile = file_get_contents($root . '/src/Integrations/BookingNotificationMobileDispatcher.php');
$whatsapp = file_get_contents($root . '/src/Integrations/TelnyxWhatsAppAdapter.php');
$config = file_get_contents($root . '/src/Integrations/Notifications/TelnyxConfiguration.php');
$renderer = file_get_contents($root . '/src/Integrations/Notifications/DelnavazanEmailRenderer.php');
$fail = static function (string $name, bool $ok): void {
    if (!$ok) throw new RuntimeException('Notification redesign contract failed: ' . $name);
};

$fail('primary notification tab exists', str_contains($shell, "'notifications' => ['Notifications'"));
$fail('notifications is not a Settings section', !str_contains($shell, "'notifications' => ['Notifications', ['dzn-notifications'"));
$fail('a single workspace groups notifications by task into cards', str_contains($controller, 'dzn-notif-grid-cards') && str_contains($controller, "array('website','email','whatsapp','sms')") && str_contains($controller, 'NotificationCatalogue::TASKS'));
$fail('search includes copy', str_contains($controller, '$library->activeCopy') && str_contains($controller, 'name="search"'));
$fail('compact filters', str_contains($controller, 'name="category"') && str_contains($controller, 'name="recipient"') && str_contains($controller, 'name="channel"') && str_contains($controller, 'name="status"'));
$fail('effective state fails closed', str_contains($controller, 'effectiveChannelState') && str_contains($controller, 'missing_provider') && str_contains($controller, 'missing_template'));
$fail('global email appearance removed', !str_contains($controller, 'EmailAppearance') && !str_contains($controller, 'dzn_notification_email_appearance'));
$fail('booking email consults event switch', str_contains($student, "eventChannelEnabled(\$eventKey,'email')") && str_contains($student, 'email_disabled_by_administrator'));
$fail('student WhatsApp failure never fans out SMS', str_contains($mobile, '$studentWhatsAppPreferred') && str_contains($mobile, '!$studentWhatsAppPreferred'));
$fail('teacher WhatsApp is independently eligible', str_contains($mobile, '$whatsappEligible') && str_contains($mobile, "audience === 'student'"));
$fail('email shell ignores global appearance', str_contains($renderer, 'Email presentation is code-owned') && !str_contains($renderer, 'EmailAppearance'));
$fail('WhatsApp has no SMS profile fallback', !str_contains($whatsapp, "value('sms','messaging_profile_id')") && !str_contains($config, "value('sms','messaging_profile_id'))}"));
$fail('ready operational mobile channels have one explicit bulk opt-in', str_contains($controller, 'dzn_notification_enable_ready_mobile') && str_contains($controller, 'enableReadyMobile') && str_contains($controller, "!=='operational'") && str_contains($controller, 'channelBlocker'));
$fail('held drafts expose their exact count and reasons', str_contains($controller, "\$workflow['held']") && str_contains($controller, "\$workflow['held_by_reason']") && !str_contains($controller, "\$workflow['restricted']"));
$lessonLifecycle = file_get_contents($root . '/src/Core/Application/LessonLifecycleNotificationService.php');
$renewal = file_get_contents($root . '/src/Core/Application/RenewalAutomationNotificationService.php');
$payment = file_get_contents($root . '/src/Core/Application/PaymentRefundNotificationService.php');
$fail('teacher SMS falls back to the teacher WhatsApp number', str_contains($lessonLifecycle, "t.whatsapp_phone teacher_whatsapp") && str_contains($lessonLifecycle, "(\$row['teacher_whatsapp']"));
$fail('student WhatsApp falls back to the student mobile', str_contains($lessonLifecycle, "(\$row['student_phone']") && str_contains($renewal, "(\$row['student_phone']") && str_contains($payment, "(\$row['student_phone']"));
$fail('one stored number fills the phone/WhatsApp pair everywhere', str_contains($lessonLifecycle, 'PhoneNumberNormalizer::pair') && str_contains($renewal, 'PhoneNumberNormalizer::pair') && str_contains($payment, 'PhoneNumberNormalizer::pair') && str_contains($mobile, 'PhoneNumberNormalizer::pair'));
