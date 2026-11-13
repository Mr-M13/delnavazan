<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationSuppressionRepository;
use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
use Delnavazan\Platform\Integrations\Notifications\NotificationChannelPolicy;
use Delnavazan\Platform\Integrations\Notifications\NotificationCopyResolver;
use Delnavazan\Platform\Integrations\Notifications\PhoneNumberNormalizer;
use Delnavazan\Platform\Integrations\Notifications\StudentNotificationContactLookup;
use Delnavazan\Platform\Integrations\Notifications\TelnyxConfiguration;

/**
 * WhatsApp-first mobile delivery for the event-owned booking notification queue.
 *
 * The booking queue predates the canonical notification authority and cannot use the canonical
 * transport router without widening the closed S intent vocabulary.  This dispatcher is deliberately
 * narrow: it only handles a booking notification that already exists, it resolves contact and copy
 * from that frozen business event, and it never invents a recipient or a provider template.
 *
 * WhatsApp and SMS are independent event-owned methods. Each one is evaluated against its own
 * event switch, configuration, recipient address and copy; neither outcome changes the other.
 */
final class BookingNotificationMobileDispatcher {
    public function __construct(
        private $settings = null,
        private ?TelnyxConfiguration $configuration = null,
        private $channelPolicy = null,
        private $suppressionCheck = null,
        private $whatsappSender = null,
        private $smsSender = null
    ) {
        $this->settings ??= new IntegrationSettingsService();
        $this->configuration ??= new TelnyxConfiguration($this->settings);
    }

    /** @return array<string,mixed> */
    public function dispatch(object $row, string $eventKey, array $variables): array {
        $event = NotificationCatalogue::get($eventKey);
        if ($event === null || (!in_array('whatsapp', (array) $event['channels'], true) && !in_array('sms', (array) $event['channels'], true))) {
            return self::skipped('channel_not_offered');
        }
        $audience = (string) $event['audience'];
        $contact = $this->contact($row, $audience);
        if ($contact === null) {
            return self::skipped('contact_unusable');
        }
        if ($this->suppressed($audience, (int) $contact['id'], $eventKey)) {
            return self::skipped('suppressed');
        }
        $results = array();
        if ($this->allowed($eventKey, 'whatsapp') && $this->configuration->whatsappReady() && (string) $contact['whatsapp'] !== '') {
            $parameters = $this->parameters($eventKey, $variables);
            if ($parameters === null) $results['whatsapp'] = array('acknowledged' => false, 'permanent_failure' => 'variable_contract_mismatch');
            else try {$results['whatsapp'] = $this->sendWhatsApp((string) $contact['whatsapp'], (int) $row->id, $eventKey, $parameters);}
            catch (\Throwable $error) {$this->report('whatsapp_exception', (int) $row->id, $error->getMessage());$results['whatsapp'] = array('acknowledged' => false, 'permanent_failure' => 'whatsapp_exception');}
        }
        if ($this->allowed($eventKey, 'sms') && $this->configuration->smsReady() && (string) $contact['sms'] !== '') {
            $body = $this->smsBody($eventKey, $variables);
            if ($body === null) $results['sms'] = array('acknowledged' => false, 'permanent_failure' => 'sms_copy_unavailable');
            else try {$results['sms'] = $this->sendSms((string) $contact['sms'], (int) $row->id, $body);}
            catch (\Throwable $error) {$this->report('sms_exception', (int) $row->id, $error->getMessage());$results['sms'] = array('acknowledged' => false, 'permanent_failure' => 'sms_exception');}
        }
        if ($results === array()) return self::skipped('no_enabled_mobile_channel');
        foreach ($results as $result) if (!empty($result['acknowledged'])) return array('state' => 'accepted', 'channels' => $results);
        foreach ($results as $result) if (($result['permanent_failure'] ?? null) === null) return array('state' => 'retryable', 'channels' => $results);
        return array('state' => 'failed', 'channels' => $results);
    }

