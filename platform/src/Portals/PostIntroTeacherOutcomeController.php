<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\PostIntroPreparationService;

/** Teacher-owned post-intro attendance/outcome record. */
final class PostIntroTeacherOutcomeController {
    public const ACTION = 'dzn_post_intro_outcome';

    public static function register(): void {
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
    }

    public static function handle(): void {
        check_admin_referer(self::ACTION);
        try {
            $principal = (new PortalPrincipalResolver())->resolve('teacher');
            if (!(new \Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository())->hasActiveTeacherAuthority((int) get_current_user_id(), (int) $principal['id'])) {
                throw new \InvalidArgumentException('teacher_onboarding_required');
            }
            $uid = sanitize_text_field((string) ($_POST['confirmation_uid'] ?? ''));
            $outcome = sanitize_key((string) ($_POST['outcome'] ?? ''));
            $note = sanitize_textarea_field((string) ($_POST['note'] ?? ''));
            if (!preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $uid)) throw new \InvalidArgumentException('intro_booking_not_found');
            (new PostIntroPreparationService())->recordTeacherOutcome($uid, (int) $principal['id'], $outcome, $note);
            self::redirect('recorded');
        } catch (\Throwable $error) {
            self::redirect('unavailable');
        }
    }

    private static function redirect(string $status): void {
        wp_safe_redirect(add_query_arg('post_intro_outcome', $status, home_url('/teacher-portal/')), 303);
        exit;
    }
}
