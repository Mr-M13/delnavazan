<?php
/**
 * Offline Stripe Checkout test-mode E2E proof. Synthetic local data only.
 *
 * This creates no Checkout Session at Stripe and makes no HTTP request. It proves the production
 * plugin bootstrap registers the provider seam, the dashboard credential-save/activation path works
 * against the real vault, the repaired legacy account reference matches signed webhooks, the canonical
 * evidence/settlement path settles exactly once, replay converges, and the student-owned return read
 * reflects durable database state.
 */
if (getenv('DZN_STRIPE_CHECKOUT_E2E_TEST') !== 'authority' || !defined('WP_CLI') || !WP_CLI
    || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Stripe Checkout test-mode E2E runtime refused.\n");
    exit(1);
}

require __DIR__ . '/phase-2a2r1-fixture.php';

use Delnavazan\Platform\Core\Application\Checkout\{CheckoutRequest, StudentCheckoutReturnReadService};
use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentEventIntakeService, PaymentExecutionIdempotency, PaymentExecutionRule, PaymentExecutionSupport, PaymentProviderAccountService, PaymentProviderRegistry, PaymentSecretVault, StripeTestConfigurationService, StripeTestModeReadinessService};
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Integrations\Payment\Stripe\{StripeCheckoutAdapter, StripeVaultCheckoutCredentialSource};

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

// The production bootstrap — not this test — must wire the provider seam before any webhook delivery.
dzn_checkout_e2e_assert(PaymentProviderRegistry::hasTranslator('stripe'), 'The production bootstrap did not register the Stripe provider-event translator');
dzn_checkout_e2e_assert(PaymentProviderRegistry::hasExecutionPort('stripe'), 'The production bootstrap did not register the Stripe payment-execution port');
dzn_checkout_e2e_assert(!PaymentExecutionRule::liveExecutionAuthorised('stripe'), 'Registering the execution port must not authorise live Stripe execution');

wp_set_current_user(1);
dzn_r1_fix_availability($fixture['teachers'] ?? array());
$scenario = dzn_r1_fix_scenario($fixture['sources'][0], 'checkout-e2e', 1);
$productId = dzn_r1_fix_product((int) $scenario['course_id'], 'AU', 25000, 'checkout-e2e');
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
if (!defined('DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED')) define('DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED', true);

/* -------------------------------------------------------------------------------------------------
 * Existing-data compatibility: a legacy dashboard account stored the digest of
 * `delnavazan-stripe-test` while Checkout metadata carried `stripe_test`. Migration 047 must repair
 * exactly that row in place, without duplicating it, before the dashboard is used again.
 * ------------------------------------------------------------------------------------------------- */
$legacyDigest = PaymentExecutionIdempotency::reference('delnavazan-stripe-test');
$canonicalDigest = PaymentExecutionIdempotency::reference(StripeTestConfigurationService::TEST_ACCOUNT_REFERENCE);
$now = gmdate('Y-m-d H:i:s');
dzn_checkout_e2e_assert($wpdb->insert($p . 'payment_provider_accounts', array(
    'uid' => Identifier::uid(), 'reference_code' => StripeTestConfigurationService::TEST_ACCOUNT_REFERENCE,
    'provider_key' => 'stripe', 'mode' => 'test', 'account_reference_digest' => $legacyDigest,
    'state' => 'active', 'execution_state' => 'disabled', 'credential_state' => 'configured',
    'account_version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1,
)) === 1, 'Legacy Stripe account could not be staged');
$legacyAccountId = (int) $wpdb->insert_id;

update_option('dzn_platform_schema_version', '46', false);
$completed = (array) get_option('dzn_platform_completed_migrations', array());
$completed = array_values(array_filter($completed, static fn($migration): bool => $migration !== '047_stripe_canonical_account_reference'));
update_option('dzn_platform_completed_migrations', $completed, false);
Migrator::maybe_upgrade();

$repaired = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_provider_accounts WHERE id=%d", $legacyAccountId));
dzn_checkout_e2e_assert($repaired !== null, 'The repaired Stripe account disappeared');
dzn_checkout_e2e_assert(hash_equals($canonicalDigest, (string) $repaired->account_reference_digest), 'Migration 047 did not repair the legacy account reference digest');
dzn_checkout_e2e_assert(PaymentProviderAccountService::canonicalReference($repaired) === StripeTestConfigurationService::TEST_ACCOUNT_REFERENCE, 'The repaired account is not canonically correlatable');
dzn_checkout_e2e_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_accounts WHERE provider_key='stripe' AND mode='test' AND reference_code=%s", StripeTestConfigurationService::TEST_ACCOUNT_REFERENCE)) === 1, 'Migration 047 must repair in place, never duplicate the account');
dzn_checkout_e2e_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_accounts WHERE account_reference_digest=%s", $legacyDigest)) === 0, 'The legacy authority digest must not survive the repair');

