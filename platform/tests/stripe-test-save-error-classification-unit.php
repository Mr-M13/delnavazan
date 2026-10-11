<?php
/** Safe Stripe save diagnostics disclose only a fixed allowlist of reason codes. */
require_once dirname(__DIR__).'/src/Admin/Controller/IntegrationControlPlaneController.php';
require_once dirname(__DIR__).'/src/Core/Infrastructure/Repository/PersistenceException.php';

use Delnavazan\Platform\Admin\Controller\IntegrationControlPlaneController;
use Delnavazan\Platform\Core\Infrastructure\Repository\PersistenceException;

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

foreach(array(1062=>'stripe_persistence_duplicate',1054=>'stripe_persistence_schema',1146=>'stripe_persistence_schema',1205=>'stripe_persistence_transient',1213=>'stripe_persistence_transient',2000=>'stripe_persistence_failed') as $errno=>$expected){
    $classified=IntegrationControlPlaneController::safeStripeSaveResult(new PersistenceException($errno,'db detail '.$privateValue,'insert'));
    stripe_save_diagnostic_assert($classified===$expected,'A database failure was not reduced to its safe category.');
    stripe_save_diagnostic_assert(!str_contains($classified,$privateValue),'Database diagnostics reflected private exception data.');
}
$wrapped=IntegrationControlPlaneController::safeStripeSaveResult(new RuntimeException('Payment secret persistence failed: '.$privateValue));
stripe_save_diagnostic_assert($wrapped==='stripe_persistence_failed','A repository update failure was not classified safely.');
stripe_save_diagnostic_assert(!str_contains($wrapped,$privateValue),'A wrapped persistence failure reflected private exception data.');
$service=file_get_contents(dirname(__DIR__).'/src/Core/Application/PaymentExecution/StripeTestConfigurationService.php');
stripe_save_diagnostic_assert(str_contains($service,"stripe_test_configuration_persistence_unconfirmed")&&str_contains($service,'$state=$this->savedState()'),'Stripe save lacks a scope-only read-after-write confirmation.');

echo "Stripe save error classification unit passed\n";
