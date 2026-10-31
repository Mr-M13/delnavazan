<?php
/**
 * Disposable WordPress/MariaDB proof for administrator-recorded offline payment completion.
 *
 * Run after the Phase-J fixture has been provisioned:
 *   DZN_FINANCE_MANUAL_RUNTIME_TEST=authority wp eval-file .../finance-manual-payment-runtime.php --user=1
 */
if (getenv('DZN_FINANCE_MANUAL_RUNTIME_TEST') !== 'authority' || !defined('WP_CLI') || !WP_CLI
    || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Finance manual payment runtime refused.\n");
    exit(1);
}

require __DIR__ . '/phase-2a2r1-fixture.php';

use Delnavazan\Platform\Core\Application\CommercialTermFundingService;
use Delnavazan\Platform\Core\Application\Finance\{ManualPaymentSettlementService,ManualRefundService};
use Delnavazan\Platform\Core\Application\RefundReviewService;
use Delnavazan\Platform\Core\Support\Identifier;

function dzn_manual_runtime_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_manual_runtime_assert(is_array($fixture) && count($fixture['sources'] ?? array()) >= 1, 'Phase-J principal fixture required');
wp_set_current_user(1);

dzn_r1_fix_reset(array(
    'commercial_commands', 'commercial_capacity_claim_intervals', 'commercial_capacity_claims', 'commercial_recurring_patterns',
    'commercial_obligation_settlements', 'commercial_payment_facts', 'commercial_payment_evidence', 'commercial_term_funding_plans',
    'commercial_entitlements', 'commercial_purchases', 'commercial_offer_obligations', 'commercial_offer_policies',
    'commercial_offer_adjustments', 'commercial_offers', 'commercial_promotion_redemptions', 'commercial_account_adjustment_events',
    'commercial_account_adjustments', 'commercial_promotions', 'commercial_prices', 'commercial_products', 'commercial_policies',
    'commercial_account_roots', 'commercial_exceptions', 'canonical_continuation_commands', 'canonical_continuation_interventions',
    'canonical_continuation_reservations', 'canonical_continuation_decisions', 'canonical_continuation_cases',
    'canonical_continuation_slot_authorities',
));

dzn_r1_fix_availability($fixture['teachers'] ?? array());
$scenario = dzn_r1_fix_scenario($fixture['sources'][0], 'manual-runtime', 1);
$productId = dzn_r1_fix_product((int) $scenario['course_id'], 'AU', 25000, 'manual-runtime');
dzn_r1_fix_activate_enrolment((int) $scenario['enrolment_id'], 'manual-runtime');
dzn_r1_fix_pattern($scenario, 'manual-runtime');
$offer = dzn_r1_fix_offer($scenario, $productId, 'two_instalments', 'manual-runtime');
$obligation = dzn_r1_fix_obligation($offer, 1);
$service = new ManualPaymentSettlementService();