/* -------------------------------------------------------------------------------------------------
 * Dashboard credential save through the real production services (no injected substitutes).
 * ------------------------------------------------------------------------------------------------- */
$publishable = 'pk_test_' . str_repeat('P', 24);
$apiKey = 'sk_test_' . str_repeat('A', 24);
$signingSecret = 'whsec_' . str_repeat('B', 32);
$configuration = new StripeTestConfigurationService();
$saved = $configuration->save(array('publishable_key' => $publishable, 'secret_key' => $apiKey, 'webhook_signing_secret' => $signingSecret));
$accountId = (int) $saved['account_id'];
dzn_checkout_e2e_assert($accountId === $legacyAccountId, 'The dashboard save must reuse the repaired account');
dzn_checkout_e2e_assert(($configuration->savedState()['account_reference'] ?? null) === StripeTestConfigurationService::TEST_ACCOUNT_REFERENCE, 'The saved account must expose the canonical reference');

// Persistence without exposure: neither secret may appear in any stored row, and ciphertext must exist.
$storedRows = array_merge(
    $wpdb->get_results("SELECT * FROM {$p}payment_provider_accounts") ?: array(),
    $wpdb->get_results("SELECT * FROM {$p}payment_provider_secrets") ?: array(),
    $wpdb->get_results("SELECT * FROM {$p}payment_provider_secret_events") ?: array()
);
$dump = (string) wp_json_encode($storedRows);
dzn_checkout_e2e_assert(strpos($dump, $apiKey) === false && strpos($dump, $signingSecret) === false, 'A Stripe test secret leaked into durable storage');
$sealed = $wpdb->get_results("SELECT * FROM {$p}payment_provider_secrets") ?: array();
$sealedClasses = array();
foreach ($sealed as $row) {
    dzn_checkout_e2e_assert(trim((string) $row->ciphertext) !== '' && trim((string) $row->nonce) !== '', 'A sealed test credential record is incomplete');
    $sealedClasses[] = (string) $row->secret_class;
}
sort($sealedClasses);
dzn_checkout_e2e_assert($sealedClasses === array('api_key', 'webhook_signing_secret'), 'Both sealed test credentials must be present exactly once');
dzn_checkout_e2e_assert((string) get_option('dzn_stripe_test_publishable_key') === $publishable, 'The publishable test key was not persisted');

// Test Connection is answered locally through WordPress's pre_http_request short-circuit; no network call.
$accountResponse = array('id' => 'acct_' . str_repeat('T', 16), 'object' => 'account', 'livemode' => false, 'charges_enabled' => true, 'payouts_enabled' => true, 'country' => 'AU', 'default_currency' => 'aud');
$httpFilter = static function ($pre, $args, $url) use ($accountResponse) {
    if (strpos((string) $url, '/v1/account') === false) return $pre;
    return array('headers' => array(), 'body' => wp_json_encode($accountResponse), 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => null);
};
add_filter('pre_http_request', $httpFilter, 10, 3);
$connection = $configuration->testConnection();
remove_filter('pre_http_request', $httpFilter, 10);
dzn_checkout_e2e_assert(($connection['state'] ?? null) === 'success', 'Test Connection must succeed against the local sandbox-account fixture: ' . (string) ($connection['code'] ?? ''));
$validatedCredentials = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_secret_events WHERE audit_type='validated' AND reason_code='explicit_test_connection_validation'");
dzn_checkout_e2e_assert($validatedCredentials === 2, 'Explicit Test Connection must record non-sensitive validation evidence for both sealed credentials');

$beforeActivation = (new StripeTestModeReadinessService())->read();
dzn_checkout_e2e_assert($beforeActivation['ready'] === true, 'Configuration readiness must be provable before activation');
dzn_checkout_e2e_assert($beforeActivation['checkout_ready'] === false, 'Checkout must stay unavailable before deliberate activation');
dzn_checkout_e2e_assert($beforeActivation['settlement_verified'] === false, 'Unsettled configuration must never claim end-to-end settlement');
foreach (array('translator_registered', 'execution_port_registered', 'test_vault', 'test_account', 'account_reference_binding', 'credentials_stored', 'webhook_endpoint', 'checkout_enabled') as $check) {
    dzn_checkout_e2e_assert(isset($beforeActivation['checks'][$check]), 'Readiness check missing: ' . $check);
}
dzn_checkout_e2e_assert($beforeActivation['checks']['translator_registered']['state'] === 'ready', 'Readiness must observe the bootstrap translator');
dzn_checkout_e2e_assert($beforeActivation['checks']['credentials_stored']['state'] === 'ready', 'Readiness must report sealed credential records without decrypting them');
dzn_checkout_e2e_assert($beforeActivation['checks']['checkout_enabled']['state'] === 'blocked', 'Readiness must report Checkout as not enabled before activation');

