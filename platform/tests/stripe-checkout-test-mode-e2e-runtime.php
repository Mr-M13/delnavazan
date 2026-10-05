<?php
/**
 * Offline Stripe Checkout test-mode E2E proof. Synthetic local data only.
 *
 * This creates no Checkout Session at Stripe and makes no HTTP request. It proves the local
 * command shape, an exact raw-body signed webhook, the canonical evidence/settlement path, replay
 * convergence, and the student-owned return read against the durable database state.
 */
if (getenv('DZN_STRIPE_CHECKOUT_E2E_TEST') !== 'authority' || !defined('WP_CLI') || !WP_CLI
    || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Stripe Checkout test-mode E2E runtime refused.\n");
    exit(1);
}

require __DIR__ . '/phase-2a2r1-fixture.php';

use Delnavazan\Platform\Core\Application\Checkout\{CheckoutRequest, StudentCheckoutReturnReadService};
use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentEventIntakeService, PaymentExecutionIdempotency, PaymentExecutionSupport, PaymentProviderAccountService, PaymentProviderRegistry, PaymentSecretVault};
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Integrations\Payment\Stripe\{StripeCheckoutAdapter, StripeEventTranslator, StripeSignatureVerifier};

function dzn_checkout_e2e_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function dzn_checkout_e2e_key(string $label): string {
    return 'dzn-checkout-e2e-' . $label . '-' . wp_generate_uuid4();
}
function dzn_checkout_e2e_evidence(string $label): array {
    return array('evidence_channel' => 'staff_record', 'evidence_reference' => 'checkout-e2e-' . $label . '-' . wp_generate_uuid4(), 'evidence_at' => gmdate('Y-m-d H:i:s'));
}

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_checkout_e2e_assert(is_array($fixture) && count($fixture['sources'] ?? array()) >= 1, 'Phase-J principal fixture required');

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
foreach (array(
    'checkout_sessions', 'payment_provider_secret_events', 'payment_provider_event_decisions',
    'payment_provider_event_decision_claims', 'payment_provider_events', 'payment_provider_event_receipts',
    'payment_execution_dispatches', 'payment_execution_results', 'payment_execution_attempts', 'payment_execution_commands',
    'payment_provider_secrets', 'payment_provider_object_commands', 'payment_provider_object_events', 'payment_provider_objects',
    'payment_provider_account_commands', 'payment_provider_account_events', 'payment_provider_accounts',
) as $table) {
    dzn_checkout_e2e_assert($wpdb->query("DELETE FROM {$p}{$table}") !== false, 'Failed to reset disposable checkout E2E storage: ' . $table);
}

wp_set_current_user(1);
dzn_r1_fix_availability($fixture['teachers'] ?? array());
$courseId = (int) $wpdb->get_var("SELECT id FROM {$p}courses ORDER BY id LIMIT 1");
$productId = dzn_r1_fix_product($courseId, 'AU', 25000, 'checkout-e2e');
$scenario = dzn_r1_fix_scenario($fixture['sources'][0], 'checkout-e2e', 1);
dzn_r1_fix_activate_enrolment((int) $scenario['enrolment_id'], 'checkout-e2e');
dzn_r1_fix_pattern($scenario, 'checkout-e2e');
$offer = dzn_r1_fix_offer($scenario, $productId, 'two_instalments', 'checkout-e2e');
dzn_r1_fix_settle($offer, 1, 'checkout-e2e-first');
$obligation = dzn_r1_fix_obligation($offer, 2);
$obligationId = (int) $obligation['obligation_id'];
$studentId = (int) $wpdb->get_var($wpdb->prepare("SELECT beneficiary_student_id FROM {$p}commercial_offers WHERE id=%d", (int) $offer['offer_id']));
$studentPrincipal = (int) $wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 LIMIT 1", $studentId));
dzn_checkout_e2e_assert($studentId > 0 && $studentPrincipal > 0, 'A linked Student principal is required');

