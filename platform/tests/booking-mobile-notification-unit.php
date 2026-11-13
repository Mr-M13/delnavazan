<?php
namespace Delnavazan\Platform\Integrations\Notifications {
    /** WordPress-free copy stub: only the SMS channel is exercised here. */
    final class NotificationCopyResolver {
        public static function message(string $eventKey, string $channel, string $locale, array $variables, array $fallback): array {
            if ($channel !== 'sms') return $fallback;
            $bodies = array(
                'booking.requested.student' => 'Delnavazan: request for {{course}} at {{when}} received. Portal: {{portal_url}}',
                'booking.new.teacher' => 'Delnavazan: new booking {{course}} with {{student}} at {{when}}. Portal: {{teacher_portal_url}}',
            );
            $body = $bodies[$eventKey] ?? '';
            foreach ($variables as $code => $value) $body = str_replace('{{' . $code . '}}', (string) $value, $body);
            return array('subject' => '', 'body' => $body);
        }
    }
}

namespace {
    $GLOBALS['wpdb'] = (object) array('prefix' => 'wp_');
    $root = dirname(__DIR__);
    require_once $root . '/src/Integrations/Notifications/NotificationCatalogue.php';
    require_once $root . '/src/Integrations/Notifications/PhoneNumberNormalizer.php';
    require_once $root . '/src/Integrations/Notifications/TelnyxConfiguration.php';
    require_once $root . '/src/Core/Application/SmsProviderRoutingPolicy.php';
    require_once $root . '/src/Integrations/BookingNotificationMobileDispatcher.php';

    use Delnavazan\Platform\Integrations\BookingNotificationMobileDispatcher;
    use Delnavazan\Platform\Integrations\Notifications\TelnyxConfiguration;

    final class DznBookingMobileSettings {
        public function __construct(private array $values) {}
        public function value(string $provider, string $field): string { return (string) ($this->values[$provider][$field] ?? ''); }
    }

    $settings = new DznBookingMobileSettings(array(
        'sms' => array('api_key' => 'test-key', 'messaging_profile_id' => 'profile-1', 'sender_id' => '+15550000001', 'webhook_public_key' => 'public-key'),
        'whatsapp' => array('sender_id' => '+15550000001', 'waba_id' => '1421107699354690', 'messaging_profile_id' => 'whatsapp-profile-1', 'webhook_public_key' => 'public-key'),
        'sms_ir' => array('api_key' => 'sms-ir-test-key', 'sender_id' => '3000xxxx', 'endpoint' => 'https://sms-ir.example.test/send'),
    ));
    $configuration = new TelnyxConfiguration($settings);
    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

    $student = (object) array('id' => 81, 'student_id' => 7, 'student_whatsapp' => '09123456789', 'student_mobile' => '09123456789', 'teacher_id' => 9, 'teacher_whatsapp' => '', 'teacher_phone' => '+61412345678');
    $confirmedVariables = array('name' => 'باران', 'teacher' => 'استاد ندا', 'course' => 'تار', 'when' => 'شنبه ۱۰ آبان، ۱۷:۰۰', 'join_url' => 'https://meet.example/abc', 'portal_url' => 'https://delnavazan.com/student-portal/');
    $requestedVariables = array('name' => 'باران', 'course' => 'تار', 'when' => 'شنبه ۱۰ آبان، ۱۷:۰۰', 'portal_url' => 'https://delnavazan.com/student-portal/');
    $teacherVariables = array('teacher' => 'استاد ندا', 'course' => 'تار', 'student' => 'باران', 'when' => 'شنبه ۱۰ آبان، ۱۷:۰۰', 'teacher_portal_url' => 'https://delnavazan.com/teacher-portal/', 'response_window' => '۲۴ ساعت', 'response_deadline' => 'یکشنبه ۱۱ آبان، ۱۷:۰۰');

