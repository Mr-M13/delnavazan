<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\Finance\Receipts\StudentReceiptRenderer;
use Delnavazan\Platform\Core\Infrastructure\Repository\CommercialPaymentRepository;

/** Owner-bound, read-only Student payment receipt. */
final class StudentReceiptController {
    public static function register(): void {
        add_action('admin_post_dzn_student_receipt', array(__CLASS__, 'render'));
    }
    public static function render(): void {
        if (!is_user_logged_in()) wp_die('Forbidden', 403);
        $nonce = isset($_GET['_wpnonce']) && is_scalar($_GET['_wpnonce']) ? (string) wp_unslash($_GET['_wpnonce']) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'dzn_student_receipt')) wp_die('Forbidden', 403);
        $uid = isset($_GET['settlement_uid']) && is_scalar($_GET['settlement_uid']) ? trim((string) wp_unslash($_GET['settlement_uid'])) : '';
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $uid) !== 1) wp_die('Receipt unavailable', 404);
        try {
            $principal = (new PortalPrincipalResolver())->resolve('student');
            global $wpdb; $p = $wpdb->prefix . 'dzn_';
            $settlement = (new CommercialPaymentRepository())->settlementByUid($uid);
            if (!$settlement) wp_die('Receipt unavailable', 404);
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT settlement.uid receipt_uid,settlement.settled_at paid_at,settlement.amount_minor,settlement.currency,
                        evidence.provider_key,
                        obligation.obligation_sequence,offer_row.reference_code offer_reference,offer_row.beneficiary_student_id,
                        student.display_name student_name,student.reference_code student_reference,
                        COALESCE(NULLIF(course.name_en,''),course.name_fa,'Course') course_name,
                        COALESCE((SELECT SUM(refund.amount_minor) FROM {$p}commercial_payment_evidence refund WHERE refund.obligation_id=obligation.id AND refund.evidence_kind='refund' AND refund.processing_state='accepted'),0) refunded_minor
                 FROM {$p}commercial_obligation_settlements settlement
                 INNER JOIN {$p}commercial_offer_obligations obligation ON obligation.id=settlement.obligation_id
                 INNER JOIN {$p}commercial_offers offer_row ON offer_row.id=obligation.offer_id
                 INNER JOIN {$p}commercial_payment_evidence evidence ON evidence.id=settlement.evidence_id
                 LEFT JOIN {$p}students student ON student.id=offer_row.beneficiary_student_id
                 LEFT JOIN {$p}courses course ON course.id=offer_row.course_id
                 WHERE settlement.uid=%s",
                $uid
            ));
            if (!$row || (int) $row->beneficiary_student_id !== (int) $principal['id']) wp_die('Receipt unavailable', 404);
            $receipt = array(
                'receipt_uid' => (string) $row->receipt_uid, 'paid_at' => (string) $row->paid_at,
                'student_name' => (string) ($row->student_name ?? ''), 'student_reference' => (string) ($row->student_reference ?? ''),
                'course' => (string) $row->course_name, 'instalment' => (int) $row->obligation_sequence,
                'offer_reference' => (string) ($row->offer_reference ?? ''), 'method' => (string) $row->provider_key === 'manual' ? 'manual' : 'online',
                'amount_minor' => (int) $row->amount_minor, 'refunded_minor' => (int) $row->refunded_minor,
                'remaining_minor' => max(0, (int) $row->amount_minor - (int) $row->refunded_minor), 'currency' => (string) $row->currency,
            );
            nocache_headers();
            header('Content-Type: text/html; charset=UTF-8');
            echo (new StudentReceiptRenderer())->render($receipt, function_exists('get_bloginfo') ? ((string) get_bloginfo('name') ?: 'Delnavazan') : 'Delnavazan');
            exit;
        } catch (\Throwable) {
            wp_die('Receipt unavailable', 404);
        }
    }
}
