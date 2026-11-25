<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\View\OwnerFollowupView;
use Delnavazan\Platform\Core\Application\Finance\Statements\TeacherStatementPaymentService;
use Delnavazan\Platform\Core\Application\OwnerFollowupAuthority;
use Delnavazan\Platform\Core\Application\OwnerFollowupReadService;

/**
 * The single-operator follow-up screen.
 *
 * It exists for one named account only (OwnerFollowupAuthority) and reuses the canonical reads and
 * the audited statement settlement; it never writes finance or onboarding state directly.
 */
final class OwnerFollowupController {
    public const PAGE = 'dzn-owner-followups';
    public const ACTION = 'dzn_owner_followup_payment';

    public static function register(): void {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'recordPayment'));
    }

    public static function menu(): void {
        if (!function_exists('add_submenu_page')) { return; }
        add_submenu_page(Menu::ROOT_SLUG, 'پیگیری‌های نیما', 'پیگیری‌های نیما', OwnerFollowupAuthority::CAPABILITY, self::PAGE, array(__CLASS__, 'screen'));
    }

    public static function screen(): void {
        if (!current_user_can(OwnerFollowupAuthority::CAPABILITY)) {
            wp_die('این صفحه فقط برای حساب مدیر هنری است.', 'دسترسی غیرمجاز', array('response' => 403));
        }
        $read = new OwnerFollowupReadService();
        $data = array('intros' => array(), 'onboarding' => array(), 'payments' => array(), 'failed' => array());
        foreach (array('intros' => 'introsWithoutTeacher', 'onboarding' => 'onboardingOutstanding', 'payments' => 'unpaidStatements') as $key => $method) {
            try {
                $data[ $key ] = $read->{$method}();
            } catch (\Throwable $error) {
                $data[ $key ] = array();
                $data['failed'][ $key ] = true;
            }
        }
        OwnerFollowupView::render($data, self::notice());
    }

    public static function recordPayment(): void {
        if (!current_user_can(OwnerFollowupAuthority::CAPABILITY)) {
            wp_die('این صفحه فقط برای حساب مدیر هنری است.', 'دسترسی غیرمجاز', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        $statementId = isset($_POST['statement_id']) ? absint($_POST['statement_id']) : 0;
        $reference = isset($_POST['payment_reference']) ? sanitize_text_field(wp_unslash((string) $_POST['payment_reference'])) : '';
        $key = isset($_POST['command_key']) ? sanitize_text_field(wp_unslash((string) $_POST['command_key'])) : '';
        if ($statementId < 1 || '' === $key) { self::redirect('unavailable'); }
        $result = 'paid';
        try {
            (new TeacherStatementPaymentService())->record($statementId, array(
                'method_code' => 'manual_bank_transfer',
                'evidence_reference' => '' !== $reference ? $reference : 'owner-followup-' . $statementId . '-' . gmdate('YmdHis'),
                'evidence_channel' => 'staff_record',
                'paid_at' => gmdate('Y-m-d H:i:s'),
                'reason' => 'operator_decision',
            ), $key);
        } catch (\Throwable $error) {
            $code = trim($error->getMessage());
            if ('statement_state_transition_conflict' === $code) { $result = 'already_paid'; }
            elseif ('finance_parent_not_live' === $code) { $result = 'missing'; }
            else { $result = 'failed'; }
        }
        self::redirect($result);
    }

    private static function redirect(string $result): void {
        wp_safe_redirect(add_query_arg(array('page' => self::PAGE, 'followup_result' => sanitize_key($result)), admin_url('admin.php')));
        exit;
    }

    /** @return array{text:string,tone:string}|null */
    private static function notice(): ?array {
        $results = array(
            'paid' => array('پرداخت ثبت شد و در پروندهٔ مالی مدرس نمایش داده می‌شود.', 'success'),
            'already_paid' => array('این صورت‌حساب پیش‌تر پرداخت شده است.', 'info'),
            'missing' => array('این صورت‌حساب پیدا نشد؛ فهرست را تازه کنید.', 'error'),
            'unavailable' => array('درخواست کامل نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'error'),
            'failed' => array('پرداخت ثبت نشد. صفحه را تازه کنید و دوباره تلاش کنید.', 'error'),
        );
        $key = isset($_GET['followup_result']) ? sanitize_key((string) $_GET['followup_result']) : '';
        return $results[ $key ] ?? null;
    }
}