    $policy = static fn(string $eventKey, string $channel): bool => $channel === 'whatsapp';
    $notSuppressed = static fn(string $audience, int $id, string $eventKey): bool => false;
    $noSms = static function () use ($assert): array { throw new RuntimeException('SMS must not be attempted'); };

    // 1. Student confirmation goes to the WhatsApp number in E.164 with the registry contract.
    $sent = array();
    $wa = static function (string $to, int $notificationId, string $eventKey, array $parameters) use (&$sent): array {
        $sent[] = compact('to', 'notificationId', 'eventKey', 'parameters');
        return array('acknowledged' => true, 'permanent_failure' => null, 'channel' => 'whatsapp');
    };
    $dispatcher = new BookingNotificationMobileDispatcher($settings, $configuration, $policy, $notSuppressed, $wa, $noSms);
    $result = $dispatcher->dispatch($student, 'booking.confirmed.student', $confirmedVariables);
    $assert(($result['state'] ?? '') === 'accepted' && isset($result['channels']['whatsapp']), 'confirmed event must hand off to WhatsApp');
    $assert(count($sent) === 1 && $sent[0]['to'] === '+989123456789', 'student WhatsApp number must be normalised to E.164');
    $assert($sent[0]['notificationId'] === 81 && $sent[0]['eventKey'] === 'booking.confirmed.student', 'booking notification identity must be preserved');
    foreach (array('name', 'teacher', 'course', 'when') as $code) $assert(isset($sent[0]['parameters'][$code]), 'confirmed parameters must include ' . $code);

    // 2. Teacher new-booking can receive WhatsApp independently of the student preference.
    $sent = array();
    $student->teacher_whatsapp = '+61455555555';
    $dispatcher = new BookingNotificationMobileDispatcher($settings, $configuration, $policy, $notSuppressed, $wa, $noSms);
    $result = $dispatcher->dispatch($student, 'booking.new.teacher', $teacherVariables);
    $assert(($result['state'] ?? '') === 'accepted' && $sent[0]['to'] === '+61455555555', 'teacher WhatsApp must be sent when the teacher route is eligible');
    $assert($sent[0]['eventKey'] === 'booking.new.teacher', 'teacher event key must be preserved');
    foreach (array('teacher', 'course', 'student', 'when') as $code) $assert(isset($sent[0]['parameters'][$code]), 'teacher parameters must include ' . $code);

    // An eligible teacher WhatsApp attempt also never fans out to SMS if the provider refuses it.
    $teacherSms = array();
    $teacherSmsAccepted = static function (string $to, int $notificationId, string $body) use (&$teacherSms): array { $teacherSms[] = compact('to', 'notificationId', 'body'); return array('acknowledged' => true, 'channel' => 'sms'); };
    $teacherWaRejected = static function (string $to, int $notificationId, string $eventKey, array $parameters): array { return array('acknowledged' => false, 'permanent_failure' => 'send_refused', 'provider_attempted' => true); };
    $dispatcher = new BookingNotificationMobileDispatcher($settings, $configuration, $policy, $notSuppressed, $teacherWaRejected, $teacherSmsAccepted);
    $teacherResult = $dispatcher->dispatch($student, 'booking.new.teacher', $teacherVariables);
    $assert(($teacherResult['state'] ?? '') === 'failed' && count($teacherSms) === 0, 'failed teacher WhatsApp must not trigger SMS');

    // 3. A failed student WhatsApp attempt never fans out to SMS.
    $sms = array();
    $waRejected = static fn(string $to, int $notificationId, string $eventKey, array $parameters): array => array('acknowledged' => false, 'permanent_failure' => 'send_refused', 'provider_attempted' => true);
    $smsAccepted = static function (string $to, int $notificationId, string $body) use (&$sms): array {
        $sms[] = compact('to', 'notificationId', 'body');
        return array('acknowledged' => true, 'permanent_failure' => null, 'channel' => 'sms');
    };
    $allChannels = static fn(string $eventKey, string $channel): bool => true;
    $dispatcher = new BookingNotificationMobileDispatcher($settings, $configuration, $allChannels, $notSuppressed, $waRejected, $smsAccepted);
    $result = $dispatcher->dispatch($student, 'booking.requested.student', $requestedVariables);
    $assert(($result['state'] ?? '') === 'failed' && !isset($result['channels']['sms']), 'WhatsApp failure must not trigger a second SMS copy');
    $assert(count($sms) === 0, 'SMS sender must not be called after a failed WhatsApp attempt');

