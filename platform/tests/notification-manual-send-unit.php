<?php
/**
 * Manual notification send: recipient segments, channel routing, STOP suppression, send idempotency
 * and the immutable batch record. Runs without WordPress or a database; a minimal in-memory `$wpdb`
 * and injected collaborators exercise the real service and the real SMS/WhatsApp adapters.
 */
namespace {
    if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__, 2) . '/');
    if (!function_exists('wp_salt')) { function wp_salt($s = 'auth') { return 'test-salt:' . $s; } }
    if (!function_exists('wp_json_encode')) { function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); } }
    if (!function_exists('do_action')) { function do_action(...$a) {} }
    if (!function_exists('current_user_can')) { function current_user_can($c) { return $GLOBALS['dzn_test_can'] ?? true; } }
    if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 7; } }
    if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['dzn_test_options'][$k] ?? $d; } }
    if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['dzn_test_options'][$k] = $v; return true; } }
    if (!function_exists('sanitize_key')) { function sanitize_key($v) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $v)); } }
    if (!function_exists('is_wp_error')) { function is_wp_error($x) { return false; } }
    if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($r) { return is_array($r) ? ($r['code'] ?? 200) : 200; } }
    if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($r) { return is_array($r) ? ($r['body'] ?? '') : ''; } }
    $GLOBALS['dzn_test_options'] = array();
    $GLOBALS['dzn_http_calls'] = array();
}

namespace Delnavazan\Platform\Tests {
    final class FakeWpdb {
        public string $prefix = 'wp_';
        public int $insert_id = 0;
        public string $last_error = '';
        public array $tables = array();
        public function prepare($sql, ...$a) { return $sql; }
        public function suppress_errors($v = true) { return false; }
        public function query($sql) { return true; }
        public function get_row($sql) { return null; }
        public function get_col($sql) { return array(); }
        public function get_results($sql) {
            if (!preg_match('/FROM\s+(\S+)/i', $sql, $m)) return array();
            $table = $this->table($m[1]);
            $rows = $this->rows($table);
            $out = array();
            foreach ($rows as $row) {
                $row = (array) $row;
                if (str_contains($sql, 'archived_at IS NULL') && ($row['archived_at'] ?? null) !== null) continue;
                if (str_contains($sql, 'archived_at IS NOT NULL') && ($row['archived_at'] ?? null) === null) continue;
                if (str_contains($sql, "status='active'") && ($row['status'] ?? '') !== 'active') continue;
                $out[] = (object) $row;
            }
            return $out;
        }
        public function insert($table, array $data) {
            $table = $this->table($table);
            $this->tables[$table][] = $data;
            $this->insert_id = count($this->tables[$table]);
            return 1;
        }
        public function seed($table, array $rows) { $this->tables[$this->table($table)] = $rows; }
        private function table(string $t): string {
            $t = preg_replace('/^`|`$/', '', $t);
            return str_starts_with($t, $this->prefix) ? $t : $this->prefix . $t;
        }
        private function rows(string $table): array { return $this->tables[$table] ?? array(); }
    }
}

namespace {
    require_once dirname(__DIR__) . '/src/Core/Support/Identifier.php';
    require_once dirname(__DIR__) . '/src/Core/Application/NotificationRule.php';
    require_once dirname(__DIR__) . '/src/Core/Application/NotificationSupport.php';
    require_once dirname(__DIR__) . '/src/Core/Application/SmsProviderRoutingPolicy.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/PhoneNumberNormalizer.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/StudentNotificationContactLookup.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationManualSendService.php';
    require_once dirname(__DIR__) . '/src/Integrations/TelnyxSmsAdapter.php';
    require_once dirname(__DIR__) . '/src/Integrations/SmsIrAdapter.php';
    require_once dirname(__DIR__) . '/src/Integrations/TelnyxWhatsAppAdapter.php';
    require_once dirname(__DIR__) . '/src/Core/Infrastructure/Repository/NotificationProviderRepository.php';
    require_once dirname(__DIR__) . '/src/Core/Infrastructure/Repository/NotificationRepository.php';
    require_once dirname(__DIR__) . '/src/Core/Infrastructure/Repository/NotificationAttemptRepository.php';
    require_once dirname(__DIR__) . '/src/Core/Infrastructure/Repository/NotificationTemplateRepository.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/TelnyxTemplateRegistry.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/TelnyxTemplateAuthority.php';

    use Delnavazan\Platform\Integrations\Notifications\NotificationManualSendService as S;

