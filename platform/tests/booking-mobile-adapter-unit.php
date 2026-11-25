<?php
$root = dirname(__DIR__);
$GLOBALS['wpdb'] = (object) array('prefix' => 'wp_');
if (!function_exists('wp_salt')) { function wp_salt(string $scheme = ''): string { return 'test-salt'; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($value): string|false { return json_encode($value); } }
if (!function_exists('is_wp_error')) { function is_wp_error($value): bool { return false; } }
if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($response): int { return (int) ($response->response['code'] ?? 0); } }
if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($response): string { return (string) ($response->body ?? ''); } }

require_once $root . '/src/Core/Support/Identifier.php';
require_once $root . '/src/Core/Application/NotificationSupport.php';
require_once $root . '/src/Core/Infrastructure/Repository/NotificationProviderRepository.php';
require_once $root . '/src/Core/Infrastructure/Repository/NotificationRepository.php';
require_once $root . '/src/Core/Infrastructure/Repository/NotificationAttemptRepository.php';
require_once $root . '/src/Core/Infrastructure/Repository/NotificationTemplateRepository.php';
require_once $root . '/src/Integrations/Notifications/NotificationCatalogue.php';
require_once $root . '/src/Integrations/Notifications/TelnyxTemplateRegistry.php';
require_once $root . '/src/Integrations/Notifications/TelnyxTemplateAuthority.php';
require_once $root . '/src/Integrations/Notifications/PhoneNumberNormalizer.php';
require_once $root . '/src/Integrations/TelnyxWhatsAppAdapter.php';
require_once $root . '/src/Integrations/TelnyxSmsAdapter.php';

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationProviderRepository;
use Delnavazan\Platform\Integrations\Notifications\TelnyxTemplateAuthority;
use Delnavazan\Platform\Integrations\Notifications\TelnyxTemplateRegistry;
use Delnavazan\Platform\Integrations\TelnyxSmsAdapter;
use Delnavazan\Platform\Integrations\TelnyxWhatsAppAdapter;

final class DznBookingAdapterSettings {
    public function __construct(private array $values) {}
    public function value(string $provider, string $field): string { return (string) ($this->values[$provider][$field] ?? ''); }
}

final class DznBookingProviderRepository extends NotificationProviderRepository {
    public array $messages = array();
    public function __construct(private array $templates = array()) {}
    public function templatesForEvent(string $provider, string $eventKey, string $language): array {
        return array_values(array_filter($this->templates, static fn(object $row): bool => (string) $row->provider === $provider && (string) $row->event_key === $eventKey && (string) $row->language === $language));
    }
    public function templateByName(string $provider, string $name, string $language): ?object {
        foreach ($this->templates as $row) if ((string) $row->provider === $provider && (string) $row->template_name === $name && (string) $row->language === $language) return $row;
        return null;
    }
    public function messageForAttempt(int $notificationId, int $attemptId, ?string $channel = null): ?object {
        $prefix = $notificationId . ':' . $attemptId;
        if ($channel !== null) return $this->messages[$prefix . ':' . $channel] ?? null;
        foreach ($this->messages as $key => $row) if (str_starts_with((string) $key, $prefix . ':')) return $row;
        return null;
    }
    public function messageForReference(string $digest): ?object {
        foreach ($this->messages as $row) if ((string) ($row->message_reference_digest ?? '') === $digest) return $row;
        return null;
    }
    public function insertMessage(array $data): int {
        $key = (int) $data['notification_id'] . ':' . (int) $data['attempt_id'] . ':' . (string) $data['channel'];
        if (isset($this->messages[$key])) throw new RuntimeException('Duplicate entry for key message_reference');
        $this->messages[$key] = (object) $data;
        return $key;
    }
}

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$settings = new DznBookingAdapterSettings(array(
    'sms' => array('api_key' => 'test-key', 'messaging_profile_id' => 'profile-1', 'sender_id' => '+15550000001'),
    'whatsapp' => array('sender_id' => '+15550000001', 'messaging_profile_id' => 'profile-1'),
));
$definition = TelnyxTemplateRegistry::definitions()['delnavazan_booking_confirmation_v2_fa'];
$template = (object) array(
    'provider' => 'telnyx', 'template_name' => 'delnavazan_booking_confirmation_v2_fa', 'language' => 'fa',
    'status' => 'APPROVED', 'category' => 'UTILITY', 'event_key' => 'booking.requested.student', 'audience' => 'student',
    'variable_order' => implode(',', $definition['variable_order']),
);
$provider = new DznBookingProviderRepository(array($template));
$authority = new TelnyxTemplateAuthority($provider);
$requests = array();
$http = static function (string $url, array $args) use (&$requests): object {
    $requests[] = array('url' => $url, 'args' => $args);
    return (object) array('response' => array('code' => 200), 'body' => json_encode(array('data' => array('id' => 'msg-123'))));
};

