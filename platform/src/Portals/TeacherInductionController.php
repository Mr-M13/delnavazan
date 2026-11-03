<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\Induction\TeacherInductionCurriculum;
use Delnavazan\Platform\Core\Application\Induction\TeacherInductionService;

/**
 * Legacy progress boundary for the one-page Teacher quick-start.
 *
 * The guide itself is read-only and no longer links to portal sections. This endpoint is kept
 * fail-closed for older bookmarks: it may only name a canonical step, resolves the Teacher from
 * the authenticated principal and redirects back to the guide.
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
            self::redirect( self::guide_url(), 'این بخش در راهنمای تدریس وجود ندارد.', true );
        }
        try {
            $result = ( new TeacherInductionService() )->completeForUser( $stepId );
            $notice = ! empty( $result['already_completed'] )
                ? 'این بخش را قبلاً مرور کرده‌اید.'
                : 'مرور این بخش در راهنمای تدریس ثبت شد.';
            self::redirect( self::guide_url(), $notice, false );
        } catch ( \Throwable $error ) {
            self::redirect( self::guide_url(), 'ثبت مرور انجام نشد. اگر حساب شما هنوز آماده نیست، ابتدا راه‌اندازی حساب را کامل کنید.', true );
        }
    }

    private static function guide_url(): string {
        return add_query_arg( 'teacher-view', 'induction', home_url( '/teacher-portal/' ) );
    }

    private static function redirect( string $url, string $notice, bool $error ): never {
        $url = add_query_arg( array( 'dzn_notice' => $notice, 'dzn_error' => $error ? '1' : '0' ), $url );
        nocache_headers();
        if ( wp_safe_redirect( $url ) ) { exit; }
        wp_die( esc_html( $notice ), 'Delnavazan', array( 'response' => $error ? 400 : 200 ) );
    }
}