    $GLOBALS['wpdb'] = new \Delnavazan\Platform\Tests\FakeWpdb();
    $fail = static function (string $name, bool $ok): void { if (!$ok) throw new RuntimeException('Manual send unit failed: ' . $name); };

    $GLOBALS['wpdb']->seed('wp_dzn_teachers', array(
        array('id' => 1, 'phone' => '+61412111111', 'whatsapp_phone' => '+61412111111', 'status' => 'active', 'archived_at' => null),
        array('id' => 2, 'phone' => '+61412222222', 'whatsapp_phone' => '', 'status' => 'active', 'archived_at' => null),
    ));
    $GLOBALS['wpdb']->seed('wp_dzn_students', array(
        array('id' => 11, 'phone' => '+989131111111', 'whatsapp_phone' => '+989131111111', 'status' => 'active', 'archived_at' => null),
        array('id' => 12, 'phone' => '+989132222222', 'whatsapp_phone' => '', 'status' => 'active', 'archived_at' => null),
        array('id' => 13, 'phone' => '', 'whatsapp_phone' => '', 'status' => 'active', 'archived_at' => null),
        array('id' => 14, 'phone' => '+989134444444', 'whatsapp_phone' => '+989134444444', 'status' => 'archived', 'archived_at' => '2026-01-01 00:00:00'),
    ));

    // Collaborators: a fake suppression register, a fake roster lookup (no DB), a fake settings object
    // and a fake HTTP transport that records every provider call and returns an accepted response.
    $suppressed = hash_hmac('sha256', 'recipient:student:11', 'test-salt:notification');
    $suppressions = new class($suppressed) {
        public function __construct(private string $blocked) {}
        public function active(string $digest, string $purpose, string $now, bool $wildcard = true): ?object {
            return $digest === $this->blocked ? (object) array('id' => 1) : null;
        }
    };
    $lookup = new class {
        public function studentIdForPhone(string $p): ?int { return null; }
        public function teacherIdForPhone(string $p): ?int { return null; }
        public function recipientDigest(int $id): string { return hash_hmac('sha256', 'recipient:student:' . $id, 'test-salt:notification'); }
        public function teacherRecipientDigest(int $id): string { return hash_hmac('sha256', 'recipient:teacher:' . $id, 'test-salt:notification'); }
    };
    $settings = new class {
        public function value(string $p, string $f): string { return $f === 'api_key' ? 'test-key' : 'configured'; }
        public function ready(string $p): bool { return true; }
    };
    $http = static function (string $url, array $args) {
        $GLOBALS['dzn_http_calls'][] = array('url' => $url, 'args' => $args);
        $reference = 'msg-' . count($GLOBALS['dzn_http_calls']);
        return array('code' => 200, 'body' => json_encode(array('data' => array('id' => $reference))));
    };
    $service = new S($suppressions, $lookup, $settings, $http);

    /* 1. Segments reflect the roster. */
    $segments = $service->segments();
    $fail('four audience segments are offered', array_keys($segments) === array('teachers', 'students_active', 'students_former', 'students_all'));
    $fail('teacher segment counts every teacher', $segments['teachers']['count'] === 2 && $segments['teachers']['audience'] === 'teacher');
    $fail('active students exclude the archived student', $segments['students_active']['count'] === 3 && $segments['students_former']['count'] === 1);
    $fail('teacher audience defaults to SMS', $segments['teachers']['channels'] === array('sms'));

    /* 2. Channel routing follows the audience. */
    $studentsPreview = $service->preview(array('segments' => array('students_active'), 'channel' => '', 'message' => '', 'parameters' => array('name' => 'x', 'message' => 'y')));
    $fail('students default to WhatsApp', $studentsPreview['channel'] === 'whatsapp');
    $teachersPreview = $service->preview(array('segments' => array('teachers'), 'channel' => '', 'message' => 'hello', 'parameters' => array()));
    $fail('teachers default to SMS', $teachersPreview['channel'] === 'sms');
    // Three active students, one with no phone at all is skipped at resolution (no contact) — reached = 2, one STOPped.
    $fail('preview reaches students with a contact and skips the STOPped one', $studentsPreview['reached'] === 1 && ($studentsPreview['skipped']['stopped'] ?? 0) === 1);

