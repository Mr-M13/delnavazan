<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Integrations\Notifications\PhoneNumberNormalizer;

/** SMS.ir adapter. It is deliberately separate from Telnyx and never acts as a provider fallback. */
final class SmsIrAdapter {
    public function __construct(private $settings = null, private $http = null) {
        $this->settings ??= new IntegrationSettingsService();
    }

    public function sendTest(string $destination): array {
        return $this->deliver($destination, '[Delnavazan TEST] SMS.ir transport check. This is not a notification.', 0);
    }

    public function send(array $command): array {
        return $this->deliver((string)($command['to']??''), (string)($command['body']??''), 0);
    }

    public function sendBookingNotification(string $to, int $notificationId, string $text): array {
        return $this->deliver($to, $text, $notificationId);
    }

    private function deliver(string $to, string $text, int $notificationId): array {
        $to = PhoneNumberNormalizer::toE164($to);
        if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $to)) return array('acknowledged'=>false,'permanent_failure'=>'contact_unusable','code'=>'destination_invalid');
        if ($text === '' || ($notificationId < 1 && $notificationId !== 0)) return array('acknowledged'=>false,'permanent_failure'=>'send_refused');
        if (!$this->settings->ready('sms_ir')) return array('acknowledged'=>false,'permanent_failure'=>'send_refused','reason'=>'sms_ir_not_configured');
        $endpoint = (string)$this->settings->value('sms_ir','endpoint');
        if ($endpoint === '') $endpoint = 'https://api.sms.ir/v1/send/bulk';
        $payload = array(
            'lineNumber' => (string)$this->settings->value('sms_ir','sender_id'),
            'Messages' => array(array('MobileNumber'=>$to,'MessageText'=>$text)),
        );
        $response = is_callable($this->http)
            ? ($this->http)($endpoint, array('timeout'=>20,'redirection'=>0,'headers'=>array('x-api-key'=>(string)$this->settings->value('sms_ir','api_key'),'Content-Type'=>'application/json'),'body'=>wp_json_encode($payload)))
            : wp_remote_post($endpoint, array('timeout'=>20,'redirection'=>0,'headers'=>array('x-api-key'=>(string)$this->settings->value('sms_ir','api_key'),'Content-Type'=>'application/json'),'body'=>wp_json_encode($payload)));
        if (is_wp_error($response)) return array('acknowledged'=>false,'permanent_failure'=>null,'failure'=>'transport','ambiguous'=>true);
        $status=(int)wp_remote_retrieve_response_code($response);
        if ($status<200||$status>=300) return array('acknowledged'=>false,'permanent_failure'=>$status>=500||$status===429?null:'send_refused','http'=>$status,'ambiguous'=>$status>=500||$status===429);
        return array('acknowledged'=>true,'permanent_failure'=>null,'provider'=>'sms_ir','channel'=>'sms');
    }
}