    /** @return array{id:int,whatsapp:string,sms:string}|null */
    private function contact(object $row, string $audience): ?array {
        if ($audience === 'student') {
            $id = (int) ($row->student_id ?? 0);
            if ($id < 1) return null;
            $whatsapp = trim((string) ($row->student_account_whatsapp ?? ''));
            $phone = trim((string) ($row->student_account_phone ?? ''));
            if ($whatsapp === '') $whatsapp = trim((string) ($row->student_whatsapp ?? ''));
            if ($phone === '') $phone = trim((string) ($row->student_mobile ?? ''));
        } elseif ($audience === 'teacher') {
            $id = (int) ($row->teacher_id ?? 0);
            if ($id < 1) return null;
            $whatsapp = trim((string) ($row->teacher_whatsapp ?? ''));
            $phone = trim((string) ($row->teacher_phone ?? ''));
        } else {
            return null;
        }
        $whatsapp = PhoneNumberNormalizer::toE164($whatsapp !== '' ? $whatsapp : $phone);
        $phone = PhoneNumberNormalizer::toE164($phone !== '' ? $phone : $whatsapp);
        if ($whatsapp === '' && $phone === '') return null;
        return array('id' => $id, 'whatsapp' => $whatsapp !== '' ? $whatsapp : $phone, 'sms' => $phone);
    }

    /** @return array<string,string>|null */
    private function parameters(string $eventKey, array $variables): ?array {
        $parameters = array();
        foreach (NotificationCatalogue::variables($eventKey) as $code) {
            if (!array_key_exists($code, $variables)) return null;
            $value = $variables[$code];
            if (is_array($value) || is_object($value)) return null;
            $parameters[$code] = trim((string) $value);
        }
        return $parameters;
    }

    private function smsBody(string $eventKey, array $variables): ?string {
        $copy = NotificationCopyResolver::message($eventKey, 'sms', NotificationCatalogue::PRIMARY_LOCALE, $variables, array('subject' => '', 'body' => ''));
        $body = trim((string) ($copy['body'] ?? ''));
        return $body === '' ? null : $body;
    }

    private function allowed(string $eventKey, string $channel): bool {
        if (is_callable($this->channelPolicy)) return (bool) ($this->channelPolicy)($eventKey, $channel);
        return NotificationChannelPolicy::allows($eventKey, $channel);
    }

    private function suppressed(string $audience, int $recipientId, string $eventKey): bool {
        if (is_callable($this->suppressionCheck)) return (bool) ($this->suppressionCheck)($audience, $recipientId, $eventKey);
        $lookup = new StudentNotificationContactLookup();
        $digest = $audience === 'teacher' ? $lookup->teacherRecipientDigest($recipientId) : $lookup->recipientDigest($recipientId);
        return (new NotificationSuppressionRepository())->active($digest, $eventKey, NotificationSupport::now(), true) !== null;
    }

    private function sendWhatsApp(string $to, int $notificationId, string $eventKey, array $parameters): array {
        if (is_callable($this->whatsappSender)) return (array) ($this->whatsappSender)($to, $notificationId, $eventKey, $parameters);
        return (new TelnyxWhatsAppAdapter($this->settings))->sendBookingNotification($to, $notificationId, $eventKey, $parameters);
    }

    private function sendSms(string $to, int $notificationId, string $body): array {
        if (is_callable($this->smsSender)) return (array) ($this->smsSender)($to, $notificationId, $body);
        return (new TelnyxSmsAdapter($this->settings))->sendBookingNotification($to, $notificationId, $body);
    }

    private function report(string $code, int $notificationId, string $detail): void {
        if (function_exists('do_action')) do_action('dzn_booking_mobile_notification_failed', $notificationId, $code, $detail);
    }

    /** @return array{state:string,reason:string} */
    private static function skipped(string $reason): array {
        return array('state' => 'skipped', 'reason' => $reason);
    }

    /** @return array{state:string,reason:string} */
    private static function failed(string $reason): array {
        return array('state' => 'failed', 'reason' => $reason);
    }
}
