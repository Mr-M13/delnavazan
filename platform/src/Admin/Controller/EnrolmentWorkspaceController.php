<?php
namespace Delnavazan\Platform\Admin\Controller;

/**
 * Temporary consolidated Enrolment workspace.
 *
 * This page groups the existing authorised booking, coordination, enrolment and
 * term workflows. It is navigation only: each card opens the canonical screen
 * that still owns its reads and writes. Contextual record actions replace these
 * links in later PRs, but no workflow is hidden in the meantime.
 */
final class EnrolmentWorkspaceController {
    public static function screen(): void {
        if (!current_user_can(Menu::ENROLMENT_WORKSPACE_CAP)) wp_die('Forbidden', 'Delnavazan', array('response' => 403));
        echo '<div class="wrap"><h1>Bookings &amp; Enrolments</h1>';
        echo '<p>Follow a learner from booking request through coordination, accepted arrangement, Enrolment and Term. Each card opens the existing authorised workflow; no booking or Enrolment authority is merged here.</p>';
        echo '<div class="dzn-workspace-grid">';
        self::card('Booking requests', 'Review incoming requests, requested times and current matchability.', 'dzn-booking-requests', 'dzn_view_booking_requests');
        self::card('Request coordination', 'Review candidates, teacher assent, proposals, acceptance evidence and the final arrangement.', 'dzn-booking-request-coordination', 'dzn_manage_booking_request_coordination');
        self::card('Enrolments', 'Open the student/course Enrolment, lifecycle history and authorised lifecycle actions.', 'dzn-enrolment', 'dzn_manage_enrolments');
        self::card('Terms', 'Create, activate, close or cancel a canonical Term for an eligible Enrolment.', 'dzn-term', 'dzn_manage_terms');
        echo '</div>';
        echo '<p class="dzn-workspace-note">Temporary navigation: these are the current authorised workflows. Contextual conversion, account-claim, assignment and Term actions will be added on the individual records in later PRs.</p>';
        echo '</div>';
    }

    private static function card(string $title, string $description, string $page, string $capability): void {
        if (!current_user_can($capability)) return;
        echo '<section class="dzn-workspace-card"><h2>' . esc_html($title) . '</h2><p>' . esc_html($description) . '</p><a class="button" href="' . esc_url(admin_url('admin.php?page=' . rawurlencode($page))) . '">Open ' . esc_html($title) . '</a></section>';
    }
}