// An authenticated operator without payment-provider authority cannot configure or inspect Stripe.
wp_set_current_user($studentPrincipal);
foreach (array(static fn() => $configuration->save(array()), static fn() => (new StripeTestModeReadinessService())->read()) as $unauthorised) {
    try { $unauthorised(); throw new RuntimeException('Unauthorised Stripe access was not refused'); }
    catch (RuntimeException $error) { dzn_checkout_e2e_assert($error->getMessage() === 'Unauthorized', 'Unauthorised Stripe access must fail closed'); }
}
wp_set_current_user(1);

// The activation transition previously failed because it supplied no evidence reference.
$activated = $configuration->activateTestCheckout();
dzn_checkout_e2e_assert(($activated['execution_state'] ?? null) === 'enabled', 'Deliberate test Checkout activation must succeed with authenticated evidence');
$afterActivation = (new StripeTestModeReadinessService())->read();
dzn_checkout_e2e_assert($afterActivation['checkout_ready'] === true, 'Checkout must report ready after deliberate activation');

$credentials = (new StripeVaultCheckoutCredentialSource())->credentials();
dzn_checkout_e2e_assert(is_array($credentials), 'The real vault credential source must resolve the activated account');
dzn_checkout_e2e_assert(hash_equals($apiKey, (string) $credentials['api_key']), 'The vault must return the stored test API key');
dzn_checkout_e2e_assert(hash_equals(StripeTestConfigurationService::TEST_ACCOUNT_REFERENCE, (string) $credentials['account_reference']), 'The credential source must return the canonical account reference');
$accountReference = (string) $credentials['account_reference'];
$selector = $accountReference;

/* -------------------------------------------------------------------------------------------------
 * Hosted Checkout creation through the real adapter, with a local synthetic Stripe response.
 * ------------------------------------------------------------------------------------------------- */
$attemptUid = Identifier::uid();
$obligationReference = (string) $offer['offer_uid'] . ':2';
$request = new CheckoutRequest($studentId, (int) $offer['offer_id'], $obligationId, (int) $obligation['amount_minor'], (string) $offer['currency'], $obligationReference, hash('sha256', 'checkout-e2e:' . $attemptUid), $attemptUid);
$successUrl = home_url('/student-portal/?portal-view=account&checkout=return&attempt=' . rawurlencode($attemptUid));
$cancelUrl = home_url('/student-portal/?portal-view=account&checkout=cancel&attempt=' . rawurlencode($attemptUid));
$expectedFields = StripeCheckoutAdapter::providerFields($request, $successUrl, $cancelUrl, $accountReference);

$capturedBody = array();
$capturedAuthorization = '';
$capturedIdempotency = '';
$sessionReference = 'cs_test_' . bin2hex(random_bytes(16));
$expiresAt = time() + 1800;
$http = static function (string $url, array $options) use (&$capturedBody, &$capturedAuthorization, &$capturedIdempotency, $sessionReference, $expiresAt, $obligation, $offer): array {
    $capturedBody = (array) ($options['body'] ?? array());
    $capturedAuthorization = (string) ($options['headers']['Authorization'] ?? '');
    $capturedIdempotency = (string) ($options['headers']['Idempotency-Key'] ?? '');
    $metadata = array();
    foreach ($capturedBody as $key => $value) {
        if (str_starts_with((string) $key, 'metadata[')) $metadata[substr((string) $key, 9, -1)] = (string) $value;
    }
    $session = array(
        'id' => $sessionReference, 'object' => 'checkout.session', 'mode' => 'payment', 'status' => 'open', 'livemode' => false,
        'url' => 'https://checkout.stripe.com/c/pay/' . $sessionReference, 'expires_at' => $expiresAt,
        'amount_total' => (int) $obligation['amount_minor'], 'currency' => strtolower((string) $offer['currency']),
        'metadata' => $metadata,
    );
    return array('headers' => array(), 'body' => wp_json_encode($session), 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => null);
};
$adapter = new StripeCheckoutAdapter(null, $http);
$created = $adapter->create($request);
dzn_checkout_e2e_assert(($created['state'] ?? null) === 'open', 'The real adapter must create the hosted test session: ' . (string) ($created['reason_code'] ?? ''));
dzn_checkout_e2e_assert(hash_equals($sessionReference, (string) $created['provider_reference']), 'The adapter must return the synthetic provider reference');
dzn_checkout_e2e_assert(str_starts_with($capturedAuthorization, 'Basic '), 'The adapter must authenticate with the stored test key');
dzn_checkout_e2e_assert(hash_equals($request->idempotencyKey(), $capturedIdempotency), 'The adapter must send the command idempotency key');
foreach (array('metadata[obligation_reference]', 'metadata[checkout_attempt_uid]', 'metadata[provider_account_reference]', 'client_reference_id') as $key) {
    dzn_checkout_e2e_assert(hash_equals((string) $expectedFields[$key], (string) ($capturedBody[$key] ?? '')), 'Checkout command correlation is incomplete: ' . $key);
}
dzn_checkout_e2e_assert(hash_equals($accountReference, (string) ($capturedBody['metadata[provider_account_reference]'] ?? '')), 'Checkout metadata must carry the canonical account reference');

