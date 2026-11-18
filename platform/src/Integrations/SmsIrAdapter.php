<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Integrations\Notifications\PhoneNumberNormalizer;
use Delnavazan\Platform\Integrations\Notifications\SmsIrTemplateRegistry;

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

    /**
     * Event-owned SMS hand-off.
     *
     * Every event is mapped to one of the two approved SMS.ir templates. When the matching provider
     * template id is configured, the adapter sends through SMS.ir's template endpoint with named
     * parameters; otherwise it sends the exact approved body through the bulk endpoint. Manual sends
     * and explicit tests keep the body they supply and never use an event mapping.
     */
    public function sendBookingNotification(string $to, int $notificationId, string $text, string $eventKey='', array $variables=array()): array {
        if ($eventKey !== '' && class_exists(SmsIrTemplateRegistry::class)) {
            $template = SmsIrTemplateRegistry::forEvent($eventKey);
            $text = SmsIrTemplateRegistry::renderForEvent($eventKey, $variables);
            $templateId = $this->templateId((string)$template['key']);
            if ($templateId > 0) {
                return $this->deliverTemplate(
                    $to,
                    $notificationId,
                    $templateId,
                    SmsIrTemplateRegistry::parametersForEvent($eventKey, $variables)
                );
            }
        }
        return $this->deliver($to, $text, $notificationId);
    }

    private function templateId(string $templateKey): int {
        $field = $templateKey === SmsIrTemplateRegistry::INTRO_BOOKING
            ? 'template_intro_booking_id'
            : 'template_account_referral_id';
        return (int)$this->settings->value('sms_ir', $field);
    }

    private function deliver(string $to, string $text, int $notificationId): array {
        $to = PhoneNumberNormalizer::toE164($to);
        if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $to)) return array('acknowledged'=>false,'permanent_failure'=>'contact_unusable','code'=>'destination_invalid');
        if ($text === '' || ($notificationId < 1 && $notificationId !== 0)) return array('acknowledged'=>false,'permanent_failure'=>'send_refused');
        if (!$this->settings->ready('sms_ir')) return array('acknowledged'=>false,'permanent_failure'=>'send_refused','reason'=>'sms_ir_not_configured');
        $endpoint = (string)$this->settings->value('sms_ir','endpoint');
        if ($endpoint === '') $endpoint = 'https://api.sms.ir/v1/send/bulk';
        return $this->request($endpoint, array(
            'lineNumber' => (string)$this->settings->value('sms_ir','sender_id'),
            'MessageText' => $text,
            'Mobiles' => array($to),
            'SendDateTime' => null,
        ));
    }

    /** @param array<int,array{name:string,value:string}> $parameters */
    private function deliverTemplate(string $to, int $notificationId, int $templateId, array $parameters): array {
        $to = PhoneNumberNormalizer::toE164($to);
        if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $to)) return array('acknowledged'=>false,'permanent_failure'=>'contact_unusable','code'=>'destination_invalid');
        if ($notificationId < 1) return array('acknowledged'=>false,'permanent_failure'=>'send_refused');
        if (!$this->settings->ready('sms_ir')) return array('acknowledged'=>false,'permanent_failure'=>'send_refused','reason'=>'sms_ir_not_configured');
        $endpoint = (string)$this->settings->value('sms_ir','template_endpoint');
        if ($endpoint === '') $endpoint = 'https://api.sms.ir/v1/send/verify';
        return $this->request($endpoint, array(
            'mobile' => $to,
            'templateId' => $templateId,
            'parameters' => $parameters,
        ));
    }

    private function request(string $endpoint, array $payload): array {
        $response = is_callable($this->http)
            ? ($this->http)($endpoint, array('timeout'=>20,'redirection'=>0,'headers'=>array('x-api-key'=>(string)$this->settings->value('sms_ir','api_key'),'Content-Type'=>'application/json'),'body'=>wp_json_encode($payload)))
            : wp_remote_post($endpoint, array('timeout'=>20,'redirection'=>0,'headers'=>array('x-api-key'=>(string)$this->settings->value('sms_ir','api_key'),'Content-Type'=>'application/json'),'body'=>wp_json_encode($payload)));
        if (is_wp_error($response)) return array('acknowledged'=>false,'permanent_failure'=>null,'failure'=>'transport','ambiguous'=>true);
        $status=(int)wp_remote_retrieve_response_code($response);
        if ($status<200||$status>=300) return array('acknowledged'=>false,'permanent_failure'=>$status>=500||$status===429?null:'send_refused','http'=>$status,'ambiguous'=>$status>=500||$status===429);
        $body=wp_remote_retrieve_body($response);
        $decoded=is_string($body)?json_decode($body,true):null;
        $successful=is_array($decoded)&&(
            ($decoded['IsSuccessful']??null)===true
            ||($decoded['isSuccessful']??null)===true
            ||($decoded['status']??null)===1
        );
        if(!$successful)return array('acknowledged'=>false,'permanent_failure'=>'send_refused','http'=>$status,'reason'=>'provider_response_not_successful');
        return array('acknowledged'=>true,'permanent_failure'=>null,'provider'=>'sms_ir','channel'=>'sms','provider_response'=>array('is_successful'=>true));
    }
}
