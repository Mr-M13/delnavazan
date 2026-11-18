<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\Diagnostics\DiagnosticRegistry;
use Delnavazan\Platform\Core\Application\DiagnosticsReadService;
use Delnavazan\Platform\Core\Application\NotificationDiagnosticsReadService;
use Delnavazan\Platform\Core\Application\NotificationManualDeliveryService;
use Delnavazan\Platform\Core\Application\SupportBundleService;

/**
 * Settings → Diagnostics: one central, expandable troubleshooting centre.
 *
 * The summary is cached briefly and derived from real checks. Category detail is loaded lazily
 * through an authenticated AJAX request, so opening Settings does not run every query. A failed
 * check is rendered as unknown or failed and never promoted to healthy.
 *
 * Read-only evidence and state-changing recovery actions are visually separated. Recovery links
 * point at the existing authority; this controller never implements a second operational workflow.
 */
final class DiagnosticsController {
    public const PAGE = 'dzn-system-health';
    public const CAPABILITY = 'dzn_view_diagnostics';
    public const ACTION_BUNDLE = 'dzn_diagnostics_support_bundle';
    public const AJAX_CATEGORY = 'dzn_diagnostics_category';
    public const ACTION_RETRY = 'dzn_notification_retry_exact';

    public static function register(): void {
        add_action('admin_post_' . self::ACTION_BUNDLE, [self::class, 'downloadBundle']);
        add_action('admin_post_' . self::AJAX_CATEGORY, [self::class, 'ajaxCategory']);
        add_action('admin_post_' . self::ACTION_RETRY, [self::class, 'retryExactNotification']);
    }

    public static function screen(): void {
        if (!current_user_can('manage_options') && !current_user_can(self::CAPABILITY)) wp_die('Forbidden', 403);
        $registry = new DiagnosticRegistry();
        $fresh = isset($_GET['dzn_refresh']) && (string) $_GET['dzn_refresh'] === '1';
        if ($fresh) {
            check_admin_referer('dzn_diagnostics_refresh');
            $registry->invalidateSummary();
        }
        try { $summary = $registry->summary($fresh); }
        catch (\Throwable) { echo '<div class="wrap"><h1>Diagnostics</h1><p>Diagnostics could not be read for this account. No check was reported as healthy.</p></div>'; return; }
        $window = isset($_GET['window']) && is_scalar($_GET['window']) ? sanitize_key(wp_unslash((string) $_GET['window'])) : '24h';
        $severity = isset($_GET['severity']) && is_scalar($_GET['severity']) ? sanitize_key(wp_unslash((string) $_GET['severity'])) : '';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $component = isset($_GET['component']) && is_scalar($_GET['component']) ? sanitize_key(wp_unslash((string) $_GET['component'])) : '';

        echo '<div class="wrap dzn-settings-page dzn-diagnostics-page" dir="ltr">';
        echo '<h1>Diagnostics</h1>';
        echo '<p class="dzn-settings-lede">Central technical troubleshooting for staff and support assistants. Every status comes from a real check. Evidence is aggregate and redacted: no credentials, message bodies, contact details, financial amounts or raw provider payloads are shown.</p>';
        self::renderOverview($summary, $fresh);
        if ($component === 'notifications') self::renderNotificationAttention();
        self::renderCategories($summary, $component);
        self::renderNotificationRegister();
        self::renderErrors($window, $severity, $paged);
        self::renderSupport($summary);
        self::renderRecoveryActions();
        self::renderScripts();
        echo '</div>';
    }

