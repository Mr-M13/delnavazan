<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\{PrincipalInvitationReadService, PrincipalInvitationService, TeacherOnboardingService};

final class OnboardingController {
    public static function handlePost(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return;
        if ( ! current_user_can( 'dzn_manage_onboarding' ) ) wp_die( 'Access denied.', 'Delnavazan', array( 'response' => 403 ) );
        $post = wp_unslash( $_POST ); $action = sanitize_key( $post['dzn_action'] ?? '' );
        if ( ! in_array( $action, array( 'issue_teacher_invitation', 'revoke_teacher_invitation', 'offboard_teacher_principal', 'admin_link_teacher_principal' ), true ) ) return;
        check_admin_referer( 'dzn_platform_' . $action );
        try {
            $teacher = absint( $post['teacher_id_override'] ?? 0 ) ?: absint( $post['teacher_id'] ?? 0 );
            if ( $action === 'review_teacher_onboarding' ) {
                ( new TeacherOnboardingService() )->review( $teacher, sanitize_key( $post['decision'] ?? '' ), sanitize_text_field( $post['reason_code'] ?? '' ) );
            } else {
                $service = new PrincipalInvitationService();
                if ( $action === 'issue_teacher_invitation' ) $service->issue( $teacher, sanitize_email( $post['recipient'] ?? '' ), 604800, sanitize_text_field( $post['command_key'] ?? '' ) ?: wp_generate_uuid4() );
                elseif ( $action === 'revoke_teacher_invitation' ) $service->revoke( $teacher, 'admin_revoked' );
                elseif ( $action === 'admin_link_teacher_principal' ) $service->linkExistingPrincipal( $teacher, absint( $post['wordpress_user_id'] ?? 0 ) );
                else $service->offboard( $teacher, 'admin_offboarded' );
            }
            self::redirect( 'Saved' );
        } catch ( \Throwable ) { self::redirect( 'Operation failed; no change was saved.', true ); }
    }

