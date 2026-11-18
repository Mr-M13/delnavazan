<?php
function is_wp_error($value): bool { return false; }
function wp_remote_retrieve_response_code($value): int { return (int)$value['code']; }
function wp_remote_retrieve_body($value): string { return (string)($value['body']??''); }
function wp_json_encode($value): string { return json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
require_once dirname(__DIR__).'/src/Integrations/Notifications/PhoneNumberNormalizer.php';
require_once dirname(__DIR__).'/src/Integrations/Notifications/SmsIrTemplateRegistry.php';
require_once dirname(__DIR__).'/src/Integrations/SmsIrAdapter.php';

use Delnavazan\Platform\Integrations\Notifications\SmsIrTemplateRegistry;
use Delnavazan\Platform\Integrations\SmsIrAdapter;

final class SmsIrSettingsFixture {
    public function __construct(private array $values, private bool $ready = true) {}
    public function ready(string $provider): bool { return $provider === 'sms_ir' && $this->ready; }
    public function value(string $provider, string $field): string { return (string)($this->values[$provider][$field] ?? ''); }
}

$calls = array();
$settings = new SmsIrSettingsFixture(array('sms_ir'=>array('api_key'=>'write-only','sender_id'=>'3000xxxx','endpoint'=>'https://sms-ir.example.test/send')));
$http = static function(string $url, array $args) use (&$calls): array { $calls[] = array($url, $args); return array('code'=>200, 'body'=>'{"IsSuccessful":true,"Message":"accepted"}'); };
$adapter = new SmsIrAdapter($settings, $http);
$result = $adapter->sendBookingNotification('+989121234567', 81, 'پیام آزمایشی');
if (empty($result['acknowledged']) || ($calls[0][0]??'') !== 'https://sms-ir.example.test/send') throw new RuntimeException('SMS.ir booking hand-off failed');
$payload = json_decode((string)($calls[0][1]['body']??''), true);
if (($payload['lineNumber']??'') !== '3000xxxx' || ($payload['MessageText']??'') !== 'پیام آزمایشی' || ($payload['Mobiles']??array()) !== array('+989121234567') || !array_key_exists('SendDateTime',$payload)) throw new RuntimeException('SMS.ir payload routing contract failed');
$errorAdapter = new SmsIrAdapter($settings, static fn(string $url, array $args): array => array('code'=>200, 'body'=>'{"IsSuccessful":false,"Message":"rejected"}'));
if (($errorAdapter->sendBookingNotification('+989121234567', 83, 'rejected')['reason']??'') !== 'provider_response_not_successful') throw new RuntimeException('SMS.ir must reject a 2xx error response');
$notReady = new SmsIrAdapter(new SmsIrSettingsFixture(array('sms_ir'=>array()), false), $http);
if (($notReady->sendBookingNotification('+989121234567', 82, 'blocked')['reason']??'') !== 'sms_ir_not_configured') throw new RuntimeException('SMS.ir must fail closed when unconfigured');

/* Event-owned SMS uses one of the two approved SMS.ir templates, never arbitrary catalogue text. */
$adapter->sendBookingNotification('+989121234567', 84, 'ignored by the approved template', 'booking.new.teacher', array(
    'teacher'=>'ندا','course'=>'تار','student'=>'باران',
    'when'=>'شنبه ۱۰ آبان ۱۴۰۵، ساعت ۱۷:۰۰ تا ۱۸:۰۰ به وقت Asia/Tehran',
));
$introPayload = json_decode((string)($calls[1][1]['body']??''), true);
$introText = (string)($introPayload['MessageText']??'');
if (!str_contains($introText, 'دلنوازان : درود ندا عزیز')
    || !str_contains($introText, 'کلاس آشنایی تار')
    || !str_contains($introText, 'در تاریخ شنبه ۱۰ آبان ۱۴۰۵ ساعت ۱۷:۰۰ تا ۱۸:۰۰')
    || !str_contains($introText, 'لطفا برای تأیید یا رد به حساب کاربری سر بزنید')) {
    throw new RuntimeException('SMS.ir new-booking template mapping failed');
}
$adapter->sendBookingNotification('+989121234567', 85, 'ignored by the approved template', 'lesson.reminder.student', array(
    'name'=>'باران','course'=>'تار','teacher'=>'ندا','when'=>'شنبه ۱۰ آبان ۱۴۰۵، ساعت ۱۷:۰۰',
));
$genericPayload = json_decode((string)($calls[2][1]['body']??''), true);
if (($genericPayload['MessageText']??'') !== 'دلنوازان: باران عزیز، لطفا به حساب کاربری خود مراجعه کنید.') {
    throw new RuntimeException('SMS.ir generic account template is not mapped to other events');
}
if (SmsIrTemplateRegistry::keyForEvent('booking.new.teacher') !== SmsIrTemplateRegistry::INTRO_BOOKING
    || SmsIrTemplateRegistry::keyForEvent('lesson.reminder.student') !== SmsIrTemplateRegistry::GENERIC) {
    throw new RuntimeException('SMS.ir event-to-template mapping is not deterministic');
}

/* Configured provider template IDs use the template endpoint with the registered parameter names. */
$templateCalls = array();
$templateSettings = new SmsIrSettingsFixture(array('sms_ir'=>array(
    'api_key'=>'write-only','sender_id'=>'3000xxxx',
    'template_endpoint'=>'https://sms-ir.example.test/verify',
    'template_intro_booking_id'=>'12345','template_account_referral_id'=>'67890',
)));
$templateHttp = static function(string $url, array $args) use (&$templateCalls): array {
    $templateCalls[] = array($url, $args);
    return array('code'=>200, 'body'=>'{"status":1,"message":"accepted"}');
};
$templateAdapter = new SmsIrAdapter($templateSettings, $templateHttp);
$templateResult = $templateAdapter->sendBookingNotification('+989121234567', 86, 'ignored', 'booking.new.teacher', array(
    'teacher'=>'ندا','course'=>'تار','when'=>'2026-11-05 17:00:00',
));
$templatePayload = json_decode((string)($templateCalls[0][1]['body']??''), true);
if (empty($templateResult['acknowledged'])
    || ($templateCalls[0][0]??'') !== 'https://sms-ir.example.test/verify'
    || (int)($templatePayload['templateId']??0) !== 12345
    || ($templatePayload['mobile']??'') !== '+989121234567'
    || array_column((array)($templatePayload['parameters']??array()), 'name') !== array('NAME','INSTRUMENT','DATE','TIME')) {
    throw new RuntimeException('SMS.ir configured template mapping failed');
}
echo "SMS.ir adapter unit passed\n";
