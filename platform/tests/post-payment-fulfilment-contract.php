<?php
/** Source contract for paid-post-intro fulfilment, portal partial reads and reviewed recovery. */
$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_readable($full)) throw new RuntimeException('missing file: ' . $path);
    return (string) file_get_contents($full);
};
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$contains = static function (string $haystack, array $needles, string $message) use ($assert): void {
    foreach ($needles as $needle) $assert(str_contains($haystack, $needle), $message . ': ' . $needle);
};

$portal = $read('platform/src/Portals/PortalReadModels.php');
$enrolmentPort = $read('platform/src/Core/Application/CanonicalStudentEnrolmentPortalReadPort.php');
$fulfilment = $read('platform/src/Core/Application/PostIntroFulfilmentReadService.php');
$recovery = $read('platform/src/Core/Application/PostIntroRecoveryService.php');
$worker = $read('platform/src/Integrations/PostIntroActivationWorker.php');
$admin = $read('platform/src/Admin/Controller/PostIntroAdminController.php');
$plugin = $read('platform/delnavazan-platform.php');
$bridge = $read('theme/inc/platform-bridge.php');
$shell = $read('theme/template-parts/portal/shell.php');
$provider = $read('platform/src/Core/Infrastructure/Repository/PaymentProviderRepository.php');
$commercial = $read('platform/src/Core/Application/CommercialPaymentService.php');
$checkoutService = $read('platform/src/Core/Application/Checkout/StudentCheckoutInitiationService.php');
$reconciliation = $read('platform/src/Core/Application/Checkout/StripeCheckoutReconciliationService.php');
$intake = $read('platform/src/Core/Application/PaymentExecution/PaymentEventIntakeService.php');

$contains($portal, array(
    'StudentEnrolmentPortalReadPort',
    'CanonicalStudentEnrolmentPortalReadPort',
    'fulfilment_state',
    'payment_verified',
    'provisioning_message_code',
), 'Student enrolment reads must use the partial-safe fulfilment projection');

$contains($enrolmentPort, array(
    'CanonicalEnrolmentLifecycleValidator::valid',
    'TeacherAssignmentAssessment::validHistory',
    'isStudentOrGuardian',
    "'assignment_state'",
    "'pending'",
    'fulfilment_state',
), 'Student enrolment ownership must remain proved while Term/Assignment are optional');

$contains($fulfilment, array(
    'settlementForObligation',
    'payment_verified',
    'authoritative_schedule_missing',
    'term_missing',
    'teacher_assignment_missing',
    'lessons_missing',
    'checkout_reconciliation_available',
    'payment_received_classes_preparing',
), 'Fulfilment status must derive from verified settlement and canonical provisioning facts');

$contains($recovery, array(
    'StripeCheckoutReconciliationService',
    "reconcile(array('attempt_uid' => \$attemptUid))",
    'PostIntroSettlementHook::handle',
    'PostIntroActivationWorker::requeue',
    'PostIntroActivationWorker::runJob',
    'verified_payment_required',
    'authoritative_schedule_missing',
    'already_completed',
), 'Recovery must reconcile or prove a genuine payment and reuse the existing activation worker');

$contains($worker, array('public static function runJob', 'post_intro_job_in_progress', 'post_intro_attempt_limit_reached'), 'Single-job recovery must preserve lease and attempt boundaries');
$contains($admin, array('Paid enrolment fulfilment', 'inspect_fulfilment', 'recover_fulfilment', 'signature_invalid', 'Recent refused webhook receipts'), 'Operators need a visible fulfilment status and webhook refusal diagnostic');
$contains($plugin, array("PostIntroRecoveryCommand::register", "defined( 'WP_CLI' )"), 'The reviewed recovery command must be available to WP-CLI only');
$contains($provider, array('recentRefusals', "verification_state<>'verified'"), 'Webhook refusal diagnostics must expose only safe receipt fields');

$contains($bridge, array('dzn_theme_platform_provisioning_notice', 'payment_received_classes_preparing', "empty( \$enrolment['payment_verified'] )", 'enrolment_awaiting_payment_confirmation'), 'Portal payment messaging must be gated by authoritative payment_verified state');
$contains($shell, array('dzn-portal__provisioning', "role=\"status\""), 'The Student Portal must render a partial provisioning notice instead of failing');

$contains($checkoutService, array("(string) \$offer->uid . ':' . (int) \$obligation->obligation_sequence", "CommercialIdempotency"), 'New Checkout metadata must carry the canonical raw obligation reference');
$assert(!str_contains($checkoutService, 'CommercialSupport::obligationReference'), 'Checkout metadata must not carry an already-keyed obligation digest');
$contains($reconciliation, array('legacyObligationReference', 'sameProviderCorrelation', 'repairUnmatchedEvidence', 'unmatched_payment_evidence'), 'Reconciliation must accept legacy keyed metadata and repair an exact unmatched event');
$contains($intake, array('canonicalObligationReference', 'obligation_reference_digest=%s', "preg_match('/^[a-f0-9]{64}$/D'"), 'Provider intake must resolve both raw and legacy keyed obligation metadata without double hashing');
$contains($commercial, array('repairUnmatchedEvidence', 'updateEvidenceAttribution', 'openExceptionForEvidence', 'Provider evidence attributed during reviewed reconciliation'), 'Unmatched evidence repair must be a controlled, auditable settlement path');

echo "Post-payment fulfilment contract passed.\n";
