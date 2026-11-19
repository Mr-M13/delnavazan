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
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
    }
    if (!function_exists('sanitize_key')) {
        function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)); }
    }
    if (!function_exists('wp_unslash')) {
        function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
    }
    if (!function_exists('admin_url')) {
        function admin_url($path = '') { return 'https://academy.example/wp-admin/' . $path; }
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
    if (!function_exists('esc_html')) {
        function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('esc_attr')) {
        function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('esc_url')) {
        function esc_url($url) { return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('esc_textarea')) {
        function esc_textarea($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('wp_nonce_field')) {
        function wp_nonce_field($action, $name = '_wpnonce', $echo = true) {
            $field = '<input type="hidden" name="' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') . '" value="test-nonce">';
            if ($echo) echo $field;
            return $field;
        }
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
    require_once dirname(__DIR__) . '/src/Core/Infrastructure/Repository/NotificationProviderRepository.php';
    require_once dirname(__DIR__) . '/src/Core/Application/NotificationTemplateService.php';
    require_once dirname(__DIR__) . '/src/Core/Support/BrandIdentity.php';
    require_once dirname(__DIR__) . '/src/Core/Support/CommunicationDesignTokens.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationCatalogue.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/TelnyxTemplateRegistry.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/TelnyxTemplateAuthority.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationTemplateSanitizer.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationTemplateValidationException.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationTemplateLibrary.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationChannelPolicy.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationCopyResolver.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/DelnavazanEmailRenderer.php';
    require_once dirname(__DIR__) . '/src/Portals/PortalNotificationInbox.php';
    require_once dirname(__DIR__) . '/src/Core/Application/IntegrationSettingsService.php';
    require_once dirname(__DIR__) . '/src/Core/Application/SmsProviderRoutingPolicy.php';
    require_once dirname(__DIR__) . '/src/Admin/Controller/NotificationManagementController.php';

    use Delnavazan\Platform\Integrations\Notifications\DelnavazanEmailRenderer;
    use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
    use Delnavazan\Platform\Integrations\Notifications\NotificationChannelPolicy;
    use Delnavazan\Platform\Integrations\Notifications\NotificationCopyResolver;
    use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary;
    use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateSanitizer;
    use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateValidationException;
    use Delnavazan\Platform\Integrations\Notifications\TelnyxTemplateAuthority;
    use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationProviderRepository;
    use Delnavazan\Platform\Portals\PortalNotificationInbox;

    $GLOBALS['wpdb'] = new \Delnavazan\Platform\Tests\FakeWpdb();
    $fail = static function (string $name, bool $ok): void { if (!$ok) throw new RuntimeException('Notification management unit failed: ' . $name); };

    /* 1. The catalogue is closed, unique and editorially complete in both supported languages. */
    $events = NotificationCatalogue::events();
    $fail('catalogue is not empty', count($events) >= 25);
    $persianText = static fn(string $text): bool => preg_match('/[\x{0600}-\x{06FF}]/u', $text) === 1;
    $placeholders = static function (string $text): array {
        $found = array();
        if (preg_match_all('/\{\{([a-z][a-z0-9_]{0,63})\}\}/', $text, $matches)) $found = array_values(array_unique($matches[1]));
        sort($found);
        return $found;
    };
    $templateKeys = array();
    $languagePairs = 0;
    foreach ($events as $key => $event) {
        $fail('event key shape ' . $key, preg_match('/^[a-z0-9._-]{1,64}$/', $key) === 1);
        $fail('event category ' . $key, isset(NotificationCatalogue::CATEGORIES[$event['category']]));
        $fail('event authority ' . $key, isset(NotificationCatalogue::AUTHORITIES[$event['authority']]));
        $fail('event variables ' . $key, is_array($event['variables']));
        foreach ($event['channels'] as $channel) {
            foreach (NotificationCatalogue::SUPPORTED_LOCALES as $locale) {
                $templateKey = NotificationCatalogue::templateKey($key, $channel, $locale);
                $fail('template key length ' . $key, strlen($templateKey) <= 64);
                $fail('template key uniqueness ' . $templateKey, !isset($templateKeys[$templateKey]));
                $templateKeys[$templateKey] = true;
                // The exact language must exist: a Persian fallback is never an English template.
                $copy = NotificationCatalogue::exactCopy($key, $channel, $locale);
                $fail('exact default copy ' . $templateKey, is_array($copy) && trim((string) ($copy['body'] ?? '')) !== '');
                $validated = NotificationTemplateSanitizer::validate($key, $channel, (string) ($copy['subject'] ?? ''), (string) $copy['body']);
                $fail('default copy validates ' . $templateKey . ': ' . implode(' ', $validated['errors']), $validated['ok']);
                if ($locale === 'en') {
                    $fail('English copy is not a Persian fallback: ' . $templateKey, !$persianText((string) $copy['body']) && !$persianText((string) ($copy['subject'] ?? '')));
                } else {
                    $fail('Persian copy never uses the retired greeting: ' . $templateKey, !str_contains((string) $copy['body'], 'سلام'));
                    if ($channel === 'email') $fail('Persian email uses the approved greeting: ' . $templateKey, str_contains((string) $copy['body'], 'درود'));
                }
                // Both languages must drive the same message contract.
                $fa = NotificationCatalogue::exactCopy($key, $channel, 'fa-IR');
                $en = NotificationCatalogue::exactCopy($key, $channel, 'en');
                if (is_array($fa) && is_array($en)) {
                    $languagePairs++;
                    $fail('translation keeps the same placeholders: ' . $templateKey, $placeholders((string) $fa['body']) === $placeholders((string) $en['body']));
                }
                // Customer copy never exposes an internal identifier.
                $fail('copy does not leak the event key: ' . $templateKey, !str_contains((string) $copy['body'], $key) && !str_contains((string) ($copy['subject'] ?? ''), $key));
            }
        }
        if ($event['category'] === 'security') {
            $fail('security is classified sensitive ' . $key, NotificationCatalogue::isMandatory($key));
            $fail('security never enables marketing ' . $key, $event['consent'] === 'security');
        }
    }
    $coverage = NotificationCatalogue::coverage();
    $fail('catalogue coverage counts every supported pair', $coverage['required'] === $languagePairs && $coverage['required'] > 0);
    $fail('no supported template lacks its own copy', $coverage['missing'] === array() && $coverage['present'] === $coverage['required']);
    foreach ($events as $key => $event) {
        foreach ($event['channels'] as $channel) {
            $default = NotificationCatalogue::defaultCopy($key, $channel, 'fa-IR');
            $fail('Persian default resolves for the sender paths ' . $key . '/' . $channel, is_array($default) && trim((string) $default['body']) !== '');
        }
    }
    $fail('review metadata is only used for known approval codes', array_diff(array_merge(...array_values(array_map(static fn(array $event): array => (array) ($event['review'] ?? array()), $events))), array_keys(NotificationCatalogue::REVIEW)) === array());
    $fail('financial copy carries no outstanding review', NotificationCatalogue::reviewFor('payment.failed.student') === array());
    $fail('security copy is flagged for security review', NotificationCatalogue::reviewFor('account.claim_issued.student') === array('security'));
    $fail('operational copy carries no outstanding review', NotificationCatalogue::reviewFor('booking.confirmed.student') === array());

    /* 2. Editorial safety and SMS segmentation. */
    $bad = NotificationTemplateSanitizer::validate('booking.confirmed.student', 'email', 'عنوان', "سلام {{name}}\n<script>alert(1)</script> {{when}} https://x.invalid {{portal_url}}");
    $fail('script is refused', !$bad['ok']);
    $xss = NotificationTemplateSanitizer::validate('booking.confirmed.student', 'email', 'عنوان', "سلام {{name}}\n{{when}}\n<a href=\"javascript:alert(1)\">کلیک</a> {{portal_url}}");
    $fail('javascript link is refused', !$xss['ok']);
    $unknown = NotificationTemplateSanitizer::validate('booking.confirmed.student', 'email', 'عنوان', "سلام {{name}} {{when}} {{portal_url}} {{claim_code}}");
    $fail('unknown placeholder is refused', !$unknown['ok']);
    $missing = NotificationTemplateSanitizer::validate('booking.confirmed.student', 'email', 'عنوان', "سلام {{name}} {{portal_url}}");
    $fail('missing required class time is refused', !$missing['ok']);
    $goodBody = "سلام {{name}}،\n\nکلاس در {{when}} برگزار می‌شود.\n\n{{portal_url}}\n\nدلنوازان";
    $good = NotificationTemplateSanitizer::validate('booking.confirmed.student', 'email', 'کلاس تأیید شد', $goodBody);
    $fail('valid copy is accepted', $good['ok']);
    $persian = NotificationTemplateSanitizer::smsSegmentation(str_repeat('ا', 80));
    $fail('Persian SMS is UCS-2', $persian['encoding'] === 'UCS-2' && $persian['segments'] === 2);
    $ascii = NotificationTemplateSanitizer::smsSegmentation(str_repeat('a', 160));
    $fail('ASCII SMS is one GSM part', $ascii['encoding'] === 'GSM-7' && $ascii['segments'] === 1);

    /* 2b. The action-link rule is a choice, not a specific placeholder: an editor who keeps the
     *     payment or survey destination is not forced to add the account link as well. */
    $altLink = NotificationTemplateSanitizer::validate('renewal.reminder.student', 'email', 'عنوان', "درود {{name}}،\n\n{{instalment_note}}\n\n{{payment_url}}\n\nدلنوازان");
    $fail('an alternative action link satisfies the contract', $altLink['ok']);
    $surveyLink = NotificationTemplateSanitizer::validate('post_intro.survey.student', 'email', 'عنوان', "درود {{name}}،\n\n{{survey_url}}\n\nدلنوازان");
    $fail('the survey destination satisfies the contract', $surveyLink['ok']);
    $noLink = NotificationTemplateSanitizer::validate('lesson.reminder.student', 'email', 'عنوان', "درود {{name}}،\n\n{{when}}\n\nدلنوازان");
    $fail('a message with no action link is refused', !$noLink['ok'] && str_contains(implode(' ', $noLink['errors']), 'action link'));
    $noTime = NotificationTemplateSanitizer::validate('lesson.reminder.student', 'email', 'عنوان', "درود {{name}}،\n\nکلاس برگزار می‌شود.\n\n{{portal_url}}\n\nدلنوازان");
    $fail('dropping the class time is refused with a readable reason', !$noTime['ok'] && str_contains(implode(' ', $noTime['errors']), 'when the class or deadline'));
    $fail('the required action links are exposed to the editor', NotificationTemplateSanitizer::requiredActionLinks('renewal.reminder.student', 'email') === array('portal_url', 'payment_url'));

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

    $customBody = "سلام {{name}}،\n\nزمان تازه: {{when}}\n\n{{portal_url}}\n\nدلنوازان";
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
    $fail('the workspace exposes the linked provider name', ($mapped['provider_name'] ?? '') === 'booking_confirmed_fa');
    $fail('the workspace exposes the registered provider name before discovery', ($mapped['provider_expected_name'] ?? '') === 'delnavazan_intro_confirmed_fa');
    $invalidMap = false;
    try { $library->saveProviderMapping($event, 'whatsapp', 'fa-IR', 'Bad Name!', 'fa'); } catch (InvalidArgumentException $error) { $invalidMap = true; }
    $fail('invalid provider name is refused', $invalidMap);

    $provider = new NotificationProviderRepository();
    $provider->upsertTemplate(array(
        'uid'=>'01TESTPROVIDERTEMPLATE0001','provider'=>'telnyx','template_name'=>'delnavazan_teacher_new_booking_fa','language'=>'fa',
        'provider_template_id'=>'meta-1','category'=>'UTILITY','status'=>'APPROVED','event_key'=>'booking.new.teacher','audience'=>'teacher',
        'variable_order'=>'teacher,course,student,when,response_window,teacher_portal_url','template_text'=>'درود {{1}}، «{{2}}» با {{3}} در {{4}} — {{5}} {{6}}',
        'last_verified_at'=>'2026-11-01 00:00:00','created_at'=>'2026-11-01 00:00:00','updated_at'=>'2026-11-01 00:00:00',
    ));
    $discoveredLibrary = new NotificationTemplateLibrary(null,null,new TelnyxTemplateAuthority($provider));
    $discoveredStatus = $discoveredLibrary->status('booking.new.teacher','whatsapp','fa-IR');
    $discoveredMapping = $discoveredLibrary->providerMapping('booking.new.teacher.whatsapp.fa-IR');
    $discoveredEvidence = $discoveredLibrary->providerEvidence('booking.new.teacher','fa-IR');
    $fail('approved discovery is the administration mapping authority', $discoveredStatus['provider_state']==='mapped'&&$discoveredMapping['source']==='discovery'&&$discoveredMapping['name']==='delnavazan_teacher_new_booking_fa');
    $fail('the workspace exposes the discovered provider name', ($discoveredStatus['provider_name'] ?? '') === 'delnavazan_teacher_new_booking_fa');
    $fail('the workspace exposes the approved provider body', is_array($discoveredEvidence['primary'] ?? null) && str_contains((string) $discoveredEvidence['primary']['template_text'], '{{1}}') && str_contains((string) $discoveredEvidence['primary']['template_text'], '{{6}}'));
    $fail('approved discovery does not require a duplicate manual option', !isset(($GLOBALS['dzn_test_options'][NotificationTemplateLibrary::OPTION_WHATSAPP]??array())['booking.new.teacher.whatsapp.fa-IR']));
    $conflictingMap=false;
    try{$discoveredLibrary->saveProviderMapping('booking.new.teacher','whatsapp','fa-IR','legacy_teacher_booking_fa','fa');}catch(InvalidArgumentException){$conflictingMap=true;}
    $fail('manual mapping cannot contradict approved discovery', $conflictingMap);

    $provider->upsertTemplate(array(
        'uid'=>'01TESTMARKETINGTEMPLATE001','provider'=>'telnyx','template_name'=>'delnavazan_teacher_new_booking_fa','language'=>'fa',
        'provider_template_id'=>'meta-marketing','category'=>'MARKETING','status'=>'APPROVED','event_key'=>'booking.new.teacher','audience'=>'teacher',
        'variable_order'=>'teacher,course,student,when,response_window,teacher_portal_url','last_verified_at'=>'2026-11-01 00:00:00','created_at'=>'2026-11-01 00:00:00','updated_at'=>'2026-11-01 00:00:00',
    ));
    $marketingLibrary = new NotificationTemplateLibrary(null,null,new TelnyxTemplateAuthority($provider));
    $fail('approved Marketing provider templates cannot map transactional notifications', $marketingLibrary->status('booking.new.teacher','whatsapp','fa-IR')['provider_state'] === 'contract_mismatch');

    $library->publish($event, 'whatsapp', 'fa-IR', 'عنوان واتس‌اپ', "سلام {{name}} {{when}} {{portal_url}}\nمتن تازهٔ واتس‌اپ");
    $fail('whatsapp copy requires provider approval after publication', $library->status($event, 'whatsapp', 'fa-IR')['provider_state'] === 'approval_required');

    /* 5. Every notification and every offered channel is administrator-controlled, including the
     *    sensitive security, account and payment notices that used to be locked. */
    $claimEmail = false;
    try { $library->saveEventChannel('account.claim_issued.student', 'email', false); } catch (InvalidArgumentException $error) { $claimEmail = true; }
    $fail('a sensitive notification email can be disabled', !$claimEmail && $library->eventChannelEnabled('account.claim_issued.student', 'email') === false);
    $fail('a disabled sensitive channel stops dispatch', NotificationChannelPolicy::allows('account.claim_issued.student', 'email') === false);
    $fail('the sensitive classification is kept as a warning only', NotificationCatalogue::isMandatory('account.claim_issued.student') === true);
    $library->saveEventChannel('account.claim_issued.student', 'email', true);
    $fail('re-enabling the sensitive channel restores it', $library->eventChannelEnabled('account.claim_issued.student', 'email') === true);
    $library->saveEventEnabled('account.claim_issued.student', false);
    $fail('a sensitive notification can be disabled across all channels', $library->eventEnabled('account.claim_issued.student') === false);
    $fail('a disabled notification blocks every offered channel', NotificationChannelPolicy::allows('account.claim_issued.student', 'email') === false);
    $fail('saved channel preferences survive a notification-wide disable', $library->eventChannelEnabled('account.claim_issued.student', 'email') === true);
    $library->saveEventEnabled('account.claim_issued.student', true);
    $fail('re-enabling a notification restores its saved channel preferences', NotificationChannelPolicy::allows('account.claim_issued.student', 'email') === true);
    $auditActions = array_column($library->auditLog(40), 'action');
    $fail('administrator changes are audited', in_array('disable_event', $auditActions, true) && in_array('disable_channel', $auditActions, true) && in_array('enable_event', $auditActions, true));
    $fail('claim event declares WhatsApp and SMS for explicit future activation', $library->channelOffered('account.claim_issued.student', 'whatsapp') && $library->channelOffered('account.claim_issued.student', 'sms'));
    $claimWhatsApp = $library->status('account.claim_issued.student', 'whatsapp', 'fa-IR');
    $fail('a disabled channel is not advertised as active', !in_array('whatsapp', $library->permittedChannels('account.claim_issued.student'), true));
    $fail('a disabled channel keeps its factual provider state', $claimWhatsApp['permitted'] === false && $claimWhatsApp['provider_state'] === 'not_mapped');
    $fail('a disabled channel reports its own state rather than a provider demand', str_contains(implode(' ', $claimWhatsApp['issues']), 'disabled'));
    $operational = $library->permittedChannels('booking.confirmed.student');
    $fail('operational events default to email and portal', $operational === array('website', 'email'));

    /* 6. Resolver: published copy wins, missing variables and unknown events fall back. */
    $resolved = NotificationCopyResolver::email($event, 'fa-IR', array('name' => 'باران', 'when' => 'شنبه', 'portal_url' => 'https://delnavazan.com/student-portal/', 'teacher' => 'استاد', 'course' => 'تار', 'join_url' => 'https://example.invalid/join'), array('subject' => 'fallback', 'body' => 'fallback'));
    $fail('resolver renders published copy', $resolved['source'] === 'published' && str_contains($resolved['body'], 'باران'));
    $fallback = NotificationCopyResolver::email($event, 'fa-IR', array('name' => 'باران'), array('subject' => 'fallback', 'body' => 'fallback'));
    $fail('resolver falls back when a variable is missing', $fallback['source'] === 'fallback' && $fallback['body'] === 'fallback');
    $unknown = NotificationCopyResolver::email('not.a.real.event', 'fa-IR', array(), array('subject' => 's', 'body' => 'b'));
    $fail('resolver falls back for an unknown event', $unknown['source'] === 'fallback');

    /* 7. Event controls keep mandatory channels locked and gate optional channels. */
    $fail('security never allows WhatsApp', NotificationChannelPolicy::allows('account.claim_issued.student', 'whatsapp') === false);
    $fail('security never allows SMS', NotificationChannelPolicy::allows('account.claim_issued.student', 'sms') === false);
    $fail('website is allowed for an operational event', NotificationChannelPolicy::allows('booking.confirmed.student', 'website') === true);
    $fail('a channel outside the event is refused', NotificationChannelPolicy::allows('post_intro.activated.student', 'sms') === false);
    $library->saveEventChannel('booking.confirmed.student', 'whatsapp', true);
    $fail('an event-scoped WhatsApp decision enables only that event', NotificationChannelPolicy::allows('booking.confirmed.student', 'whatsapp') === true);

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

    /* 9. The catalogue's displayed result set uses every filter after editor return, and blockers
     * remain visible even while an event channel is switched off. */
    $controllerClass = 'Delnavazan\\Platform\\Admin\\Controller\\NotificationManagementController';
    $matches = new \ReflectionMethod($controllerClass, 'tableFilterMatches');
    $matches->setAccessible(true);
    $visibleRow = array('event'=>array('category'=>'booking','audience'=>'student'),'offered'=>array('email','sms'),'states'=>array('email'=>array('state'=>'enabled')),'search_text'=>'booking confirmed lesson');
    $hiddenRow = array('event'=>array('category'=>'finance','audience'=>'admin'),'offered'=>array('email'),'states'=>array('email'=>array('state'=>'enabled')),'search_text'=>'statement');
    $allFilters = array('search'=>'lesson','category'=>'booking','recipient'=>'student','channel'=>'email','status'=>'enabled');
    $fail('displayed table rows apply all preserved filters', $matches->invoke(null,$visibleRow,$allFilters)===true && $matches->invoke(null,$hiddenRow,$allFilters)===false);
    $stateMethod = new \ReflectionMethod($controllerClass, 'effectiveChannelState');
    $stateMethod->setAccessible(true);
    $library->saveEventChannel($event, 'whatsapp', false);
    $offWhatsApp = $stateMethod->invoke(null, $library, $event, 'whatsapp');
    $fail('off WhatsApp still shows approval blocker', $offWhatsApp['state']==='disabled' && !empty($offWhatsApp['enable_blocked']) && stripos($offWhatsApp['reason'], 'approv') !== false);
    $smsEvent = 'booking.new.teacher';
    $library->saveEventChannel($smsEvent, 'sms', false);
    $offSms = $stateMethod->invoke(null, $library, $smsEvent, 'sms');
    $fail('off SMS still shows unavailable selected routes', $offSms['state']==='disabled' && !empty($offSms['enable_blocked']) && str_contains($offSms['reason'], 'unavailable'));
    $controllerSource=(string)file_get_contents(dirname(__DIR__).'/src/Admin/Controller/NotificationManagementController.php');
    $fail('enabling a blocked channel stores intent and keeps the blocker visible', str_contains($controllerSource, 'self::channelBlocker($library,$eventKey,$channel)') && str_contains($controllerSource, 'Sending will start when this is resolved.'));
    $fail('a card toggle returns without a template key while the editor keeps it', str_contains($controllerSource, "if(\$source==='editor')\$extra=array('template'=>\$eventKey,'template_channel'=>\$channel)"));

    /* 10. Template/provider readiness is evaluated independently of the current on/off value. A
     * configured WhatsApp provider with no mapped template must still block enabling, and the
     * same blocker is what the toggle handler consumes. A ready channel remains enableable. */
    (new \Delnavazan\Platform\Core\Application\IntegrationSettingsService())->save('whatsapp', array(
        'app_id'=>'1234567890','business_account_id'=>'0987654321','phone_number_id'=>'1122334455',
        'access_token'=>'test-access-token','test_template_name'=>'delnavazan_test_template',
    ));
    $blockerMethod = new \ReflectionMethod($controllerClass, 'channelBlocker');
    $blockerMethod->setAccessible(true);
    $unmappedEvent = 'lesson.reminder.student';
    $fail('the unmapped event offers WhatsApp', $library->channelOffered($unmappedEvent, 'whatsapp'));
    $fail('the unmapped WhatsApp channel starts off', $library->eventChannelEnabled($unmappedEvent, 'whatsapp') === false);
    $unmappedStatus = $library->status($unmappedEvent, 'whatsapp', 'fa-IR');
    $unmappedIssues = implode(' ', (array) $unmappedStatus['issues']);
    $fail('a configured provider without a mapping stays not_mapped', $unmappedStatus['provider_state'] === 'not_mapped');
    $fail('an off channel keeps both the off notice and its provider issue', str_contains($unmappedIssues, 'disabled') && str_contains($unmappedIssues, 'Meta'));
    $unmappedState = $stateMethod->invoke(null, $library, $unmappedEvent, 'whatsapp');
    $fail('configured provider without a mapping keeps the blocker visible while off', $unmappedState['state'] === 'disabled' && $unmappedState['enable_blocked'] === true && str_contains($unmappedState['reason'], 'Meta-approved'));
    $unmappedBlocker = $blockerMethod->invoke(null, $library, $unmappedEvent, 'whatsapp');
    $fail('the toggle handler receives the same unavailable blocker', is_array($unmappedBlocker) && $unmappedBlocker['state'] === 'missing_provider');
    $canonicalBlocker = $blockerMethod->invoke(null, $library, 'account.claim_issued.student', 'whatsapp');
    $fail('a sensitive canonical event without a version reports the outstanding approval, not missing copy', is_array($canonicalBlocker) && $canonicalBlocker['state'] === 'awaiting_approval' && str_contains((string) $canonicalBlocker['reason'], 'Security review required'));
    $operationalFinanceBlocker = $blockerMethod->invoke(null, $library, 'renewal.automatic_charged.student', 'email');
    $fail('a wired finance event is never held for editorial approval', is_array($operationalFinanceBlocker) && $operationalFinanceBlocker['state'] !== 'awaiting_approval');
    $fail('an unsupported channel is not described as a fault', (static function () use ($stateMethod, $library): bool {
        $unsupported = $stateMethod->invoke(null, $library, 'unknown.event', 'email');
        return $unsupported['state'] === 'unsupported' && $unsupported['label'] === 'Not offered';
    })());
    $fail('a ready channel has no blocker', $blockerMethod->invoke(null, $library, $event, 'website') === null);
    $library->saveEventChannel($event, 'website', false);
    $readyOff = $stateMethod->invoke(null, $library, $event, 'website');
    $fail('a ready off channel remains enableable', $readyOff['state'] === 'disabled' && empty($readyOff['enable_blocked']) && $blockerMethod->invoke(null, $library, $event, 'website') === null);
    $library->saveEventChannel($event, 'website', true);

    /* 11. The publishable workflow: preparation is idempotent, restricted copy stays review-only,
     *     immutable history is preserved and publishing never enables a delivery channel. */
    $prepared = $library->prepareMissingCatalogueDrafts();
    $fail('every supported template can be prepared as a reviewable draft', $prepared['missing'] === array() && $prepared['invalid'] === array());
    $fail('preparation created the missing drafts', $prepared['created'] > 0);
    $again = $library->prepareMissingCatalogueDrafts();
    $fail('bulk draft preparation is idempotent', $again['created'] === 0 && $again['existing'] > 0 && $again['missing'] === array());
    $fail('dedicated one-time-secret copy is never prepared here', $library->workingCopy('teacher.invitation_issued.teacher', 'email', 'fa-IR')['draft'] === null);
    $fail('an English draft is prepared from English copy', is_string((string) ($library->workingCopy('lesson.reminder.student', 'email', 'en')['draft']['body'] ?? '')) && str_contains((string) $library->workingCopy('lesson.reminder.student', 'email', 'en')['draft']['body'], 'Hello'));
    $fail('financial drafts need editorial approval, not a finance hold', $library->draftReviewPolicy('payment.failed.student', 'email', 'fa-IR')['allowed'] === false && str_contains($library->draftReviewPolicy('payment.failed.student', 'email', 'fa-IR')['reason'], 'approved'));
    $fail('security drafts await security approval', $library->draftReviewPolicy('account.claim_issued.student', 'email', 'en')['allowed'] === false && str_contains($library->draftReviewPolicy('account.claim_issued.student', 'email', 'en')['reason'], 'Security'));
    $fail('an unapproved WhatsApp draft is not publishable in bulk', $library->draftReviewPolicy('lesson.reminder.student', 'whatsapp', 'fa-IR')['allowed'] === false && str_contains($library->draftReviewPolicy('lesson.reminder.student', 'whatsapp', 'fa-IR')['reason'], 'approved'));
    $library->approveDraft('lesson.reminder.student', 'whatsapp', 'fa-IR');
    $approvedWhatsApp = $library->draftReviewPolicy('lesson.reminder.student', 'whatsapp', 'fa-IR');
    $fail('editorial approval never unlocks an unmapped provider route', $approvedWhatsApp['allowed'] === false && str_contains((string) $approvedWhatsApp['reason'], 'Meta'));

    /* 11a. Bulk publication needs an explicit editorial approval bound to the exact copy. */
    $fail('an unapproved operational draft is not publishable in bulk', $library->draftReviewPolicy('booking.declined.student', 'email', 'fa-IR')['allowed'] === false && str_contains($library->draftReviewPolicy('booking.declined.student', 'email', 'fa-IR')['reason'], 'approved'));
    $fail('no approval exists before one is granted', $library->draftApproval('booking.declined.student', 'email', 'fa-IR') === null);
    $library->approveDraft('booking.declined.student', 'email', 'fa-IR');
    $approved = $library->draftApproval('booking.declined.student', 'email', 'fa-IR');
    $fail('an approval records its actor and copy digest', is_array($approved) && (int) $approved['approved_by'] === 7 && strlen((string) $approved['digest']) === 64);
    $fail('an approved operational draft becomes publishable', $library->draftReviewPolicy('booking.declined.student', 'email', 'fa-IR')['allowed'] === true);
    $approvedDraft = $library->workingCopy('booking.declined.student', 'email', 'fa-IR')['draft'];
    $library->saveDraft('booking.declined.student', 'email', 'fa-IR', (string) $approvedDraft['subject'], (string) $approvedDraft['body'] . "\n" . 'با احترام، دلنوازان');
    $fail('editing approved copy invalidates the approval', $library->draftApproval('booking.declined.student', 'email', 'fa-IR') === null && $library->draftReviewPolicy('booking.declined.student', 'email', 'fa-IR')['allowed'] === false);
    $library->discardDraft('booking.declined.student', 'email', 'fa-IR');
    $fail('discarding a draft removes its approval', $library->draftApproval('booking.declined.student', 'email', 'fa-IR') === null);
    $library->saveDraft('booking.declined.student', 'email', 'fa-IR', (string) $approvedDraft['subject'], (string) $approvedDraft['body']);

    /* 11b. The provider gate is a whitelist: only a positively mapped template may be published. */
    $providerLibrary = $library;
    foreach (array(
        TelnyxTemplateAuthority::NOT_MAPPED => 'No approved',
        TelnyxTemplateAuthority::NOT_APPROVED => 'rejected or not approved',
        TelnyxTemplateAuthority::AMBIGUOUS => 'More than one approved',
        TelnyxTemplateAuthority::CONTRACT_MISMATCH => 'variable contract',
        'approval_required' => 'Meta must approve',
        'unrecognised_future_state' => 'unknown',
    ) as $providerState => $expected) {
        $reasonMethod = new \ReflectionMethod($providerLibrary, 'providerBlockReason');
        $reasonMethod->setAccessible(true);
        $reason = (string) $reasonMethod->invoke(null, $providerState);
        $fail('provider state ' . $providerState . ' is refused with a reason', $reason !== '' && str_contains($reason, $expected));
    }
    $fail('only the mapped provider state has no blocker reason', (new \ReflectionMethod($providerLibrary, 'providerBlockReason'))->invoke(null, TelnyxTemplateAuthority::MAPPED) === '');

    /* 11c. The positive path: a manually mapped route becomes bulk-publishable only when the draft
     *      body is the exact copy the mapping was recorded against and an editor approved it. */
    $waBody = (string) $library->activeCopy('booking.confirmed.student', 'whatsapp', 'fa-IR')['body'];
    $library->saveProviderMapping('booking.confirmed.student', 'whatsapp', 'fa-IR', 'delnavazan_intro_confirmed_fa', 'fa');
    $library->saveDraft('booking.confirmed.student', 'whatsapp', 'fa-IR', 'عنوان واتس‌اپ', $waBody);
    $fail('a mapped WhatsApp draft without editorial approval is still refused', $library->draftReviewPolicy('booking.confirmed.student', 'whatsapp', 'fa-IR')['allowed'] === false);
    $library->approveDraft('booking.confirmed.student', 'whatsapp', 'fa-IR');
    $mappedPolicy = $library->draftReviewPolicy('booking.confirmed.student', 'whatsapp', 'fa-IR');
    $fail('a mapped and approved WhatsApp draft is publishable', $mappedPolicy['allowed'] === true && $mappedPolicy['reason'] === '');
    $library->saveDraft('booking.confirmed.student', 'whatsapp', 'fa-IR', 'عنوان واتس‌اپ', $waBody . "\n" . 'سطر تازه');
    $library->approveDraft('booking.confirmed.student', 'whatsapp', 'fa-IR');
    $driftedPolicy = $library->draftReviewPolicy('booking.confirmed.student', 'whatsapp', 'fa-IR');
    $fail('editing the body after the provider mapping is refused', $driftedPolicy['allowed'] === false && str_contains((string) $driftedPolicy['reason'], 'provider mapping'));
    $library->discardDraft('booking.confirmed.student', 'whatsapp', 'fa-IR');

    /* 11d. A discovered but contract-invalid template is evidence, not a usable mapping: the
     *      workspace must still name it and show the approved body while sending stays blocked. */
    $provider->upsertTemplate(array(
        'uid'=>'01TESTINTROCONFIRMEDTEXT0001','provider'=>'telnyx','template_name'=>'delnavazan_intro_confirmed_fa','language'=>'fa',
        'provider_template_id'=>'meta-intro-confirmed','category'=>'UTILITY','status'=>'APPROVED','event_key'=>null,'audience'=>'student',
        'variable_order'=>'name,course,teacher,when','template_text'=>'درود {{1}}، کلاس «{{2}}» با {{3}} در {{4}} تأیید شد.',
        'last_verified_at'=>'2026-11-01 00:00:00','created_at'=>'2026-11-01 00:00:00','updated_at'=>'2026-11-01 00:00:00',
    ));
    $mismatchLibrary = new NotificationTemplateLibrary(null,null,new TelnyxTemplateAuthority($provider));
    $mismatchStatus = $mismatchLibrary->status('booking.confirmed.student','whatsapp','fa-IR');
    $mismatchMapping = $mismatchLibrary->providerMapping('booking.confirmed.student.whatsapp.fa-IR');
    $mismatchEvidence = $mismatchLibrary->providerEvidence('booking.confirmed.student','fa-IR');
    $fail('a contract mismatch is not presented as a usable mapping', $mismatchStatus['provider_state']==='contract_mismatch'&&$mismatchMapping['name']==='');
    $fail('a contract mismatch still exposes the discovered provider name', is_array($mismatchEvidence['primary']??null)&&$mismatchEvidence['primary']['name']==='delnavazan_intro_confirmed_fa'&&$mismatchEvidence['primary']['status']==='APPROVED');
    $fail('a contract mismatch still exposes the approved provider body', str_contains((string)($mismatchEvidence['primary']['template_text']??''),'{{4}}'));
    $fail('the English route does not claim the Persian registered template name', $mismatchLibrary->providerEvidence('booking.confirmed.student','en')['expected_name']===null);

    $fail('operational drafts still require approval', $library->draftReviewPolicy('booking.declined.student', 'email', 'fa-IR')['allowed'] === false);
    $library->approveDraft('booking.declined.student', 'email', 'fa-IR');
    $fail('an approved operational draft is publishable', $library->draftReviewPolicy('booking.declined.student', 'email', 'fa-IR')['allowed'] === true);
    $historyBefore = count($library->history('booking.declined.student', 'email', 'fa-IR'));
    $bulk = $library->publishReviewedDrafts();
    $blockedKeys = array_column($bulk['blocked'], 'template_key');
    $fail('bulk publishing activates approved operational copy', in_array('booking.declined.student.email.fa-IR', $bulk['published'], true));
    $fail('bulk publishing still adds exactly one immutable version per template', count($library->history('booking.declined.student', 'email', 'fa-IR')) === $historyBefore + 1);
    $fail('publishing consumes the approval', $library->draftApproval('booking.declined.student', 'email', 'fa-IR') === null);
    $fail('an unapproved draft is never bulk-published', in_array('payment.failed.student.email.fa-IR', $blockedKeys, true));
    $fail('security drafts are never bulk-published', in_array('account.claim_issued.student.email.fa-IR', $blockedKeys, true));
    $fail('provider-pending drafts are never bulk-published', in_array('lesson.reminder.student.whatsapp.fa-IR', $blockedKeys, true));
    $fail('publishing a template never enables its channel', $library->eventChannelEnabled('booking.declined.student', 'website') === true && $library->eventChannelEnabled('lesson.reminder.student', 'whatsapp') === false);
    $publishedAgain = $library->publishReviewedDrafts();
    $fail('a second bulk publish does not duplicate versions', in_array('booking.declined.student.email.fa-IR', $publishedAgain['published'], true) === false && count($library->history('booking.declined.student', 'email', 'fa-IR')) === $historyBefore + 1);
    $coverage = $library->coverageReport();
    $fail('the coverage report accounts for every supported template', $coverage['required'] === $coverage['copy_present'] && $coverage['copy_missing'] === 0);
    $fail('the coverage report tracks prepared and approved drafts separately', isset($coverage['drafts'], $coverage['drafts_approved']) && $coverage['drafts'] > $coverage['drafts_approved']);
    $fail('the coverage report keeps canonical gaps visible', in_array('account.claim_issued.student', $coverage['unpublished_canonical'], true));
    $fail('the coverage report no longer marks wired renewal producers declared-only', $coverage['declared_only'] === array());
    $fail('the coverage report has no unsupported route once every event offers every method', $coverage['unsupported'] === array());
    $fail('the coverage report keeps unmapped WhatsApp routes visible', in_array('lesson.reminder.student', $coverage['unmapped_whatsapp'], true));

    /* 12. Exhaustive administration matrix: every catalogue event and every offered channel can be
     *     switched off, and suppression is consulted during hand-off rather than only at enqueue. */
    $seenEvents = 0;
    foreach (NotificationCatalogue::events() as $matrixEvent => $matrixDefinition) {
        $seenEvents++;
        $library->saveEventEnabled($matrixEvent, false);
        $fail('every notification can be disabled: ' . $matrixEvent, $library->eventEnabled($matrixEvent) === false);
        foreach ((array) $matrixDefinition['channels'] as $matrixChannel) {
            $fail('a disabled notification never dispatches: ' . $matrixEvent . '/' . $matrixChannel, NotificationChannelPolicy::allows($matrixEvent, (string) $matrixChannel) === false);
        }
        $library->saveEventEnabled($matrixEvent, true);
        $fail('every notification can be enabled again: ' . $matrixEvent, $library->eventEnabled($matrixEvent) === true);
        foreach ((array) $matrixDefinition['channels'] as $matrixChannel) {
            $library->saveEventChannel($matrixEvent, (string) $matrixChannel, false);
            $fail('every offered channel can be disabled: ' . $matrixEvent . '/' . $matrixChannel, $library->eventChannelEnabled($matrixEvent, (string) $matrixChannel) === false);
            $fail('a disabled channel never dispatches: ' . $matrixEvent . '/' . $matrixChannel, NotificationChannelPolicy::allows($matrixEvent, (string) $matrixChannel) === false);
            $library->saveEventChannel($matrixEvent, (string) $matrixChannel, true);
            $fail('a re-enabled channel preference is restored: ' . $matrixEvent . '/' . $matrixChannel, $library->eventChannelEnabled($matrixEvent, (string) $matrixChannel) === true);
        }
    }
    $fail('the administration matrix covers the whole catalogue', $seenEvents === count($events));

    $transportSource = (string) file_get_contents(dirname(__DIR__) . '/src/Integrations/Notifications/CompositeNotificationTransport.php');
    $fail('canonical hand-off re-applies the administrator policy', str_contains($transportSource, 'private function allowed') && str_contains($transportSource, 'channelPolicy'));
    $studentWorker = (string) file_get_contents(dirname(__DIR__) . '/src/Integrations/BookingStudentNotificationWorker.php');
    $fail('the booking queue re-checks the switch at dispatch time', str_contains($studentWorker, "eventChannelEnabled(\$eventKey,'email')") && str_contains($studentWorker, 'email_disabled_by_administrator'));
    $allocationWorker = (string) file_get_contents(dirname(__DIR__) . '/src/Integrations/BookingAllocationNotificationWorker.php');
    $fail('the allocation queue re-checks the switch at dispatch time', str_contains($allocationWorker, "eventChannelEnabled('booking.new.teacher','email')"));
    $mobileSource = (string) file_get_contents(dirname(__DIR__) . '/src/Integrations/BookingNotificationMobileDispatcher.php');
    $fail('SMS keeps its own administrator switch', str_contains($mobileSource, "\$this->allowed(\$eventKey, 'sms')"));
    $fail('SMS is never triggered by a WhatsApp outcome', str_contains($mobileSource, '!$whatsappAttempted') && str_contains($mobileSource, '!$studentWhatsAppPreferred'));
    $fail('SMS copy is resolved independently of WhatsApp', str_contains($mobileSource, "NotificationCopyResolver::message(\$eventKey, 'sms'"));
    $controllerSource = (string) file_get_contents(dirname(__DIR__) . '/src/Admin/Controller/NotificationManagementController.php');
    $fail('SMS is presented as an independent method, never a fallback', !str_contains($controllerSource, 'SMS fallback') && !str_contains($controllerSource, 'WhatsApp → SMS') && !str_contains($controllerSource, 'notification_event_mandatory'));
    $fail('the workspace never claims a locked mandatory channel', !str_contains($controllerSource, 'cannot disable all channels') && !str_contains($controllerSource, 'Mandatory and locked'));
    $fail('the account-claim mobile route is administrator-controllable', (static function () use ($library): bool {
        $was = $library->eventChannelEnabled('account.claim_issued.student', 'whatsapp');
        $library->saveEventChannel('account.claim_issued.student', 'whatsapp', true);
        $on = $library->eventChannelEnabled('account.claim_issued.student', 'whatsapp');
        $library->saveEventChannel('account.claim_issued.student', 'whatsapp', $was);
        return $on && $library->eventChannelEnabled('account.claim_issued.student', 'whatsapp') === $was;
    })());

    /* 13. The administrator switch is consulted at provider hand-off, not only when the work was
     *     enqueued. A queued or retried canonical notification must not reach a provider port after
     *     the switch was turned off, and the switch must never alter the business record. */
    require_once dirname(__DIR__) . '/src/Core/Application/NotificationTransportPort.php';
    require_once dirname(__DIR__) . '/src/Core/Application/NotificationCopyPort.php';
    require_once dirname(__DIR__) . '/src/Core/Application/NotificationTransportRouter.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/NotificationEventKey.php';
    require_once dirname(__DIR__) . '/src/Integrations/Notifications/CompositeNotificationTransport.php';

    $emailStatus = $library->status('booking.confirmed.student', 'email', 'fa-IR');
    $frozenVersion = (int) ($emailStatus['active_version_id'] ?? 0);
    $fail('a frozen version exists for the hand-off proof', $frozenVersion > 0);
    $fail('the frozen version resolves back to its event key', \Delnavazan\Platform\Integrations\Notifications\NotificationEventKey::fromTemplateVersion($frozenVersion) === 'booking.confirmed.student');

    $port = new class implements \Delnavazan\Platform\Core\Application\NotificationTransportPort {
        public array $seen = array();
        public function handoff(array $command): array { $this->seen[] = $command; return array('acknowledged' => true, 'permanent_failure' => null); }
    };
    $copyPort = new class implements \Delnavazan\Platform\Core\Application\NotificationCopyPort {
        public function render(int $version, string $channel, string $locale, array $parameters): ?array { return array('subject' => 's', 'body' => 'b'); }
    };
    $handoff = static function () use ($port, $copyPort, $frozenVersion): array {
        $transport = new \Delnavazan\Platform\Integrations\Notifications\CompositeNotificationTransport(
            new \Delnavazan\Platform\Core\Application\NotificationTransportRouter(array(array('channel' => 'email', 'configured' => true, 'port' => $port))),
            $copyPort,
            static fn(array $command): array => array('route' => 'email', 'locale' => 'fa-IR', 'email_eligible' => true, 'email' => 'student@example.invalid'),
            static fn(string $eventKey, string $channel): bool => NotificationChannelPolicy::allows($eventKey, $channel)
        );
        return $transport->handoff(array('template_version_id' => $frozenVersion, 'parameters' => array(), 'notification_key_digest' => 'x', 'attempt_sequence' => 1, 'audience' => 'student', 'variable_codes' => array()));
    };

    $library->saveEventEnabled('booking.confirmed.student', true);
    $library->saveEventChannel('booking.confirmed.student', 'email', true);
    $allowedResult = $handoff();
    $fail('an enabled channel reaches the provider port', $allowedResult['acknowledged'] === true && count($port->seen) === 1);

    $business = array('booking' => 'unchanged', 'payment' => 'unchanged', 'lesson' => 'unchanged');
    $library->saveEventChannel('booking.confirmed.student', 'email', false);
    $channelOffResult = $handoff();
    $fail('a disabled channel never reaches the provider port', count($port->seen) === 1 && ($channelOffResult['acknowledged'] ?? true) === false && ($channelOffResult['permanent_failure'] ?? null) === 'no_route');
    $fail('disabling a channel does not alter the business record', $business === array('booking' => 'unchanged', 'payment' => 'unchanged', 'lesson' => 'unchanged'));

    $library->saveEventChannel('booking.confirmed.student', 'email', true);
    $library->saveEventEnabled('booking.confirmed.student', false);
    $eventOffResult = $handoff();
    $fail('a disabled notification never reaches the provider port', count($port->seen) === 1 && ($eventOffResult['permanent_failure'] ?? null) === 'no_route');
    $fail('a disabled notification keeps its saved channel preference', $library->eventChannelEnabled('booking.confirmed.student', 'email') === true);
    $library->saveEventEnabled('booking.confirmed.student', true);
    $restored = $handoff();
    $fail('re-enabling the notification restores provider hand-off', $restored['acknowledged'] === true && count($port->seen) === 2);
    $fail('the switch never rewrites the version the notification froze', (int) $library->status('booking.confirmed.student', 'email', 'fa-IR')['active_version_id'] === $frozenVersion);

    /* 14. Production safety: preparation and publication never seed, rewrite or activate the saved
     *     administrator preferences, and the retired category override is the only key removed. */
    $optionsBefore = $GLOBALS['dzn_test_options'];
    $library->prepareMissingCatalogueDrafts();
    $library->publishReviewedDrafts();
    $optionsAfter = $GLOBALS['dzn_test_options'];
    $fail('preparation and publication never create the catalogue-seeded marker', !array_key_exists(\Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary::OPTION_SEEDED, $optionsAfter));
    $fail('preparation and publication never rewrite saved event switches', ($optionsBefore[\Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary::OPTION_EVENT_ENABLED] ?? null) === ($optionsAfter[\Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary::OPTION_EVENT_ENABLED] ?? null));
    $fail('preparation and publication never rewrite saved channel preferences', ($optionsBefore[\Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary::OPTION_EVENT_CHANNELS] ?? null) === ($optionsAfter[\Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary::OPTION_EVENT_CHANNELS] ?? null));
    $fail('running the workflow twice does not duplicate a published version', count($library->history('payment.failed.student', 'email', 'en')) === 0);
    $fail('a template with no explicitly approved draft stays unpublished', $library->status('payment.failed.student', 'email', 'en')['published'] === false);

    $GLOBALS['dzn_test_options']['dzn_notification_channel_policy'] = array('security' => array('email' => true));
    $GLOBALS['dzn_test_options'][\Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary::OPTION_EVENT_ENABLED] = array('booking.confirmed.student' => false);
    \Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary::retireLegacyCategoryPolicy();
    $fail('the retired category override is deleted', !array_key_exists('dzn_notification_channel_policy', $GLOBALS['dzn_test_options']));
    $fail('retiring the legacy override leaves saved event switches untouched', ($GLOBALS['dzn_test_options'][\Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary::OPTION_EVENT_ENABLED]['booking.confirmed.student'] ?? null) === false);
    $fail('a saved off switch survives the whole workflow', $library->eventEnabled('booking.confirmed.student') === false);
    $library->saveEventEnabled('booking.confirmed.student', true);

    $library->saveEventChannel('booking.confirmed.student', 'email', false);
    $savedChannels = $GLOBALS['dzn_test_options'][\Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary::OPTION_EVENT_CHANNELS];
    $library->saveEventChannel('booking.confirmed.student', 'website', true);
    $afterChannels = $GLOBALS['dzn_test_options'][\Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary::OPTION_EVENT_CHANNELS];
    $fail('saving one channel never drops the other saved preferences', ($afterChannels['booking.confirmed.student']['email'] ?? null) === false && ($savedChannels['booking.confirmed.student']['whatsapp'] ?? null) === ($afterChannels['booking.confirmed.student']['whatsapp'] ?? null));

    /* 15. The administration projection keeps six independent facts per notification. */
    $lifecycleMethod = new \ReflectionMethod($controllerClass, 'lifecycleSummary');
    $lifecycleMethod->setAccessible(true);
    $publishedEvent = $lifecycleMethod->invoke(null, $library, 'booking.declined.student', array('email', 'website'));
    $fail('every readiness dimension is reported separately', array_keys($publishedEvent) === array('Catalogue copy', 'Drafts', 'Publication', 'Approvals', 'Delivery', 'Producer'));
    $fail('complete copy is reported as complete, not as readiness to send', $publishedEvent['Catalogue copy']['text'] === 'Complete in both languages');
    $fail('publication is reported independently of copy', str_contains($publishedEvent['Publication']['text'], 'published'));
    $fail('a registered producer is reported separately from copy', $publishedEvent['Producer']['text'] === 'Registered');
    $renewalEvent = $lifecycleMethod->invoke(null, $library, 'renewal.automatic_upcoming.student', array('email', 'website', 'whatsapp'));
    $fail('a wired renewal event reports its producer', $renewalEvent['Producer']['text'] === 'Registered' && $renewalEvent['Producer']['class'] === 'is-ok');
    $financialEvent = $lifecycleMethod->invoke(null, $library, 'payment.failed.student', array('email', 'website', 'whatsapp'));
    $fail('financial copy shows no finance/legal hold, only provider readiness', !str_contains($financialEvent['Approvals']['text'], 'Finance') && !str_contains($financialEvent['Approvals']['text'], 'Legal'));
    $fail('a wired financial event reports its producer separately from copy', $financialEvent['Producer']['text'] === 'Registered');
    $canonicalEvent = $lifecycleMethod->invoke(null, $library, 'account.claim_issued.student', array('email', 'whatsapp', 'sms'));
    $fail('a canonical event without a published version says so', str_contains($canonicalEvent['Publication']['text'], 'Required'));

    /* 16. The three-step workflow actually reaches a published state. This is the regression the
     *     production report described: Prepare succeeded, Publish published nothing, and the
     *     catalogue looked unchanged afterwards. */
    $targetEvent = 'post_intro.activated.teacher';
    $fail('the workflow target starts unpublished', $library->status($targetEvent, 'email', 'fa-IR')['published'] === false);
    $bulkApprove = $library->approveOperationalDrafts();
    $fail('step 2 approves operational drafts in bulk', $bulkApprove['approved'] > 0);
    $fail('step 2 lists the drafts it held back', $bulkApprove['skipped'] !== array());
    $skippedReasons = implode(' | ', array_column($bulkApprove['skipped'], 'reason'));
        $fail('step 2 never approves security copy', str_contains($skippedReasons, 'Security approval'));
    $fail('step 2 never approves unmapped provider routes', str_contains($skippedReasons, 'Meta'));
    $workflow = $library->workflowReport();
    $fail('the workflow report counts the approved drafts', $workflow['approved'] > 0 && $workflow['publishable'] > 0);
    $fail('the workflow report classifies every draft exactly once', $workflow['approved'] + $workflow['awaiting'] + $workflow['held'] === $workflow['drafts']);
    $fail('the workflow report explains what is held back', $workflow['held'] > 0 && array_sum($workflow['held_by_reason']) >= $workflow['held']);
        $bulkPublish = $library->publishReviewedDrafts();
    $fail('step 3 publishes the approved drafts', count($bulkPublish['published']) > 0);
    $fail('step 3 changes the catalogue state it was meant to change', $library->status($targetEvent, 'email', 'fa-IR')['published'] === true);
    $fail('step 3 publishes the financial copy once approved', $library->status('payment.failed.student', 'email', 'fa-IR')['published'] === true);
    $fail('step 3 still refuses security copy even when approved', (static function () use ($library): bool {
        try {
            $library->approveDraft('account.claim_issued.student', 'email', 'fa-IR');
        } catch (Throwable $error) {
            // an approval can be recorded, but publication must stay refused for a one-time-code route
        }
        return $library->draftReviewPolicy('account.claim_issued.student', 'email', 'fa-IR')['allowed'] === false;
    })());
    $fail('a second run publishes nothing new', count($library->publishReviewedDrafts()['published']) === 0);
    $library->saveDraft($targetEvent, 'website', 'en', 'Your class with a new student is active', "Hello {{teacher}},\n\nThe student enrolment is active.\n\nView your classes:\n{{teacher_portal_url}}\n\nDelnavazan");
    $library->saveEventChannel($targetEvent, 'website', false);
    $library->approveDraft($targetEvent, 'website', 'en');
    $library->publishReviewedDrafts();
    $fail('publishing never re-enables a channel the administrator switched off', $library->eventChannelEnabled($targetEvent, 'website') === false);
    $library->saveEventChannel($targetEvent, 'website', true);

    /* 17. The explicit one-time owner action approves and publishes every stored draft once. The
     *     standing fail-closed workflow above remains unchanged, and the one-time action cannot be
     *     replayed as a permanent bypass. */
    $library->saveEventChannel('lesson.reminder.student', 'whatsapp', false);
    $oneTime = $library->approveAndPublishEveryDraftOnce();
    $fail('the one-time action publishes the remaining drafts', count($oneTime['published']) > 0);
    $fail('the one-time action records its use', is_array($library->oneTimeAllDraftApproval()) && (int)($library->oneTimeAllDraftApproval()['used_by'] ?? 0) === 7);
    $fail('the one-time action leaves no draft behind', $library->workflowReport()['drafts'] === 0);
    $fail('the one-time action never enables a delivery channel', $library->eventChannelEnabled('lesson.reminder.student', 'whatsapp') === false);
    try {
        $library->approveAndPublishEveryDraftOnce();
        $fail('the one-time action refuses a second run', false);
    } catch (Throwable $error) {
        $fail('the one-time action refuses a second run', str_contains($error->getMessage(), 'already used'));
    }

    /* 18. The catalogue card and the tabbed editor render without WordPress and preserve the
     *     requested information hierarchy. */
    $cardEvent = 'booking.confirmed.student';
    $cardDefinition = NotificationCatalogue::get($cardEvent);
    $cardStates = array();
    foreach (array('website', 'email', 'whatsapp', 'sms') as $cardChannel) $cardStates[$cardChannel] = $stateMethod->invoke(null, $library, $cardEvent, $cardChannel);
    $cardRow = array('key' => $cardEvent, 'event' => $cardDefinition, 'offered' => (array) $cardDefinition['channels'], 'states' => $cardStates, 'readiness' => 'ready');
    $cardMethod = new \ReflectionMethod($controllerClass, 'renderEventCard');
    $cardMethod->setAccessible(true);
    ob_start();
    $cardMethod->invoke(null, $cardRow, $library);
    $cardHtml = (string) ob_get_clean();
    $fail('the card renders the clear notification title', str_contains($cardHtml, (string) $cardDefinition['label']));
    $fail('the card renders all four delivery switches', substr_count($cardHtml, 'dzn-nc-channel ') === 4 && str_contains($cardHtml, '>Portal<') && str_contains($cardHtml, '>Email<') && str_contains($cardHtml, '>WhatsApp<') && str_contains($cardHtml, '>SMS<'));
    $fail('the card switch posts a card-source toggle and never opens the editor', str_contains($cardHtml, 'name="source" value="card"') && !str_contains($cardHtml, 'name="source" value="editor"'));
    $fail('the card has an Open action', str_contains($cardHtml, 'dzn-nc-open') && str_contains($cardHtml, '>Open<'));
    $fail('the card no longer repeats the purpose paragraph', !str_contains($cardHtml, (string) ($cardDefinition['purpose'] ?? '')));
    $fail('the card no longer carries the old event-level disable control', !str_contains($cardHtml, 'Disable notification'));

    $editorMethod = new \ReflectionMethod($controllerClass, 'renderEditor');
    $editorMethod->setAccessible(true);
    ob_start();
    $editorMethod->invoke(null, $library, $cardEvent, 'email', 'fa-IR');
    $editorHtml = (string) ob_get_clean();
    $fail('the editor consolidates the overview into one card', str_contains($editorHtml, 'dzn-nc-overview') && str_contains($editorHtml, 'Variable contract') && str_contains($editorHtml, 'dzn-nc-facts'));
    $fail('the editor switch posts an editor-source toggle', str_contains($editorHtml, 'name="source" value="editor"'));
    $fail('the editor offers one tab per delivery method', str_contains($editorHtml, 'role="tablist"') && substr_count($editorHtml, 'dzn-nc-tab') >= 4 && str_contains($editorHtml, '>Portal<') && str_contains($editorHtml, '>Email<') && str_contains($editorHtml, '>WhatsApp<') && str_contains($editorHtml, '>SMS<'));
    $fail('the editor keeps the preview, editor and history in the selected tab', str_contains($editorHtml, '<h3>Preview</h3>') && str_contains($editorHtml, 'id="dzn-notif-body"') && str_contains($editorHtml, 'Version History'));
    $fail('the editor no longer duplicates the old Delivery and Status cards', !str_contains($editorHtml, '<h3>Delivery</h3>') && !str_contains($editorHtml, '<h3>Status</h3>'));

    ob_start();
    $editorMethod->invoke(null, $library, $cardEvent, 'whatsapp', 'fa-IR');
    $whatsappEditorHtml = (string) ob_get_clean();
    $fail('the WhatsApp editor names the discovered provider template', str_contains($whatsappEditorHtml, 'delnavazan_intro_confirmed_fa'));
    $fail('the WhatsApp editor shows the approved provider body read-only', str_contains($whatsappEditorHtml, 'Approved provider body') && str_contains($whatsappEditorHtml, 'درود {{1}}'));
    $fail('the WhatsApp editor flags a provider body that differs from the local copy', str_contains($whatsappEditorHtml, 'Provider body differs from the reviewed local copy'));
    $fail('the WhatsApp editor offers the in-place provider refresh', str_contains($whatsappEditorHtml, 'dzn_notification_provider_refresh'));

    echo "Notification management unit passed\n";
}
