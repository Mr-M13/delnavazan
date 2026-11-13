<?php
function is_wp_error($value): bool { return false; }
function wp_remote_retrieve_response_code($value): int { return (int)$value['code']; }
function wp_json_encode($value): string { return json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
require_once dirname(__DIR__).'/src/Integrations/Notifications/PhoneNumberNormalizer.php';
require_once dirname(__DIR__).'/src/Integrations/SmsIrAdapter.php';

use Delnavazan\Platform\Integrations\SmsIrAdapter;

final class SmsIrSettingsFixture {
    public function __construct(private array $values, private bool $ready = true) {}
    public function ready(string $provider): bool { return $provider === 'sms_ir' && $this->ready; }
    public function value(string $provider, string $field): string { return (string)($this->values[$provider][$field] ?? ''); }
}

$calls = array();
$settings = new SmsIrSettingsFixture(array('sms_ir'=>array('api_key'=>'write-only','sender_id'=>'3000xxxx','endpoint'=>'https://sms-ir.example.test/send')));
$http = static function(string $url, array $args) use (&$calls): array { $calls[] = array($url, $args); return array('code'=>200, 'body'=>'{}'); };
$adapter = new SmsIrAdapter($settings, $http);
$result = $adapter->sendBookingNotification('+989121234567', 81, 'پیام آزمایشی');
if (empty($result['acknowledged']) || ($calls[0][0]??'') !== 'https://sms-ir.example.test/send') throw new RuntimeException('SMS.ir booking hand-off failed');
$payload = json_decode((string)($calls[0][1]['body']??''), true);
if (($payload['lineNumber']??'') !== '3000xxxx' || ($payload['Messages'][0]['MobileNumber']??'') !== '+989121234567') throw new RuntimeException('SMS.ir payload routing contract failed');
$notReady = new SmsIrAdapter(new SmsIrSettingsFixture(array('sms_ir'=>array()), false), $http);
if (($notReady->sendBookingNotification('+989121234567', 82, 'blocked')['reason']??'') !== 'sms_ir_not_configured') throw new RuntimeException('SMS.ir must fail closed when unconfigured');
echo "SMS.ir adapter unit passed\n";