$first = $service->markStandardPaid(array(
    'offer_uid' => (string) $offer['offer_uid'],
    'obligation_sequence' => 1,
    'evidence_reference' => 'BANK-MANUAL-FIRST',
    'received_on' => gmdate('Y-m-d'),
), dzn_r1_fix_key('manual-first'));
dzn_manual_runtime_assert((string) ($first['processing_state'] ?? '') === 'accepted', 'A manual payment must be accepted by the canonical settlement authority');
dzn_manual_runtime_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", (int) $obligation['obligation_id'])) === 1, 'A manual payment must create exactly one settlement');
dzn_manual_runtime_assert((string) $wpdb->get_var($wpdb->prepare("SELECT provider_key FROM {$p}commercial_payment_evidence WHERE obligation_id=%d", (int) $obligation['obligation_id'])) === 'manual', 'The settlement evidence must be attributed to the manual provider');
dzn_manual_runtime_assert((string) $wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_offers WHERE id=%d", (int) $offer['offer_id'])) === 'accepted', 'The offer must become accepted after the first manual settlement');
dzn_manual_runtime_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_entitlements entitlement INNER JOIN {$p}commercial_purchases purchase ON purchase.id=entitlement.purchase_id WHERE purchase.offer_id=%d", (int) $offer['offer_id'])) === 1, 'A manual settlement must create exactly one entitlement');
$effective = (int) (new CommercialTermFundingService())->effectiveSessions((int) $offer['offer_id']);
dzn_manual_runtime_assert($effective === 6, 'The first instalment must fund Lessons 1–6');

$refused = false;
try {
    $service->markStandardPaid(array(
        'offer_uid' => (string) $offer['offer_uid'],
        'obligation_sequence' => 1,
        'evidence_reference' => 'BANK-MANUAL-FIRST',
        'received_on' => gmdate('Y-m-d'),
    ), dzn_r1_fix_key('manual-duplicate'));
} catch (\InvalidArgumentException $error) {
    $refused = $error->getMessage() === 'manual_payment_already_settled';
}
dzn_manual_runtime_assert($refused, 'A second manual settlement of the same obligation must fail closed');
dzn_manual_runtime_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", (int) $obligation['obligation_id'])) === 1, 'A refused duplicate must not create a second settlement');

$second = $service->markStandardPaid(array(
    'offer_uid' => (string) $offer['offer_uid'],
    'obligation_sequence' => 2,
    'evidence_reference' => 'BANK-MANUAL-SECOND',
    'received_on' => gmdate('Y-m-d'),
), dzn_r1_fix_key('manual-second'));
dzn_manual_runtime_assert((string) ($second['processing_state'] ?? '') === 'accepted', 'The second manual instalment must settle');
$effective = (int) (new CommercialTermFundingService())->effectiveSessions((int) $offer['offer_id']);
dzn_manual_runtime_assert($effective === 12, 'Both manual instalments must fund the full 12-session Term');
dzn_manual_runtime_assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}commercial_payment_evidence WHERE provider_key='manual' AND processing_state='accepted'") >= 2, 'Manual evidence must remain visible and append-only');

/* Paid introductory obligation: the same manual path must reuse the paid transition. */
$introUid = Identifier::uid();
$introReference = 'DZN-INTRO-' . $introUid;
$wpdb->insert($p . 'intro_payment_obligations', array(
    'uid' => $introUid, 'reference_code' => $introReference,
    'reference_digest' => hash('sha256', 'dzn-intro-payment:' . $introReference),
    'booking_request_id' => 990001, 'confirmation_id' => 990001,
    'student_id' => (int) $scenario['student_id'], 'teacher_id' => (int) $scenario['teacher_id'],
    'course_id' => (int) $scenario['course_id'], 'region_code' => 'AU', 'currency' => 'AUD',
    'amount_minor' => 1000, 'state' => 'pending', 'provider_key' => 'stripe',
    'paid_at' => null, 'failed_at' => null, 'refunded_at' => null, 'failure_reason_code' => null,
    'version' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'created_by' => 1,
    'updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => 1,
));
$introResult = $service->markIntroPaid(array(
    'obligation_uid' => $introUid,
    'evidence_reference' => 'BANK-MANUAL-INTRO',
    'received_on' => gmdate('Y-m-d'),
), dzn_r1_fix_key('manual-intro'));
dzn_manual_runtime_assert(!empty($introResult['accepted']) && (string) $wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}intro_payment_obligations WHERE uid=%s", $introUid)) === 'paid', 'A manual introductory payment must move the obligation to paid');
dzn_manual_runtime_assert((string) $wpdb->get_var($wpdb->prepare("SELECT provider_key FROM {$p}intro_payment_events WHERE intro_obligation_id=(SELECT id FROM {$p}intro_payment_obligations WHERE uid=%s) ORDER BY id DESC LIMIT 1", $introUid)) === 'manual', 'The introductory payment event must be attributed to the manual provider');
$introRefused = false;
try {
    $service->markIntroPaid(array('obligation_uid' => $introUid, 'evidence_reference' => 'BANK-MANUAL-INTRO-2', 'received_on' => gmdate('Y-m-d')), dzn_r1_fix_key('manual-intro-duplicate'));
} catch (\InvalidArgumentException $error) {
    $introRefused = $error->getMessage() === 'intro_payment_state_conflict';
}
dzn_manual_runtime_assert($introRefused, 'A paid introductory obligation must refuse a second manual settlement');

