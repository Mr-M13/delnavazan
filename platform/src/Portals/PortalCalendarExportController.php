<?php
namespace Delnavazan\Platform\Portals;

/** Authenticated, owner-scoped calendar download for Student and Teacher portals. */
final class PortalCalendarExportController {
    public static function register(): void {
        add_action('admin_post_dzn_portal_calendar_export', array(__CLASS__, 'download'));
        // WordPress dispatches anonymous admin-post requests to a separate hook. Register it so
        // the authentication redirect below is actually reached instead of returning a blank page.
        add_action('admin_post_nopriv_dzn_portal_calendar_export', array(__CLASS__, 'download'));
    }

    public static function download(): never {
        if (!is_user_logged_in()) {
            auth_redirect();
            exit;
        }
        $role = sanitize_key((string) ($_GET['role'] ?? ''));
        $source = sanitize_key((string) ($_GET['source'] ?? ''));
        $eventId = trim(sanitize_text_field((string) ($_GET['event'] ?? '')));
        try {
            $action = PortalCalendarExportService::nonceAction($role, $source, $eventId);
            if (!wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), $action)) {
                throw new \InvalidArgumentException('calendar_export_invalid_nonce');
            }
            self::assertRateLimit();
            $reader = new PortalAuthenticatedReadService();
            $read = $role === 'teacher' ? $reader->teacher() : $reader->student();
            $download = (new PortalCalendarExportService())->export($read, $role, $source, $eventId);
            nocache_headers();
            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $download['filename'] . '"');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            echo $download['content'];
            exit;
        } catch (\Throwable) {
            $path = $role === 'teacher' ? '/teacher-portal/' : '/student-portal/';
            $query = $role === 'teacher'
                ? array('teacher-view' => 'home', 'calendar_status' => 'export_unavailable')
                : array('portal-view' => 'home', 'calendar_status' => 'export_unavailable');
            wp_safe_redirect(add_query_arg($query, home_url($path)));
            exit;
        }
    }

    /** Bound inexpensive downloads per authenticated account without weakening owner scoping. */
    private static function assertRateLimit(): void {
        $key = 'dzn_calendar_export_rate_' . (int) get_current_user_id();
        $count = (int) get_transient($key);
        if ($count >= 30) throw new \InvalidArgumentException('calendar_export_rate_limited');
        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
    }
}
