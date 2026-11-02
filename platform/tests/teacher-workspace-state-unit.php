<?php
/** Pure state vocabulary coverage for the consolidated Teachers workspace. */
if (!function_exists('wp_date')) { function wp_date(string $format, int $timestamp): string { return gmdate($format, $timestamp); } }
if (!function_exists('current_user_can')) { function current_user_can(string $capability): bool { return true; } }
if (!function_exists('number_format_i18n')) { function number_format_i18n($number, int $decimals = 0): string { return number_format((float) $number, $decimals); } }

require_once dirname(__DIR__) . '/src/Core/Application/TeacherWorkspaceReadService.php';

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$service = new \Delnavazan\Platform\Core\Application\TeacherWorkspaceReadService();
$call = static function (string $method, array $args) use ($service) {
    $reflection = new ReflectionMethod($service, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($service, $args);
};

$active = (object) array('status' => 'active', 'archived_at' => null, 'onboarding_state' => 'active', 'readiness_state' => 'ready', 'principal_status' => 'active', 'generation_status' => 'claimed', 'linked_user_id' => 4);
$assert($call('directoryStatus', array($active)) === 'active', 'A ready, linked teacher must be active.');
$onboarding = (object) array('status' => 'active', 'archived_at' => null, 'onboarding_state' => 'linked_pending', 'readiness_state' => 'not_ready', 'principal_status' => 'active', 'generation_status' => 'active', 'generation_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600), 'linked_user_id' => 5);
$assert($call('directoryStatus', array($onboarding)) === 'invitation_available', 'A live invitation must remain a pending invitation, not active.');
$archived = (object) array('status' => 'archived', 'archived_at' => '2026-01-01 00:00:00');
$assert($call('directoryStatus', array($archived)) === 'archived', 'Archived records must not appear as active.');
$paused = (object) array('status' => 'inactive', 'archived_at' => null);
$assert($call('directoryStatus', array($paused)) === 'inactive', 'Inactive teachers must be treated as unavailable.');

$notApplicable = $call('paymentState', array(null, false));
$assert($notApplicable['state'] === 'not_applicable' && $notApplicable['label'] === 'Not applicable', 'No teaching and no statement must be Not applicable.');
$awaiting = $call('paymentState', array(null, true));
$assert($awaiting['state'] === 'awaiting_statement', 'Recorded teaching without a statement must await issuance.');
$draft = $call('paymentState', array((object) array('state' => 'draft', 'payable_amount_minor' => 1000, 'currency' => 'AUD', 'period_label' => 'October 2026', 'id' => 9, 'paid_at' => null), true));
$assert($draft['state'] === 'awaiting_statement', 'A draft must not be presented as paid.');
$issued = $call('paymentState', array((object) array('state' => 'issued', 'payable_amount_minor' => 1000, 'currency' => 'AUD', 'period_label' => 'October 2026', 'id' => 10, 'paid_at' => null), true));
$assert($issued['state'] === 'pending' && $issued['label'] === 'Payment pending', 'An issued statement without settlement evidence must remain payment pending.');
$paid = $call('paymentState', array((object) array('state' => 'paid', 'payable_amount_minor' => 1000, 'currency' => 'AUD', 'period_label' => 'October 2026', 'id' => 11, 'paid_at' => '2026-11-01 10:00:00'), true));
$assert($paid['state'] === 'paid' && $paid['label'] === 'Up to date', 'Only a recorded settlement event may show Up to date.');

$empty = $call('emptyResponse', array());
$assert($empty['average_seconds'] === null && $empty['median_seconds'] === null, 'A teacher without measurable responses must not invent a response time.');

echo "teacher-workspace-state-unit: OK\n";