/* Administrator-only manual refund: accepted refund evidence plus review lifecycle. */
$refunds = new ManualRefundService();
$refundKey = dzn_r1_fix_key('refund-manual');
$refund = $refunds->record(array(
    'offer_uid' => (string) $offer['offer_uid'],
    'obligation_sequence' => 1,
    'amount' => '',
    'evidence_reference' => 'REFUND-MANUAL-FIRST',
    'refunded_on' => gmdate('Y-m-d'),
    'note' => 'Synthetic manual refund verified by the administrator.',
), $refundKey);
dzn_manual_runtime_assert((int) $refund['evidence_id'] > 0 && (int) $refund['refund_review_id'] > 0, 'A manual refund must create evidence and a review case');
dzn_manual_runtime_assert((int) $refund['amount_minor'] === 12500 && (int) $refund['remaining_minor'] === 0, 'A blank manual refund amount must refund the full remaining settled amount');
dzn_manual_runtime_assert((string) $wpdb->get_var($wpdb->prepare("SELECT evidence_kind FROM {$p}commercial_payment_evidence WHERE id=%d", (int) $refund['evidence_id'])) === 'refund', 'Manual refund evidence must be recorded as refund evidence');
dzn_manual_runtime_assert((string) $wpdb->get_var($wpdb->prepare("SELECT provider_key FROM {$p}commercial_payment_evidence WHERE id=%d", (int) $refund['evidence_id'])) === 'manual', 'Manual refund evidence must be attributed to the manual provider');
dzn_manual_runtime_assert((string) $wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}refund_review_cases WHERE id=%d", (int) $refund['refund_review_id'])) === 'open', 'A manual refund must open a review case');
dzn_manual_runtime_assert((int) $refunds->refundedMinor((int) $obligation['obligation_id']) === 12500, 'The refunded total must be read from accepted refund evidence');
$replay = $refunds->record(array(
    'offer_uid' => (string) $offer['offer_uid'],
    'obligation_sequence' => 1,
    'amount' => '',
    'evidence_reference' => 'REFUND-MANUAL-FIRST',
    'refunded_on' => gmdate('Y-m-d'),
    'note' => 'Synthetic manual refund verified by the administrator.',
), $refundKey);
dzn_manual_runtime_assert((int) $replay['evidence_id'] === (int) $refund['evidence_id'] && (int) $replay['refund_review_id'] === (int) $refund['refund_review_id'], 'An identical refund command replay must converge on the recorded evidence and review case');

$overRefund = false;
try {
    $refunds->record(array(
        'offer_uid' => (string) $offer['offer_uid'],
        'obligation_sequence' => 1,
        'amount' => '1.00',
        'evidence_reference' => 'REFUND-MANUAL-OVER',
        'refunded_on' => gmdate('Y-m-d'),
    ), dzn_r1_fix_key('refund-over'));
} catch (\InvalidArgumentException $error) {
    $overRefund = $error->getMessage() === 'manual_refund_already_complete';
}
dzn_manual_runtime_assert($overRefund, 'A fully refunded obligation must refuse another manual refund');

$review = new RefundReviewService();
$now = gmdate('Y-m-d H:i:s');
$review->routeForReview((int) $refund['refund_review_id'], array('evidence_channel' => 'staff_record', 'evidence_reference' => 'REFUND-ROUTE', 'evidence_at' => $now), dzn_r1_fix_key('refund-route'));
$review->resolve((int) $refund['refund_review_id'], array('resolution_note' => 'Refund verified and completed manually.', 'evidence_channel' => 'staff_record', 'evidence_reference' => 'REFUND-RESOLVE', 'evidence_at' => $now), dzn_r1_fix_key('refund-resolve'));
$reviewRow = $wpdb->get_row($wpdb->prepare("SELECT state,resolution_note FROM {$p}refund_review_cases WHERE id=%d", (int) $refund['refund_review_id']));
dzn_manual_runtime_assert($reviewRow !== null && (string) $reviewRow->state === 'resolved' && (string) $reviewRow->resolution_note === 'Refund verified and completed manually.', 'The manual refund review must resolve with its decision note');

echo "Finance manual payment runtime: PASS\n";
