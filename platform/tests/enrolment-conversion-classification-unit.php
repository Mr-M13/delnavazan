<?php
/** Pure conversion classification checks for trustworthy reporting. */
$GLOBALS['wpdb'] = (object) array('prefix' => 'wp_');
require_once dirname(__DIR__) . '/src/Core/Application/EnrolmentWorkspaceReadService.php';
use Delnavazan\Platform\Core\Application\EnrolmentWorkspaceReadService;

$service = new EnrolmentWorkspaceReadService();
$method = new ReflectionMethod(EnrolmentWorkspaceReadService::class, 'conversionClassification');
$method->setAccessible(true);
$classify = static fn(array $row): array => $method->invoke($service, (object) $row);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

$base = array('lifecycle_status' => 'closed', 'resolution_state' => 'teacher_confirmed', 'student_id' => 4);
$assert($classify($base + array('preparation_state' => 'activated', 'converted_enrolment_id' => 9))['bucket'] === 'converted', 'An activated continuation is converted');
$assert($classify($base + array('outcome_code' => 'delivered', 'preparation_state' => 'prepared'))['bucket'] === 'awaiting', 'A delivered intro inside the hold period is awaiting, not failed');
$assert($classify($base + array('outcome_code' => 'delivered', 'preparation_state' => 'checkout_pending', 'offer_id' => 3))['bucket'] === 'awaiting', 'An open checkout is awaiting decision or payment');
$assert($classify($base + array('outcome_code' => 'delivered', 'preparation_state' => 'expired'))['bucket'] === 'did_not_convert', 'An expired hold without verified payment did not convert');
$assert($classify($base + array('outcome_code' => 'student_no_show'))['bucket'] === 'excluded', 'A no-show trial is excluded and reviewed separately');
$assert($classify($base + array('outcome_code' => 'delivered', 'preparation_state' => 'exception'))['bucket'] === 'excluded', 'Unresolved post-intro evidence is excluded');
$assert($classify(array('lifecycle_status' => 'submitted', 'resolution_state' => 'unresolved') + $base + array('preparation_state' => ''))['bucket'] === 'awaiting', 'An open request remains in the denominator as awaiting');

echo "Enrolment conversion classification unit passed\n";
