<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\{PrincipalInvitationReadService, PrincipalInvitationService, TeacherOnboardingService};

final class OnboardingController {
    public static function handlePost(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return;
        if ( ! current_user_can( 'dzn_manage_onboarding' ) ) wp_die( 'Access denied.', 'Delnavazan', array( 'response' => 403 ) );
        $post = wp_unslash( $_POST ); $action = sanitize_key( $post['dzn_action'] ?? '' );
        if ( ! in_array( $action, array( 'invite_new_teacher', 'issue_teacher_invitation', 'revoke_teacher_invitation', 'offboard_teacher_principal', 'review_teacher_onboarding' ), true ) ) return;
        check_admin_referer( 'dzn_platform_' . $action );
        try {
            $teacher = absint( $post['teacher_id'] ?? 0 );
            if ( $action === 'invite_new_teacher' ) {
                ( new PrincipalInvitationService() )->inviteNew( sanitize_email( $post['recipient'] ?? '' ) );
                self::redirect( 'Teacher invitation queued. Delivery status appears below.' );
            } elseif ( $action === 'review_teacher_onboarding' ) {
                ( new TeacherOnboardingService() )->review( $teacher, sanitize_key( $post['decision'] ?? '' ), sanitize_text_field( $post['reason_code'] ?? '' ) );
            } else {
                $service = new PrincipalInvitationService();
                if ( $action === 'issue_teacher_invitation' ) $service->issue( $teacher, sanitize_email( $post['recipient'] ?? '' ), 604800, 'admin-reissue:' . wp_generate_uuid4() );
                elseif ( $action === 'revoke_teacher_invitation' ) $service->revoke( $teacher, 'admin_revoked' );
                else $service->offboard( $teacher, 'admin_offboarded' );
            }
            self::redirect( 'Saved' );
        } catch ( \InvalidArgumentException $e ) { self::redirect( $e->getMessage(), true ); }
        catch ( \Throwable ) { self::redirect( 'Invitation or review could not be saved. Check the status below and try again.', true ); }
    }

    public static function screen(): void {
        if ( ! current_user_can( 'dzn_manage_onboarding' ) ) { echo '<div class="wrap"><p>Access denied.</p></div>'; return; }
        echo '<div class="wrap"><h1>Invite and onboard teachers</h1><p>Enter the teacher’s email. They will create or connect their own account, complete their profile and availability, then submit it for your review. An invitation is queued first; check its delivery state below before asking the teacher to use it. Raw invitation secrets are never rendered, retained, logged, or placed in URLs.</p>';
        if ( isset( $_GET['dzn_notice'] ) ) echo '<div class="notice ' . ( ( $_GET['dzn_error'] ?? '' ) === '1' ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['dzn_notice'] ) ) ) . '</p></div>';
        if ( current_user_can( 'dzn_issue_teacher_invitations' ) && current_user_can( 'dzn_manage_teachers' ) ) {
            echo '<h2>Invite a new teacher</h2><form method="post">'; wp_nonce_field( 'dzn_platform_invite_new_teacher' );
            echo '<input type="hidden" name="dzn_action" value="invite_new_teacher"><p><label>Email address <input required type="email" name="recipient" autocomplete="email"></label></p>';
            submit_button( 'Queue invitation' ); echo '</form>';
        }
        if ( current_user_can( 'dzn_issue_teacher_invitations' ) ) {
            echo '<details><summary>Existing teacher: resend or change invitation email</summary><form method="post">'; wp_nonce_field( 'dzn_platform_issue_teacher_invitation' );
            echo '<input type="hidden" name="dzn_action" value="issue_teacher_invitation"><p><label>Teacher ID <input required type="number" min="1" name="teacher_id"></label></p><p><label>Recipient email <input required type="email" name="recipient"></label></p>';
            submit_button( 'Issue / reissue invitation', 'secondary' ); echo '</form></details>';
        }

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

        echo '<h2>Invitation and account status</h2><p>“Delivered” means the mail transport accepted the message; it does not prove inbox receipt. A failed delivery needs a resend after mail is repaired. The teacher enters their code at <code>' . esc_html( home_url( '/teacher-invitation/' ) ) . '</code>.</p><table class="widefat striped"><thead><tr><th>Teacher</th><th>Email / name</th><th>Account</th><th>Invitation delivery</th><th>Onboarding</th></tr></thead><tbody>';
        foreach ( ( new PrincipalInvitationReadService() )->rows() as $row ) {
            $delivery = $row->generation_status === 'claimed' ? 'Claimed' : ( $row->delivery_status === 'delivered' ? 'Accepted by mail transport' : ( $row->delivery_status === 'failed' ? 'Delivery failed' : ( $row->delivery_status === 'prepared' ? 'Sending / delivery uncertain' : ( $row->delivery_status === 'pending' ? 'Queued for delivery' : 'No invitation queued' ) ) ) );
            echo '<tr><td>#' . esc_html( (string) $row->teacher_id ) . '</td><td>' . esc_html( (string) $row->email ) . '<br><small>' . esc_html( (string) $row->display_name ) . '</small></td><td>' . esc_html( $row->wordpress_user_id ? 'Linked' : 'Awaiting claim' ) . '</td><td>' . esc_html( $delivery ) . ( $row->delivery_reason ? '<br><small>Reason: ' . esc_html( (string) $row->delivery_reason ) . '</small>' : '' ) . '</td><td>' . esc_html( (string) ( $row->onboarding_state ?: 'Not started' ) ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function redirect(string $notice, bool $error = false): never {
        $url = add_query_arg( array( 'page' => 'dzn-onboarding', 'dzn_notice' => $notice, 'dzn_error' => $error ? '1' : '0' ), admin_url( 'admin.php' ) );
        nocache_headers(); if ( wp_safe_redirect( $url ) ) exit;
        wp_die( esc_html( $notice ), 'Delnavazan', array( 'response' => $error ? 500 : 200 ) );
    }
}
