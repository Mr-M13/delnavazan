<?php
/** Local-only provider QA contract: WhatsApp and Telnyx must never send. */
$GLOBALS['dzn_mock_options'] = array();
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['dzn_mock_options'][$key] ?? $default; }
function update_option(string $key, mixed $value, mixed $autoload = null): bool { $GLOBALS['dzn_mock_options'][$key] = $value; return true; }
function wp_salt(string $scheme = 'auth'): string { return 'mock-notification-test-salt-' . $scheme; }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_email(string $value): string { return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : ''; }
function is_email(string $value): string|false { return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : false; }
function esc_url_raw(string $value): string { return filter_var($value, FILTER_VALIDATE_URL) ? $value : ''; }
function admin_url(string $path = ''): string { return 'https://academy.example/wp-admin/' . $path; }
function wp_remote_post(): never { throw new RuntimeException('A local provider mock attempted network I/O'); }

$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/IntegrationSettingsService.php';
require_once $root . '/src/Integrations/TelnyxSmsAdapter.php';
require_once $root . '/src/Integrations/IntegrationTestRunner.php';

$settings = new \Delnavazan\Platform\Core\Application\IntegrationSettingsService();
$settings->save('whatsapp', array(
    'app_id' => 'mock-app', 'business_account_id' => 'mock-business', 'phone_number_id' => 'mock-phone',
    'test_template_name' => 'dzn_test_delivery', 'test_template_language' => 'en_US',
    'access_token' => 'mock-token', 'app_secret' => 'mock-secret',
));
$settings->save('sms', array('messaging_profile_id' => 'mock-profile', 'api_key' => 'mock-key'));
$runner = new \Delnavazan\Platform\Integrations\IntegrationTestRunner();
$whatsapp = $runner->run('whatsapp', array('destination' => '+61400123456'));
$sms = $runner->run('sms', array('destination' => '+61400123456'));
if (($whatsapp['state'] ?? '') !== 'success' || ($whatsapp['code'] ?? '') !== 'meta_mock_accepted') throw new RuntimeException('Configured WhatsApp test must return local mock acceptance');
if (($sms['state'] ?? '') !== 'success' || ($sms['code'] ?? '') !== 'telnyx_mock_accepted') throw new RuntimeException('Configured Telnyx test must return local mock acceptance');
if (($runner->run('sms', array('destination' => 'not-a-number'))['code'] ?? '') !== 'destination_invalid') throw new RuntimeException('Telnyx local mock must still reject invalid destinations');
echo "Integration notification local mock runtime contract passed\n";