// Model the locally persisted result of the guarded creation response; no provider call is made here.
$now = gmdate('Y-m-d H:i:s');
dzn_checkout_e2e_assert($wpdb->insert($p . 'checkout_sessions', array(
    'uid' => $attemptUid, 'student_id' => $studentId, 'offer_id' => (int) $offer['offer_id'], 'obligation_id' => $obligationId,
    'amount_minor' => (int) $obligation['amount_minor'], 'currency' => (string) $offer['currency'], 'state' => 'open', 'active_slot' => 1,
    'request_key_digest' => hash('sha256', $request->idempotencyKey()), 'provider_key' => 'stripe',
    'provider_reference_digest' => PaymentExecutionIdempotency::reference($sessionReference), 'created_at' => $now, 'created_by' => 1,
    'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt), 'closed_at' => null, 'close_reason' => null,
)) === 1, 'Checkout attempt could not be persisted');

$settlementsBefore = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", $obligationId));
$priorUser = (int) get_current_user_id();
wp_set_current_user($studentPrincipal);
$beforeWebhook = (new StudentCheckoutReturnReadService())->read(array('attempt_uid' => $attemptUid));
wp_set_current_user($priorUser);
dzn_checkout_e2e_assert(($beforeWebhook['payment_state'] ?? null) === 'processing' && ($beforeWebhook['checkout_state'] ?? null) === 'open', 'Checkout return must report an accepted offer’s unpaid attempt as processing');
dzn_checkout_e2e_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", $obligationId)) === $settlementsBefore, 'Checkout return must not settle payment');

$bodyFor = static function (string $eventId, string $reference, string $providerAccountReference) use ($sessionReference, $obligation, $offer, $attemptUid): string {
    return (string) wp_json_encode(array(
        'id' => $eventId, 'type' => 'checkout.session.completed', 'created' => time(),
        'data' => array('object' => array(
            'object' => 'checkout.session', 'id' => $sessionReference, 'livemode' => false, 'status' => 'complete', 'mode' => 'payment', 'payment_status' => 'paid',
            'amount_total' => (int) $obligation['amount_minor'], 'currency' => strtolower((string) $offer['currency']),
            'metadata' => array('obligation_reference' => $reference, 'checkout_attempt_uid' => $attemptUid, 'provider_account_reference' => $providerAccountReference),
        )),
    ));
};
$headersFor = static function (string $body) use ($signingSecret): array {
    $timestamp = time();
    return array('stripe-signature' => 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $signingSecret));
};
$intake = new PaymentEventIntakeService();

// A forged body with an otherwise exact Checkout correlation has no authority.
$receiptsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_event_receipts");
$validBody = $bodyFor('evt_' . bin2hex(random_bytes(12)), $obligationReference, $accountReference);
$forged = $intake->receive('stripe', $selector, $validBody, array('stripe-signature' => 't=' . time() . ',v1=' . str_repeat('0', 64)));
dzn_checkout_e2e_assert(($forged['verification_state'] ?? null) === 'refused' && ($forged['reason_code'] ?? null) === 'signature_invalid', 'An unverified Checkout webhook must be refused');
dzn_checkout_e2e_assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_event_receipts") === $receiptsBefore + 1, 'A refused webhook must still be receipted for observability');
dzn_checkout_e2e_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", $obligationId)) === $settlementsBefore, 'An unverified Checkout webhook must settle nothing');