// The worker is separate from the Student and contains exactly the bounded payment capabilities.
$worker = wp_insert_user(array('user_login' => 'dzn-checkout-e2e-worker-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(24), 'role' => 'subscriber'));
dzn_checkout_e2e_assert((int) $worker > 0, 'Disposable payment worker could not be created');
$workerUser = get_user_by('id', (int) $worker);
foreach (PaymentExecutionSupport::WORKER_CAPABILITIES as $capability) $workerUser->add_cap($capability);
update_option(PaymentExecutionSupport::WORKER_PRINCIPAL_OPTION, (int) $worker, false);

if (!defined('DZN_PLATFORM_PAYMENT_TEST_VAULT')) define('DZN_PLATFORM_PAYMENT_TEST_VAULT', true);
$selector = 'checkout-e2e-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 12);
$accountReference = 'acct_' . bin2hex(random_bytes(12));
$accountService = new PaymentProviderAccountService();
$registered = $accountService->register(array(
    'provider_key' => 'stripe', 'mode' => 'test', 'reference_code' => $selector,
    'account_reference' => $accountReference, 'execution_state' => 'disabled', 'credential_state' => 'configured',
) + dzn_checkout_e2e_evidence('account'), dzn_checkout_e2e_key('account'));
$accountId = (int) $registered['provider_account_id'];
dzn_checkout_e2e_assert($accountId > 0, 'Disposable Stripe test account could not be registered');

// This generated per-run value exists only in the disposable test vault and local process memory.
$signingSecret = 'whsec_' . bin2hex(random_bytes(32));
$stored = (new PaymentSecretVault())->store('stripe', $accountId, 'webhook_signing_secret', 'test', $signingSecret, array('nonce' => bin2hex(random_bytes(12))), dzn_checkout_e2e_key('secret'));
dzn_checkout_e2e_assert(($stored['stored'] ?? false) === true, 'Disposable webhook-signing secret could not be stored');
PaymentProviderRegistry::registerTranslator(new StripeEventTranslator(new StripeSignatureVerifier()));

$attemptUid = Identifier::uid();
$obligationReference = (string) $offer['offer_uid'] . ':2';
$request = new CheckoutRequest($studentId, (int) $offer['offer_id'], $obligationId, (int) $obligation['amount_minor'], (string) $offer['currency'], $obligationReference, hash('sha256', 'checkout-e2e:' . $attemptUid), $attemptUid);
$fields = StripeCheckoutAdapter::providerFields($request, 'https://example.invalid/success', 'https://example.invalid/cancel', $accountReference);
foreach (array(
    'metadata[obligation_reference]' => $obligationReference,
    'metadata[checkout_attempt_uid]' => $attemptUid,
    'metadata[provider_account_reference]' => $accountReference,
    'client_reference_id' => $attemptUid,
) as $key => $value) {
    dzn_checkout_e2e_assert(isset($fields[$key]) && hash_equals($value, (string) $fields[$key]), 'Checkout command correlation is incomplete: ' . $key);
}

// Model the locally persisted result of the guarded creation response; no provider call is made here.
$sessionReference = 'cs_test_' . bin2hex(random_bytes(16));
$now = gmdate('Y-m-d H:i:s');
dzn_checkout_e2e_assert($wpdb->insert($p . 'checkout_sessions', array(
    'uid' => $attemptUid, 'student_id' => $studentId, 'offer_id' => (int) $offer['offer_id'], 'obligation_id' => $obligationId,
    'amount_minor' => (int) $obligation['amount_minor'], 'currency' => (string) $offer['currency'], 'state' => 'open', 'active_slot' => 1,
    'request_key_digest' => hash('sha256', $request->idempotencyKey()), 'provider_key' => 'stripe',
    'provider_reference_digest' => PaymentExecutionIdempotency::reference($sessionReference), 'created_at' => $now, 'created_by' => 1,
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 900), 'closed_at' => null, 'close_reason' => null,
)) === 1, 'Checkout attempt could not be persisted');

$settlementsBefore = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", $obligationId));
$priorUser = (int) get_current_user_id();
wp_set_current_user($studentPrincipal);
$beforeWebhook = (new StudentCheckoutReturnReadService())->read(array('attempt_uid' => $attemptUid));
wp_set_current_user($priorUser);
dzn_checkout_e2e_assert(($beforeWebhook['payment_state'] ?? null) === 'processing' && ($beforeWebhook['checkout_state'] ?? null) === 'open', 'Checkout return must report an accepted offer’s unpaid attempt as processing');
dzn_checkout_e2e_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", $obligationId)) === $settlementsBefore, 'Checkout return must not settle payment');

$bodyFor = static function (string $eventId, string $reference) use ($sessionReference, $obligation, $offer, $attemptUid, $accountReference): string {
    return (string) wp_json_encode(array(
        'id' => $eventId, 'type' => 'checkout.session.completed', 'created' => time(),
        'data' => array('object' => array(
            'object' => 'checkout.session', 'id' => $sessionReference, 'livemode' => false, 'status' => 'complete', 'mode' => 'payment', 'payment_status' => 'paid',
            'amount_total' => (int) $obligation['amount_minor'], 'currency' => strtolower((string) $offer['currency']),
            'metadata' => array('obligation_reference' => $reference, 'checkout_attempt_uid' => $attemptUid, 'provider_account_reference' => $accountReference),
        )),
    ));
};
$headersFor = static function (string $body) use ($signingSecret): array {
    $timestamp = time();
    return array('stripe-signature' => 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $signingSecret));
};
$intake = new PaymentEventIntakeService();