    public static function screen(): void {
        if ( ! current_user_can( 'dzn_manage_onboarding' ) ) { echo '<div class="wrap"><p>Access denied.</p></div>'; return; }
        $transport = ( function_exists( 'wp_mail' ) && (bool) apply_filters( 'dzn_teacher_invitation_email_transport_configured', false ) ) ? 'configured_unverified' : 'not_configured';
        $transportCopy = $transport === 'configured_unverified' ? 'Outbound transport is configured, but WordPress mail acceptance is not recipient delivery.' : 'No outbound email transport is configured; issued invitations will be recorded as failed until transport is configured.';
        echo '<div class="wrap"><h1>Teacher onboarding</h1><p>Issuing creates a delivery intent; it does not mean an email was sent or received. ' . esc_html( $transportCopy ) . ' Raw invitation secrets are never rendered, retained, logged, or placed in URLs, so this screen cannot provide a manual invitation code. The claim page is <code>' . esc_html( home_url( '/teacher-invitation/' ) ) . '</code>.</p>';
        $contextTeacherId = self::contextId();
        if ( isset( $_GET['dzn_notice'] ) ) echo '<div class="notice ' . ( ( $_GET['dzn_error'] ?? '' ) === '1' ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['dzn_notice'] ) ) ) . '</p></div>';
        if ( current_user_can( 'dzn_issue_teacher_invitations' ) ) {
            echo '<h2>Issue invitation</h2><form method="post">'; wp_nonce_field( 'dzn_platform_issue_teacher_invitation' );
            echo '<input type="hidden" name="dzn_action" value="issue_teacher_invitation"><input type="hidden" name="command_key" value="' . esc_attr( wp_generate_uuid4() ) . '"><p><label>Teacher <select required name="teacher_id"><option value="">Select teacher</option>';
            foreach ( ( new PrincipalInvitationReadService() )->rows() as $teacherRow ) echo '<option value="' . esc_attr( (string) $teacherRow->teacher_id ) . '" ' . selected( $contextTeacherId, (string) $teacherRow->teacher_id, false ) . '>' . esc_html( $teacherRow->display_name . ' (#' . $teacherRow->teacher_id . ')' ) . '</option>';
            echo '</select></label></p><p><label>Teacher ID (for Teachers not shown above) <input min="1" type="number" name="teacher_id_override"></label></p><p><label>Recipient email <input required type="email" name="recipient"></label></p>';
            submit_button( 'Queue invitation / create replacement' ); echo '</form>';
        }

        echo '<h2>Admin-assisted principal link</h2><p><strong>Use only after verifying the person’s identity.</strong> This binds an existing WordPress account to a Teacher and bypasses email invitation delivery as a delivery mechanism only. It does not bypass principal ownership safeguards; the teacher must complete onboarding before automatic activation.</p><form method="post">'; wp_nonce_field( 'dzn_platform_admin_link_teacher_principal' );
        echo '<input type="hidden" name="dzn_action" value="admin_link_teacher_principal"><p><label>Teacher <select required name="teacher_id"><option value="">Select teacher</option>';
        foreach ( ( new PrincipalInvitationReadService() )->rows() as $teacherRow ) echo '<option value="' . esc_attr( (string) $teacherRow->teacher_id ) . '" ' . selected( $contextTeacherId, (string) $teacherRow->teacher_id, false ) . '>' . esc_html( $teacherRow->display_name . ' (#' . $teacherRow->teacher_id . ')' ) . '</option>';
        echo '</select></label></p><p><label>Existing WordPress user <select required name="wordpress_user_id"><option value="">Select account</option>';
        foreach ( get_users( array( 'number' => 200, 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => array( 'ID', 'display_name', 'user_login', 'user_email' ) ) ) as $user ) {
            $label = trim( (string) $user->display_name ) . ' (' . (string) $user->user_login . ' · ' . (string) $user->user_email . ')';
            echo '<option value="' . esc_attr( (string) $user->ID ) . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select></label></p>'; submit_button( 'Link existing account to Teacher', 'secondary' ); echo '</form>';

        echo '<h2>Onboarding diagnostics</h2><p>Invitation is the approval decision. Teachers activate automatically when profile and availability requirements are complete. This table is diagnostic; it does not gate readiness.</p><table class="widefat striped"><thead><tr><th>Teacher</th><th>Profile</th><th>Availability</th><th>Agreement</th><th>State</th><th>Readiness</th></tr></thead><tbody>';
        $rows = ( new TeacherOnboardingService() )->reviewRows();
        if ( ! $rows ) echo '<tr><td colspan="6">No linked teachers have started onboarding.</td></tr>';
        foreach ( $rows as $row ) {
            echo '<tr><td>' . esc_html( $row->display_name . ' (#' . $row->teacher_id . ')' ) . '<br><small>' . esc_html( (string) $row->email ) . '</small></td>';
            echo '<td>' . esc_html( (string) $row->profile_state ) . '</td><td>' . esc_html( (string) $row->availability_state ) . '</td><td>' . esc_html( (string) $row->agreement_state ) . '</td>';
            echo '<td>' . esc_html( (string) $row->state ) . ( $row->review_reason_code ? '<br><small>' . esc_html( (string) $row->review_reason_code ) . '</small>' : '' ) . '</td><td>' . esc_html((string)$row->readiness_state) . '</td>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Invitation and principal status</h2><table class="widefat striped"><thead><tr><th>Teacher</th><th>Name</th><th>Teacher state</th><th>Onboarding</th><th>WP principal</th><th>Invitation</th><th>Email transport outcome</th></tr></thead><tbody>';
        foreach ( ( new PrincipalInvitationReadService() )->rows() as $row ) {
            $delivery = (string) ( $row->delivery_status ?? '' );
            $description = $delivery === 'delivered' ? 'Accepted by WordPress mail transport; recipient delivery is not confirmed' : ( $delivery === 'failed' ? 'Not sent: ' . (string) ( $row->delivery_failure_reason ?? 'transport_failed' ) : ( $delivery === 'pending' ? 'Queued; not yet confirmed' : ( $delivery === 'prepared' ? 'Delivery interrupted after secret preparation; create a replacement invitation' : 'No delivery recorded' ) ) );
            if ( ! empty( $row->delivery_processed_at ) ) $description .= ' · ' . (string) $row->delivery_processed_at;
            echo '<tr><td>' . esc_html( (string) $row->teacher_id ) . '</td><td>' . esc_html( (string) $row->display_name ) . '</td><td>' . esc_html( (string) $row->teacher_status ) . '</td><td>' . esc_html( (string) ( $row->onboarding_state ?: 'not_started' ) ) . '</td><td>' . esc_html( $row->wordpress_user_id ? 'linked' : 'none' ) . '</td><td>' . esc_html( (string) ( $row->invitation_status ?: 'none' ) . ( $row->generation_status ? ' / ' . $row->generation_status : '' ) ) . '</td><td>' . esc_html( $description ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function redirect(string $notice, bool $error = false): never {
        $url = add_query_arg( array( 'page' => 'dzn-onboarding', 'dzn_notice' => $notice, 'dzn_error' => $error ? '1' : '0' ), admin_url( 'admin.php' ) );
        nocache_headers(); if ( wp_safe_redirect( $url ) ) exit;
        wp_die( esc_html( $notice ), 'Delnavazan', array( 'response' => $error ? 500 : 200 ) );
    }

    private static function contextId(): string { $value=wp_unslash($_GET['teacher_id']??''); return is_scalar($value)&&preg_match('/^[1-9][0-9]*$/D',(string)$value)?(string)absint($value):''; }
}
