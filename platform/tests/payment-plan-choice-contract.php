<?php
/**
 * Source contract for B2a: pre-offer plan choice, full-term default, instalment opt-in,
 * single-offer/immutable-plan rules, later-instalment fulfilment and the checkout prerequisite.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($assert): void {
    foreach ($needles as $needle) $assert(str_contains($source, $needle), $message . ': ' . $needle);
};

$plugin = $read('delnavazan-platform.php');
$migration = $read('src/Core/Infrastructure/Migration/Migrator.php');
$offer = $read('src/Core/Application/CommercialOfferService.php');
$digest = $read('src/Core/Application/CommercialOfferQuoteDigest.php');
$quote = $read('src/Core/Application/PostIntroPlanQuoteService.php');
$continuation = $read('src/Core/Application/PostIntroContinuationService.php');
$readService = $read('src/Portals/PostIntroStudentReadService.php');
$controller = $read('src/Portals/PostIntroContinuationController.php');
$checkoutInit = $read('src/Core/Application/Checkout/StudentCheckoutInitiationService.php');
$checkoutRead = $read('src/Core/Application/Checkout/StudentCommercialCheckoutReadService.php');
$settlement = $read('src/Integrations/PostIntroSettlementHook.php');
$worker = $read('src/Integrations/PostIntroActivationWorker.php');
$activation = $read('src/Core/Application/PostIntroActivationService.php');
$fulfilment = $read('src/Core/Application/PostIntroFulfilmentReadService.php');
$paymentService = $read('src/Core/Application/CommercialPaymentService.php');
$theme = $read('../theme/template-parts/portal/post-intro.php');
$checkoutUi = $read('../theme/assets/js/checkout.js');
$payments = $read('../theme/template-parts/portal/payments.php');

$assert(str_contains($plugin, "DZN_PLATFORM_SCHEMA_VERSION', '53'"), 'B2a must publish Schema 53');
$contains($migration, array(
    "'052_payment_plan_choice'", 'install_payment_plan_choice', 'verify_payment_plan_choice_schema',
    'post_intro_plan_choices', 'preparation_active', 'quote_digest', 'funding_target', 'funding_materialised',
), 'B2a migration must be additive and verifiable');
$assert(strpos($migration, 'DELETE FROM {$p}commercial_offers') === false, 'B2a must not delete existing offers');

// Quote is an owner-bound read and a stale guard, never an authority credential.
$contains($quote, array('forPreparation', 'student_id', 'resolvePrice', 'grantedFor', 'quote_token', 'CommercialOfferQuoteDigest::make'), 'quote must revalidate ownership and canonical pricing');
$contains($digest, array('stale-quote guard', 'never an authorisation credential', 'hash', 'VERSION'), 'quote digest must be a stale guard only');
$contains($offer, array("'expected_quote_digest'", "'quote_subject'", "plan_quote_stale", "commercial_offer_exists", 'offersForContinuation'), 'offer issuance must recheck the quote and refuse a second live offer');
$assert(strpos($offer, "expected_quote_digest' =>") === false, 'client cannot supply the digest as a trusted payload field');

// Full is the default; two instalments require an explicit opt-in and the selected offer is immutable.
$contains($continuation, array(
    "if (\$planKind === '') \$planKind = 'full'", "plan_unavailable", '$instalmentsOptIn',
    "plan_choice_conflict", 'activePlanChoice', 'insertPlanChoice', 'supersedeActivePlanChoice',
    "'plan_kind' => \$planKind", 'expected_quote_digest',
), 'continuation must default to full, require opt-in for two, and lock the offer plan');
$contains($controller, array("'plan_kind'", "'quote_token'", "'instalments_opt_in'", "'plan_choice_conflict'", "'plan_quote_stale'"), 'controller must carry and safely report the plan choice');
$contains($readService, array('plan_options', 'quote_token', 'plan_locked', 'selected_plan_kind', 'term_label'), 'portal read must expose quoted options and the locked plan');

// Full term is primary; instalments are a secondary explicit opt-in.
$contains($theme, array('پرداخت کامل ترم', 'پرداخت در دو قسط (اختیاری)', 'instalments_opt_in', 'name="plan_kind" value="full"', 'name="plan_kind" value="two_instalments"', 'quote_token'), 'theme must render full payment first and instalments as an opt-in');
$assert(strpos($theme, 'checked') === false || strpos($theme, 'plan_kind" value="two_instalments"') > strpos($theme, 'پرداخت کامل ترم'), 'instalments must not be preselected');

// Normal Checkout for tranche 2 waits for its predecessor; reconciliation is a separate seam.
$contains($checkoutInit, array("'checkout_prerequisite_pending'", 'obligation_sequence', 'prerequisites_satisfied', 'CommercialTermFundingService', "\$state === 'issued' && \$expired"), 'student Checkout must refuse an out-of-sequence instalment and bound only the initial issued window');
$contains($checkoutRead, array("\$offerState==='accepted'", 'offer_expires_at'), 'the portal read must keep an accepted commitment payable after the initial hold');
$contains($paymentService, array("(string)\$offer->state==='issued'&&\$offer->expires_at!==null", 'late_payment_after_offer_window'), 'later accepted-offer settlements must not be rejected by the initial hold');
$contains($checkoutRead, array('prerequisite_satisfied', 'prerequisite_pending', 'paid_prerequisite_pending', 'term_paid', 'outstanding_minor', 'term_sequence'), 'portal read must expose the prerequisite and term balance');
$assert(strpos($checkoutInit, '->ingest(') === false, 'Checkout initiation must not settle payment');
$assert(strpos($checkoutRead, '->ingest(') === false, 'Checkout read must not settle payment');

// Later settlement must converge, not be lost when a worker completes an earlier target.
$contains($settlement, array('effectiveSessions', 'funding_target', 'funding_materialised', 'state === \'running\'', 'additional_funding', 'activeJobForPurchase'), 'settlement hook must raise the target under the job lock');
$contains($worker, array('effectiveSessions', 'funding_materialised', 'additional_funding_pending', 'retryable', 'completed', '->job((int) $job->id, true)', 'funding_target'), 'worker must lock the job and requeue when more sessions became effective');
$contains($fulfilment, array('awaiting_next_instalment', 'next_instalment_due', 'effective_lesson_count'), 'the fulfilment projection must distinguish a half-funded two-instalment Term from a provisioning failure');
$contains($activation, array('effectiveSessions', 'commercial_funding_not_effective', '$count = min(52, $effectiveSessions)', 'new_lessons', '$this->notify($preparation'), 'activation must materialise only the effective sessions and retry idempotent notifications');

// Term-level wording, not an "instalment" label for a full payment.
$contains($checkoutUi, array('termLabel', 'term_paid', 'پرداخت ترم بعد', 'پرداخت کامل ترم', 'قسط '), 'student account must use term-level payment wording');
$contains($payments, array('وضعیت پرداخت ترم', 'data-dzn-student-checkout'), 'account summary must use term-level labels and the existing checkout root');
$assert(strpos($checkoutUi, 'innerHTML') === false, 'payment UI must not render untrusted HTML');

// No new checkout path and no live Stripe configuration change.
$controllerSources = $read('src/Portals/StudentCheckoutController.php');
$contains($controllerSources, array("'/student/commercial-checkout'", "'/student/checkout'"), 'the existing owner-bound Checkout routes must remain the only path');
$assert(substr_count($controllerSources, "'/student/checkout'") === 1, 'there must be one student Checkout command route');

echo "Payment plan choice contract passed\n";
