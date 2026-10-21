<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\PostIntroContinuationService;

/** Student-owned "Complete Payment / Confirm Enrolment" endpoint. */
final class PostIntroContinuationController {
    public const ACTION = 'dzn_post_intro_continue';

    public static function register(): void {
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
    }

    public static function handle(): void {
        check_admin_referer(self::ACTION);
        $uid = sanitize_text_field((string) ($_POST['preparation_uid'] ?? ''));
        $attested = (string) ($_POST['adult_attested'] ?? '') === '1';
        try {
            if (!preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $uid)) throw new \InvalidArgumentException('post_intro_invitation_not_found');
            $result = (new PostIntroContinuationService())->continue($uid, $attested);
            if (!empty($result['redirect_url'])) {
                wp_redirect((string) $result['redirect_url'], 303);
                exit;
            }
            $status = (string) $result['state'];
            if ($status === 'checkout_pending' && !empty($result['message'])) $status = (string) $result['message'];
            self::redirect($status);
        } catch (\Throwable $error) {
            self::redirect(self::safeStatus($error));
        }
    }

    private static function safeStatus(\Throwable $error): string {
        $message = trim($error->getMessage());
        return match ($message) {
            'adult_attestation_required', 'guardian_required', 'post_intro_invitation_expired',
            'post_intro_invitation_not_found', 'post_intro_invitation_not_ready', 'checkout_temporarily_unavailable',
            'checkout_pending', 'payment_received', 'activated' => $message,
            default => 'post_intro_unavailable',
        };
    }

    private static function redirect(string $status): void {
        $url = add_query_arg('post_intro_status', $status, home_url('/student-portal/'));
        wp_safe_redirect($url, 303);
        exit;
    }
}
