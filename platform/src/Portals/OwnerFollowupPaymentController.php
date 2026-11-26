<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\Finance\Statements\TeacherStatementPaymentService;
use Delnavazan\Platform\Core\Application\OwnerFollowupAuthority;

/**
 * The single-operator follow-up screen's payment action.
 *
 * Lives in the Platform (the theme is presentation-only): it authenticates the operator identity,
 * verifies the nonce and records the settlement through the same audited finance authority the
 * admin uses, then returns to the Teacher portal follow-up screen.
 */
final class OwnerFollowupPaymentController {
    public const ACTION = 'dzn_owner_followup_payment';

    public static function register(): void {
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
    }

    public static function handle(): void {
        if (!OwnerFollowupAuthority::isOperator()) {
            wp_die('این صفحه فقط برای حساب مدیر هنری است.', 'دسترسی غیرمجاز', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        $statementId = isset($_POST['statement_id']) ? absint($_POST['statement_id']) : 0;
        $reference = isset($_POST['payment_reference']) ? sanitize_text_field(wp_unslash((string) $_POST['payment_reference'])) : '';
        $key = isset($_POST['command_key']) ? sanitize_text_field(wp_unslash((string) $_POST['command_key'])) : '';
        $result = 'paid';
        if ($statementId < 1 || '' === $key) {
            $result = 'unavailable';
        } else {
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
        }
        wp_safe_redirect(add_query_arg(array('teacher-view' => 'followups', 'followup' => sanitize_key($result)), home_url('/teacher-portal/')), 303);
        exit;
    }
}