    // 4. Student SMS is available when WhatsApp is not an eligible route before sending.
    $sms = array();
    $smsOnly = static fn(string $eventKey, string $channel): bool => $channel === 'sms';
    $dispatcher = new BookingNotificationMobileDispatcher($settings, $configuration, $smsOnly, $notSuppressed, $wa, $smsAccepted);
    $result = $dispatcher->dispatch($student, 'booking.requested.student', $requestedVariables);
    $assert(($result['state'] ?? '') === 'accepted' && count($sms) === 1, 'SMS must be sent when student WhatsApp is ineligible before dispatch');
    $assert($sms[0]['to'] === '+989123456789' && str_contains($sms[0]['body'], 'تار'), 'SMS must use normalized destination and catalogue copy');

    // 5. A transient WhatsApp failure remains retryable when SMS is disabled for the event.
    $waTransient = static fn(string $to, int $notificationId, string $eventKey, array $parameters): array => array('acknowledged' => false, 'permanent_failure' => null, 'ambiguous' => true);
    $dispatcher = new BookingNotificationMobileDispatcher($settings, $configuration, $policy, $notSuppressed, $waTransient, $noSms);
    $result = $dispatcher->dispatch($student, 'booking.requested.student', $requestedVariables);
    $assert(($result['state'] ?? '') === 'retryable', 'transient WhatsApp failure must stay retryable');

    // 6. A suppression blocks mobile hand-off.
    $suppressed = static fn(string $audience, int $id, string $eventKey): bool => true;
    $dispatcher = new BookingNotificationMobileDispatcher($settings, $configuration, $policy, $suppressed, $wa, $noSms);
    $result = $dispatcher->dispatch($student, 'booking.confirmed.student', $confirmedVariables);
    $assert(($result['state'] ?? '') === 'skipped' && ($result['reason'] ?? '') === 'suppressed', 'an active suppression must skip mobile delivery');

    // 7. Policy-disabled WhatsApp never reaches the provider.
    $blocked = static fn(string $eventKey, string $channel): bool => false;
    $dispatcher = new BookingNotificationMobileDispatcher($settings, $configuration, $blocked, $notSuppressed, $wa, $noSms);
    $result = $dispatcher->dispatch($student, 'booking.confirmed.student', $confirmedVariables);
    $assert(($result['state'] ?? '') === 'skipped' && ($result['reason'] ?? '') === 'no_enabled_mobile_channel', 'event policy must block mobile delivery');

    // 8. A missing catalogue variable cannot produce a partial template.
    $dispatcher = new BookingNotificationMobileDispatcher($settings, $configuration, $policy, $notSuppressed, $wa, $noSms);
    $result = $dispatcher->dispatch($student, 'booking.confirmed.student', array('name' => 'باران'));
    $assert(($result['state'] ?? '') === 'failed', 'missing parameters must fail closed');

    // 9. An event that does not offer WhatsApp is never sent on the mobile route.
    $dispatcher = new BookingNotificationMobileDispatcher($settings, $configuration, $policy, $notSuppressed, $wa, $noSms);
    $result = $dispatcher->dispatch($student, 'booking.declined.student', array('name' => 'باران', 'teacher' => 'استاد ندا', 'course' => 'تار', 'portal_url' => 'https://delnavazan.com/student-portal/'));
    $assert(($result['state'] ?? '') === 'skipped' && ($result['reason'] ?? '') === 'channel_not_offered', 'declined event must not use WhatsApp');

    echo "Booking mobile notification unit passed\n";
}
