<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\{PrincipalInvitationReadService, PrincipalInvitationService, TeacherOnboardingService};

final class OnboardingController {
    public static function handlePost(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return;
        if ( ! current_user_can( 'dzn_manage_onboarding' ) ) wp_die( 'Access denied.', 'Delnavazan', array( 'response' => 403 ) );
        $post = wp_unslash( $_POST ); $action = sanitize_key( $post['dzn_action'] ?? '' );
        if ( ! in_array( $action, array( 'issue_teacher_invitation', 'revoke_teacher_invitation', 'offboard_teacher_principal', 'review_teacher_onboarding' ), true ) ) return;
        check_admin_referer( 'dzn_platform_' . $action );
        try {
            $teacher = absint( $post['teacher_id'] ?? 0 );
            if ( $action === 'review_teacher_onboarding' ) {
                ( new TeacherOnboardingService() )->review( $teacher, sanitize_key( $post['decision'] ?? '' ), sanitize_text_field( $post['reason_code'] ?? '' ) );
            } else {
                $service = new PrincipalInvitationService();
                if ( $action === 'issue_teacher_invitation' ) $service->issue( $teacher, sanitize_email( $post['recipient'] ?? '' ) );
                elseif ( $action === 'revoke_teacher_invitation' ) $service->revoke( $teacher, 'admin_revoked' );
                else $service->offboard( $teacher, 'admin_offboarded' );
            }
            self::redirect( 'Saved' );
        } catch ( \Throwable ) { self::redirect( 'Operation failed; no change was saved.', true ); }
    }

    public static function screen(): void {
        if ( ! current_user_can( 'dzn_manage_onboarding' ) ) { echo '<div class="wrap"><p>Access denied.</p></div>'; return; }
        echo '<div class="wrap"><h1>Teacher onboarding</h1><p>Invitation-only lifecycle. Issuing creates a secure delivery intent. Raw invitation secrets are never rendered, retained, logged, or placed in URLs. Until a delivery worker is configured, operators must treat delivery as pending rather than copying a token from this screen. The manual code-entry destination is <code>' . esc_html( home_url( '/teacher-invitation/' ) ) . '</code>.</p>';
        if ( isset( $_GET['dzn_notice'] ) ) echo '<div class="notice ' . ( ( $_GET['dzn_error'] ?? '' ) === '1' ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['dzn_notice'] ) ) ) . '</p></div>';
        echo '<h2>Issue invitation</h2><form method="post">'; wp_nonce_field( 'dzn_platform_issue_teacher_invitation' );
        echo '<input type="hidden" name="dzn_action" value="issue_teacher_invitation"><p><label>Teacher ID <input required type="number" min="1" name="teacher_id"></label></p><p><label>Recipient email <input required type="email" name="recipient"></label></p>';
        submit_button( 'Issue / reissue invitation' ); echo '</form>';

        echo '<h2>Review queue</h2><table class="widefat striped"><thead><tr><th>Teacher</th><th>Profile</th><th>Availability</th><th>Agreement</th><th>State</th><th>Review action</th></tr></thead><tbody>';
        $rows = ( new TeacherOnboardingService() )->reviewRows();
        if ( ! $rows ) echo '<tr><td colspan="6">No linked teachers have started onboarding.</td></tr>';
        foreach ( $rows as $row ) {
            echo '<tr><td>' . esc_html( $row->display_name . ' (#' . $row->teacher_id . ')' ) . '<br><small>' . esc_html( (string) $row->email ) . '</small></td>';
            echo '<td>' . esc_html( (string) $row->profile_state ) . '</td><td>' . esc_html( (string) $row->availability_state ) . '</td><td>' . esc_html( (string) $row->agreement_state ) . '</td>';
            echo '<td>' . esc_html( (string) $row->state ) . ( $row->review_reason_code ? '<br><small>' . esc_html( (string) $row->review_reason_code ) . '</small>' : '' ) . '</td><td>';
            if ( $row->state === 'pending_review' ) {
                echo '<form method="post">'; wp_nonce_field( 'dzn_platform_review_teacher_onboarding' );
                echo '<input type="hidden" name="dzn_action" value="review_teacher_onboarding"><input type="hidden" name="teacher_id" value="' . esc_attr( (string) $row->teacher_id ) . '">';
                echo '<select name="decision" aria-label="Decision"><option value="approve">Approve and activate</option><option value="return">Return for changes</option><option value="reject">Reject (resubmittable)</option></select> ';
                echo '<input type="text" maxlength="191" name="reason_code" placeholder="Reason required for return/reject"> ';
                submit_button( 'Save review', 'secondary', 'submit', false ); echo '</form>';
            } else echo '&mdash;';
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Invitation and principal status</h2><table class="widefat striped"><thead><tr><th>Teacher</th><th>Name</th><th>Teacher state</th><th>Onboarding</th><th>WP principal</th><th>Invitation</th></tr></thead><tbody>';
        foreach ( ( new PrincipalInvitationReadService() )->rows() as $row ) echo '<tr><td>' . esc_html( (string) $row->teacher_id ) . '</td><td>' . esc_html( (string) $row->display_name ) . '</td><td>' . esc_html( (string) $row->teacher_status ) . '</td><td>' . esc_html( (string) ( $row->onboarding_state ?: 'not_started' ) ) . '</td><td>' . esc_html( $row->wordpress_user_id ? 'linked' : 'none' ) . '</td><td>' . esc_html( (string) ( $row->invitation_status ?: 'none' ) ) . '</td></tr>';
        echo '</tbody></table></div>';
    }

    private static function redirect(string $notice, bool $error = false): never {
        $url = add_query_arg( array( 'page' => 'dzn-onboarding', 'dzn_notice' => $notice, 'dzn_error' => $error ? '1' : '0' ), admin_url( 'admin.php' ) );
        nocache_headers(); if ( wp_safe_redirect( $url ) ) exit;
        wp_die( esc_html( $notice ), 'Delnavazan', array( 'response' => $error ? 500 : 200 ) );
    }
}