    /* 3. A teacher SMS send routes to Telnyx and records an idempotent batch. */
    $GLOBALS['dzn_http_calls'] = array();
    $commandKey = 'manual-test-1';
    $sent = $service->send(array('segments' => array('teachers'), 'channel' => '', 'message' => 'کلاسها این هفته برگزار میشود', 'parameters' => array()), $commandKey);
    $fail('the teacher send reaches both teachers by SMS', $sent['sent'] === 2 && $sent['failed'] === 0 && $sent['skipped'] === 0);
    $fail('the send used the Telnyx SMS endpoint', count($GLOBALS['dzn_http_calls']) === 2 && str_contains($GLOBALS['dzn_http_calls'][0]['url'], 'api.telnyx.com/v2/messages'));
    $fail('a batch is recorded', ($GLOBALS['dzn_test_options'][S::OPTION_BATCHES][0]['channel'] ?? '') === 'sms');
    $again = $service->send(array('segments' => array('teachers'), 'channel' => '', 'message' => 'کلاسها این هفته برگزار میشود', 'parameters' => array()), $commandKey);
    $fail('a repeated command key converges on the same batch', $again['idempotent'] === true && count($GLOBALS['dzn_http_calls']) === 2);

    /* 4. A student WhatsApp send uses the announcement template. */
    $GLOBALS['dzn_http_calls'] = array();
    $wa = $service->send(array('segments' => array('students_all'), 'channel' => 'whatsapp', 'parameters' => array('name' => 'باران', 'message' => 'اطلاعیه'), 'message' => ''), 'manual-test-2');
    // students_all = 3 active with phone/wa + archived student (13 has no phone -> skipped at resolve; 14 archived, 11 STOPped). Reachable = 1 (12).
    $fail('a WhatsApp send uses the announcement template', $wa['sent'] >= 1 && str_contains($GLOBALS['dzn_http_calls'][0]['args']['body'], S::WHATSAPP_TEMPLATE));
    $fail('the WhatsApp template variables are ordered name,message', (static function () { $payload = json_decode($GLOBALS['dzn_http_calls'][0]['args']['body'], true); $params = $payload['whatsapp_message']['template']['components'][0]['parameters'] ?? array(); return array_column($params, 'text') === array('باران', 'اطلاعیه'); })());

    /* 5. Manual numbers are normalised and deduplicated. */
    $manual = $service->preview(array('numbers' => "09123555555, +98 912 355 5555\n09123666666", 'channel' => 'sms', 'message' => 'x', 'parameters' => array()));
    $fail('typed numbers are normalised and deduplicated', $manual['total'] === 2);

    /* 6. A message-less SMS send is refused, and an unknown audience segment is an error. */
    $empty = $service->preview(array('segments' => array('teachers'), 'channel' => 'sms', 'message' => '   ', 'parameters' => array()));
    $fail('an empty SMS message is reported', in_array('A message is required for SMS.', $empty['errors'], true));
    $unknown = $service->preview(array('segments' => array('nonsense'), 'channel' => '', 'message' => 'x', 'parameters' => array()));
    $fail('an unknown segment is reported', in_array('Unknown audience segment.', $unknown['errors'], true));

    /* 7. A manual send is gated by the dispatch capability. */
    $GLOBALS['dzn_test_can'] = false;
    $forbidden = false;
    try { $service->send(array('segments' => array('teachers'), 'channel' => '', 'message' => 'x', 'parameters' => array()), 'manual-test-perm'); } catch (InvalidArgumentException $error) { $forbidden = $error->getMessage() === 'notification_manual_send_forbidden'; }
    $GLOBALS['dzn_test_can'] = true;
    $fail('a send without the dispatch capability is refused', $forbidden);

    /* 8. Re-submitting the identical send converges on the same batch — a partial batch is never
     *     resent to recipients who already received it. This is what the content-derived command key
     *     guarantees in the controller. */
    $GLOBALS['dzn_http_calls'] = array();
    $contentKey = 'manual-send:' . get_current_user_id() . ':' . hash('sha256', wp_json_encode(array('segments' => array('teachers'), 'channel' => 'sms', 'message' => 'same message', 'parameters' => array())));
    $first = $service->send(array('segments' => array('teachers'), 'channel' => 'sms', 'message' => 'same message', 'parameters' => array()), $contentKey);
    $second = $service->send(array('segments' => array('teachers'), 'channel' => 'sms', 'message' => 'same message', 'parameters' => array()), $contentKey);
    $fail('an identical send never reaches the provider twice', $second['idempotent'] === true && $first['sent'] === 2 && count($GLOBALS['dzn_http_calls']) === 2);

    echo "Notification manual send unit passed\n";
}
