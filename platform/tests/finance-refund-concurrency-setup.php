<?php
/** Synthetic fixture for actual database-backed manual-refund concurrency. */
if (getenv('DZN_FINANCE_REFUND_CONCURRENCY_TEST') !== 'setup' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Refund concurrency setup refused.\n"); exit(1);
}
require __DIR__ . '/phase-2a2r1-fixture.php';
global $wpdb; $p = $wpdb->prefix . 'dzn_';
$fixture = get_option('dzn_phase_2a2j_fixture');
if (!is_array($fixture) || count($fixture['sources'] ?? array()) < 1) throw new RuntimeException('Phase-J fixture required');
wp_set_current_user(1);
dzn_r1_fix_reset(array(
    'finance_manual_refund_events', 'finance_manual_refund_reservations',
    'commercial_commands', 'commercial_capacity_claim_intervals', 'commercial_capacity_claims', 'commercial_recurring_patterns',
    'commercial_obligation_settlements', 'commercial_payment_facts', 'commercial_payment_evidence', 'commercial_term_funding_plans',
    'commercial_entitlements', 'commercial_purchases', 'commercial_offer_obligations', 'commercial_offer_policies',
    'commercial_offer_adjustments', 'commercial_offers', 'commercial_promotion_redemptions', 'commercial_account_adjustment_events',
    'commercial_account_adjustments', 'commercial_promotions', 'commercial_prices', 'commercial_products', 'commercial_policies',
    'commercial_account_roots', 'commercial_exceptions', 'canonical_continuation_commands', 'canonical_continuation_interventions',
    'canonical_continuation_reservations', 'canonical_continuation_decisions', 'canonical_continuation_cases',
    'canonical_continuation_slot_authorities', 'refund_review_events', 'refund_review_commands', 'refund_review_cases',
));
dzn_r1_fix_availability($fixture['teachers'] ?? array());
$scenario = dzn_r1_fix_scenario($fixture['sources'][0], 'refund-concurrency', 1);
$productId = dzn_r1_fix_product((int) $scenario['course_id'], 'AU', 25000, 'refund-concurrency');
dzn_r1_fix_activate_enrolment((int) $scenario['enrolment_id'], 'refund-concurrency');
dzn_r1_fix_pattern($scenario, 'refund-concurrency');
$offer = dzn_r1_fix_offer($scenario, $productId, 'two_instalments', 'refund-concurrency');
$first = dzn_r1_fix_obligation($offer, 1);
$second = dzn_r1_fix_obligation($offer, 2);
dzn_r1_fix_settle($offer, 1, 'refund-concurrency-first');
dzn_r1_fix_settle($offer, 2, 'refund-concurrency-second');
update_option('dzn_finance_refund_concurrency_fixture', array(
    'offer_uid' => (string) $offer['offer_uid'],
    'offer_id' => (int) $offer['offer_id'],
    'obligation_1' => (int) $first['obligation_id'],
    'obligation_2' => (int) $second['obligation_id'],
    'settled_minor' => 12500,
), false);
echo "Refund concurrency fixture ready\n";
