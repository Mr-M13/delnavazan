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
    require_once $root . '/src/Integrations/BookingNotificationMobileDispatcher.php';

    use Delnavazan\Platform\Integrations\BookingNotificationMobileDispatcher;
    use Delnavazan\Platform\Integrations\Notifications\TelnyxConfiguration;

    final class DznBookingMobileSettings {
        public function __construct(private array $values) {}
        public function value(string $provider, string $field): string { return (string) ($this->values[$provider][$field] ?? ''); }
    }

    $settings = new DznBookingMobileSettings(array(
        'sms' => array('api_key' => 'test-key', 'messaging_profile_id' => 'profile-1', 'sender_id' => '+15550000001', 'webhook_public_key' => 'public-key'),
        'whatsapp' => array('sender_id' => '+15550000001', 'waba_id' => '1421107699354690', 'webhook_public_key' => 'public-key'),
    ));
    $configuration = new TelnyxConfiguration($settings);
    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

    $student = (object) array('id' => 81, 'student_id' => 7, 'student_whatsapp' => '09123456789', 'student_mobile' => '09123456789', 'teacher_id' => 9, 'teacher_whatsapp' => '', 'teacher_phone' => '+61412345678');
    $confirmedVariables = array('name' => 'باران', 'teacher' => 'استاد ندا', 'course' => 'تار', 'when' => 'شنبه ۱۰ آبان، ۱۷:۰۰', 'join_url' => 'https://meet.example/abc', 'portal_url' => 'https://delnavazan.com/student-portal/');
    $requestedVariables = array('name' => 'باران', 'course' => 'تار', 'when' => 'شنبه ۱۰ آبان، ۱۷:۰۰', 'portal_url' => 'https://delnavazan.com/student-portal/');
    $teacherVariables = array('teacher' => 'استاد ندا', 'course' => 'تار', 'student' => 'باران', 'when' => 'شنبه ۱۰ آبان، ۱۷:۰۰', 'teacher_portal_url' => 'https://delnavazan.com/teacher-portal/');

    $policy = static fn(string $eventKey, string $channel, bool $fallback): bool => true;
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
    $assert(($result['state'] ?? '') === 'accepted' && ($result['channel'] ?? '') === 'whatsapp', 'confirmed event must hand off to WhatsApp');
    $assert(count($sent) === 1 && $sent[0]['to'] === '+989123456789', 'student WhatsApp number must be normalised to E.164');
    $assert($sent[0]['notificationId'] === 81 && $sent[0]['eventKey'] === 'booking.confirmed.student', 'booking notification identity must be preserved');
    foreach (array('name', 'teacher', 'course', 'when') as $code) $assert(isset($sent[0]['parameters'][$code]), 'confirmed parameters must include ' . $code);

    // 2. Teacher new-booking falls back to the teacher phone when no WhatsApp number is stored.
    $sent = array();
    $dispatcher = new BookingNotificationMobileDispatcher($settings, $configuration, $policy, $notSuppressed, $wa, $noSms);
    $result = $dispatcher->dispatch($student, 'booking.new.teacher', $teacherVariables);
    $assert(($result['state'] ?? '') === 'accepted' && $sent[0]['to'] === '+61412345678', 'teacher mobile must fall back to the stored phone');
    $assert($sent[0]['eventKey'] === 'booking.new.teacher', 'teacher event key must be preserved');
    foreach (array('teacher', 'course', 'student', 'when') as $code) $assert(isset($sent[0]['parameters'][$code]), 'teacher parameters must include ' . $code);

    // 3. A permanent WhatsApp refusal may use SMS when the category explicitly enables it.
    $sms = array();
    $waRejected = static fn(string $to, int $notificationId, string $eventKey, array $parameters): array => array('acknowledged' => false, 'permanent_failure' => 'send_refused', 'provider_attempted' => true);
    $smsAccepted = static function (string $to, int $notificationId, string $body) use (&$sms): array {
        $sms[] = compact('to', 'notificationId', 'body');
        return array('acknowledged' => true, 'permanent_failure' => null, 'channel' => 'sms');
    };
    $dispatcher = new BookingNotificationMobileDispatcher($settings, $configuration, $policy, $notSuppressed, $waRejected, $smsAccepted);
    $result = $dispatcher->dispatch($student, 'booking.requested.student', $requestedVariables);
    $assert(($result['state'] ?? '') === 'accepted' && ($result['channel'] ?? '') === 'sms', 'permanent WhatsApp refusal must use the SMS fallback');
    $assert(count($sms) === 1 && $sms[0]['to'] === '+989123456789', 'SMS must use the E.164 mobile number');
    $assert(str_contains($sms[0]['body'], 'تار') && str_contains($sms[0]['body'], 'شنبه'), 'SMS copy must be rendered from the catalogue');

    // 4. A transient WhatsApp failure is retried by the queue, never fanned out to SMS.
    $waTransient = static fn(string $to, int $notificationId, string $eventKey, array $parameters): array => array('acknowledged' => false, 'permanent_failure' => null, 'ambiguous' => true);
    $dispatcher = new BookingNotificationMobileDispatcher($settings, $configuration, $policy, $notSuppressed, $waTransient, $noSms);
    $result = $dispatcher->dispatch($student, 'booking.requested.student', $requestedVariables);
    $assert(($result['state'] ?? '') === 'retryable', 'transient WhatsApp failure must stay retryable');

    // 5. A suppression blocks mobile hand-off.
    $suppressed = static fn(string $audience, int $id, string $eventKey): bool => true;
    $dispatcher = new BookingNotificationMobileDispatcher($settings, $configuration, $policy, $suppressed, $wa, $noSms);
    $result = $dispatcher->dispatch($student, 'booking.confirmed.student', $confirmedVariables);
    $assert(($result['state'] ?? '') === 'skipped' && ($result['reason'] ?? '') === 'suppressed', 'an active suppression must skip mobile delivery');

    // 6. Policy-disabled WhatsApp never reaches the provider.
    $blocked = static fn(string $eventKey, string $channel, bool $fallback): bool => false;
    $dispatcher = new BookingNotificationMobileDispatcher($settings, $configuration, $blocked, $notSuppressed, $wa, $noSms);
    $result = $dispatcher->dispatch($student, 'booking.confirmed.student', $confirmedVariables);
    $assert(($result['state'] ?? '') === 'skipped' && ($result['reason'] ?? '') === 'whatsapp_policy_blocked', 'category policy must block WhatsApp');

    // 7. A missing catalogue variable cannot produce a partial template.
    $dispatcher = new BookingNotificationMobileDispatcher($settings, $configuration, $policy, $notSuppressed, $wa, $noSms);
    $result = $dispatcher->dispatch($student, 'booking.confirmed.student', array('name' => 'باران'));
    $assert(($result['state'] ?? '') === 'skipped' && ($result['reason'] ?? '') === 'variable_contract_mismatch', 'missing parameters must fail closed');

    // 8. An event that does not offer WhatsApp is never sent on the mobile route.
    $dispatcher = new BookingNotificationMobileDispatcher($settings, $configuration, $policy, $notSuppressed, $wa, $noSms);
    $result = $dispatcher->dispatch($student, 'booking.declined.student', array('name' => 'باران', 'teacher' => 'استاد ندا', 'course' => 'تار', 'portal_url' => 'https://delnavazan.com/student-portal/'));
    $assert(($result['state'] ?? '') === 'skipped' && ($result['reason'] ?? '') === 'channel_not_offered', 'declined event must not use WhatsApp');

    echo "Booking mobile notification unit passed\n";
}
