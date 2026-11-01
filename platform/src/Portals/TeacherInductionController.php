<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\Induction\TeacherInductionCurriculum;
use Delnavazan\Platform\Core\Application\Induction\TeacherInductionService;

/**
 * Nonce-protected boundary for the one-page Teacher quick-start.
 *
 * A step click records that the Teacher has seen the step and then jumps to the real portal
 * screen with a highlight marker. The request may name only a canonical step; the Teacher is
 * resolved from the authenticated principal and the live readiness authority.
 */
final class TeacherInductionController {
    public const ACTION = 'dzn_teacher_induction_complete';

    public static function register(): void {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'complete' ) );
    }

    public static function complete(): never {
        if ( ! is_user_logged_in() ) {
            wp_die( 'Access denied.', 'Delnavazan', array( 'response' => 403 ) );
        }
        check_admin_referer( self::ACTION );
        $stepId = TeacherInductionCurriculum::moduleId( (string) wp_unslash( $_POST['module_id'] ?? '' ) );
        $step = '' !== $stepId ? TeacherInductionCurriculum::module( $stepId ) : null;
        if ( ! $step ) {
            self::redirect( home_url( '/teacher-portal/' ), 'این بخش در راهنمای سریع وجود ندارد.', true, '' );
        }
        try {
            $result = ( new TeacherInductionService() )->completeForUser( $stepId );
            $notice = ! empty( $result['already_completed'] )
                ? 'این بخش را قبلاً دیده‌اید. همان بخش در پرتال مشخص شده است.'
                : 'بخش بعدی راهنما در پرتال مشخص شد.';
            self::redirect( self::screen_url( (string) $step['portal_screen'] ), $notice, false, $stepId );
        } catch ( \Throwable $error ) {
            self::redirect( home_url( '/teacher-portal/' ), 'ثبت پیشرفت انجام نشد. اگر حساب شما هنوز آماده نیست، ابتدا راه‌اندازی حساب را کامل کنید.', true, '' );
        }
    }

    private static function screen_url( string $screen ): string {
        $screen = in_array( $screen, array( 'home', 'classes', 'finance', 'settings' ), true ) ? $screen : 'home';
        return add_query_arg( 'teacher-view', $screen, home_url( '/teacher-portal/' ) );
    }

    private static function redirect( string $base, string $notice, bool $error, string $stepId ): never {
        $args = array( 'dzn_notice' => $notice, 'dzn_error' => $error ? '1' : '0' );
        if ( '' !== $stepId ) { $args['induction-highlight'] = $stepId; }
        $url = add_query_arg( $args, $base );
        nocache_headers();
        if ( wp_safe_redirect( $url ) ) { exit; }
        wp_die( esc_html( $notice ), 'Delnavazan', array( 'response' => $error ? 400 : 200 ) );
    }
}