    /**
     * The landing card behind Overview → Notification delivery failures.
     *
     * The legacy Communications page and every notification deep link target
     * `?component=notifications`. This card explains each failure class and links
     * to the register or the recovery action that resolves it; it never sends.
     */
    private static function renderNotificationAttention(): void {
        if (!current_user_can('dzn_view_notification_authority') && !current_user_can('manage_options')) return;
        try { $report = (new NotificationDiagnosticsReadService())->report(); }
        catch (\Throwable) { $report = array(); }
        $classes = array(
            'retry_exhausted' => array('Retry exhausted', 'Open the owning record and retry the exact notification when its window still allows it. A retry is bound to one existing notification and never changes the recipient or template.'),
            'retry_window_exhausted' => array('Send window expired (closed)', 'The permitted window closed. This is a closed outcome and is not counted as open work on the Overview. Review the record only when a replacement message is now justified.'),
            'stuck_lease' => array('Stuck delivery lease', 'Use Recover expired leases below. It releases only expired leases and creates no message.'),
            'orphan_outbox_row' => array('Queue row without a notification', 'A delivery queue row points at a missing notification. Copy the support bundle below and record an operational exception; this is queue damage, not a resend.'),
            'unregistered_intent' => array('Queued event with no registered route', 'A pending outbox row names an event this delivery authority does not own. Review the support bundle with the platform owner; do not re-send blindly.'),
            'unroutable_intent' => array('Event without an active route (configuration)', 'This is a routing configuration gap, not a queued message. Enable or configure the event under Notifications → Events before it is used.'),
        );
        echo '<section class="dzn-settings-card" id="notification-attention"><h2>Notification delivery attention</h2>';
        echo '<p class="dzn-settings-meta">A failed notification, a lost delivery lease, an unregistered queued event or a queue row without a notification is an open delivery failure. An expired send window is a closed outcome, and an event without an active route is a configuration gap; both are shown here but do not keep the Overview warning open.</p>';
        echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>Class</th><th>Count</th><th>What resolves it</th></tr></thead><tbody>';
        foreach ($classes as $key => $definition) {
            $count = (int) ($report[$key] ?? 0);
            $state = $count > 0 ? '<span class="dzn-status-badge is-warn">' . esc_html(number_format_i18n($count)) . '</span>' : '<span class="dzn-status-badge is-ok">0</span>';
            echo '<tr><td><strong>' . esc_html($definition[0]) . '</strong><br><code dir="ltr">' . esc_html($key) . '</code></td><td>' . $state . '</td><td>' . esc_html($definition[1]) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><a class="button" href="#notification-register">Open delivery register</a> <a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-notifications')) . '">Open Notifications events</a> <a class="button" href="#diagnostic-support">Open support bundle</a> <a class="button" href="#diagnostic-recovery">Recovery actions</a></p>';
        echo '</section>';
    }

    private static function renderOverview(array $summary, bool $fresh): void {
        $overall = (string) ($summary['overall'] ?? 'unknown');
        $labels = [
            'healthy' => 'Healthy',
            'degraded' => 'Degraded',
            'failed' => 'Failed',
            'unknown' => 'Unknown',
            'not_configured' => 'Not configured',
        ];
        echo '<section class="dzn-settings-card" id="diagnostics-overview"><div class="dzn-settings-card__head"><div><h2>System health summary</h2><p class="dzn-settings-meta">Last diagnostic check: <code dir="ltr">' . esc_html((string) ($summary['generated_at'] ?? '')) . '</code></p></div>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="display:inline"><input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '"><input type="hidden" name="dzn_refresh" value="1">';
        wp_nonce_field('dzn_diagnostics_refresh');
        echo '<button class="button" type="submit">Refresh checks</button></form></div>';
        echo '<div class="dzn-diagnostics-overview">';
        $cards = [
            'Overall health' => $labels[$overall] ?? 'Unknown',
            'Critical issues' => (string) (int) ($summary['critical'] ?? 0),
            'Warnings' => (string) (int) ($summary['warning'] ?? 0),
            'Healthy subsystems' => (string) (int) ($summary['healthy'] ?? 0),
            'Unknown / unavailable' => (string) (int) ($summary['unknown'] ?? 0) . ' / ' . (string) (int) ($summary['not_configured'] ?? 0),
            'Pending recovery actions' => (string) (int) ($summary['recovery'] ?? 0),
        ];
        foreach ($cards as $label => $value) {
            $class = $label === 'Overall health' ? self::badgeClass($overall) : '';
            echo '<div class="dzn-diagnostics-stat"><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong>';
            if ($class !== '') echo ' <span class="dzn-status-badge ' . esc_attr($class) . '">' . esc_html($value) . '</span>';
            echo '</div>';
        }
        echo '</div><p class="dzn-settings-note">Statuses are derived from the checks below. A check that could not complete is reported as unknown, not healthy. The summary is cached for up to one minute; use Refresh checks for an immediate re-run.</p></section>';
    }

    private static function renderCategories(array $summary, string $component = ''): void {
        echo '<section class="dzn-settings-card" id="diagnostic-categories"><h2>Diagnostic categories</h2><p class="dzn-settings-note">All categories are collapsed by default. Expand one to run and inspect its checks. Filtering and detail stay inside the category so the page remains scannable.</p><div class="dzn-diagnostics-accordion">';
        foreach ((array) ($summary['categories'] ?? []) as $key => $category) {
            $state = (string) ($category['summary']['overall'] ?? 'unknown');
            $counts = (array) ($category['summary'] ?? []);
            $isTarget = $component !== '' && (string) $key === $component;
            echo '<details class="dzn-diagnostics-category" data-dzn-diagnostic-category="' . esc_attr((string) $key) . '" data-dzn-endpoint="' . esc_url(admin_url('admin-post.php')) . '" id="diagnostic-category-' . esc_attr((string) $key) . '"' . ($isTarget ? ' open' : '') . '>';
            echo '<summary><span class="dzn-diagnostics-category__title"><strong>' . esc_html((string) $category['label']) . '</strong><span class="dzn-settings-meta">' . esc_html((string) $category['description']) . '</span></span>';
            echo '<span class="dzn-diagnostics-category__status"><span class="dzn-status-badge ' . esc_attr(self::badgeClass($state)) . '">' . esc_html(ucfirst(str_replace('_', ' ', $state))) . '</span><span class="dzn-settings-meta">' . (int) ($counts['healthy'] ?? 0) . ' healthy · ' . (int) ($counts['degraded'] ?? 0) . ' warning · ' . (int) ($counts['failed'] ?? 0) . ' failed · ' . ((int) ($counts['unknown'] ?? 0) + (int) ($counts['not_configured'] ?? 0)) . ' unknown</span></span></summary>';
            echo '<div class="dzn-diagnostics-category__body" data-dzn-diagnostic-body><p class="dzn-settings-meta">Expand to load diagnostics for this category.</p></div></details>';
        }
        echo '</div></section>';
    }

    /**
     * Safe notification failure/retry register. It exposes intent, audience, lifecycle state,
     * safe reason codes and retry evidence only. Message bodies, contact values, credentials
     * and raw provider payloads are never selected.
     */
    private static function renderNotificationRegister(): void {
        if (!current_user_can('dzn_view_notification_authority') && !current_user_can('manage_options')) return;
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) return;
        $key = isset($_GET['nkey']) && is_scalar($_GET['nkey']) ? sanitize_key(wp_unslash((string) $_GET['nkey'])) : '';
        $audience = isset($_GET['naudience']) && is_scalar($_GET['naudience']) ? sanitize_key(wp_unslash((string) $_GET['naudience'])) : '';
        $state = isset($_GET['nstate']) && is_scalar($_GET['nstate']) ? sanitize_key(wp_unslash((string) $_GET['nstate'])) : '';
        $subject = isset($_GET['subject']) && is_scalar($_GET['subject']) ? sanitize_key(wp_unslash((string) $_GET['subject'])) : '';
        $subjectId = isset($_GET['subject_id']) && is_scalar($_GET['subject_id']) ? absint($_GET['subject_id']) : 0;
        if (!in_array($subject, array('booking_request', 'lesson'), true) || $subjectId < 1) { $subject = ''; $subjectId = 0; }
        $days = isset($_GET['ndays']) && is_scalar($_GET['ndays']) ? absint($_GET['ndays']) : 7;
        $days = in_array($days, array(0, 1, 7, 30), true) ? $days : 7;
        $paged = isset($_GET['npaged']) ? max(1, absint($_GET['npaged'])) : 1;
        $retryResult = isset($_GET['retry_result']) && is_scalar($_GET['retry_result']) ? sanitize_key(wp_unslash((string) $_GET['retry_result'])) : '';
        $perPage = 20;
        $table = $wpdb->prefix . 'dzn_notifications';
        $attempts = $wpdb->prefix . 'dzn_notification_attempts';
        $deliveries = $wpdb->prefix . 'dzn_notification_deliveries';
        $where = array();
        $args = array();
        if ($days > 0) { $where[] = 'n.created_at >= UTC_TIMESTAMP() - INTERVAL %d DAY'; $args[] = $days; }
        if ($key !== '') { $where[] = 'n.intent_key LIKE %s'; $args[] = '%' . $wpdb->esc_like($key) . '%'; }
        if ($audience !== '') { $where[] = 'n.audience=%s'; $args[] = $audience; }
        if ($state !== '') { $where[] = 'n.state=%s'; $args[] = $state; }
        if ($subject !== '') { $where[] = 'n.subject_aggregate=%s AND n.subject_aggregate_id=%d'; $args[] = $subject; $args[] = $subjectId; }
        if (!$where) $where[] = '1=1';
        $whereSql = implode(' AND ', $where);
        try {
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} n WHERE {$whereSql}", ...$args));
            $offset = ($paged - 1) * $perPage;
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT n.id,n.intent_key,n.audience,n.subject_aggregate,n.subject_aggregate_id,n.state,n.failure_reason_code,n.created_at,n.updated_at,
                    (SELECT COUNT(*) FROM {$attempts} a WHERE a.notification_id=n.id) AS attempt_count,
                    (SELECT a.state FROM {$attempts} a WHERE a.notification_id=n.id ORDER BY a.attempt_sequence DESC LIMIT 1) AS last_attempt_state,
                    (SELECT a.outcome_code FROM {$attempts} a WHERE a.notification_id=n.id ORDER BY a.attempt_sequence DESC LIMIT 1) AS last_outcome_code,
                    (SELECT d.delivery_state FROM {$deliveries} d WHERE d.notification_id=n.id ORDER BY d.delivery_sequence DESC LIMIT 1) AS delivery_state
                 FROM {$table} n WHERE {$whereSql} ORDER BY n.id DESC LIMIT %d OFFSET %d",
                ...array_merge($args, array($perPage, $offset))
            )) ?: array();
        } catch (\Throwable) {
            echo '<section class="dzn-settings-card" id="notification-register"><h2>Notification delivery register</h2><p>The notification register is unavailable. It is reported as unknown, not healthy.</p></section>';
            return;
        }
        $pages = max(1, (int) ceil($total / $perPage));
        echo '<section class="dzn-settings-card" id="notification-register"><h2>Notification delivery register</h2>';
        echo '<p class="dzn-settings-meta">Failure and retry evidence for notifications. Contact values, message bodies, credentials and raw provider payloads are not selected. A retry is bound to one exact notification and is performed from its owning record or from the row below; this register never composes a message.</p>';
        if ($retryResult !== '') {
            $retryMessage = self::retryResultMessage($retryResult);
            $notice = in_array($retryResult, array('queued', 'replayed'), true) ? 'notice-success' : 'notice-warning';
            echo '<div class="notice ' . esc_attr($notice) . ' inline" role="status"><p>' . esc_html($retryMessage) . '</p></div>';
        }
        if ($subject !== '') {
            echo '<div class="notice notice-info inline"><p>Filtered to <strong>' . esc_html($subject === 'booking_request' ? 'booking request' : 'class') . ' #' . (int) $subjectId . '</strong>. <a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE . '&component=notifications#notification-register')) . '">Clear record filter</a></p></div>';
        }
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="dzn-settings-filter"><input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '">';
        if ($subject !== '') { echo '<input type="hidden" name="subject" value="' . esc_attr($subject) . '"><input type="hidden" name="subject_id" value="' . esc_attr((string) $subjectId) . '">'; }
        echo '<label>Notification key <input type="search" name="nkey" value="' . esc_attr($key) . '" placeholder="intent key"></label> ';
        echo '<label>Audience <select name="naudience"><option value="">All</option>';
        foreach (['student' => 'Student', 'teacher' => 'Teacher', 'guardian' => 'Guardian', 'staff' => 'Staff'] as $value => $label) echo '<option value="' . esc_attr($value) . '"' . selected($audience, $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label> <label>Status <select name="nstate"><option value="">All</option>';
        foreach (['pending' => 'Pending', 'scheduled' => 'Scheduled', 'delivered' => 'Delivered', 'failed' => 'Failed', 'expired' => 'Expired', 'suppressed' => 'Suppressed'] as $value => $label) echo '<option value="' . esc_attr($value) . '"' . selected($state, $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label> <label>Window <select name="ndays">';
        foreach ([1 => '24 hours', 7 => '7 days', 30 => '30 days', 0 => 'All time'] as $value => $label) echo '<option value="' . $value . '"' . selected($days, $value, false) . '>' . $label . '</option>';
        echo '</select></label> <button class="button">Filter</button></form>';
        if ($rows === array()) { echo '<p>No notification matches these filters.</p></section>'; return; }
        $canRetry = current_user_can('dzn_operate_notification_dispatch');
        echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>Notification</th><th>Key</th><th>Audience</th><th>Owning record</th><th>State</th><th>Attempts</th><th>Last outcome</th><th>Delivery</th><th>Failure reason</th><th>Action</th><th>Created (UTC)</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $aggregate = (string) $row->subject_aggregate;
            $aggregateId = (int) $row->subject_aggregate_id;
            $recordUrl = self::notificationRecordUrl($aggregate, $aggregateId);
            echo '<tr><td><code>#' . (int) $row->id . '</code></td><td><code dir="ltr">' . esc_html((string) $row->intent_key) . '</code></td><td>' . esc_html((string) $row->audience) . '</td>';
            echo '<td>' . ($recordUrl !== ''
                ? '<a href="' . esc_url($recordUrl) . '">' . esc_html($aggregate) . ' #' . $aggregateId . '</a>'
                : esc_html($aggregate) . ' #' . $aggregateId) . '</td>';
            echo '<td>' . esc_html((string) $row->state) . '</td><td>' . (int) $row->attempt_count . ' · ' . esc_html((string) ($row->last_attempt_state ?? '—')) . '</td>';
            echo '<td>' . esc_html((string) ($row->last_outcome_code ?? '—')) . '</td><td>' . esc_html((string) ($row->delivery_state ?? '—')) . '</td>';
            echo '<td><code dir="ltr">' . esc_html((string) ($row->failure_reason_code ?? '—')) . '</code></td><td>';
            $retryable = (string) $row->state === 'failed';
            if ($retryable && $canRetry) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr(self::ACTION_RETRY) . '"><input type="hidden" name="notification_id" value="' . (int) $row->id . '"><input type="hidden" name="nstate" value="' . esc_attr($state) . '">';
                wp_nonce_field(self::ACTION_RETRY);
                echo '<button class="button button-small" type="submit" onclick="return confirm(\'Queue one exact retry for this notification? The recipient, template and send window are re-checked; nothing else is sent.\')">Retry exact notification</button></form>';
            } elseif ($retryable) {
                echo '<span class="dzn-settings-meta">Requires the notification dispatch authority.</span>';
            } elseif ((string) $row->state === 'expired') {
                echo '<span class="dzn-settings-meta">Window closed — no retry.</span>';
            } else {
                echo '—';
            }
            echo '</td><td><code dir="ltr">' . esc_html((string) $row->created_at) . '</code></td></tr>';
        }
        echo '</tbody></table><p class="dzn-settings-meta">Page ' . (int) $paged . ' of ' . (int) $pages . ' · ' . (int) $total . ' notification(s). A retry re-arms one existing notification only and never changes its recipient, template or subject. The owning record remains the place to review the message before deciding whether a retry is still meaningful.</p>';
        if ($pages > 1) {
            $base = array('page' => self::PAGE, 'nkey' => $key, 'naudience' => $audience, 'nstate' => $state, 'ndays' => $days);
            if ($subject !== '') { $base['subject'] = $subject; $base['subject_id'] = $subjectId; }
            echo '<p>';
            if ($paged > 1) echo '<a class="button button-small" href="' . esc_url(add_query_arg($base + array('npaged' => $paged - 1), admin_url('admin.php'))) . '">Previous</a> ';
            if ($paged < $pages) echo '<a class="button button-small" href="' . esc_url(add_query_arg($base + array('npaged' => $paged + 1), admin_url('admin.php'))) . '">Next</a>';
            echo '</p>';
        }
        echo '</section>';
    }

    /** The record that owns a notification and offers its exact, bounded retry. */
    private static function notificationRecordUrl(string $aggregate, int $id): string {
        if ($id < 1) return '';
        if ($aggregate === 'booking_request') {
            $canCoordinate = current_user_can('dzn_manage_booking_request_coordination') && current_user_can('dzn_view_booking_requests');
            return $canCoordinate
                ? admin_url('admin.php?page=dzn-booking-request-coordination&booking_request_id=' . $id . '#communications')
                : admin_url('admin.php?page=dzn-booking-requests&request_id=' . $id);
        }
        return match ($aggregate) {
            'lesson' => admin_url('admin.php?page=dzn-lesson&id=' . $id),
            'student' => admin_url('admin.php?page=dzn-student&id=' . $id),
            'teacher' => admin_url('admin.php?page=dzn-teachers-menu&id=' . $id),
            'enrolment' => admin_url('admin.php?page=dzn-enrolment&id=' . $id),
            'term' => admin_url('admin.php?page=dzn-term&id=' . $id),
            'payment_obligation' => admin_url('admin.php?page=dzn-finance&tab=payments'),
            default => '',
        };
    }

    private static function renderErrors(string $window, string $severity, int $paged = 1): void {
        $service = new DiagnosticsReadService();
        try { $report = $service->report($window, 'exceptions', $severity); }
        catch (\Throwable) { $report = ['errors' => [], 'window' => $window]; }
        echo '<section class="dzn-settings-card" id="recent-errors"><h2>Recent structured errors</h2>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="dzn-settings-filter"><input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '"><label>Time <select name="window">';
        foreach (DiagnosticsReadService::WINDOWS as $key => $label) echo '<option value="' . esc_attr($key) . '"' . selected($window, $key, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label> <label>Severity <select name="severity"><option value="">All</option>';
        foreach (['info' => 'Info', 'warning' => 'Warning', 'error' => 'Error'] as $key => $label) echo '<option value="' . esc_attr($key) . '"' . selected($severity, $key, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label> <button class="button">Apply filters</button></form>';
        $errors = (array) ($report['errors'] ?? []);
        if ($errors === []) { echo '<p>No structured operational exception was recorded in this window.</p></section>'; return; }
        $perPage = 20;
        $total = count($errors);
        $pages = max(1, (int) ceil($total / $perPage));
        $paged = min(max(1, $paged), $pages);
        echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>Detected (UTC)</th><th>Component</th><th>Error code</th><th>Severity</th><th>Status</th><th>Entity type</th></tr></thead><tbody>';
        foreach (array_slice($errors, ($paged - 1) * $perPage, $perPage) as $error) {
            echo '<tr><td><code dir="ltr">' . esc_html((string) ($error['detected_at'] ?? '')) . '</code></td><td>' . esc_html((string) ($error['component'] ?? '')) . '</td><td><code dir="ltr">' . esc_html((string) ($error['error_code'] ?? '')) . '</code></td><td>' . esc_html((string) ($error['severity'] ?? '')) . '</td><td>' . esc_html((string) ($error['status'] ?? '')) . '</td><td>' . esc_html((string) ($error['entity_type'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="dzn-settings-meta">Page ' . (int) $paged . ' of ' . (int) $pages . ' · ' . (int) $total . ' record(s). Free-form details and personal data are deliberately omitted.</p>';
        if ($pages > 1) {
            echo '<p>';
            if ($paged > 1) echo '<a class="button button-small" href="' . esc_url(add_query_arg(['page' => self::PAGE, 'window' => $window, 'severity' => $severity, 'paged' => $paged - 1], admin_url('admin.php'))) . '">Previous</a> ';
            if ($paged < $pages) echo '<a class="button button-small" href="' . esc_url(add_query_arg(['page' => self::PAGE, 'window' => $window, 'severity' => $severity, 'paged' => $paged + 1], admin_url('admin.php'))) . '">Next</a>';
            echo '</p>';
        }
        echo '</section>';
    }

    private static function renderSupport(array $summary): void {
        echo '<section class="dzn-settings-card" id="diagnostic-support"><h2>Copy or download diagnostic evidence</h2>';
        echo '<p>The report contains check states, technical codes, versions, counts and audit references only. It is sanitised before download and contains no credentials, tokens, message bodies or personal data.</p>';
        echo '<label for="dzn-support-summary"><strong>Sanitised diagnostic report</strong></label>';
        echo '<textarea id="dzn-support-summary" class="large-text code" rows="10" readonly>' . esc_textarea(self::supportSummary($summary)) . '</textarea>';
        echo '<p><button type="button" class="button" data-dzn-copy-support>Copy report</button> ';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline"><input type="hidden" name="action" value="' . esc_attr(self::ACTION_BUNDLE) . '">';
        wp_nonce_field(self::ACTION_BUNDLE);
        echo '<button class="button button-primary" type="submit">Download support bundle</button></form></p></section>';
    }

    private static function renderRecoveryActions(): void {
        if (!current_user_can('dzn_operate_notification_dispatch') && !current_user_can('manage_options')) return;
        echo '<section class="dzn-settings-card" id="diagnostic-recovery"><h2>State-changing recovery actions</h2>';
        echo '<p class="dzn-settings-note">These actions are separate from read-only diagnostics. Each one requires its own permission, validates current eligibility and records an audit event through the existing authority. Diagnostics never silently retries a payment, notification, booking or consent record.</p>';
        echo '<p><strong>Recover expired notification leases.</strong> This releases only expired leases under the existing retry policy. It creates no message and changes no recipient, booking, lesson or payment.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="dzn_notification_recover_leases">';
        wp_nonce_field('dzn_notification_recover_leases');
        echo '<p><button class="button" type="submit" onclick="return confirm(\'Recover expired notification leases now?\')">Recover expired leases</button></p></form>';
        echo '<p class="dzn-settings-note">A failed row in the delivery register also offers one explicit, idempotent retry of that exact notification when the existing dispatch authority still permits it. An expired send window is shown as closed and is not re-offered. The register never composes a new message.</p>';
        if (current_user_can('dzn_view_portal_capabilities')) echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-portal-capabilities')) . '">Portal capability controls</a></p>';
        if (current_user_can('dzn_access_core_workflow')) echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-core-dataset-readiness')) . '">Advanced recovery controls</a></p>';
        if (current_user_can('dzn_manage_exceptions')) echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-exception')) . '">Open operational exceptions</a></p>';
        echo '</section>';
    }

    private static function renderScripts(): void {
        $nonce = wp_create_nonce(self::AJAX_CATEGORY);
        $action = self::AJAX_CATEGORY;
        echo <<<HTML
<script>
(function(){
  var nonce = '{$nonce}', action = '{$action}';
  function loadCategory(details){
    if (details.dataset.dznLoaded === '1') return;
    var body = details.querySelector('[data-dzn-diagnostic-body]');
    if (!body) return;
    details.dataset.dznLoaded = '1';
    body.innerHTML = '<p class="dzn-settings-meta">Running checks…</p>';
    var data = new URLSearchParams();
    data.set('action', action); data.set('_wpnonce', nonce); data.set('category', details.dataset.dznDiagnosticCategory);
    fetch(details.dataset.dznEndpoint, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:data.toString()})
      .then(function(response){ return response.json(); })
      .then(function(payload){
        if (!payload || !payload.success || !payload.data || !payload.data.html) throw new Error('diagnostic_load_failed');
        body.innerHTML = payload.data.html;
      })
      .catch(function(){ details.dataset.dznLoaded = ''; body.innerHTML = '<div class="notice notice-error inline"><p>This category could not load. It is reported as unknown, not healthy.</p></div>'; });
  }
  document.querySelectorAll('[data-dzn-diagnostic-category]').forEach(function(details){
    details.addEventListener('toggle', function(){
      if (details.open) loadCategory(details);
    });
    // A component deep link (for example ?component=notifications) renders the
    // category open; run its checks immediately instead of waiting for a toggle.
    if (details.open) loadCategory(details);
  });
  var copy = document.querySelector('[data-dzn-copy-support]');
  if (copy) copy.addEventListener('click', function(){
    var field = document.getElementById('dzn-support-summary');
    if (!field) return;
    field.select();
    try { navigator.clipboard.writeText(field.value); } catch (error) { document.execCommand('copy'); }
    copy.textContent = 'Copied';
  });
})();
</script>
HTML;
    }

    public static function ajaxCategory(): void {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') wp_send_json_error(['message' => 'Method not allowed'], 405);
        if (!current_user_can('manage_options') && !current_user_can(self::CAPABILITY)) wp_send_json_error(['message' => 'Forbidden'], 403);
        check_admin_referer(self::AJAX_CATEGORY);
        $category = isset($_POST['category']) && is_scalar($_POST['category']) ? sanitize_key(wp_unslash((string) $_POST['category'])) : '';
        try {
            $payload = (new DiagnosticRegistry())->category($category);
            wp_send_json_success(['html' => self::renderCategoryTable($payload)]);
        } catch (\Throwable $error) {
            wp_send_json_error(['message' => 'This diagnostic category is unavailable.', 'code' => 'category_unavailable'], 200);
        }
    }

    /**
     * Retry one exact, existing notification aggregate.
     *
     * This is not a resend form: the caller names a notification row, and the
     * existing NotificationManualDeliveryService verifies the exact recipient,
     * subject, current state and send window before re-arming that one outbox
     * row. A different notification, recipient or template can never be sent.
     */
    public static function retryExactNotification(): never {
        if (!current_user_can('dzn_operate_notification_dispatch') && !current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer(self::ACTION_RETRY);
        $notificationId = isset($_POST['notification_id']) && is_scalar($_POST['notification_id']) ? absint($_POST['notification_id']) : 0;
        $state = isset($_POST['nstate']) && is_scalar($_POST['nstate']) ? sanitize_key(wp_unslash((string) $_POST['nstate'])) : '';
        $result = 'refused';
        try {
            $retry = (new NotificationManualDeliveryService())->retry(
                $notificationId,
                'diagnostics-retry:' . $notificationId . ':' . wp_generate_uuid4()
            );
            $result = !empty($retry['replayed']) ? 'replayed' : 'queued';
        } catch (\Throwable $error) {
            $known = array('notification_not_retryable', 'notification_retry_window_closed', 'notification_subject_not_retryable', 'notification_not_found', 'notification_idempotency_conflict', 'notification_dispatch_forbidden');
            $result = in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'refused';
            error_log('[Delnavazan diagnostics] exact notification retry refused: ' . get_class($error) . ' ' . $error->getMessage());
        }
        $args = array('page' => self::PAGE, 'component' => 'notifications', 'ndays' => 0, 'retry_result' => $result);
        if (in_array($state, array('failed', 'expired'), true)) $args['nstate'] = $state;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . '#notification-register');
        exit;
    }

    /** A safe, closed message for the exact-retry result; provider/database detail is never shown. */
    private static function retryResultMessage(string $code): string {
        return match ($code) {
            'queued' => 'The exact notification was queued for one retry. Provider acceptance and delivery remain separate states.',
            'replayed' => 'That exact retry was already recorded; it remains queued.',
            'notification_not_retryable' => 'Only a failed or expired notification can be retried.',
            'notification_retry_window_closed' => 'The permitted send window has closed. A new message is a separate decision, not a retry.',
            'notification_subject_not_retryable' => 'This notification subject is not eligible for a scoped retry.',
            'notification_not_found' => 'That notification no longer exists.',
            'notification_idempotency_conflict' => 'That retry command was already used for a different notification. Nothing was sent.',
            'notification_dispatch_forbidden' => 'You no longer have permission to retry a notification.',
            default => 'The retry was refused. Nothing was sent.',
        };
    }

    private static function renderCategoryTable(array $payload): string {
        $html = '<p class="dzn-settings-meta">Checked <code dir="ltr">' . esc_html(gmdate('Y-m-d H:i:s')) . '</code> · ' . esc_html((string) ($payload['description'] ?? '')) . '</p>';
        $html .= '<table class="widefat striped dzn-settings-table dzn-diagnostics-table"><thead><tr><th>Check</th><th>State</th><th>Detail</th><th>Technical code</th><th>Related</th></tr></thead><tbody>';
        if (empty($payload['checks'])) {
            $html .= '<tr><td colspan="5"><em>No check is available to this account.</em></td></tr>';
        }
        foreach ((array) ($payload['checks'] ?? []) as $check) {
            $state = (string) ($check['state'] ?? 'unknown');
            $html .= '<tr><td><strong>' . esc_html((string) $check['label']) . '</strong><br><span class="dzn-settings-meta">' . esc_html((string) $check['description']) . '</span></td>';
            $html .= '<td><span class="dzn-status-badge ' . esc_attr(self::badgeClass($state)) . '">' . esc_html(ucfirst(str_replace('_', ' ', $state))) . '</span></td>';
            $html .= '<td>' . esc_html((string) $check['detail']) . '</td>';
            $html .= '<td><code dir="ltr">' . esc_html((string) $check['technical_code']) . '</code><br><span class="dzn-settings-meta">' . esc_html((string) $check['last_checked']) . '</span></td>';
            $html .= '<td>';
            $links = [];
            foreach ((array) ($check['related'] ?? []) as $query) {
                if (!is_string($query) || $query === '') continue;
                $links[] = '<a class="button button-small" href="' . esc_url(admin_url('admin.php?' . $query)) . '">Open record area</a>';
            }
            if (!empty($check['recovery_link'])) $links[] = '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=' . $check['recovery_link'])) . '">Existing recovery</a>';
            $html .= $links === [] ? '—' : implode(' ', $links);
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    public static function downloadBundle(): never {
        if (!current_user_can('manage_options') && !current_user_can(self::CAPABILITY)) wp_die('Forbidden', 403);
        check_admin_referer(self::ACTION_BUNDLE);
        $service = new DiagnosticsReadService();
        $report = $service->report('24h');
        $bundle = (new SupportBundleService())->build($report);
        $json = (new SupportBundleService())->toJson($bundle);
        $filename = 'delnavazan-support-' . gmdate('Ymd-His') . '.json';
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $json;
        exit;
    }

    private static function supportSummary(array $summary): string {
        $lines = [
            'Delnavazan diagnostic report',
            'Generated (UTC): ' . (string) ($summary['generated_at'] ?? ''),
            'Overall: ' . (string) ($summary['overall'] ?? 'unknown'),
            'Critical: ' . (int) ($summary['critical'] ?? 0) . ' · Warnings: ' . (int) ($summary['warning'] ?? 0) . ' · Healthy: ' . (int) ($summary['healthy'] ?? 0) . ' · Unknown: ' . (int) ($summary['unknown'] ?? 0) . ' · Not configured: ' . (int) ($summary['not_configured'] ?? 0),
            '',
            'Category | State | Healthy | Warning | Failed | Unknown',
        ];
        foreach ((array) ($summary['categories'] ?? []) as $category) {
            $counts = (array) ($category['summary'] ?? []);
            $lines[] = implode(' | ', [
                (string) ($category['label'] ?? ''),
                (string) ($counts['overall'] ?? 'unknown'),
                (string) (int) ($counts['healthy'] ?? 0),
                (string) (int) ($counts['degraded'] ?? 0),
                (string) (int) ($counts['failed'] ?? 0),
                (string) ((int) ($counts['unknown'] ?? 0) + (int) ($counts['not_configured'] ?? 0)),
            ]);
        }
        return implode("\n", $lines);
    }

    private static function badgeClass(string $state): string {
        return match ($state) {
            'healthy' => 'is-ok',
            'degraded', 'not_configured' => 'is-warn',
            'failed' => 'is-error',
            default => 'is-locked',
        };
    }
}
