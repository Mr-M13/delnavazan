<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\AcademyDashboardScreen;
use Delnavazan\Platform\Core\Application\AcademyDashboardReadService;
use Delnavazan\Platform\Core\Application\AcademyJourneyReadService;
use Delnavazan\Platform\Core\Application\CoreReadService;

/**
 * Read-only academy operations dashboard. It joins canonical/authoritative
 * projections only and deep-links to their existing command owners; this screen
 * never becomes a second writer.
 */
final class AcademyOperationsController {
    private const CAP = 'dzn_view_booking_requests';
    private const PAGE = 'dzn-academy-dashboard';

    /** @var array<string,mixed>|null */
    private static ?array $classCache = null;

    /** @var list<array<string,mixed>>|null */
    private static ?array $pendingCache = null;

    public static function screen(): void {
        if (!current_user_can(self::CAP)) wp_die('Forbidden', 403);
        $query = isset($_GET['q']) && is_scalar($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
        $stage = isset($_GET['stage']) && is_scalar($_GET['stage']) ? sanitize_key(wp_unslash((string) $_GET['stage'])) : 'needs_action';
        $view = isset($_GET['view']) && is_scalar($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : 'overview';
        if (!in_array($view, array('overview', 'upcoming', 'attention'), true)) $view = 'overview';
        $page = isset($_GET['paged']) && is_scalar($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $result = (new AcademyJourneyReadService())->search($query, $stage, $page);
        $summary = (new AcademyDashboardReadService())->summary();

        echo '<div class="wrap dzn-academy-dashboard">';
        self::hero();
        if (AcademyDashboardScreen::shows('quick')) self::quickActions();
        if (AcademyDashboardScreen::shows('kpis')) self::kpiCards($summary);
        if (AcademyDashboardScreen::shows('actions')) self::pendingActions();
        if (AcademyDashboardScreen::shows('pipeline')) self::pipelinePanel($summary);
        if (AcademyDashboardScreen::shows('schedule') || AcademyDashboardScreen::shows('attention')) self::classOperationalState();
        if (AcademyDashboardScreen::shows('activity')) self::activityPanel($summary);
        if (AcademyDashboardScreen::shows('finance')) self::financePanel($summary);
        if (AcademyDashboardScreen::shows('queue')) self::journeyQueue($result, $query, $stage, $page, $view);
        if (AcademyDashboardScreen::shows('reschedules')) self::rescheduleQueue();
        echo '</div>';
    }

    private static function hero(): void {
        $today = wp_date('l, j F Y');
        echo '<header class="dzn-dash-hero">';
        echo '<div><p class="dzn-dash-eyebrow">Delnavazan operations</p>';
        echo '<h1>Academy</h1>';
        echo '<p>Today’s work is collected here. Review the next action for each learner journey, then open the existing operational screen to complete it. The authorised path is booking → coordination and reviewed identity → accepted Service Arrangement → Enrolment → Teacher Assignment → Term and Lessons.</p></div>';
        echo '<div class="dzn-dash-hero__side"><span class="dzn-dash-date">' . esc_html($today) . '</span>';
        if (current_user_can('dzn_manage_lessons')) echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=dzn-lesson')) . '">Open classes</a>';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-booking-requests')) . '">Review bookings</a>';
        echo '</div></header>';
    }

    private static function quickActions(): void {
        $actions = array();
        if (current_user_can('dzn_manage_students')) $actions[] = array('Add student', 'dzn-student');
        if (current_user_can('dzn_access_teacher_operations')) $actions[] = array('Add teacher', 'dzn-teachers-menu');
        if (current_user_can('dzn_manage_lessons')) $actions[] = array('Issue class', 'dzn-lesson');
        if (current_user_can('dzn_view_booking_requests')) $actions[] = array('Bookings inbox', 'dzn-booking-requests');
        if (current_user_can(Menu::FINANCE_WORKSPACE_CAP)) $actions[] = array('Finance', 'dzn-finance&tab=payments');
        if (!$actions) return;
        echo '<section class="dzn-panel"><div class="dzn-panel__head"><div><h2>Quick actions</h2><p>Start the tasks that are available to your account.</p></div></div><div class="dzn-quick">';
        foreach ($actions as [$label, $target]) {
            $url = str_contains($target, '&') ? admin_url('admin.php?page=' . $target) : admin_url('admin.php?page=' . rawurlencode($target));
            echo '<a href="' . esc_url($url) . '"><span class="dzn-quick__plus" aria-hidden="true">+</span>' . esc_html($label) . '</a>';
        }
        echo '</div></section>';
    }

    private static function kpiCards(array $summary): void {
        $counts = $summary['counts'];
        $classes = self::classSnapshot();
        $pending = self::pendingActionItems();
        $actionCount = 0;
        foreach ($pending as $item) $actionCount += (int) $item['count'];
        $cards = array(
            array('Active students', $counts['students'] ?? null, 'Student directory', 'dzn-student'),
            array('Active teachers', $counts['teachers'] ?? null, 'Teacher overview', 'dzn-teachers-menu'),
            array('Active enrolments', $counts['enrolments'] ?? null, 'Bookings & enrolments', 'dzn-enrolment-workspace'),
            array('Open bookings', $counts['bookings'] ?? null, 'Booking inbox', 'dzn-booking-requests'),
            array('Today’s classes', $classes['available'] ? count($classes['today']) : null, 'Classes & schedules', 'dzn-lesson'),
            array('Open action items', $actionCount, 'Review below', '#dzn-action-centre'),
        );
        echo '<div class="dzn-kpi-grid">';
        foreach ($cards as [$label, $value, $hint, $target]) {
            $isLink = $value !== null && !str_starts_with($target, '#');
            $tag = $isLink ? 'a' : 'div';
            $href = $isLink ? ' href="' . esc_url(admin_url('admin.php?page=' . rawurlencode($target))) . '"' : '';
            $classes = 'dzn-kpi' . ($value === null ? ' dzn-kpi--restricted' : '') . ($label === 'Open action items' && $actionCount > 0 ? ' dzn-kpi--attention' : '');
            echo '<' . $tag . ' class="' . esc_attr($classes) . '"' . $href . '>';
            echo '<span class="dzn-kpi__label">' . esc_html($label) . '</span>';
            echo '<span class="dzn-kpi__value">' . ($value === null ? '—' : esc_html(number_format_i18n((int) $value))) . '</span>';
            echo '<span class="dzn-kpi__hint">' . ($value === null ? 'No access for this account' : esc_html($hint)) . '</span>';
            echo '</' . $tag . '>';
        }
        echo '</div>';
    }

    private static function pipelinePanel(array $summary): void {
        $pipeline = $summary['pipeline'];
        $total = max(1, (int) $summary['pipeline_total']);
        echo '<section class="dzn-panel" id="dzn-pipeline"><div class="dzn-panel__head"><div><h2>Learner pipeline</h2><p>Open learner journeys by the next stage that needs work. Select a stage to filter the queue.</p></div><a class="dzn-panel__link" href="' . esc_url(add_query_arg(array('page' => self::PAGE, 'stage' => 'needs_action'), admin_url('admin.php'))) . '">Action queue →</a></div>';
        if ((int) $summary['pipeline_total'] < 1) {
            echo '<p class="dzn-empty">No submitted learner journeys are waiting in the pipeline.</p></section>';
            return;
        }
        echo '<div class="dzn-pipeline">';
        foreach (AcademyJourneyReadService::PIPELINE_STAGES as $key => $label) {
            $count = (int) ($pipeline[$key] ?? 0);
            $width = max(0, min(100, (int) round(($count / $total) * 100)));
            $url = add_query_arg(array('page' => self::PAGE, 'stage' => $key), admin_url('admin.php'));
            echo '<a class="dzn-pipeline__row" href="' . esc_url($url) . '"><span class="dzn-pipeline__label">' . esc_html($label) . '</span><span class="dzn-pipeline__track"><span class="dzn-pipeline__fill" style="inline-size:' . $width . '%"></span></span><span class="dzn-pipeline__count">' . esc_html(number_format_i18n($count)) . '</span></a>';
        }
        echo '</div></section>';
    }

    /** Prominent queues are individually capability-gated; this page never broadens authority. */
    private static function pendingActions(): void {
        $items = array_values(array_filter(self::pendingActionItems(), static fn(array $item): bool => (int) $item['count'] > 0));
        echo '<section class="dzn-panel" id="dzn-action-centre"><div class="dzn-panel__head"><div><h2>Action centre</h2><p>Only work that is waiting for a decision is shown. Zero-count queues are omitted.</p></div></div>';
        if (!$items) {
            echo '<p class="dzn-all-clear"><span aria-hidden="true">✓</span> No pending operational decisions in your work areas.</p></section>';
            return;
        }
        echo '<div class="dzn-action-grid">';
        foreach ($items as $item) {
            $url = add_query_arg(array_merge(array('page' => $item['page']), $item['args']), admin_url('admin.php'));
            echo '<article class="dzn-action-card' . (!empty($item['urgent']) ? ' is-urgent' : '') . '"><div class="dzn-action-card__top"><span class="dzn-action-card__count">' . esc_html(number_format_i18n((int) $item['count'])) . '</span><span class="dzn-badge' . (!empty($item['urgent']) ? ' dzn-badge--red' : ' dzn-badge--gold') . '">' . (!empty($item['urgent']) ? 'Needs attention' : 'Waiting') . '</span></div><h3>' . esc_html($item['label']) . '</h3><p>' . esc_html($item['description']) . '</p><a class="button' . (!empty($item['urgent']) ? ' button-primary' : '') . '" href="' . esc_url($url) . '">' . esc_html($item['action']) . '</a></article>';
        }
        echo '</div></section>';
    }

    /** @return list<array{label:string,count:int,page:string,action:string,args:array<string,mixed>,description:string,urgent:bool}> */
    private static function pendingActionItems(): array {
        if (self::$pendingCache !== null) return self::$pendingCache;
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $items = array();
        if (current_user_can('dzn_manage_onboarding')) {
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}teacher_onboarding_states WHERE state='pending_review'");
            $items[] = array('label' => 'Teacher onboarding submissions', 'count' => $count, 'page' => 'dzn-teachers-menu', 'action' => 'Review and decide', 'args' => array('view' => 'onboarding'), 'description' => 'Profiles and availability submitted for an explicit staff decision.', 'urgent' => $count > 0);
        }
        if (current_user_can('dzn_manage_booking_request_coordination')) {
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}booking_request_teacher_deliveries d INNER JOIN {$p}booking_requests r ON r.id=d.booking_request_id WHERE d.state='pending_request' AND r.resolution_state='unresolved' AND r.privacy_erased_at IS NULL");
            $items[] = array('label' => 'Teacher booking requests pending response', 'count' => $count, 'page' => 'dzn-booking-requests', 'action' => 'Review request delivery', 'args' => array(), 'description' => 'A teacher has not yet answered a booking request.', 'urgent' => false);
        }
        if (current_user_can('dzn_manage_canonical_attendance_review')) {
            // Pending/accepted proposals are still owned by the Teacher and Student. The absence
            // queue must not ask an administrator to intervene before the parties have resolved it.
            $count = (int) $wpdb->get_var("SELECT COUNT(DISTINCT c.id) FROM {$p}canonical_attendance_cases c INNER JOIN {$p}canonical_attendance_evidence e ON e.case_id=c.id LEFT JOIN {$p}canonical_lesson_schedule_versions v ON v.id=c.schedule_version_id AND v.lesson_id=c.lesson_id WHERE c.state IN ('open','ready_for_review','settlement_pending') AND e.source_kind='student' AND (v.id IS NULL OR (v.applicable_slot=1 AND v.superseded_at IS NULL)) AND NOT EXISTS (SELECT 1 FROM {$p}lesson_reschedule_proposals rp WHERE rp.lesson_id=c.lesson_id AND rp.created_at>=c.created_at AND (rp.state IN ('committing','accepted') OR (rp.state='pending' AND (rp.expires_at IS NULL OR rp.expires_at>UTC_TIMESTAMP()))))");
            $items[] = array('label' => 'Absence reports needing administrator review', 'count' => $count, 'page' => 'dzn-attendance-review', 'action' => 'Review attendance', 'args' => array(), 'description' => 'No active Teacher/Student replacement is resolving these reports. Exhausted allowances and disputed outcomes are included.', 'urgent' => $count > 0);
        }
        if (current_user_can('dzn_manage_exceptions')) {
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}operational_exceptions WHERE status='open'");
            $items[] = array('label' => 'Operational exceptions', 'count' => $count, 'page' => 'dzn-exception', 'action' => 'Review exceptions', 'args' => array(), 'description' => 'Catalogue, booking or workflow exceptions need acknowledgement or resolution.', 'urgent' => $count > 0);
        }
        if (current_user_can('dzn_manage_lessons')) {
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}lesson_reschedule_proposals WHERE state='pending' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())");
            $items[] = array('label' => 'Class time changes awaiting student response', 'count' => $count, 'page' => self::PAGE, 'action' => 'Review time changes', 'args' => array(), 'description' => 'A negotiated time change is waiting for the other party.', 'urgent' => false);
            $escalated = (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT lesson_id FROM {$p}lesson_reschedule_proposals WHERE state='rejected' GROUP BY lesson_id HAVING COUNT(*)>=3) escalated");
            $items[] = array('label' => 'Reschedule negotiations needing admin intervention', 'count' => $escalated, 'page' => self::PAGE, 'action' => 'Open reschedule queue', 'args' => array(), 'description' => 'Three or more rejected proposals need a manual schedule decision.', 'urgent' => $escalated > 0);
        }
        self::$pendingCache = $items;
        return $items;
    }

    /**
     * Reuses the Classes workflow's capability-gated projection. The Academy page
     * intentionally does not query lesson tables or expose class data to an actor
     * who cannot open the existing Classes authority screen.
     */
    private static function classOperationalState(): void {
        $state = self::classSnapshot();
        if (!$state['available']) return;
        $showSchedule = AcademyDashboardScreen::shows('schedule');
        $showAttention = AcademyDashboardScreen::shows('attention');
        if (!$showSchedule && !$showAttention) return;
        echo '<div class="dzn-dash-grid' . ($showSchedule && $showAttention ? '' : ' dzn-dash-grid--single') . '">';
        if ($showSchedule) {
            echo '<section class="dzn-panel" id="dzn-academy-today-upcoming"><div class="dzn-panel__head"><div><h2>Today and upcoming lessons</h2><p>Live class state from the existing Classes read model. Times remain in each class’s scheduled time zone.</p></div><a class="dzn-panel__link" href="' . esc_url(admin_url('admin.php?page=dzn-lesson')) . '">Open Classes →</a></div>';
            if (!$state['today'] && !$state['upcoming']) {
                echo '<p class="dzn-empty">No scheduled classes are currently visible in the Classes workflow.</p>';
            } else {
                echo '<ul class="dzn-list">';
                foreach (array_slice(array_merge($state['today'], $state['upcoming']), 0, 10) as $row) {
                    $url = self::classUrl($row);
                    $action = ($row->record_model ?? '') === 'confirmed_intro_booking_v1' ? 'Open booking' : 'Open class';
                    $time = self::classTimeLabel($row);
                    echo '<li class="dzn-list__item"><span class="dzn-list__time">' . esc_html($time) . '</span><span class="dzn-list__body"><a class="dzn-list__title" href="' . esc_url($url) . '">' . esc_html((string) ($row->student_name ?: $row->course_name ?: 'Class')) . '</a><span class="dzn-list__meta">' . esc_html((string) ($row->course_name ?: 'Course')) . ' · ' . esc_html((string) ($row->teacher_name ?: 'Teacher')) . '</span></span><span class="dzn-list__action"><span class="dzn-badge dzn-badge--teal">' . esc_html((string) $row->class_status) . '</span><a class="button button-small" href="' . esc_url($url) . '">' . esc_html($action) . '</a></span></li>';
                }
                echo '</ul>';
            }
            echo '</section>';
        }
        if ($showAttention) {
            echo '<section class="dzn-panel" id="dzn-academy-attention"><div class="dzn-panel__head"><div><h2>Attention needed</h2><p>Classes that need scheduling, integrity review or a delivery decision.</p></div></div>';
            if (!$state['attention']) {
                echo '<p class="dzn-all-clear"><span aria-hidden="true">✓</span> No classes currently need scheduling or integrity review.</p>';
            } else {
                echo '<ul class="dzn-list">';
                foreach (array_slice($state['attention'], 0, 8) as $row) {
                    $url = self::classUrl($row);
                    $label = (string) ($row->student_name ?: $row->course_name ?: 'Class');
                    echo '<li class="dzn-list__item"><span class="dzn-badge dzn-badge--red">Review</span><span class="dzn-list__body"><a class="dzn-list__title" href="' . esc_url($url) . '">' . esc_html($label) . '</a><span class="dzn-list__meta">' . esc_html((string) $row->class_status) . '</span></span><span class="dzn-list__action"><a class="button button-small" href="' . esc_url($url) . '">Open class</a></span></li>';
                }
                echo '</ul>';
            }
            echo '</section>';
        }
        echo '</div>';
    }

    /** @return array{available:bool,today:list<object>,upcoming:list<object>,attention:list<object>,rows:list<object>} */
    private static function classSnapshot(): array {
        if (self::$classCache !== null) return self::$classCache;
        if (!current_user_can('dzn_manage_lessons')) {
            self::$classCache = array('available' => false, 'today' => array(), 'upcoming' => array(), 'attention' => array(), 'rows' => array());
            return self::$classCache;
        }
        $rows = (new CoreReadService())->classOperatorRows();
        $attention = array_values(array_filter($rows, static fn(object $row): bool => in_array((string) $row->class_status, array('Integrity review needed', 'Schedule needs review', 'Ready to schedule'), true)));
        $todayRows = array_values(array_filter($rows, static fn(object $row): bool => self::scheduleDate($row) !== '' && self::scheduleDate($row) === self::todayInScheduleTimezone($row)));
        $upcoming = array_values(array_filter($rows, static fn(object $row): bool => self::scheduleDate($row) !== '' && self::scheduleDate($row) > self::todayInScheduleTimezone($row)));
        self::$classCache = array('available' => true, 'today' => $todayRows, 'upcoming' => $upcoming, 'attention' => $attention, 'rows' => $rows);
        return self::$classCache;
    }

    private static function classTimeLabel(object $row): string {
        $instant = self::scheduleUtcInstant((string) ($row->schedule_starts_at_utc ?? ''));
        $timezone = (string) ($row->schedule_timezone ?? '');
        if ($instant !== null && self::validScheduleTimezone($timezone)) {
            try { return wp_date('H:i', $instant->getTimestamp(), new \DateTimeZone($timezone)); } catch (\Throwable) {}
        }
        $label = (string) ($row->schedule_label ?? '');
        return $label !== '' ? $label : 'Time not set';
    }

    private static function activityPanel(array $summary): void {
        $trend = $summary['trend'];
        $showLessons = !empty($summary['availability']['classes']);
        $totalBookings = 0;
        $totalLessons = 0;
        $max = 1;
        foreach ($trend as $day) {
            $totalBookings += (int) $day['bookings'];
            $dayLessons = $showLessons ? (int) $day['lessons'] : 0;
            $totalLessons += $dayLessons;
            $max = max($max, (int) $day['bookings'], $dayLessons);
        }
        $summaryText = number_format_i18n($totalBookings) . ' new booking' . ($totalBookings === 1 ? '' : 's');
        if ($showLessons) $summaryText .= ' · ' . number_format_i18n($totalLessons) . ' completed class' . ($totalLessons === 1 ? '' : 'es');
        echo '<section class="dzn-panel" id="dzn-activity"><div class="dzn-panel__head"><div><h2>14-day activity</h2><p>' . esc_html($summaryText) . '.</p></div></div>';
        if ($totalBookings === 0 && $totalLessons === 0) {
            echo '<p class="dzn-empty">No ' . ($showLessons ? 'bookings or completed classes were' : 'bookings were') . ' recorded in the last 14 days.</p></section>';
            return;
        }
        echo '<div class="dzn-chart__legend"><span class="dzn-chart__key"><span class="dzn-chart__swatch"></span>New bookings</span>';
        if ($showLessons) echo '<span class="dzn-chart__key"><span class="dzn-chart__swatch dzn-chart__swatch--lessons"></span>Completed classes</span>';
        echo '</div>';
        echo '<div class="dzn-chart__plot" role="img" aria-label="Daily ' . ($showLessons ? 'bookings and completed classes' : 'bookings') . ' for the last 14 days">';
        foreach ($trend as $day) {
            $bh = max(3, (int) round(((int) $day['bookings'] / $max) * 120));
            $lh = $showLessons ? max(3, (int) round(((int) $day['lessons'] / $max) * 120)) : 0;
            $title = $day['label'] . ': ' . $day['bookings'] . ' bookings' . ($showLessons ? ', ' . $day['lessons'] . ' completed classes' : '');
            echo '<div class="dzn-chart__day" title="' . esc_attr($title) . '"><div class="dzn-chart__bars"><span class="dzn-chart__bar" style="--h:' . $bh . 'px"></span>' . ($showLessons ? '<span class="dzn-chart__bar dzn-chart__bar--lessons" style="--h:' . $lh . 'px"></span>' : '') . '</div><span class="dzn-chart__label">' . esc_html((string) $day['label']) . '</span></div>';
        }
        echo '</div></section>';
    }

    private static function financePanel(array $summary): void {
        $finance = $summary['finance'];
        if (empty($finance['available']) && empty($finance['payments_available'])) return;
        $payments = (int) ($finance['payments_pending'] ?? 0);
        $statements = (int) ($finance['statements_open'] ?? 0);
        $payability = (int) ($finance['payability_pending'] ?? 0);
        $exceptions = (int) ($finance['exceptions_open'] ?? 0);
        $openWork = (!empty($finance['payments_available']) ? $payments : 0) + $statements + $payability + $exceptions;
        echo '<section class="dzn-panel" id="dzn-finance"><div class="dzn-panel__head"><div><h2>Finance snapshot</h2><p>Only recorded finance facts are shown. Open the Finance workspace to review or act.</p></div><a class="dzn-panel__link" href="' . esc_url(admin_url('admin.php?page=dzn-finance&tab=payments')) . '">Open Finance →</a></div>';
        if ($openWork < 1) {
            echo '<p class="dzn-all-clear"><span aria-hidden="true">✓</span> No payment, statement, payability or finance-exception work is waiting.</p></section>';
            return;
        }
        echo '<div class="dzn-finance-cards">';
        if (!empty($finance['payments_available']) && $payments > 0) {
            echo '<a class="dzn-finance-card" href="' . esc_url(admin_url('admin.php?page=dzn-finance&tab=payments')) . '"><strong>' . esc_html(number_format_i18n($payments)) . '</strong><span>Payment sessions awaiting outcome</span></a>';
        }
        if (!empty($finance['available'])) {
            if ($statements > 0) echo '<a class="dzn-finance-card" href="' . esc_url(admin_url('admin.php?page=dzn-finance&tab=statements')) . '"><strong>' . esc_html(number_format_i18n($statements)) . '</strong><span>Open teacher statements</span></a>';
            if ($payability > 0) echo '<a class="dzn-finance-card" href="' . esc_url(admin_url('admin.php?page=dzn-finance-payability')) . '"><strong>' . esc_html(number_format_i18n($payability)) . '</strong><span>Lessons with pending payability</span></a>';
            if ($exceptions > 0) echo '<a class="dzn-finance-card" href="' . esc_url(admin_url('admin.php?page=dzn-finance-reconciliation')) . '"><strong>' . esc_html(number_format_i18n($exceptions)) . '</strong><span>Open finance exceptions</span></a>';
        }
        echo '</div></section>';
    }

    private static function journeyQueue(array $result, string $query, string $stage, int $page, string $view): void {
        $rows = $result['rows'];
        echo '<section class="dzn-panel" id="dzn-journey-queue"><div class="dzn-panel__head"><div><h2>Learner journeys</h2><p>Search and follow a learner from booking to assignment. Only unresolved work is shown in the action queue.</p></div></div>';
        echo '<form method="get" class="dzn-filter-bar"><input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '"><label>Search name, email, booking reference, instrument or course<input type="search" name="q" value="' . esc_attr($query) . '"></label><label>Stage<select name="stage"><option value="needs_action"' . selected($stage, 'needs_action', false) . '>Action queue</option><option value=""' . selected($stage, '', false) . '>All records</option>';
        foreach (AcademyJourneyReadService::PIPELINE_STAGES as $value => $label) echo '<option value="' . esc_attr($value) . '"' . selected($stage, $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label><button class="button">Search</button></form>';
        echo '<p class="dzn-list__meta"><strong>' . esc_html(number_format_i18n((int) $result['total'])) . '</strong> matching learner journey' . ((int) $result['total'] === 1 ? '' : 's') . ' · showing up to ' . esc_html(number_format_i18n((int) $result['per_page'])) . ' per page.</p>';
        if (in_array($view, array('upcoming', 'attention'), true)) {
            $target = $view === 'upcoming' ? 'dzn-academy-today-upcoming' : 'dzn-academy-attention';
            echo '<script>document.getElementById(' . wp_json_encode($target) . ')?.scrollIntoView();</script>';
        }
        $detailId = isset($_GET['detail_request_id']) && is_scalar($_GET['detail_request_id']) ? absint($_GET['detail_request_id']) : 0;
        if ($detailId > 0) foreach ($rows as $r) if ((int) $r->request_id === $detailId) { self::journeyDetail($r); break; }
        echo '<table class="widefat striped dzn-queue-table"><thead><tr><th>Booking / person</th><th>Instrument / course</th><th>Journey stage</th><th>Current blocker / next action</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="4">No booking journeys match this search.</td></tr>';
        foreach ($rows as $r) {
            $detailUrl = add_query_arg(array('page' => self::PAGE, 'q' => $query, 'stage' => $stage, 'paged' => $page, 'detail_request_id' => (int) $r->request_id), admin_url('admin.php'));
            echo '<tr><td><a class="dzn-cell-main" href="' . esc_url($detailUrl) . '">' . esc_html((string) $r->request_ref) . '</a><br><span class="dzn-cell-sub">' . esc_html((string) ($r->student_name ?: $r->contact_name ?: 'Identity not resolved')) . ($r->contact_email ? '<br>' . esc_html((string) $r->contact_email) : '') . '</span></td><td>' . esc_html((string) ($r->instrument_fa ?: $r->instrument_en ?: '')) . ($r->course_fa || $r->course_en ? ' · ' . esc_html((string) ($r->course_fa ?: $r->course_en)) : '') . '</td>';
            $steps = array(
                'Coordinated' => array((bool) $r->case_id, 'Coordination needed'),
                'Identity reviewed' => array((bool) $r->student_id, 'Identity unresolved'),
                'Accepted' => array((bool) $r->arrangement_id, 'Not accepted'),
                'Enrolled' => array((bool) $r->enrolment_id, 'No Enrolment'),
                'Teacher assigned' => array((bool) $r->assignment_id, 'No Teacher Assignment'),
            );
            echo '<td><div class="dzn-stage-line">';
            foreach ($steps as $step => [$done, $blocked]) echo '<span class="' . ($done ? 'is-done' : 'is-blocked') . '">' . esc_html($done ? $step : $blocked) . '</span>';
            echo '</div><span class="dzn-cell-sub">' . esc_html(self::claimStatus($r)) . '</span></td><td>' . self::attention($r) . '<br>' . self::next($r) . '</td></tr>';
        }
        if ($result['pages'] > 1) {
            echo '</tbody></table><nav class="tablenav"><div class="tablenav-pages">';
            for ($n = 1; $n <= $result['pages']; $n++) {
                if (abs($n - $page) > 2 && $n !== 1 && $n !== $result['pages']) continue;
                $url = add_query_arg(array('page' => self::PAGE, 'q' => $query, 'stage' => $stage, 'paged' => $n), admin_url('admin.php'));
                echo '<a class="button' . ($n === $page ? ' button-primary' : '') . '" href="' . esc_url($url) . '">' . (int) $n . '</a> ';
            }
            echo '</div></nav>';
        } else {
            echo '</tbody></table>';
        }
        echo '</section>';
    }

    /** Pending Teacher proposals with administrator confirm/cancel and a direct manual-change link. */
    private static function rescheduleQueue(): void {
        if (!current_user_can('dzn_manage_lessons') && !current_user_can('dzn_manage_canonical_lesson_schedules')) return;
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $status = sanitize_key((string) ($_GET['reschedule_status'] ?? ''));
        $rows = $wpdb->get_results(
            "SELECT r.uid proposal_uid,r.lesson_id,r.proposed_starts_at_utc,r.proposed_ends_at_utc,r.schedule_timezone,
                    v.starts_at_utc current_start_utc,v.ends_at_utc current_end_utc,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.reference_code,''),'Student') student_name,
                    COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name,'Teacher') teacher_name,
                    COALESCE(NULLIF(i.name_fa,''),NULLIF(c.name_fa,''),NULLIF(c.name_en,''),'Course') course_name,
                    (SELECT COUNT(*) FROM {$p}lesson_reschedule_proposals x WHERE x.lesson_id=r.lesson_id AND x.state='rejected') rejection_count,
                    (SELECT e.reason_code FROM {$p}lesson_reschedule_events e WHERE e.proposal_id=r.id AND e.event_type='proposed' ORDER BY e.id ASC LIMIT 1) proposal_reason
             FROM {$p}lesson_reschedule_proposals r
             INNER JOIN {$p}lessons l ON l.id=r.lesson_id AND l.archived_at IS NULL
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             INNER JOIN {$p}students s ON s.id=r.student_id
             INNER JOIN {$p}teachers t ON t.id=r.teacher_id
             LEFT JOIN {$p}courses c ON c.id=l.course_id
             LEFT JOIN {$p}instruments i ON i.id=c.instrument_id
             WHERE r.state='pending' AND (r.expires_at IS NULL OR r.expires_at>UTC_TIMESTAMP())
             ORDER BY r.proposed_starts_at_utc,r.id LIMIT 50"
        );
        $escalated = $wpdb->get_results(
            "SELECT r.lesson_id,COUNT(*) rejections,MAX(r.decided_at) last_rejected_at,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.reference_code,''),'Student') student_name,
                    COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name,'Teacher') teacher_name,
                    v.starts_at_utc current_start_utc
             FROM {$p}lesson_reschedule_proposals r
             INNER JOIN {$p}lessons l ON l.id=r.lesson_id AND l.archived_at IS NULL
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             INNER JOIN {$p}students s ON s.id=r.student_id
             INNER JOIN {$p}teachers t ON t.id=r.teacher_id
             WHERE r.state='rejected'
             GROUP BY r.lesson_id,s.display_name,s.reference_code,t.display_name,t.english_name,t.persian_name,v.starts_at_utc
             HAVING COUNT(*)>=3
             ORDER BY last_rejected_at DESC LIMIT 20"
        );
        $history = $wpdb->get_results(
            "SELECT r.uid proposal_uid,r.lesson_id,r.state,r.failure_code,r.proposed_starts_at_utc,r.schedule_timezone,r.created_at,r.decided_at,r.expires_at,
                    (SELECT e.reason_code FROM {$p}lesson_reschedule_events e WHERE e.proposal_id=r.id AND e.event_type='proposed' ORDER BY e.id ASC LIMIT 1) proposal_reason,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.reference_code,''),'Student') student_name,
                    COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name,'Teacher') teacher_name,
                    COALESCE(NULLIF(i.name_fa,''),NULLIF(c.name_fa,''),NULLIF(c.name_en,''),'Course') course_name
             FROM {$p}lesson_reschedule_proposals r
             INNER JOIN {$p}lessons l ON l.id=r.lesson_id AND l.archived_at IS NULL
             INNER JOIN {$p}students s ON s.id=r.student_id
             INNER JOIN {$p}teachers t ON t.id=r.teacher_id
             LEFT JOIN {$p}courses c ON c.id=l.course_id
             LEFT JOIN {$p}instruments i ON i.id=c.instrument_id
             WHERE r.state IN ('accepted','rejected','failed','cancelled') OR (r.state='pending' AND r.expires_at IS NOT NULL AND r.expires_at<UTC_TIMESTAMP())
             ORDER BY r.id DESC LIMIT 30"
        ) ?: array();
        if (!$rows && !$escalated && !$history && '' === $status) return;
        echo '<section class="dzn-panel dzn-academy-reschedules" id="dzn-academy-reschedules"><div class="dzn-panel__head"><div><h2>Class time changes</h2><p>Confirm the negotiated proposal, or cancel it and open the class to change the time manually. Confirmation uses the same canonical schedule and audit path as Student acceptance.</p></div></div>';
        if ('saved' === $status) echo '<div class="notice notice-success inline"><p>Class time change decision recorded.</p></div>';
        elseif ('error' === $status) echo '<div class="notice notice-error inline"><p>The class time change could not be recorded. Refresh and review the current class schedule before retrying.</p></div>';
        if ($rows) {
            echo '<table class="widefat striped"><thead><tr><th>Student / Teacher</th><th>Class</th><th>Current time</th><th>Proposed time</th><th>Rejections</th><th>Action</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $tz = (string) $row->schedule_timezone;
                try { $zone = new \DateTimeZone($tz); } catch (\Throwable) { $zone = wp_timezone(); }
                $current = wp_date('Y-m-d H:i', (int) strtotime((string) $row->current_start_utc . ' UTC'), $zone);
                $proposed = wp_date('Y-m-d H:i', (int) strtotime((string) $row->proposed_starts_at_utc . ' UTC'), $zone);
                $classUrl = add_query_arg(array('page' => 'dzn-lesson', 'id' => (int) $row->lesson_id), admin_url('admin.php'));
                $kindLabel = (string) ($row->proposal_reason ?? '') === 'teacher_replacement_proposed' ? 'Replacement' : 'Reschedule';
                echo '<tr><td><strong>' . esc_html((string) $row->student_name) . '</strong><br><small>' . esc_html((string) $row->teacher_name) . '</small></td><td>' . esc_html((string) $row->course_name) . '<br><small>' . esc_html($kindLabel) . '</small></td><td>' . esc_html($current . ' · ' . $tz) . '</td><td>' . esc_html($proposed . ' · ' . $tz) . '</td><td>' . (int) $row->rejection_count . '</td><td>';
                $nonceKey = wp_generate_uuid4();
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 4px 4px 0">';
                wp_nonce_field('dzn_admin_reschedule_confirm');
                echo '<input type="hidden" name="action" value="dzn_admin_reschedule_confirm"><input type="hidden" name="proposal_uid" value="' . esc_attr((string) $row->proposal_uid) . '"><input type="hidden" name="decision_key" value="' . esc_attr($nonceKey) . '"><button class="button button-primary">Confirm proposed time</button></form>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 4px 4px 0">';
                wp_nonce_field('dzn_admin_reschedule_cancel');
                echo '<input type="hidden" name="action" value="dzn_admin_reschedule_cancel"><input type="hidden" name="proposal_uid" value="' . esc_attr((string) $row->proposal_uid) . '"><input type="hidden" name="decision_key" value="' . esc_attr(wp_generate_uuid4()) . '"><button class="button">Cancel proposal</button></form>';
                echo '<a class="button" href="' . esc_url($classUrl) . '">Open class to change manually</a></td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p class="dzn-empty">No pending class time changes.</p>';
        }
        if ($escalated) {
            echo '<h3>Escalated after three rejections</h3><ul>';
            foreach ($escalated as $row) {
                $classUrl = add_query_arg(array('page' => 'dzn-lesson', 'id' => (int) $row->lesson_id), admin_url('admin.php'));
                echo '<li><strong>' . esc_html((string) $row->student_name) . '</strong> · ' . esc_html((string) $row->teacher_name) . ' · ' . (int) $row->rejections . ' rejections · <a class="button" href="' . esc_url($classUrl) . '">Open class and fix manually</a></li>';
            }
            echo '</ul>';
        }
        if ($history) {
            echo '<details class="dzn-academy-reschedule-history"><summary>Confirmed, rejected, failed and expired class time changes</summary><table class="widefat striped"><thead><tr><th>Student / Teacher</th><th>Class</th><th>Kind</th><th>Proposed time</th><th>State</th><th>Recorded</th></tr></thead><tbody>';
            foreach ($history as $row) {
                $tz = (string) $row->schedule_timezone;
                try { $zone = new \DateTimeZone($tz); } catch (\Throwable) { $zone = wp_timezone(); }
                $proposed = wp_date('Y-m-d H:i', (int) strtotime((string) $row->proposed_starts_at_utc . ' UTC'), $zone);
                $kindLabel = (string) ($row->proposal_reason ?? '') === 'teacher_replacement_proposed' ? 'Replacement' : 'Reschedule';
                $state = (string) $row->state;
                if ($state === 'pending') $state = 'expired';
                if ($row->failure_code !== null && (string) $row->failure_code !== '') $state .= ' · ' . $row->failure_code;
                $recorded = (string) ($row->decided_at ?: $row->created_at);
                echo '<tr><td><strong>' . esc_html((string) $row->student_name) . '</strong><br><small>' . esc_html((string) $row->teacher_name) . '</small></td><td>' . esc_html((string) $row->course_name) . '</td><td>' . esc_html($kindLabel) . '</td><td>' . esc_html($proposed . ' · ' . $tz) . '</td><td>' . esc_html($state) . '</td><td>' . esc_html($recorded) . '</td></tr>';
            }
            echo '</tbody></table></details>';
        }
        echo '</section>';
    }

    private static function journeyDetail(object $r): void {
        echo '<section class="notice notice-info inline"><h2>Journey detail: ' . esc_html((string) $r->request_ref) . '</h2><p>' . esc_html((string) ($r->student_name ?: $r->contact_name ?: 'Identity unresolved')) . ($r->contact_email ? ' · ' . esc_html((string) $r->contact_email) : '') . ' · ' . esc_html((string) ($r->instrument_fa ?: $r->instrument_en ?: 'Instrument unavailable')) . ($r->course_fa || $r->course_en ? ' · ' . esc_html((string) ($r->course_fa ?: $r->course_en)) : '') . '</p><ol>';
        foreach (array('Booking submitted' => true, 'Coordination opened' => (bool) $r->case_id, 'Student identity resolved' => (bool) $r->student_id, 'Service Arrangement accepted' => (bool) $r->arrangement_id, 'Enrolment converted' => (bool) $r->enrolment_id, 'Teacher Assignment current' => (bool) $r->assignment_id, 'Student account connected' => $r->claim_state === 'claimed', 'Term created' => (bool) $r->term_id) as $label => $done) echo '<li>' . esc_html($label) . ': <strong>' . ($done ? 'Complete' : 'Pending') . '</strong></li>';
        echo '</ol><p><strong>Current blocker and next authorised action:</strong> ' . self::next($r) . '</p><p>Unavailable actions are identified by their required capability or missing predecessor stage above. Authority screens revalidate each command.</p></section>';
    }

    private static function classUrl(object $row): string {
        if (($row->record_model ?? '') === 'confirmed_intro_booking_v1') {
            return add_query_arg(array('page' => 'dzn-booking-requests', 'request_id' => (int) $row->booking_request_id), admin_url('admin.php'));
        }
        return add_query_arg(array('page' => 'dzn-lesson', 'id' => (int) $row->id), admin_url('admin.php'));
    }

    private static function scheduleDate(object $row): string {
        $instant = self::scheduleUtcInstant((string) ($row->schedule_starts_at_utc ?? ''));
        $timezone = (string) ($row->schedule_timezone ?? '');
        if ($instant !== null && self::validScheduleTimezone($timezone)) {
            return $instant->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d');
        }
        $label = (string) ($row->schedule_label ?? '');
        return preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2}) · /', $label, $matches) ? $matches[1] : '';
    }

    private static function todayInScheduleTimezone(object $row): string {
        $timezone = (string) ($row->schedule_timezone ?? '');
        if (self::validScheduleTimezone($timezone)) {
            return (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d');
        }
        $label = (string) ($row->schedule_label ?? '');
        $fallback = substr($label, (int) strrpos($label, ' ') + 1);
        if (self::validScheduleTimezone($fallback)) {
            return (new \DateTimeImmutable('now', new \DateTimeZone($fallback)))->format('Y-m-d');
        }
        return gmdate('Y-m-d');
    }

    private static function scheduleUtcInstant(string $value): ?\DateTimeImmutable {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) return null;
        return $date;
    }

    private static function validScheduleTimezone(string $timezone): bool {
        if ($timezone === '') return false;
        try { new \DateTimeZone($timezone); return true; } catch (\Throwable) { return false; }
    }

    private static function attention(object $r): string {
        if (!$r->match_attention_code) return '<span aria-label="No active matching attention">—</span>';
        $labels = array('no_current_coverage' => 'No current teacher coverage', 'instrument_unavailable' => 'Instrument unavailable', 'selected_course_unavailable' => 'Selected intro course unavailable', 'default_intro_course_unavailable' => 'Default intro course unavailable');
        $label = $labels[(string) $r->match_attention_code] ?? 'Matching needs attention';
        $action = current_user_can('dzn_prepare_booking_request_matches') ? self::link('dzn-booking-requests', 'Review request', array('request_id' => (int) $r->request_id)) : 'Read-only';
        if ($r->match_attention_code === 'no_current_coverage' && current_user_can('dzn_manage_teachers')) $action .= ' ' . self::link('dzn-teachers-menu', 'View supply gaps');
        return '<strong>' . esc_html($label) . '</strong><br>' . $action;
    }

    private static function next(object $r): string {
        if ($r->match_attention_code === 'no_current_coverage') {
            $links = array();
            if (current_user_can('dzn_manage_teachers')) $links[] = self::link('dzn-teachers-menu', 'Review teacher availability');
            if (current_user_can('dzn_prepare_booking_request_matches')) $links[] = self::link('dzn-booking-requests', 'Reassess request', array('request_id' => (int) $r->request_id));
            if (current_user_can('dzn_manage_booking_request_coordination') && $r->case_id) $links[] = self::link('dzn-booking-request-coordination', 'Continue coordination', array('case_id' => (int) $r->case_id));
            return $links ? implode('<br>', $links) : 'Await authorised coverage review';
        }
        if (!$r->case_id) return current_user_can('dzn_manage_booking_request_coordination') ? '<strong>Blocker:</strong> coordination has not started.<br>' . self::link('dzn-booking-request-coordination', 'Open coordination', array('booking_request_id' => (int) $r->request_id)) : '<strong>Unavailable:</strong> coordination requires booking-coordination capability.';
        if (!$r->student_id) return current_user_can('dzn_resolve_booking_request_student_identity') && current_user_can('dzn_view_student_acceptance_eligibility') ? '<strong>Blocker:</strong> booking identity is unresolved; review evidence and resolve or record an outcome.<br>' . self::link('dzn-student-identity-authority', 'Review student identity', array('booking_request_id' => (int) $r->request_id)) : '<strong>Unavailable:</strong> identity review requires identity-resolution and acceptance-eligibility access.';
        if (!$r->arrangement_id) return current_user_can('dzn_manage_booking_request_coordination') ? '<strong>Blocker:</strong> no accepted Service Arrangement exists; confirm acceptance authority and complete coordination.<br>' . self::link('dzn-booking-request-coordination', 'Continue coordination', array('case_id' => (int) $r->case_id)) : '<strong>Unavailable:</strong> no accepted arrangement; coordination capability is required.';
        if ($r->arrangement_id && !$r->enrolment_id) return current_user_can('dzn_convert_service_arrangements_to_enrolments') ? '<strong>Blocker:</strong> accepted arrangement has not been converted to an Enrolment.<br>' . self::link('dzn-core-dataset-readiness', 'Convert accepted arrangement', array('accepted_service_arrangement_id' => (int) $r->arrangement_id)) : '<strong>Unavailable:</strong> Enrolment conversion capability is required.';
        if ($r->enrolment_id && !$r->assignment_id) return current_user_can('dzn_manage_teacher_assignments') ? '<strong>Blocker:</strong> Enrolment exists; initial assignment follows its accepted arrangement.<br>' . self::link('dzn-core-dataset-readiness', 'Assign accepted Teacher', array('enrolment_id' => (int) $r->enrolment_id)) : '<strong>Unavailable:</strong> teacher-assignment capability is required.';
        if ($r->enrolment_id && $r->claim_state === 'active') return '<strong>Student account:</strong> invitation is active; review its lifecycle before replacing it.';
        if ($r->enrolment_id && $r->claim_state !== 'claimed') return current_user_can('dzn_issue_student_account_claims') ? '<strong>Blocker:</strong> Student account has not been connected.<br>' . self::link('dzn-core-dataset-readiness', 'Offer Student account claim', array('enrolment_id' => (int) $r->enrolment_id)) : '<strong>Unavailable:</strong> student-account claim capability is required.';
        $replace = ($r->assignment_id && current_user_can('dzn_manage_teacher_assignments')) ? self::link('dzn-core-dataset-readiness', 'Review replacement by agreement', array('enrolment_id' => (int) $r->enrolment_id, 'expected_assignment_id' => (int) $r->assignment_id)) : '';
        if ($r->assignment_id && !$r->term_id) return (current_user_can('dzn_manage_canonical_terms') ? self::link('dzn-core-dataset-readiness', 'Create canonical Term', array('enrolment_id' => (int) $r->enrolment_id)) : 'Unavailable: canonical-Term capability is required.') . '<br>' . $replace;
        if ($r->term_id && (int) $r->lesson_count === 0) return (current_user_can('dzn_manage_canonical_lessons') ? self::link('dzn-core-dataset-readiness', 'Issue first Lesson', array('term_id' => (int) $r->term_id, 'teacher_assignment_id' => (int) $r->assignment_id)) : 'Unavailable: canonical-Lesson capability is required.') . '<br>' . $replace;
        return (current_user_can('dzn_manage_canonical_lessons') ? self::link('dzn-core-dataset-readiness', 'Manage canonical lessons', array('term_id' => (int) $r->term_id, 'teacher_assignment_id' => (int) $r->assignment_id)) : 'Journey assigned; further lesson actions require canonical-Lesson capability.') . '<br>' . $replace;
    }

    private static function claimStatus(object $r): string {
        if (!$r->enrolment_id) return 'Account after enrolment';
        if ($r->claim_state === 'claimed') return 'Account connected';
        if ($r->claim_state === 'active') return 'Account invitation active';
        if ($r->claim_state === 'revoked') return 'Account invitation needs review';
        return 'Account not offered';
    }

    private static function link(string $page, string $label, array $args = array()): string {
        $url = add_query_arg(array('page' => $page) + $args, admin_url('admin.php'));
        return '<a class="button" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
}