$whatsApp = new TelnyxWhatsAppAdapter($settings, $http, $provider, null, null, null, $authority);
$result = $whatsApp->sendBookingNotification('09123456789', 81, 'booking.requested.student', array('name' => 'باران', 'course' => 'تار', 'when' => 'شنبه ۱۰ آبان', 'portal_url' => 'https://example.invalid/student-portal/'));
$assert(($result['acknowledged'] ?? false) === true, 'booking WhatsApp hand-off must be acknowledged');
$assert(count($requests) === 1 && $requests[0]['url'] === 'https://api.telnyx.com/v2/messages/whatsapp', 'WhatsApp must use the Telnyx WhatsApp endpoint');
$payload = json_decode($requests[0]['args']['body'], true);
$params = $payload['whatsapp_message']['template']['components'][0]['parameters'] ?? array();
$assert(array_column($params, 'text') === array('باران', 'تار', 'شنبه ۱۰ آبان', 'https://example.invalid/student-portal/'), 'booking WhatsApp must follow the approved registry variable order');
$assert(($payload['to'] ?? '') === '+989123456789', 'booking WhatsApp must normalise the destination');
$assert(isset($provider->messages['81:0:whatsapp']) && $provider->messages['81:0:whatsapp']->channel === 'whatsapp', 'booking WhatsApp must correlate the provider reference with attempt_id 0');

$again = $whatsApp->sendBookingNotification('09123456789', 81, 'booking.requested.student', array('name' => 'باران', 'course' => 'تار', 'when' => 'شنبه ۱۰ آبان', 'portal_url' => 'https://example.invalid/student-portal/'));
$assert(($again['idempotent'] ?? false) === true && count($requests) === 1, 'a repeated booking hand-off must not call the provider twice');

$smsProvider = new DznBookingProviderRepository();
$smsRequests = array();
$smsHttp = static function (string $url, array $args) use (&$smsRequests): object {
    $smsRequests[] = array('url' => $url, 'args' => $args);
    return (object) array('response' => array('code' => 200), 'body' => json_encode(array('data' => array('id' => 'sms-123'))));
};
$sms = new TelnyxSmsAdapter($settings, $smsHttp, $smsProvider);
$smsResult = $sms->sendBookingNotification('09123456789', 82, 'دلنوازان: پیام آزمایشی');
$assert(($smsResult['acknowledged'] ?? false) === true, 'booking SMS hand-off must be acknowledged');
$smsPayload = json_decode($smsRequests[0]['args']['body'], true);
$assert(($smsPayload['to'] ?? '') === '+989123456789' && ($smsPayload['text'] ?? '') === 'دلنوازان: پیام آزمایشی', 'booking SMS payload must carry the normalised destination and rendered copy');
$assert(isset($smsProvider->messages['82:0:sms']) && $smsProvider->messages['82:0:sms']->channel === 'sms', 'booking SMS must correlate the provider reference with attempt_id 0');
$smsAgain = $sms->sendBookingNotification('09123456789', 82, 'دلنوازان: پیام آزمایشی');
$assert(($smsAgain['idempotent'] ?? false) === true && count($smsRequests) === 1, 'a repeated booking SMS hand-off must not call the provider twice');

