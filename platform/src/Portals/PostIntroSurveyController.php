<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\PostIntroFeedbackService;

/**
 * Student-owned last-day post-intro feedback submission.
 *
 * The survey records feedback separately from continuation. Only an explicit student choice of
 * contact or decline is forwarded to the existing canonical continuation authority.
 */
final class PostIntroSurveyController {
    public const ACTION = 'dzn_post_intro_survey';

    public static function register(): void {
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
    }

    public static function handle(): void {
        check_admin_referer(self::ACTION);
        $uid = sanitize_text_field((string) ($_POST['preparation_uid'] ?? ''));
        $reason = sanitize_key((string) ($_POST['feedback_reason'] ?? ''));
        $choice = sanitize_key((string) ($_POST['continuation_choice'] ?? ''));
        $comment = sanitize_textarea_field((string) ($_POST['feedback_comment'] ?? ''));
        try {
            if (!preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $uid)) {
                throw new \InvalidArgumentException('post_intro_survey_unavailable');
            }
            (new PostIntroFeedbackService())->submit($uid, $reason, $comment, $choice);
            self::redirect('survey_recorded');
        } catch (\Throwable $error) {
            self::redirect(self::safeStatus($error));
        }
    }

    private static function safeStatus(\Throwable $error): string {
        $message = trim($error->getMessage());
        return match ($message) {
            'post_intro_survey_unavailable', 'post_intro_survey_reason_required', 'post_intro_survey_choice_required' => $message,
            'Unauthorized' => 'post_intro_survey_unavailable',
            'continuation_closed', 'teacher_match_suppressed' => 'post_intro_survey_unavailable',
            default => 'post_intro_unavailable',
        };
    }

    private static function redirect(string $status): void {
        $url = add_query_arg('post_intro_status', $status, home_url('/student-portal/')) . '#dzn-post-intro-survey';
        wp_safe_redirect($url, 303);
        exit;
    }
}
