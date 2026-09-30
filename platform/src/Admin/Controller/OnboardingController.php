<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\{PrincipalInvitationReadService, PrincipalInvitationService};

final class OnboardingController {
    public static function handlePost(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return;
        if ( ! current_user_can( 'dzn_manage_onboarding' ) ) wp_die( 'Access denied.', 'Delnavazan', ['response' => 403] );
        $post = wp_unslash( $_POST ); $action = sanitize_key( $post['dzn_action'] ?? '' );
        if ( ! in_array( $action, ['issue_teacher_invitation', 'revoke_teacher_invitation', 'offboard_teacher_principal'], true ) ) return;
        check_admin_referer( 'dzn_platform_' . $action );
        try {
            $service = new PrincipalInvitationService(); $teacher = absint( $post['teacher_id'] ?? 0 );
            if ( $action === 'issue_teacher_invitation' ) $service->issue( $teacher, sanitize_email( $post['recipient'] ?? '' ) );
            elseif ( $action === 'revoke_teacher_invitation' ) $service->revoke( $teacher, 'admin_revoked' );
            else $service->offboard( $teacher, 'admin_offboarded' );
            self::redirect( 'Saved' );
        } catch ( \Throwable ) { self::redirect( 'Operation failed; no change was saved.', true ); }
    }
    public static function screen(): void {
        if ( ! current_user_can( 'dzn_manage_onboarding' ) ) { echo '<div class="wrap"><p>Access denied.</p></div>'; return; }
        echo '<div class="wrap"><h1>Teacher onboarding</h1><p>Issuing creates a delivery intent only. Raw invitation secrets are never rendered, retained, logged, or placed in URLs.</p>';
        if ( isset( $_GET['dzn_notice'] ) ) echo '<div class="notice ' . ( ( $_GET['dzn_error'] ?? '' ) === '1' ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['dzn_notice'] ) ) ) . '</p></div>';
        echo '<h2>Issue invitation</h2><form method="post">'; wp_nonce_field( 'dzn_platform_issue_teacher_invitation' );
        echo '<input type="hidden" name="dzn_action" value="issue_teacher_invitation"><p><label>Teacher ID <input required type="number" name="teacher_id"></label></p><p><label>Recipient email <input required type="email" name="recipient"></label></p>';
        submit_button( 'Issue / reissue invitation' ); echo '</form><h2>Current states</h2><table class="widefat striped"><thead><tr><th>Teacher</th><th>Name</th><th>Teacher state</th><th>Onboarding</th><th>WP principal</th><th>Invitation</th></tr></thead><tbody>';
        foreach ( ( new PrincipalInvitationReadService() )->rows() as $row ) echo '<tr><td>' . esc_html( (string) $row->teacher_id ) . '</td><td>' . esc_html( (string) $row->display_name ) . '</td><td>' . esc_html( (string) $row->teacher_status ) . '</td><td>' . esc_html( (string) ( $row->onboarding_state ?: 'not_started' ) ) . '</td><td>' . esc_html( $row->wordpress_user_id ? 'linked' : 'none' ) . '</td><td>' . esc_html( (string) ( $row->invitation_status ?: 'none' ) ) . '</td></tr>';
        echo '</tbody></table></div>';
    }
    private static function redirect( string $notice, bool $error = false ): never {
        $url = add_query_arg( ['page' => 'dzn-onboarding', 'dzn_notice' => $notice, 'dzn_error' => $error ? '1' : '0'], admin_url( 'admin.php' ) );
        nocache_headers(); if ( wp_safe_redirect( $url ) ) exit;
        wp_die( esc_html( $notice ), 'Delnavazan', ['response' => $error ? 500 : 200] );
    }
}