/*
 * SMS and WhatsApp can share one notification row. A provider reference recorded by one channel
 * must never make the other channel look already delivered: both are independent methods.
 */
$sharedParams = array('name' => 'باران', 'course' => 'تار', 'when' => 'شنبه ۱۰ آبان', 'portal_url' => 'https://example.invalid/student-portal/');
$shared = new DznBookingProviderRepository(array($template));
$sharedAuthority = new TelnyxTemplateAuthority($shared);
$sharedSmsRequests = array(); $sharedWaRequests = array();
$sharedSmsHttp = static function (string $url, array $args) use (&$sharedSmsRequests): object {
    $sharedSmsRequests[] = array('url' => $url, 'args' => $args);
    return (object) array('response' => array('code' => 200), 'body' => json_encode(array('data' => array('id' => 'sms-shared-90'))));
};
$sharedWaHttp = static function (string $url, array $args) use (&$sharedWaRequests): object {
    $sharedWaRequests[] = array('url' => $url, 'args' => $args);
    return (object) array('response' => array('code' => 200), 'body' => json_encode(array('data' => array('id' => 'wa-shared-90'))));
};
$sharedSms = new TelnyxSmsAdapter($settings, $sharedSmsHttp, $shared);
$sharedWa = new TelnyxWhatsAppAdapter($settings, $sharedWaHttp, $shared, null, null, null, $sharedAuthority);
$sharedSms->sendBookingNotification('+61412345678', 90, 'SMS first');
$waAfterSms = $sharedWa->sendBookingNotification('+61412345678', 90, 'booking.requested.student', $sharedParams);
$assert(($waAfterSms['acknowledged'] ?? false) === true && ($waAfterSms['idempotent'] ?? false) === false, 'WhatsApp must still send when SMS already used the same notification row');
$assert(count($sharedWaRequests) === 1, 'WhatsApp must reach the provider once even after an SMS hand-off');
$assert(isset($shared->messages['90:0:sms'], $shared->messages['90:0:whatsapp']), 'both channel references must be correlated separately');

$sharedReverse = new DznBookingProviderRepository(array($template));
$sharedReverseAuthority = new TelnyxTemplateAuthority($sharedReverse);
$sharedReverseSmsRequests = array(); $sharedReverseWaRequests = array();
$sharedReverseWaHttp = static function (string $url, array $args) use (&$sharedReverseWaRequests): object {
    $sharedReverseWaRequests[] = array('url' => $url, 'args' => $args);
    return (object) array('response' => array('code' => 200), 'body' => json_encode(array('data' => array('id' => 'wa-shared-91'))));
};
$sharedReverseSmsHttp = static function (string $url, array $args) use (&$sharedReverseSmsRequests): object {
    $sharedReverseSmsRequests[] = array('url' => $url, 'args' => $args);
    return (object) array('response' => array('code' => 200), 'body' => json_encode(array('data' => array('id' => 'sms-shared-91'))));
};
$sharedReverseWa = new TelnyxWhatsAppAdapter($settings, $sharedReverseWaHttp, $sharedReverse, null, null, null, $sharedReverseAuthority);
$sharedReverseSms = new TelnyxSmsAdapter($settings, $sharedReverseSmsHttp, $sharedReverse);
$sharedReverseWa->sendBookingNotification('+61412345678', 91, 'booking.requested.student', $sharedParams);
$smsAfterWa = $sharedReverseSms->sendBookingNotification('+61412345678', 91, 'SMS after WhatsApp');
$assert(($smsAfterWa['acknowledged'] ?? false) === true && ($smsAfterWa['idempotent'] ?? false) === false, 'SMS must still send when WhatsApp already used the same notification row');
$assert(count($sharedReverseSmsRequests) === 1, 'SMS must reach the provider once even after a WhatsApp hand-off');
$assert(isset($sharedReverse->messages['91:0:whatsapp'], $sharedReverse->messages['91:0:sms']), 'both channel references must be correlated separately in either order');

echo "Booking mobile adapter unit passed\n";
