<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\Induction\TeacherInductionCurriculum;
use Delnavazan\Platform\Core\Application\Induction\TeacherInductionService;

/**
 * Authenticated, nonce-protected boundary for Teacher induction completion.
 *
 * The request may name a curriculum module only. The Teacher is resolved from the authenticated
 * principal link and the module is validated against the code-owned curriculum before any write.
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
        $moduleId = TeacherInductionCurriculum::moduleId( (string) wp_unslash( $_POST['module_id'] ?? '' ) );
        if ( '' === $moduleId ) {
            self::redirect( 'بخش درخواستی در راهنمای تدریس وجود ندارد.', true, '' );
        }
        try {
            $result = ( new TeacherInductionService() )->completeForUser( $moduleId );
            $notice = ! empty( $result['already_completed'] )
                ? 'این بخش پیش‌تر تکمیل شده بود؛ پیشرفت شما بدون تغییر ماند.'
                : 'این بخش از راهنمای تدریس تکمیل شد.';
            self::redirect( $notice, false, $moduleId );
        } catch ( \Throwable $error ) {
            self::redirect( 'ثبت پیشرفت انجام نشد. اگر حساب شما هنوز آماده نیست، ابتدا راه‌اندازی حساب را کامل کنید.', true, $moduleId );
        }
    }

    private static function redirect( string $notice, bool $error, string $moduleId ): never {
        $args = array(
            'teacher-view' => 'induction',
            'dzn_notice' => $notice,
            'dzn_error' => $error ? '1' : '0',
        );
        if ( '' !== $moduleId ) { $args['induction-module'] = $moduleId; }
        $url = add_query_arg( $args, home_url( '/teacher-portal/' ) );
        nocache_headers();
        if ( wp_safe_redirect( $url ) ) { exit; }
        wp_die( esc_html( $notice ), 'Delnavazan', array( 'response' => $error ? 400 : 200 ) );
    }
}