// A correctly signed event for a different account reference still has no account authority.
$accountMismatchBody = $bodyFor('evt_' . bin2hex(random_bytes(12)), $obligationReference, 'stripe_not_this_account');
$accountMismatch = $intake->receive('stripe', $selector, $accountMismatchBody, $headersFor($accountMismatchBody));
dzn_checkout_e2e_assert(($accountMismatch['verification_state'] ?? null) === 'verified', 'An account-mismatched event should be signature-verified first');
$accountMismatchDecision = (string) $wpdb->get_var("SELECT reason_code FROM {$p}payment_provider_event_decisions ORDER BY id DESC LIMIT 1");
dzn_checkout_e2e_assert($accountMismatchDecision === 'unmapped_provider_account', 'A Checkout event naming another account must be refused');
dzn_checkout_e2e_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", $obligationId)) === $settlementsBefore, 'An account-mismatched Checkout webhook must settle nothing');

// The exact local session may settle only its own canonical obligation, never metadata for another one.
$mismatchBody = $bodyFor('evt_' . bin2hex(random_bytes(12)), (string) $offer['offer_uid'] . ':1', $accountReference);
$mismatched = $intake->receive('stripe', $selector, $mismatchBody, $headersFor($mismatchBody));
dzn_checkout_e2e_assert(($mismatched['verification_state'] ?? null) === 'verified', 'A mismatched Checkout webhook should be signature-verified before attribution refusal');
$mismatchDecision = (string) $wpdb->get_var("SELECT reason_code FROM {$p}payment_provider_event_decisions ORDER BY id DESC LIMIT 1");
dzn_checkout_e2e_assert($mismatchDecision === 'ambiguous_obligation_attribution', 'A Checkout Session must not settle a different obligation');
dzn_checkout_e2e_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", $obligationId)) === $settlementsBefore, 'A mismatched Checkout webhook must settle nothing');

$verified = $intake->receive('stripe', $selector, $validBody, $headersFor($validBody));
dzn_checkout_e2e_assert(($verified['verification_state'] ?? null) === 'verified' && (int) ($verified['status'] ?? 0) === 200, 'The locally signed Checkout webhook must verify');
$settlementsAfter = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", $obligationId));
dzn_checkout_e2e_assert($settlementsAfter === $settlementsBefore + 1, 'The verified Checkout webhook must settle through canonical payment authority exactly once');

// Settlement must leave the student holding a valid R1 entitlement, and receipts must stay secret-free.
$entitlementId = dzn_r1_fix_entitlement((int) $offer['offer_id']);
$entitlement = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_entitlements WHERE id=%d", $entitlementId));
dzn_checkout_e2e_assert($entitlement !== null && (int) $entitlement->beneficiary_student_id === $studentId, 'The settled payment must leave the paying student entitlement valid');
dzn_checkout_e2e_assert(in_array((string) $entitlement->state, array('issued', 'term_bound'), true), 'The student entitlement must stay usable after settlement');
$receiptDump = (string) wp_json_encode($wpdb->get_results("SELECT * FROM {$p}payment_provider_event_receipts") ?: array());
dzn_checkout_e2e_assert(strpos($receiptDump, $apiKey) === false && strpos($receiptDump, $signingSecret) === false, 'Webhook receipts must never contain a credential value');

// An identical duplicate verified webhook must converge on the existing event and settlement.
$eventsBeforeReplay = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_events");
$replayed = $intake->receive('stripe', $selector, $validBody, $headersFor($validBody));
dzn_checkout_e2e_assert(($replayed['verification_state'] ?? null) === 'verified', 'Duplicate verified webhook must be acknowledged');
dzn_checkout_e2e_assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_events") === $eventsBeforeReplay, 'A duplicate verified webhook must not create another provider event');
dzn_checkout_e2e_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", $obligationId)) === $settlementsAfter, 'A duplicate verified webhook must not create another canonical settlement');

$afterWebhook = (new StripeTestModeReadinessService())->read();
dzn_checkout_e2e_assert($afterWebhook['settlement_verified'] === true, 'Readiness must report verified settlement after the durable settlement exists');
dzn_checkout_e2e_assert($afterWebhook['checkout_ready'] === true, 'Checkout must remain ready after settlement');

wp_set_current_user($studentPrincipal);
$studentRead = (new StudentCheckoutReturnReadService())->read(array('attempt_uid' => $attemptUid));
wp_set_current_user($priorUser);
dzn_checkout_e2e_assert(($studentRead['payment_state'] ?? null) === 'paid' && ($studentRead['checkout_state'] ?? null) === 'completed', 'Student return read must report only the canonical settled payment state');
dzn_checkout_e2e_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d", $obligationId)) === $settlementsAfter, 'Student payment read must not mutate canonical settlement');

echo "stripe-checkout-test-mode-e2e-runtime: OK\n";
