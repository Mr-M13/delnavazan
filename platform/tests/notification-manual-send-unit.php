<?php
/**
 * Manual notification send: database-backed idempotency, crash safety, recipient claiming, STOP
 * suppression and the permission gate. Runs without WordPress; an in-memory `$wpdb` implements the
 * exact statements the manual-send repository issues, so the real repository/service code paths run.
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
    /** Minimal in-memory store for the manual-send repository statements, including unique keys. */
    final class FakeWpdb {
        public string $prefix = 'wp_';
        public int $insert_id = 0;
        public string $last_error = '';
        public array $tables = array();
        private array $seq = array();
        private array $unique = array(
            'wp_dzn_notification_manual_send_batches' => array('uid', 'command_key_digest'),
            'wp_dzn_notification_manual_send_recipients' => array('uid', 'batch_contact'),
        );
        public function prepare($sql, ...$args) {
            $i = 0;
            return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) {
                $v = $args[$i++] ?? '';
                return $m[0] === '%d' ? (string) (int) $v : "'" . str_replace("'", "''", (string) $v) . "'";
            }, $sql);
        }
        public function suppress_errors($v = true) { return false; }
        public function query($sql) { return true; } // START TRANSACTION / COMMIT / ROLLBACK
        private function table(string $t): string {
            $t = preg_replace('/^`|`$/', '', $t);
            return str_starts_with($t, $this->prefix) ? $t : $this->prefix . $t;
        }
        public function insert($table, array $data) {
            $table = $this->table($table);
            if (!isset($this->tables[$table])) $this->tables[$table] = array();
            foreach ($this->unique[$table] ?? array() as $key) {
                if ($key === 'batch_contact') {
                    $b = $data['batch_id'] ?? null; $c = $data['contact_digest'] ?? null;
                    foreach ($this->tables[$table] as $row) if (($row['batch_id'] ?? null) === $b && ($row['contact_digest'] ?? null) === $c) { $this->last_error = "Duplicate entry 'x' for key 'batch_contact'"; return false; }
                    continue;
                }
                $v = $data[$key] ?? null;
                if ($v === null) continue;
                foreach ($this->tables[$table] as $row) if (($row[$key] ?? null) === $v) { $this->last_error = "Duplicate entry 'x' for key '{$key}'"; return false; }
            }
            $this->seq[$table] = ($this->seq[$table] ?? 0) + 1;
            $data['id'] = $this->seq[$table];
            $this->tables[$table][] = $data;
            $this->insert_id = $data['id'];
            $this->last_error = '';
            return 1;
        }
        public function update($table, array $data, array $where) {
            $table = $this->table($table);
            if (!isset($this->tables[$table])) return 0;
            $count = 0;
            foreach ($this->tables[$table] as $i => $row) {
                $match = true;
                foreach ($where as $col => $val) if (($row[$col] ?? null) != $val) { $match = false; break; }
                if (!$match) continue;
                foreach ($data as $col => $val) $this->tables[$table][$i][$col] = $val;
                $count++;
            }
            return $count;
        }
        public function get_row($sql) {
            if (!preg_match('/FROM\s+(\S+)/i', $sql, $m)) return null;
            $table = $this->table($m[1]);
            $where = $this->whereClause($sql);
            foreach (($this->tables[$table] ?? array()) as $row) if ($where === '' || $this->where($row, $where)) return (object) $row;
            return null;
        }
        public function get_results($sql) {
            if (!preg_match('/FROM\s+(\S+)/i', $sql, $m)) return array();
            $table = $this->table($m[1]);
            $where = $this->whereClause($sql);
            $rows = array();
            foreach (($this->tables[$table] ?? array()) as $row) if ($where === '' || $this->where($row, $where)) $rows[] = (object) $row;
            if (stripos($sql, 'GROUP BY state') !== false) {
                $out = array();
                foreach ($rows as $row) {
                    $s = (string) $row->state;
                    $out[$s] = $out[$s] ?? (object) array('state' => $s, 'total' => 0);
                    $out[$s]->total++;
                }
                return array_values($out);
            }
            return $rows;
        }
        private function whereClause(string $sql): string {
            if (!preg_match('/WHERE\s+(.*?)(?:\s+ORDER BY|\s+GROUP BY|\s+LIMIT|$)/i', $sql, $m)) return '';
            return trim($m[1]);
        }
        private function where(array $row, string $where): bool {
            preg_match_all("/([a-z_]+)\s*=\s*(?:'((?:[^']|'')*)'|(-?\d+))/i", $where, $matches, PREG_SET_ORDER);
            foreach ($matches as $mm) {
                $col = $mm[1];
                $expected = ($mm[3] ?? '') !== '' ? (string) (int) $mm[3] : str_replace("''", "'", $mm[2]);
                if ((string) ($row[$col] ?? '') !== $expected) return false;
            }
            return true;
        }
        public function seed(string $table, array $rows) { $this->tables[$this->table($table)] = $rows; $this->seq[$this->table($table)] = count($rows); }
        public function rows(string $table): array { return $this->tables[$this->table($table)] ?? array(); }
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
    require_once dirname(__DIR__) . '/src/Core/Infrastructure/Repository/NotificationManualSendRepository.php';
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
    use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationManualSendRepository as Repo;

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
    $service = new S($suppressions, $lookup, $settings, $http, new Repo());

    /* 1. Segments reflect the roster. */
    $segments = $service->segments();
    $fail('four audience segments are offered', array_keys($segments) === array('teachers', 'students_active', 'students_former', 'students_all'));
    $fail('teacher segment defaults to SMS', $segments['teachers']['channels'] === array('sms'));

    /* 2. Channel routing follows the audience and STOP suppression is applied at preview. */
    $teachersPreview = $service->preview(array('segments' => array('teachers'), 'channel' => '', 'message' => 'hello', 'parameters' => array()));
    $fail('teachers default to SMS', $teachersPreview['channel'] === 'sms');
    $studentsPreview = $service->preview(array('segments' => array('students_active'), 'channel' => '', 'message' => 'x', 'parameters' => array('name' => 'x', 'message' => 'y')));
    $fail('students default to WhatsApp and the STOPped student is skipped', $studentsPreview['channel'] === 'whatsapp' && $studentsPreview['reached'] === 1 && ($studentsPreview['skipped']['stopped'] ?? 0) === 1);

    /* 3. An SMS send routes to Telnyx, persists recipients, and is idempotent by content. */
    $GLOBALS['dzn_http_calls'] = array();
    $contentKey = 'manual-send:' . get_current_user_id() . ':' . hash('sha256', wp_json_encode(array('segments' => array('teachers'), 'channel' => 'sms', 'message' => 'کلاسها این هفته برگزار میشود', 'parameters' => array())));
    $first = $service->send(array('segments' => array('teachers'), 'channel' => 'sms', 'message' => 'کلاسها این هفته برگزار میشود', 'parameters' => array()), $contentKey);
    $fail('the teacher send reaches both teachers by SMS', $first['sent'] === 2 && $first['failed'] === 0);
    $fail('exactly two provider calls for two recipients', count($GLOBALS['dzn_http_calls']) === 2 && str_contains($GLOBALS['dzn_http_calls'][0]['url'], 'api.telnyx.com/v2/messages'));
    $fail('the Telnyx SMS carries a per-recipient idempotency key', !empty($GLOBALS['dzn_http_calls'][0]['args']['headers']['Idempotency-Key']));

    // 4. Repeated submission after completion returns the same batch and never re-dispatches.
    $again = $service->send(array('segments' => array('teachers'), 'channel' => 'sms', 'message' => 'کلاسها این هفته برگزار میشود', 'parameters' => array()), $contentKey);
    $fail('a completed batch is not re-sent', $again['batch_id'] === $first['batch_id'] && $again['idempotent'] === true && $again['sent'] === 2 && count($GLOBALS['dzn_http_calls']) === 2);

    // 5. Two identical requests share one batch (the unique command key is database-enforced).
    $repo = new Repo();
    $digest = hash_hmac('sha256', 'test', 'test-salt:notification');
    $before = count($GLOBALS['wpdb']->rows('wp_dzn_notification_manual_send_batches'));
    $a = $repo->insertBatch(array('uid' => 'uid-1', 'command_key_digest' => $digest, 'channel' => 'sms', 'audience' => 'teacher', 'state' => 'created', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'), 'created_by' => 7, 'updated_by' => 7));
    $b = $repo->insertBatch(array('uid' => 'uid-2', 'command_key_digest' => $digest, 'channel' => 'sms', 'audience' => 'teacher', 'state' => 'created', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'), 'created_by' => 7, 'updated_by' => 7));
    $after = count($GLOBALS['wpdb']->rows('wp_dzn_notification_manual_send_batches'));
    $fail('two identical command keys share one batch', $a['id'] === $b['id'] && $a['created'] === true && $b['created'] === false && $after === $before + 1);

    /* 6. Crash safety: an interrupted 'sending' recipient is marked uncertain, never resent; the
     *    'sent' recipient is never touched; only the 'pending' one is dispatched. */
    $interruptedKey = 'manual-send:' . get_current_user_id() . ':interrupted';
    $interruptedDigest = \Delnavazan\Platform\Core\Application\NotificationSupport::keyDigest($interruptedKey);
    $now = gmdate('Y-m-d H:i:s');
    $repo2 = new Repo();
    $repo2->begin();
    $created = $repo2->insertBatch(array('uid' => 'batch-interrupted', 'command_key_digest' => $interruptedDigest, 'channel' => 'sms', 'audience' => 'teacher', 'state' => 'created', 'created_at' => $now, 'updated_at' => $now, 'created_by' => 7, 'updated_by' => 7));
    $repo2->insertRecipient(array('uid' => 'r1', 'batch_id' => $created['id'], 'contact' => '+61412111111', 'contact_digest' => 'c1', 'audience' => 'teacher', 'subject_id' => 1, 'state' => 'sent', 'created_at' => $now, 'updated_at' => $now));
    $repo2->insertRecipient(array('uid' => 'r2', 'batch_id' => $created['id'], 'contact' => '+61412222222', 'contact_digest' => 'c2', 'audience' => 'teacher', 'subject_id' => 2, 'state' => 'sending', 'claimed_at' => $now, 'created_at' => $now, 'updated_at' => $now));
    $repo2->insertRecipient(array('uid' => 'r3', 'batch_id' => $created['id'], 'contact' => '+61412333333', 'contact_digest' => 'c3', 'audience' => 'teacher', 'subject_id' => null, 'state' => 'pending', 'created_at' => $now, 'updated_at' => $now));
    $repo2->commit();

    $GLOBALS['wpdb']->seed('wp_dzn_teachers', array(
        array('id' => 1, 'phone' => '+61412111111', 'whatsapp_phone' => '', 'status' => 'active', 'archived_at' => null),
        array('id' => 2, 'phone' => '+61412222222', 'whatsapp_phone' => '', 'status' => 'active', 'archived_at' => null),
        array('id' => 3, 'phone' => '+61412333333', 'whatsapp_phone' => '', 'status' => 'active', 'archived_at' => null),
    ));
    $GLOBALS['dzn_http_calls'] = array();
    $resume = $service->send(array('segments' => array('teachers'), 'channel' => 'sms', 'message' => 'resume', 'parameters' => array()), $interruptedKey);
    $fail('resuming never re-sends the already-sent or interrupted recipient', count($GLOBALS['dzn_http_calls']) === 1 && str_contains($GLOBALS['dzn_http_calls'][0]['url'], 'api.telnyx.com/v2/messages'));
    $fail('the interrupted recipient is marked uncertain', $resume['uncertain'] === 1 && $resume['sent'] === 2);
    $finalRows = $GLOBALS['wpdb']->rows('wp_dzn_notification_manual_send_recipients');
    $states = array();
    foreach ($finalRows as $row) $states[(string) $row['contact']] = (string) $row['state'];
    $fail('sent stays sent, sending becomes uncertain, pending becomes sent', $states['+61412111111'] === 'sent' && $states['+61412222222'] === 'uncertain' && $states['+61412333333'] === 'sent');

    /* 7. A permanent failure is recorded and never auto-retried. */
    $GLOBALS['wpdb']->seed('wp_dzn_teachers', array(
        array('id' => 1, 'phone' => '+61412111111', 'whatsapp_phone' => '', 'status' => 'active', 'archived_at' => null),
        array('id' => 2, 'phone' => '+61412222222', 'whatsapp_phone' => '', 'status' => 'active', 'archived_at' => null),
    ));
    $GLOBALS['dzn_http_calls'] = array();
    $httpFail = static function (string $url, array $args) { $GLOBALS['dzn_http_calls'][] = array('url' => $url, 'args' => $args); return array('code' => 422, 'body' => json_encode(array('errors' => array(array('code' => '40002'))))); };
    $failService = new S($suppressions, $lookup, $settings, $httpFail, new Repo());
    $failKey = 'manual-send:' . get_current_user_id() . ':fail';
    $failedSend = $failService->send(array('segments' => array('teachers'), 'channel' => 'sms', 'message' => 'will fail', 'parameters' => array()), $failKey);
    $fail('a permanent provider refusal is recorded as failed', $failedSend['failed'] === 2 && $failedSend['sent'] === 0);
    $failedAgain = $failService->send(array('segments' => array('teachers'), 'channel' => 'sms', 'message' => 'will fail', 'parameters' => array()), $failKey);
    $fail('a failed batch is never re-sent on re-submission', $failedAgain['idempotent'] === true && count($GLOBALS['dzn_http_calls']) === 2);

    /* 8. The permission gate refuses a send. */
    $GLOBALS['dzn_test_can'] = false;
    $forbidden = false;
    try { $service->send(array('segments' => array('teachers'), 'channel' => '', 'message' => 'x', 'parameters' => array()), 'manual-test-perm'); } catch (InvalidArgumentException $e) { $forbidden = $e->getMessage() === 'notification_manual_send_forbidden'; }
    $GLOBALS['dzn_test_can'] = true;
    $fail('a send without the dispatch capability is refused', $forbidden);

    echo "Notification manual send unit passed\n";
}
