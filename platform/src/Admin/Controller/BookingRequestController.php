<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\BookingRequestMatchAssessmentService;
use Delnavazan\Platform\Core\Application\BookingRequestPrivacyService;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestIntakeRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestRepository;

/** Protected intake inspection; match assessment is current-state advisory only. */
final class BookingRequestController {
    private static ?array $assessment = null;

    public static function handlePost(): void {
        if ( ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ) return;
        $action = isset($_POST['dzn_booking_request_action']) && is_string($_POST['dzn_booking_request_action']) ? $_POST['dzn_booking_request_action'] : '';
        $id = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        if ( ! $id ) return;
        if ( $action === 'assess_matches' ) {
            self::requireCapabilities('dzn_view_booking_requests', 'dzn_prepare_booking_request_matches');
            check_admin_referer('dzn_prepare_booking_request_matches_' . $id);
            try {
                self::$assessment = (new BookingRequestMatchAssessmentService())->assess((int) $id, get_current_user_id());
            } catch (\Throwable) {
                // Do not reflect private intake facts or database diagnostics.
                self::$assessment = array('error' => 'Assessment could not be completed.');
            }
            return;
        }
        if ( $action === 'review_duplicate' ) {
            self::requireCapabilities('dzn_view_booking_requests', 'dzn_review_booking_request_duplicates');
            check_admin_referer('dzn_review_booking_request_' . $id);
            $flag = filter_var($_POST['flag_id'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
            $status = isset($_POST['review_status']) && is_string($_POST['review_status']) ? $_POST['review_status'] : '';
            $reason = isset($_POST['reason']) && is_string($_POST['reason']) ? sanitize_key($_POST['reason']) : '';
            if ( ! $flag || ! in_array($status, array('reviewed_no_match','reviewed_possible_duplicate','dismissed'), true) || $reason === '' ) wp_die('Invalid review.');
            (new BookingRequestIntakeRepository())->reviewDuplicateFlag((int) $id, (int) $flag, $status, $reason, get_current_user_id(), gmdate('Y-m-d H:i:s'));
        } elseif ( $action === 'erase_pii' ) {
            self::requireCapabilities('dzn_view_booking_requests', 'dzn_erase_booking_request_pii');
            check_admin_referer('dzn_erase_booking_request_' . $id);
            $reason = isset($_POST['reason']) && is_string($_POST['reason']) ? sanitize_key($_POST['reason']) : '';
            (new BookingRequestPrivacyService())->erase((int) $id, get_current_user_id(), $reason);
        } else return;
        wp_safe_redirect(add_query_arg(array('page' => 'dzn-booking-requests', 'request_id' => $id, 'updated' => '1'), admin_url('admin.php')));
        exit;
    }

    public static function screen(): void {
        if ( ! current_user_can('dzn_view_booking_requests') ) { echo '<div class="wrap"><p>Access denied.</p></div>'; return; }
        $repo = new BookingRequestRepository();
        $id = filter_var($_GET['request_id'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        echo '<div class="wrap"><h1>Booking Requests</h1>';
        if ( $id && ($row = $repo->details((int) $id)) ) { self::details($repo, (int) $id, $row); echo '</div>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>Reference</th><th>Instrument</th><th>Lifecycle</th><th>Resolution</th><th>Created</th></tr></thead><tbody>';
        foreach ( $repo->rows() as $row ) echo '<tr><td><a href="' . esc_url(add_query_arg(array('page' => 'dzn-booking-requests','request_id' => $row->id),admin_url('admin.php'))) . '">' . esc_html((string) $row->reference_code) . '</a></td><td>' . esc_html((string) $row->requested_instrument_id) . '</td><td>' . esc_html((string) $row->lifecycle_status) . '</td><td>' . esc_html((string) $row->resolution_state) . '</td><td>' . esc_html((string) $row->created_at) . '</td></tr>';
        echo '</tbody></table></div>';
    }

    private static function details(BookingRequestRepository $repo, int $id, object $row): void {
        foreach (get_object_vars($row) as $field => $value) echo '<p><strong>' . esc_html($field) . ':</strong> ' . esc_html((string) $value) . '</p>';
        echo '<h2>Requested times</h2>'; foreach ($repo->times($id) as $time) echo '<p>' . esc_html(wp_json_encode($time)) . '</p>';
        self::assessmentPresentation($id, $row);
        $flags = (new BookingRequestIntakeRepository())->duplicateFlags($id);
        if ($flags) { echo '<h2>Duplicate-review signals</h2>'; foreach ($flags as $flag) { echo '<p>Candidate #' . esc_html((string) $flag->candidate_booking_request_id) . ' — ' . esc_html((string) $flag->signal_type) . ' — ' . esc_html((string) $flag->status) . '</p>'; if ($flag->status === 'open' && current_user_can('dzn_review_booking_request_duplicates')) { echo '<form method="post">'; wp_nonce_field('dzn_review_booking_request_' . $id); echo '<input type="hidden" name="dzn_booking_request_action" value="review_duplicate"><input type="hidden" name="request_id" value="' . esc_attr((string) $id) . '"><input type="hidden" name="flag_id" value="' . esc_attr((string) $flag->id) . '"><select name="review_status"><option value="reviewed_no_match">No match</option><option value="reviewed_possible_duplicate">Possible duplicate</option><option value="dismissed">Dismissed</option></select><input name="reason" required maxlength="64"><button class="button">Save review</button></form>'; } } }
        if ($row->lifecycle_status === 'submitted' && $row->resolution_state === 'unresolved' && current_user_can('dzn_erase_booking_request_pii')) { echo '<h2>Privacy erasure</h2><form method="post">'; wp_nonce_field('dzn_erase_booking_request_' . $id); echo '<input type="hidden" name="dzn_booking_request_action" value="erase_pii"><input type="hidden" name="request_id" value="' . esc_attr((string) $id) . '"><input name="reason" required maxlength="64" pattern="[a-z0-9_]{3,64}"><button class="button button-secondary">Erase identifying data</button></form>'; }
    }

    private static function assessmentPresentation(int $id, object $row): void {
        if ($row->lifecycle_status !== 'submitted' || $row->resolution_state !== 'unresolved' || !current_user_can('dzn_prepare_booking_request_matches')) return;
        echo '<h2>Current advisory assessment</h2><p>This does not select, reserve, assign, or contact a Teacher.</p><form method="post">';
        wp_nonce_field('dzn_prepare_booking_request_matches_' . $id);
        echo '<input type="hidden" name="dzn_booking_request_action" value="assess_matches"><input type="hidden" name="request_id" value="' . esc_attr((string) $id) . '"><button class="button">Assess current matchability</button></form>';
        if (self::$assessment === null) return;
        if (isset(self::$assessment['error'])) { echo '<p>' . esc_html((string) self::$assessment['error']) . '</p>'; return; }
        echo '<p><strong>Outcome:</strong> ' . esc_html((string) self::$assessment['outcome']) . ' — criteria ' . esc_html((string) self::$assessment['criteria_version']) . ' — assessed ' . esc_html((string) self::$assessment['assessed_at']) . '</p><ul>';
        foreach (self::$assessment['requested_times'] as $time) echo '<li>Preference ' . esc_html((string) $time['sequence']) . ': ' . ($time['covered'] ? 'currently covered' : 'no current coverage') . ($time['limited'] ? ' (limited Teacher included)' : '') . '</li>';
        echo '</ul>';
    }

    private static function requireCapabilities(string ...$caps): void { foreach ($caps as $cap) if (!current_user_can($cap)) wp_die('Access denied.'); }
}
