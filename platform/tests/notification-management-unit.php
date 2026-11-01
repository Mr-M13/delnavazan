<?php
/**
 * Notification management: catalogue, editorial safety, branded rendering, immutable publishing,
 * rollback, provider mapping, channel policy and the website inbox.
 *
 * Runs without WordPress or a database. A small in-memory `$wpdb` implements only the statements the
 * notification services use, so the real repository/service code paths are exercised rather than stubs.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(__DIR__, 2) . '/');
    }
    if (!function_exists('wp_salt')) {
        function wp_salt($scheme = 'auth') { return 'test-salt:' . $scheme; }
    }
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($value) { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    }
    if (!function_exists('do_action')) {
        function do_action(...$args) {}
    }
    if (!function_exists('is_user_logged_in')) {
        function is_user_logged_in() { return true; }
    }
    if (!function_exists('current_user_can')) {
        function current_user_can($capability) { return true; }
    }
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id() { return 7; }
    }
    if (!function_exists('get_option')) {
        function get_option($key, $default = false) { return $GLOBALS['dzn_test_options'][$key] ?? $default; }
    }
    if (!function_exists('update_option')) {
        function update_option($key, $value, $autoload = null) { $GLOBALS['dzn_test_options'][$key] = $value; return true; }
    }
    if (!function_exists('delete_option')) {
        function delete_option($key) { unset($GLOBALS['dzn_test_options'][$key]); return true; }
    }
    if (!function_exists('wp_kses')) {
        function wp_kses($text, $allowed) { return strip_tags($text); }
    }
    if (!function_exists('wp_mail')) {
        function wp_mail($to, $subject, $body, $headers = array()) {
            $GLOBALS['dzn_test_mail'][] = compact('to', 'subject', 'body', 'headers');
            return true;
        }
    }
    if (!function_exists('add_action')) {
        function add_action($hook, $callback, $priority = 10) { $GLOBALS['dzn_test_hooks'][$hook][] = $callback; return true; }
    }
    if (!function_exists('remove_action')) {
        function remove_action($hook, $callback, $priority = 10) { return true; }
    }
    $GLOBALS['dzn_test_options'] = array();
    $GLOBALS['dzn_test_mail'] = array();
    $GLOBALS['dzn_test_hooks'] = array();
}

namespace Delnavazan\Platform\Tests {
    /** Minimal deterministic in-memory store for the exact statements the notification code issues. */
    final class FakeWpdb {
        public string $prefix = 'wp_';
        public int $insert_id = 0;
        public string $last_error = '';
        /** @var array<string,array<int,array<string,mixed>>> */
        public array $tables = array();
        private array $sequences = array();
        private string $last = '';

        public function prepare($sql, ...$args) {
            $i = 0;
            return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
                $value = $args[$i++] ?? null;
                return $m[0] === '%d' ? (string) (int) $value : "'" . str_replace("'", "''", (string) $value) . "'";
            }, $sql);
        }
        public function suppress_errors($value = true) { return false; }
        public function query($sql) {
            $this->last = (string) $sql;
            if (preg_match('/^\s*(START TRANSACTION|COMMIT|ROLLBACK|SET TRANSACTION)/i', $sql)) return true;
            if (preg_match('/^\s*UPDATE\s+(\S+)\s+SET\s+(.*?)\s+WHERE\s+(.*)$/is', $sql, $m)) {
                $table = $this->table($m[1]);
                $set = $this->assignments($m[2]);
                $count = 0;
                foreach ($this->rows($table) as $index => $row) {
                    if (!$this->where($row, $m[3])) continue;
                    $this->tables[$table][$index] = array_merge($row, $set);
                    $count++;
                }
                return $count;
            }
            if (preg_match('/^\s*DELETE\s+FROM\s+(\S+)(?:\s+WHERE\s+(.*))?$/is', $sql, $m)) {
                $table = $this->table($m[1]);
                $kept = array();
                $count = 0;
                foreach ($this->rows($table) as $row) {
                    if (isset($m[2]) && $m[2] !== '' && $this->where($row, $m[2])) { $count++; continue; }
                    $kept[] = $row;
                }
                $this->tables[$table] = $kept;
                return $count;
            }
            return true;
        }
        public function get_var($sql) {
            $this->last = (string) $sql;
            if (preg_match('/COALESCE\(MAX\(version_number\),0\)\s+FROM\s+(\S+)\s+WHERE\s+(.*)$/is', $sql, $m)) {
                $max = 0;
                foreach ($this->rows($this->table($m[1])) as $row) if ($this->where($row, $m[2])) $max = max($max, (int) $row['version_number']);
                return $max;
            }
            if (preg_match('/SELECT\s+COUNT\(\*\)\s+FROM\s+(\S+)(?:\s+WHERE\s+(.*?))?(?:\s+LIMIT.*)?$/is', $sql, $m)) {
                $count = 0;
                foreach ($this->rows($this->table($m[1])) as $row) if (!isset($m[2]) || $m[2] === '' || $this->where($row, $m[2])) $count++;
                return $count;
            }
            if (preg_match('/SELECT\s+id\s+FROM\s+(\S+)\s+WHERE\s+(.*?)\s+LIMIT\s+1/is', $sql, $m)) {
                foreach ($this->rows($this->table($m[1])) as $row) if ($this->where($row, $m[2])) return $row['id'];
                return null;
            }
            return null;
        }
        public function get_row($sql) {
            $this->last = (string) $sql;
            if (!preg_match('/FROM\s+(\S+)/i', $sql, $m)) return null;
            $table = $this->table($m[1]);
            $where = $this->whereClause($sql);
            $rows = $this->rows($table);
            if (preg_match('/ORDER BY\s+version_number\s+DESC/i', $sql)) $rows = array_reverse($rows);
            foreach ($rows as $row) if ($where === '' || $this->where($row, $where)) return (object) $row;
            return null;
        }
        public function get_results($sql) {
            $this->last = (string) $sql;
            if (preg_match('/SELECT audience,COUNT\(\*\) total,SUM\(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END\) unread\s+FROM\s+(\S+)/is', $sql, $m)) {
                $out = array();
                foreach ($this->rows($this->table($m[1])) as $row) {
                    $audience = (string) $row['audience'];
                    $out[$audience] = $out[$audience] ?? (object) array('audience' => $audience, 'total' => 0, 'unread' => 0);
                    $out[$audience]->total++;
                    if ($row['read_at'] === null) $out[$audience]->unread++;
                }
                return array_values($out);
            }
            if (preg_match('/SELECT\s+t\.template_key,v\.id\s+version_id\s+FROM\s+(\S+)\s+t\s+INNER\s+JOIN\s+(\S+)\s+v/is', $sql, $m)) {
                $templates = $this->rows($this->table($m[1]));
                $versions = $this->rows($this->table($m[2]));
                $out = array();
                foreach ($versions as $version) {
                    if ((string) $version['state'] !== 'active') continue;
                    foreach ($templates as $template) {
                        if ((int) $template['id'] === (int) $version['template_id']) {
                            $out[] = (object) array('template_key' => $template['template_key'], 'version_id' => $version['id']);
                        }
                    }
                }
                return $out;
            }
            if (!preg_match('/FROM\s+(\S+)/i', $sql, $m)) return array();
            $table = $this->table($m[1]);
            $where = $this->whereClause($sql);
            $rows = array();
            foreach ($this->rows($table) as $row) if ($where === '' || $this->where($row, $where)) $rows[] = (object) $row;
            if (preg_match('/ORDER BY\s+created_at\s+DESC,id\s+DESC/i', $sql)) usort($rows, static function ($a, $b) {
                $order = strcmp((string) $b->created_at, (string) $a->created_at);
                return $order !== 0 ? $order : ((int) $b->id <=> (int) $a->id);
            });
            if (preg_match('/LIMIT\s+(\d+)/i', $sql, $limit)) $rows = array_slice($rows, 0, (int) $limit[1]);
            return $rows;
        }
        public function insert($table, array $data) {
            $table = $this->table($table);
            $this->sequences[$table] = ($this->sequences[$table] ?? 0) + 1;
            $data['id'] = $this->sequences[$table];
            $this->tables[$table][] = $data;
            $this->insert_id = (int) $data['id'];
            return 1;
        }
        public function update($table, array $data, array $where) {
            $table = $this->table($table);
            $count = 0;
            foreach ($this->rows($table) as $index => $row) {
                foreach ($where as $column => $value) if (!array_key_exists($column, $row) || (string) $row[$column] !== (string) $value) continue 2;
                $this->tables[$table][$index] = array_merge($row, $data);
                $count++;
            }
            return $count;
        }
        public function row(string $table, int $id): ?object {
            foreach ($this->rows($table) as $row) if ((int) $row['id'] === $id) return (object) $row;
            return null;
        }
        public function seed(string $table, array $rows): void { $this->tables[$table] = $rows; $this->sequences[$table] = max(array_map(static fn($r) => (int) ($r['id'] ?? 0), $rows ?: array(0))); }
        private function table(string $name): string { return str_starts_with($name, $this->prefix) ? substr($name, strlen($this->prefix)) : $name; }
        private function rows(string $table): array { return $this->tables[$table] ?? array(); }
        private function whereClause(string $sql): string {
            if (!preg_match('/WHERE\s+(.*?)(?:\s+ORDER BY|\s+LIMIT|\s+FOR UPDATE|$)/is', $sql, $m)) return '';
            return trim(preg_replace('/\s+FOR UPDATE\s*$/i', '', $m[1]));
        }
        private function assignments(string $set): array {
            $out = array();
            foreach (explode(',', $set) as $part) {
                if (!preg_match('/^\s*([a-z_]+)\s*=\s*(.*?)\s*$/is', $part, $m)) continue;
                $raw = trim($m[2]);
                $out[$m[1]] = $raw === 'NULL' ? null : (preg_match('/^-?\d+$/', $raw) ? (int) $raw : trim($raw, "'"));
            }
            return $out;
        }
        private function where(array $row, string $where): bool {
            $where = preg_replace('/\b(FOR UPDATE|ORDER BY.*|LIMIT.*)$/i', '', $where);
            // The inbox deliberately writes `(expires_at IS NULL OR expires_at > now)`; the fixture only
            // needs the unexpired branch, because every inserted row carries a future expiry.
            $where = preg_replace('/\(?\s*expires_at IS NULL OR expires_at>[^)]*\)?/i', '', $where);
            preg_match_all('/([a-z_]+)\s+IS\s+(NOT\s+)?NULL/i', $where, $nulls, PREG_SET_ORDER);
            foreach ($nulls as $null) {
                if (!array_key_exists($null[1], $row)) return false;
                $isNull = $row[$null[1]] === null;
                $not = trim((string) ($null[2] ?? ''));
                if (($not === 'NOT' && $isNull) || ($not === '' && !$isNull)) return false;
            }
            preg_match_all("/([a-z_]+)\s*(=|<>)\s*(?:'((?:[^']|'')*)'|(-?\d+))/i", $where, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $column = $match[1];
                if (!array_key_exists($column, $row)) return false;
                $expected = ($match[4] ?? '') !== '' ? (string) (int) $match[4] : str_replace("''", "'", (string) ($match[3] ?? ''));
                $actual = (string) $row[$column];
                $equal = $expected === $actual || (is_numeric($expected) && is_numeric($actual) && (int) $expected === (int) $actual);
                if (($match[2] === '=' && !$equal) || ($match[2] === '<>' && $equal)) return false;
            }
            return true;
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/src/Core/Support/Identifier.php';
    require_once dirname(__DIR__) . '/src/Core/Application/NotificationRule.php';
    require_once dirname(__DIR__) . '/src/Core/Application/NotificationSupport.php';
    require_once dirname(__DIR__) . '/src/Core/Application/NotificationIntegrity.php';
    require_once dirname(__DIR__) . '/src/Core/Application/NotificationCopyPort.php';
    require_once dirname(__DIR__) . '/src/Core/Infrastructure/Repository/NotificationTemplateRepository.php';
    require_once dirname(__DIR__) . '/src/Core/Application/NotificationTemplateService.php';
    require_once dirname(__DIR__) . '/src/Core/Support/BrandIdentity.php';
    require_once dirname(__DIR__) . '/src/Core/Support/CommunicationDesignTokens.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationCatalogue.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationTemplateSanitizer.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationTemplateValidationException.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationTemplateLibrary.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationChannelPolicy.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationCopyResolver.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/DelnavazanEmailRenderer.php';
    require_once dirname(__DIR__) . '/src/Portals/PortalNotificationInbox.php';

    use Delnavazan\Platform\Integrations\Notifications\DelnavazanEmailRenderer;
    use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
    use Delnavazan\Platform\Integrations\Notifications\NotificationChannelPolicy;
    use Delnavazan\Platform\Integrations\Notifications\NotificationCopyResolver;
    use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary;
    use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateSanitizer;
    use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateValidationException;
    use Delnavazan\Platform\Portals\PortalNotificationInbox;

    $GLOBALS['wpdb'] = new \Delnavazan\Platform\Tests\FakeWpdb();
    $fail = static function (string $name, bool $ok): void { if (!$ok) throw new RuntimeException('Notification management unit failed: ' . $name); };

    /* 1. The catalogue is closed, unique and editorially complete. */
    $events = NotificationCatalogue::events();
    $fail('catalogue is not empty', count($events) >= 25);
    $templateKeys = array();
    foreach ($events as $key => $event) {
        $fail('event key shape ' . $key, preg_match('/^[a-z0-9._-]{1,64}$/', $key) === 1);
        $fail('event category ' . $key, isset(NotificationCatalogue::CATEGORIES[$event['category']]));
        $fail('event authority ' . $key, isset(NotificationCatalogue::AUTHORITIES[$event['authority']]));
        $fail('event variables ' . $key, is_array($event['variables']));
        foreach ($event['channels'] as $channel) {
            $templateKey = NotificationCatalogue::templateKey($key, $channel, 'fa-IR');
            $fail('template key length ' . $key, strlen($templateKey) <= 64);
            $fail('template key uniqueness ' . $templateKey, !isset($templateKeys[$templateKey]));
            $templateKeys[$templateKey] = true;
            $copy = NotificationCatalogue::defaultCopy($key, $channel, 'fa-IR');
            $fail('default copy ' . $key . '/' . $channel, is_array($copy) && trim((string) ($copy['body'] ?? '')) !== '');
            $validated = NotificationTemplateSanitizer::validate($key, $channel, (string) ($copy['subject'] ?? ''), (string) $copy['body']);
            $fail('default copy validates ' . $key . '/' . $channel . ': ' . implode(' ', $validated['errors']), $validated['ok']);
        }
        if ($event['category'] === 'security') {
            $fail('security is mandatory ' . $key, NotificationCatalogue::isMandatory($key));
            $fail('security never enables marketing ' . $key, $event['consent'] === 'security');
        }
    }

    /* 2. Editorial safety and SMS segmentation. */
    $bad = NotificationTemplateSanitizer::validate('booking.confirmed.student', 'email', 'عنوان', "سلام {{name}}\n<script>alert(1)</script> {{when}} https://x.invalid {{portal_url}}");
    $fail('script is refused', !$bad['ok']);
    $xss = NotificationTemplateSanitizer::validate('booking.confirmed.student', 'email', 'عنوان', "سلام {{name}}\n{{when}}\n<a href=\"javascript:alert(1)\">کلیک</a> {{portal_url}}");
    $fail('javascript link is refused', !$xss['ok']);
    $unknown = NotificationTemplateSanitizer::validate('booking.confirmed.student', 'email', 'عنوان', "سلام {{name}} {{when}} {{portal_url}} {{claim_code}}");
    $fail('unknown placeholder is refused', !$unknown['ok']);
    $missing = NotificationTemplateSanitizer::validate('booking.confirmed.student', 'email', 'عنوان', "سلام {{name}} {{portal_url}}");
    $fail('missing required class time is refused', !$missing['ok']);
    $goodBody = "سلام {{name}}،\n\nکلاس در {{when}} برگزار می‌شود.\nپیوند: {{join_url}}\n\n{{portal_url}}\n\nدلنوازان";
    $good = NotificationTemplateSanitizer::validate('booking.confirmed.student', 'email', 'کلاس تأیید شد', $goodBody);
    $fail('valid copy is accepted', $good['ok']);
    $persian = NotificationTemplateSanitizer::smsSegmentation(str_repeat('ا', 80));
    $fail('Persian SMS is UCS-2', $persian['encoding'] === 'UCS-2' && $persian['segments'] === 2);
    $ascii = NotificationTemplateSanitizer::smsSegmentation(str_repeat('a', 160));
    $fail('ASCII SMS is one GSM part', $ascii['encoding'] === 'GSM-7' && $ascii['segments'] === 1);

    /* 3. Branded email: RTL, brand colours, escaped content, plain-text alternative, safe CTA. */
    $rendered = DelnavazanEmailRenderer::render('عنوان <تست>', "سلام {{x}} <script>alert(1)</script>\n\nخط دوم", array('locale' => 'fa-IR', 'cta' => array('label' => 'رفتن', 'url' => 'https://delnavazan.com/student-portal/')));
    $fail('email is RTL Persian', str_contains($rendered['html'], 'dir="rtl"') && str_contains($rendered['html'], 'lang="fa"'));
    $fail('email uses the brand palette', str_contains($rendered['html'], '#FBF8F2') && str_contains($rendered['html'], '#1E7B70') && str_contains($rendered['html'], '#A33E48') && str_contains($rendered['html'], 'Vazirmatn'));
    $fail('email has no script or injected tag', !str_contains($rendered['html'], '<script') && !str_contains($rendered['html'], '<تست>'));
    $fail('plain-text alternative exists', str_contains($rendered['text'], 'خط دوم'));
    $unsafe = DelnavazanEmailRenderer::render('t', 'body', array('cta' => array('label' => 'x', 'url' => 'javascript:alert(1)')));
    $fail('unsafe CTA is dropped', !str_contains($unsafe['html'], 'javascript:'));
    $english = DelnavazanEmailRenderer::render('Subject', 'Body', array('locale' => 'en'));
    $fail('English shell is LTR', str_contains($english['html'], 'dir="ltr"'));

    $sent = DelnavazanEmailRenderer::send('student@example.invalid', 'عنوان', "خط اول\n\nخط دوم", array('locale' => 'fa-IR', 'cta' => array('label' => 'رفتن', 'url' => 'https://delnavazan.com/student-portal/')));
    $fail('branded mail is sent as HTML', $sent && str_contains((string) $GLOBALS['dzn_test_mail'][0]['body'], '#FBF8F2') && str_contains(implode("\n", (array) $GLOBALS['dzn_test_mail'][0]['headers']), 'text/html'));
    $mailer = (object) array('AltBody' => '');
    foreach ((array) ($GLOBALS['dzn_test_hooks']['phpmailer_init'] ?? array()) as $callback) $callback($mailer);
    $fail('branded mail carries a plain-text alternative', $mailer->AltBody === "خط اول\n\nخط دوم");

    /* 4. Immutable publishing, draft isolation, rollback and provider mapping. */
    $library = new NotificationTemplateLibrary();
    $event = 'booking.confirmed.student';
    $default = NotificationCatalogue::defaultCopy($event, 'email', 'fa-IR');
    $first = $library->publish($event, 'email', 'fa-IR', (string) $default['subject'], (string) $default['body']);
    $fail('first publish creates version 1', $first['version_number'] === 1 && $first['state'] === 'active');
    $active = $library->activeCopy($event, 'email', 'fa-IR');
    $fail('published copy is active', ($active['source'] ?? '') === 'published' && str_contains($active['body'], '{{name}}'));
    $fail('definitions key the active version', isset($library->copyDefinitions()[$first['template_version_id'] . ':email:fa-IR']));

    $customBody = "سلام {{name}}،\n\nزمان تازه: {{when}}\nپیوند: {{join_url}}\n\n{{portal_url}}\n\nدلنوازان";
    $library->saveDraft($event, 'email', 'fa-IR', 'پیش‌نویس عنوان', $customBody);
    $draft = $library->workingCopy($event, 'email', 'fa-IR');
    $fail('draft is visible but not active', $draft['source'] === 'draft' && $library->activeCopy($event, 'email', 'fa-IR')['source'] !== 'draft');
    $second = $library->publish($event, 'email', 'fa-IR', 'عنوان تازه', $customBody);
    $fail('publish creates version 2', $second['version_number'] === 2);
    $repeat = $library->publish($event, 'email', 'fa-IR', 'عنوان تازه', $customBody);
    $fail('a repeated publish of active content is an idempotent no-op', $repeat['idempotent'] === true && $repeat['version_number'] === 2);
    $fail('a repeated publish adds no version', count($library->history($event, 'email', 'fa-IR')) === 2);
    $fail('draft is cleared after publish', $library->workingCopy($event, 'email', 'fa-IR')['draft'] === null);
    $history = $library->history($event, 'email', 'fa-IR');
    $fail('history keeps both immutable versions', count($history) === 2 && $history[0]['version_number'] === 2 && $history[1]['version_number'] === 1);
    $byNumber = static fn(array $rows, int $number): array => (array) current(array_values(array_filter($rows, static fn(array $row): bool => (int) $row['version_number'] === $number)));
    $fail('version 1 body is preserved', $byNumber($history, 1)['body_present'] && str_contains((string) $byNumber($history, 1)['body'], 'کلاس آشنایی'));

    $rollback = $library->restoreVersion($event, 'email', 'fa-IR', $first['template_version_id']);
    $fail('rollback publishes a new version', $rollback['version_number'] === 3);
    $fail('rollback content equals version 1', str_contains($library->activeCopy($event, 'email', 'fa-IR')['body'], 'کلاس آشنایی'));
    $fail('version 2 body is unchanged by rollback', str_contains((string) $byNumber($library->history($event, 'email', 'fa-IR'), 2)['body'], 'زمان تازه'));

    $restored = $library->restoreDefault($event, 'email', 'fa-IR');
    $fail('restore default creates version 4', $restored['version_number'] === 4);
    $fail('restore default equals catalogue copy', str_contains($library->activeCopy($event, 'email', 'fa-IR')['body'], 'کلاس آشنایی'));

    $invalid = false;
    try { $library->publish($event, 'email', 'fa-IR', 'x', 'بدون جای‌نگهدار'); } catch (NotificationTemplateValidationException $error) { $invalid = true; }
    $fail('invalid publish is refused', $invalid);
    $fail('refused publish created no version', count($library->history($event, 'email', 'fa-IR')) === 4);

    $library->saveProviderMapping($event, 'whatsapp', 'fa-IR', 'booking_confirmed_fa', 'fa');
    $mapped = $library->status($event, 'whatsapp', 'fa-IR');
    $fail('provider mapping is recorded', $library->providerMapping('booking.confirmed.student.whatsapp.fa-IR')['name'] === 'booking_confirmed_fa');
    $invalidMap = false;
    try { $library->saveProviderMapping($event, 'whatsapp', 'fa-IR', 'Bad Name!', 'fa'); } catch (InvalidArgumentException $error) { $invalidMap = true; }
    $fail('invalid provider name is refused', $invalidMap);

    $library->publish($event, 'whatsapp', 'fa-IR', 'عنوان واتس‌اپ', "سلام {{name}} {{when}} {{join_url}} {{portal_url}}\nمتن تازهٔ واتس‌اپ");
    $fail('whatsapp copy requires provider approval after publication', $library->status($event, 'whatsapp', 'fa-IR')['provider_state'] === 'approval_required');

    /* 5. Channel policy cannot disable mandatory channels. */
    $policies = $library->saveChannelPolicy(array(
        'security' => array('email' => false, 'website' => false, 'whatsapp' => true, 'sms' => true, 'sms_fallback' => true),
        'optional' => array('email' => true, 'website' => true, 'whatsapp' => false, 'sms' => false, 'sms_fallback' => false),
    ));
    $fail('security channels stay locked', $policies['security']['email'] === true && $policies['security']['website'] === true && $policies['security']['whatsapp'] === false && $policies['security']['sms'] === false);
    $fail('claim event offers only email', $library->permittedChannels('account.claim_issued.student') === array('email'));
    $claimWhatsApp = $library->status('account.claim_issued.student', 'whatsapp', 'fa-IR');
    $fail('a policy-disabled channel is not advertised', $library->catalogueRows('whatsapp', 'fa-IR') === array());
    $fail('a policy-disabled channel keeps its factual provider state', $claimWhatsApp['permitted'] === false && $claimWhatsApp['provider_state'] === 'not_mapped');
    $fail('a policy-disabled channel does not demand a Meta mapping', !str_contains(implode(' ', $claimWhatsApp['issues']), 'Meta-approved template'));
    $operational = $library->permittedChannels('booking.confirmed.student');
    $fail('operational events default to email and portal', $operational === array('website', 'email'));

    /* 5b. SMS is fallback-only: a direct SMS flag cannot survive save or policy read. */
    $direct = $library->saveChannelPolicy(array('booking' => array('sms' => true, 'sms_fallback' => false)));
    $fail('direct SMS alone is refused', $direct['booking']['sms'] === false && $direct['booking']['sms_fallback'] === false);
    $fallback = $library->saveChannelPolicy(array('booking' => array('sms_fallback' => true)));
    $fail('SMS fallback enables the derived SMS capability', $fallback['booking']['sms_fallback'] === true && $fallback['booking']['sms'] === true);
    $library->saveChannelPolicy(array('booking' => array('email' => true, 'website' => true, 'whatsapp' => false, 'sms_fallback' => false)));
    $fail('SMS fallback and SMS capability stay in sync', $library->channelPolicy('booking')['sms'] === false && $library->channelPolicy('booking')['sms_fallback'] === false);

    /* 6. Resolver: published copy wins, missing variables and unknown events fall back. */
    $resolved = NotificationCopyResolver::email($event, 'fa-IR', array('name' => 'باران', 'when' => 'شنبه', 'portal_url' => 'https://delnavazan.com/student-portal/', 'teacher' => 'استاد', 'course' => 'تار', 'join_url' => 'https://example.invalid/join'), array('subject' => 'fallback', 'body' => 'fallback'));
    $fail('resolver renders published copy', $resolved['source'] === 'published' && str_contains($resolved['body'], 'باران'));
    $fallback = NotificationCopyResolver::email($event, 'fa-IR', array('name' => 'باران'), array('subject' => 'fallback', 'body' => 'fallback'));
    $fail('resolver falls back when a variable is missing', $fallback['source'] === 'fallback' && $fallback['body'] === 'fallback');
    $unknown = NotificationCopyResolver::email('not.a.real.event', 'fa-IR', array(), array('subject' => 's', 'body' => 'b'));
    $fail('resolver falls back for an unknown event', $unknown['source'] === 'fallback');

    /* 7. Channel policy keeps mandatory categories locked and gates optional channels. */
    $fail('security never allows WhatsApp', NotificationChannelPolicy::allows('account.claim_issued.student', 'whatsapp') === false);
    $fail('security never allows SMS fallback', NotificationChannelPolicy::allowsSmsFallback('account.claim_issued.student') === false);
    $fail('website is allowed for an operational event', NotificationChannelPolicy::allows('booking.confirmed.student', 'website') === true);
    $fail('a channel outside the event is refused', NotificationChannelPolicy::allows('post_intro.activated.student', 'sms') === false);

    /* 8. Website inbox: dedupe, ownership and read state. */
    $GLOBALS['wpdb']->seed('dzn_students', array(array('id' => 9, 'wordpress_user_id' => 7, 'status' => 'active', 'archived_at' => null, 'locale' => 'fa_IR')));
    $inbox = new PortalNotificationInbox();
    $inboxId = $inbox->record('post_intro.activated.student', 'student', 9, 'contract_test', 55, array('name' => 'باران', 'teacher' => 'استاد ندا', 'course' => 'تار', 'portal_url' => 'https://delnavazan.com/student-portal/'));
    $fail('inbox records a student notification', is_int($inboxId) && $inboxId > 0);
    $again = $inbox->record('post_intro.activated.student', 'student', 9, 'contract_test', 55, array('name' => 'باران', 'teacher' => 'استاد ندا', 'course' => 'تار', 'portal_url' => 'https://delnavazan.com/student-portal/'));
    $fail('inbox dedupe converges on the same row', $again === $inboxId);
    $rows = $inbox->forUser(7);
    $fail('inbox reads only the linked user rows', count($rows) === 1 && $rows[0]['is_unread'] === true);
    $fail('inbox reads another user as empty', $inbox->forUser(8) === array());
    $fail('mark read requires ownership', $inbox->markRead(8, $inboxId) === false);
    $fail('mark read succeeds for the owner', $inbox->markRead(7, $inboxId) === true);
    $fail('unread count drops after read', $inbox->unreadCount(7) === 0);
    $fail('admin summary stays separate', isset($inbox->adminSummary()['student']) && $inbox->adminSummary()['admin']['total'] === 0);

    echo "Notification management unit passed\n";
}
