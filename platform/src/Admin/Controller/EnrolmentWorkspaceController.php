<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\AcademyJourneyReadService;

/**
 * Temporary consolidated Enrolment workspace.
 *
 * This page groups the existing authorised booking, coordination, enrolment and
 * term workflows. It is navigation only: each card opens the canonical screen
 * that still owns its reads and writes. The resolutions view reads the existing
 * Academy journey projection and never writes a booking or Enrolment fact.
 */
final class EnrolmentWorkspaceController {
    public static function screen(): void {
        if (!current_user_can(Menu::ENROLMENT_WORKSPACE_CAP)) wp_die('Forbidden', 'Delnavazan', array('response' => 403));
        $view = isset($_GET['view']) && is_scalar($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : '';
        if ($view === 'resolutions') {
            self::resolutions();
            return;
        }
        echo '<div class="wrap"><h1>Bookings &amp; Enrolments</h1>';
        echo '<p>Follow a learner from booking request through coordination, accepted arrangement, Enrolment and Term. Each card opens the existing authorised workflow; no booking or Enrolment authority is merged here.</p>';
        echo '<p><a class="button" href="' . esc_url(add_query_arg(array('page' => 'dzn-enrolment-workspace', 'view' => 'resolutions'), admin_url('admin.php'))) . '">Open enrolment resolutions</a></p>';
        echo '<div class="dzn-workspace-grid">';
        self::card('Booking requests', 'Review incoming requests, requested times and current matchability.', 'dzn-booking-requests', 'dzn_view_booking_requests');
        self::card('Request coordination', 'Review candidates, teacher assent, proposals, acceptance evidence and the final arrangement.', 'dzn-booking-request-coordination', 'dzn_manage_booking_request_coordination');
        self::card('Enrolments', 'Open the student/course Enrolment, lifecycle history and authorised lifecycle actions.', 'dzn-enrolment', 'dzn_manage_enrolments');
        self::card('Terms', 'Create, activate, close or cancel a canonical Term for an eligible Enrolment.', 'dzn-term', 'dzn_manage_terms');
        echo '</div>';
        echo '<p class="dzn-workspace-note">Temporary navigation: these are the current authorised workflows. Contextual conversion, account-claim, assignment and Term actions will be added on the individual records in later PRs.</p>';
        echo '</div>';
    }

    /** Read-only unresolved learner-journey queue; each row opens the existing authority. */
    private static function resolutions(): void {
        echo '<div class="wrap"><h1>Enrolment resolutions</h1>';
        echo '<p>Unresolved learner journeys from the existing Academy queue. Each row names the current blocker and opens an existing authorised workflow; this view does not write booking, arrangement, Enrolment or Term facts.</p>';
        echo '<p><a class="button" href="' . esc_url(add_query_arg(array('page' => 'dzn-enrolment-workspace'), admin_url('admin.php'))) . '">Back to Bookings &amp; Enrolments</a></p>';
        if (!current_user_can('dzn_view_booking_requests')) {
            echo '<p class="dzn-empty">The unresolved learner queue requires booking-request visibility.</p></div>';
            return;
        }
        try {
            $result = (new AcademyJourneyReadService())->search('', 'needs_action', 1, 50);
        } catch (\Throwable) {
            echo '<p class="notice notice-error inline">The unresolved learner queue could not be read. No record was changed.</p></div>';
            return;
        }
        $rows = (array) ($result['rows'] ?? array());
        echo '<p><strong>' . esc_html(number_format_i18n((int) ($result['total'] ?? 0))) . '</strong> unresolved learner journey' . ((int) ($result['total'] ?? 0) === 1 ? '' : 's') . '.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Booking / learner</th><th>Instrument / course</th><th>Current blocker</th><th>Existing workflow</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="4">No unresolved learner journeys.</td></tr>';
        foreach ($rows as $row) {
            $detail = add_query_arg(array('page' => 'dzn-academy-dashboard', 'stage' => 'needs_action', 'detail_request_id' => (int) $row->request_id), admin_url('admin.php')) . '#dzn-journey-queue';
            $learner = (string) ($row->student_name ?: $row->contact_name ?: 'Identity unresolved');
            $course = trim((string) ($row->instrument_fa ?: $row->instrument_en ?: '') . ((string) ($row->course_fa ?: $row->course_en ?: '') !== '' ? ' · ' . (string) ($row->course_fa ?: $row->course_en) : ''));
            $blocker = !$row->case_id ? 'Coordination needed' : (!$row->student_id ? 'Identity unresolved' : (!$row->arrangement_id ? 'Service Arrangement not accepted' : (!$row->enrolment_id ? 'Enrolment not converted' : (!$row->assignment_id ? 'Teacher Assignment missing' : 'Account or Term action needed'))));
            $route = !$row->case_id ? array('dzn-booking-request-coordination', 'Open coordination', array('booking_request_id' => (int) $row->request_id))
                : (!$row->student_id ? array('dzn-student-identity-authority', 'Review identity', array('booking_request_id' => (int) $row->request_id))
                : (!$row->arrangement_id ? array('dzn-booking-request-coordination', 'Continue coordination', array('case_id' => (int) $row->case_id))
                : array('dzn-core-dataset-readiness', 'Open resolution workflow', array())));
            $url = add_query_arg(array_merge(array('page' => $route[0]), $route[2]), admin_url('admin.php'));
            echo '<tr><td><a href="' . esc_url($detail) . '">' . esc_html((string) $row->request_ref) . '</a><br><small>' . esc_html($learner) . '</small></td><td>' . esc_html($course) . '</td><td>' . esc_html($blocker) . '</td><td><a class="button" href="' . esc_url($url) . '">' . esc_html($route[1]) . '</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function card(string $title, string $description, string $page, string $capability): void {
        if (!current_user_can($capability)) return;
        echo '<section class="dzn-workspace-card"><h2>' . esc_html($title) . '</h2><p>' . esc_html($description) . '</p><a class="button" href="' . esc_url(admin_url('admin.php?page=' . rawurlencode($page))) . '">Open ' . esc_html($title) . '</a></section>';
    }
}