// A forged body with an otherwise exact Checkout correlation has no authority.
$validBody = $bodyFor('evt_' . bin2hex(random_bytes(12)), $obligationReference);
$forged = $intake->receive('stripe', $selector, $validBody, array('stripe-signature' => 't=' . time() . ',v1=' . str_repeat('0', 64)));
dzn_checkout_e2e_assert(($forged['verification_state'] ?? null) === 'refused' && ($forged['reason_code'] ?? null) === 'signature_invalid', 'An unverified Checkout webhook must be refused');
dzn_checkout_e2e_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", $obligationId)) === $settlementsBefore, 'An unverified Checkout webhook must settle nothing');

// The exact local session may settle only its own canonical obligation, never metadata for another one.
$mismatchBody = $bodyFor('evt_' . bin2hex(random_bytes(12)), (string) $offer['offer_uid'] . ':1');
$mismatched = $intake->receive('stripe', $selector, $mismatchBody, $headersFor($mismatchBody));
dzn_checkout_e2e_assert(($mismatched['verification_state'] ?? null) === 'verified', 'A mismatched Checkout webhook should be signature-verified before attribution refusal');
$mismatchDecision = (string) $wpdb->get_var("SELECT reason_code FROM {$p}payment_provider_event_decisions ORDER BY id DESC LIMIT 1");
dzn_checkout_e2e_assert($mismatchDecision === 'ambiguous_obligation_attribution', 'A Checkout Session must not settle a different obligation');
dzn_checkout_e2e_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", $obligationId)) === $settlementsBefore, 'A mismatched Checkout webhook must settle nothing');

$verified = $intake->receive('stripe', $selector, $validBody, $headersFor($validBody));
dzn_checkout_e2e_assert(($verified['verification_state'] ?? null) === 'verified' && (int) ($verified['status'] ?? 0) === 200, 'The locally signed Checkout webhook must verify');
$settlementsAfter = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", $obligationId));
dzn_checkout_e2e_assert($settlementsAfter === $settlementsBefore + 1, 'The verified Checkout webhook must settle through canonical payment authority exactly once');

// An identical duplicate verified webhook must converge on the existing event and settlement.
$eventsBeforeReplay = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_events");
$replayed = $intake->receive('stripe', $selector, $validBody, $headersFor($validBody));
dzn_checkout_e2e_assert(($replayed['verification_state'] ?? null) === 'verified', 'Duplicate verified webhook must be acknowledged');
dzn_checkout_e2e_assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_events") === $eventsBeforeReplay, 'A duplicate verified webhook must not create another provider event');
dzn_checkout_e2e_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", $obligationId)) === $settlementsAfter, 'A duplicate verified webhook must not create another canonical settlement');

wp_set_current_user($studentPrincipal);
$afterWebhook = (new StudentCheckoutReturnReadService())->read(array('attempt_uid' => $attemptUid));
wp_set_current_user($priorUser);
dzn_checkout_e2e_assert(($afterWebhook['payment_state'] ?? null) === 'paid' && ($afterWebhook['checkout_state'] ?? null) === 'completed', 'Student return read must report only the canonical settled payment state');
dzn_checkout_e2e_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", $obligationId)) === $settlementsAfter, 'Student payment read must not mutate canonical settlement');

echo "stripe-checkout-test-mode-e2e-runtime: OK\n";
