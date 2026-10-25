<?php
/* Executable controller regression tests, run in isolated CLI children because the controller exits. */
namespace Delnavazan\Platform\Portals {
    function add_action($hook, $callback): void { $GLOBALS['dzn_calendar_actions'][$hook] = $callback; }
    function is_user_logged_in(): bool { return (bool) ($GLOBALS['dzn_calendar_case']['logged_in'] ?? false); }
    function auth_redirect(): void { $GLOBALS['dzn_calendar_result']['redirect'] = 'auth'; }
    function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-z0-9_-]/', '', $value) ?? ''); }
    function sanitize_text_field(string $value): string { return $value; }
    function wp_verify_nonce(string $nonce, string $action): bool { return $nonce === 'valid'; }
    function get_current_user_id(): int { return 42; }
    function get_transient(string $key): int { return (int) ($GLOBALS['dzn_calendar_transients'][$key] ?? 0); }
    function set_transient(string $key, int $value, int $ttl): bool { $GLOBALS['dzn_calendar_transients'][$key] = $value; return true; }
    function nocache_headers(): void { $GLOBALS['dzn_calendar_result']['headers'][] = 'Cache-Control: no-store'; }
    function header(string $value): void { $GLOBALS['dzn_calendar_result']['headers'][] = $value; }
    function home_url(string $path): string { return 'https://delnavazan.test' . $path; }
    function add_query_arg(array $query, string $url): string { return $url . '?' . http_build_query($query); }
    function wp_safe_redirect(string $url): void { $GLOBALS['dzn_calendar_result']['redirect'] = $url; }

    final class PortalAuthenticatedReadService {
        public function teacher(): array { $GLOBALS['dzn_calendar_result']['reads'][] = 'teacher'; return (array) ($GLOBALS['dzn_calendar_case']['teacher'] ?? array()); }
        public function student(): array { $GLOBALS['dzn_calendar_result']['reads'][] = 'student'; return (array) ($GLOBALS['dzn_calendar_case']['student'] ?? array()); }
    }
}

namespace {
    if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
    $root = dirname(__DIR__);
    if (($argv[1] ?? '') === '--case') {
        $GLOBALS['dzn_calendar_case'] = json_decode(base64_decode((string) ($argv[2] ?? ''), true) ?: '', true, 512, JSON_THROW_ON_ERROR);
        $GLOBALS['dzn_calendar_result'] = array('headers' => array(), 'reads' => array());
        $GLOBALS['dzn_calendar_transients'] = array();
        ob_start();
        register_shutdown_function(static function (): void {
            $GLOBALS['dzn_calendar_result']['body'] = ob_get_clean();
            echo "\n__DZN_CALENDAR_RESULT__" . json_encode($GLOBALS['dzn_calendar_result'], JSON_UNESCAPED_SLASHES) . "\n";
        });
        require_once $root . '/src/Portals/PortalCalendarExportService.php';
        require_once $root . '/src/Portals/PortalCalendarExportController.php';
        $_GET = (array) ($GLOBALS['dzn_calendar_case']['get'] ?? array());
        \Delnavazan\Platform\Portals\PortalCalendarExportController::download();
    }

    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
    require_once $root . '/src/Portals/PortalCalendarExportService.php';
    require_once $root . '/src/Portals/PortalCalendarExportController.php';
    \Delnavazan\Platform\Portals\PortalCalendarExportController::register();
    $assert(isset($GLOBALS['dzn_calendar_actions']['admin_post_dzn_portal_calendar_export'])
        && isset($GLOBALS['dzn_calendar_actions']['admin_post_nopriv_dzn_portal_calendar_export']), 'Both authenticated and anonymous admin-post hooks must reach the controller.');

    $event = array('version' => 'student_booking_class_v1', 'confirmation_uid' => '01KABCDEF0123456789ABCDEFX', 'course_reference' => 'Setar', 'teacher_display_reference' => 'Teacher A', 'starts_at_utc' => '2026-11-01 08:30:00', 'ends_at_utc' => '2026-11-01 09:15:00', 'schedule_timezone' => 'Australia/Brisbane', 'confirmation_state' => 'confirmed');
    $base = array('logged_in' => true, 'student' => array('booking_classes' => array($event)), 'teacher' => array('booking_classes' => array($event)));
    $run = static function (array $case): array {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --case ' . escapeshellarg(base64_encode(json_encode($case, JSON_THROW_ON_ERROR)));
        exec($command, $lines, $status);
        $line = end($lines);
        if ($status !== 0 || !is_string($line) || !str_starts_with($line, '__DZN_CALENDAR_RESULT__')) throw new RuntimeException('Controller child did not report a result.');
        return json_decode(substr($line, strlen('__DZN_CALENDAR_RESULT__')), true, 512, JSON_THROW_ON_ERROR);
    };
    $query = array('role' => 'student', 'source' => 'booking', 'event' => $event['confirmation_uid'], '_wpnonce' => 'valid');

    $valid = $run($base + array('get' => $query));
    $assert($valid['reads'] === array('student'), 'A valid request must use only the selected owner-scoped read.');
    foreach (array('Cache-Control: no-store', 'Content-Type: text/calendar; charset=utf-8', 'Content-Disposition: attachment; filename="delnavazan-calendar.ics"', 'X-Content-Type-Options: nosniff', 'Referrer-Policy: no-referrer') as $header) $assert(in_array($header, $valid['headers'], true), 'Missing response header: ' . $header);
    $assert(str_contains($valid['body'], 'BEGIN:VCALENDAR') && str_contains($valid['body'], 'CLASS:PRIVATE'), 'A valid request must return one private ICS file.');

    $badNonce = $run($base + array('get' => array_merge($query, array('_wpnonce' => 'invalid'))));
    $assert($badNonce['reads'] === array() && str_contains((string) ($badNonce['redirect'] ?? ''), 'calendar_status=export_unavailable'), 'Nonce failure must not read or export a calendar event.');
    $unowned = $run($base + array('get' => array_merge($query, array('event' => '01KNOTOWNED000000000000000'))));
    $assert($unowned['reads'] === array('student') && str_contains((string) ($unowned['redirect'] ?? ''), 'calendar_status=export_unavailable'), 'An unowned event must fail after the owner-scoped read.');
    $roleTamper = $run($base + array('get' => array_merge($query, array('role' => 'teacher'))));
    $assert($roleTamper['reads'] === array('teacher') && str_contains((string) ($roleTamper['redirect'] ?? ''), 'calendar_status=export_unavailable'), 'Role tampering must not export a differently typed event.');
    $sourceTamper = $run($base + array('get' => array_merge($query, array('source' => 'lesson'))));
    $assert($sourceTamper['reads'] === array('student') && str_contains((string) ($sourceTamper['redirect'] ?? ''), 'calendar_status=export_unavailable'), 'Source tampering must not export a booking as a lesson.');
    $signedOut = $run(array('logged_in' => false, 'get' => $query));
    $assert(($signedOut['redirect'] ?? '') === 'auth' && $signedOut['reads'] === array(), 'Anonymous admin-post handling must redirect before reading or exporting.');

    echo "Portal calendar export controller unit passed\n";
}
