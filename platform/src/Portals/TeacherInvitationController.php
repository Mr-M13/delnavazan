<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\PrincipalInvitationService;

/** One-time code claim boundary; invitation secrets are accepted only in POST bodies. */
final class TeacherInvitationController {
    public static function register(): void {
        add_action( 'admin_post_dzn_teacher_invitation_claim', array( __CLASS__, 'claim' ) );
        add_action( 'admin_post_nopriv_dzn_teacher_invitation_claim', array( __CLASS__, 'claim' ) );
    }

    public static function claim(): never {
        check_admin_referer( 'dzn_teacher_invitation_claim' );
        $post = wp_unslash( $_POST ); $secret = trim( (string) ( $post['invitation_code'] ?? '' ) );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $secret ) ) self::redirect( 'کد دعوت معتبر نیست یا منقضی شده است.', true );
        $service = new PrincipalInvitationService(); $command = 'teacher-claim-' . wp_generate_uuid4();
        try {
            if ( is_user_logged_in() ) {
                $userId = (int) get_current_user_id();
                $service->beginExistingClaim( $secret, $userId, $command );
                $service->finalizeClaim( $command, $userId );
            } else {
                $password = (string) ( $post['password'] ?? '' ); $confirmation = (string) ( $post['password_confirmation'] ?? '' );
                if ( strlen( $password ) < 12 || ! hash_equals( $password, $confirmation ) ) throw new \InvalidArgumentException( 'Password requirements not met' );
                $claim = $service->beginProvisioning( $secret, $command ); $recipient = (string) $claim['recipient'];
                if ( email_exists( $recipient ) ) throw new \InvalidArgumentException( 'Existing account must authenticate first' );
                $base = sanitize_user( strstr( $recipient, '@', true ) ?: 'teacher', true ); if ( $base === '' ) $base = 'teacher';
                $login = $base; for ( $i = 0; username_exists( $login ) && $i < 20; $i++ ) $login = $base . wp_rand( 1000, 999999 );
                if ( username_exists( $login ) ) throw new \RuntimeException( 'Unable to allocate account identity' );
                if ( ! function_exists( 'wp_create_user' ) ) require_once ABSPATH . 'wp-admin/includes/user.php';
                $userId = wp_create_user( $login, $password, $recipient );
                if ( is_wp_error( $userId ) || ! $userId ) throw new \RuntimeException( 'Account creation failed' );
                $userId = (int) $userId;
                $service->recordProvisioningResult( $command, $userId, true );
                $service->finalizeClaim( $command, $userId );
                wp_set_current_user( $userId ); wp_set_auth_cookie( $userId, true, is_ssl() );
            }
            $user = get_userdata( $userId ); if ( $user && get_role( 'dzn_teacher' ) ) $user->add_role( 'dzn_teacher' );
            unset( $secret, $password, $confirmation );
            wp_safe_redirect( add_query_arg( 'teacher-view', 'onboarding', home_url( '/teacher-portal/' ) ) ); exit;
        } catch ( \Throwable $e ) {
            unset( $secret, $password, $confirmation );
            $message = is_user_logged_in() ? 'اتصال حساب انجام نشد. کد را بررسی کنید یا با مدیر تماس بگیرید.' : 'ساخت یا اتصال حساب انجام نشد. اگر قبلاً حساب دارید، ابتدا وارد شوید و دوباره کد را ثبت کنید.';
            self::redirect( $message, true );
        }
    }

    private static function redirect(string $notice, bool $error = false): never {
        $url = add_query_arg( array( 'dzn_notice' => $notice, 'dzn_error' => $error ? '1' : '0' ), home_url( '/teacher-invitation/' ) );
        nocache_headers(); if ( wp_safe_redirect( $url ) ) exit;
        wp_die( esc_html( $notice ), 'Delnavazan', array( 'response' => $error ? 400 : 200 ) );
    }
}
