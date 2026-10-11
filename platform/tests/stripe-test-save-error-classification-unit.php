<?php
/** Safe Stripe save diagnostics disclose only a fixed allowlist of reason codes. */
require_once dirname(__DIR__).'/src/Admin/Controller/IntegrationControlPlaneController.php';

use Delnavazan\Platform\Admin\Controller\IntegrationControlPlaneController;

function stripe_save_diagnostic_assert(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
}

$known=array(
    'invalid_stripe_test_publishable_key','invalid_stripe_test_secret_key','invalid_stripe_test_webhook_secret',
    'complete_stripe_test_configuration_required','stripe_test_environment_required','stripe_account_scope_ambiguous',
    'stripe_test_account_state_unavailable','stripe_test_secret_store_refused'
);
foreach($known as $code){
    stripe_save_diagnostic_assert(
        IntegrationControlPlaneController::safeStripeSaveResult(new RuntimeException($code))===$code,
        'A known fixed Stripe save failure was not classified safely.'
    );
}
stripe_save_diagnostic_assert(
    IntegrationControlPlaneController::safeStripeSaveResult(new RuntimeException('Unauthorized'))==='stripe_configuration_unauthorized',
    'The capability refusal was not classified safely.'
);
$privateValue='sk_test_SECRET_MUST_NOT_ESCAPE';
$unknown=IntegrationControlPlaneController::safeStripeSaveResult(new RuntimeException('database failure '.$privateValue));
stripe_save_diagnostic_assert($unknown==='save_failed','An unknown save failure was not reduced to the generic result.');
stripe_save_diagnostic_assert(!str_contains($unknown,$privateValue),'An exception value was reflected into the result.');

echo "Stripe save error classification unit passed\n";
