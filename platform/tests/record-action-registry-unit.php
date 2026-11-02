<?php
/** Registry visibility and capability metadata for contextual record actions. */
require_once dirname(__DIR__) . '/src/Admin/RecordAction/RecordActionException.php';
require_once dirname(__DIR__) . '/src/Admin/RecordAction/RecordActionContext.php';
require_once dirname(__DIR__) . '/src/Admin/RecordAction/RecordActionRegistry.php';

use Delnavazan\Platform\Admin\RecordAction\RecordActionContext;
use Delnavazan\Platform\Admin\RecordAction\RecordActionException;
use Delnavazan\Platform\Admin\RecordAction\RecordActionRegistry;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$active = (object) array('id' => 11, 'archived_at' => null, 'status' => 'active', 'wordpress_user_id' => null, 'onboarding_state' => 'active');
$archived = (object) array('id' => 12, 'archived_at' => '2026-10-24 00:00:00', 'status' => 'archived');
$pending = (object) array('id' => 13, 'archived_at' => null, 'status' => 'active', 'onboarding_state' => 'pending_review');

$activeActions = RecordActionRegistry::forRecord('teacher', $active);
foreach (array('teacher-archive', 'teacher-profile', 'teacher-accepting-state', 'teacher-eligibility', 'teacher-availability-profile', 'teacher-availability-rule') as $key) {
    $assert(isset($activeActions[$key]), 'Active teacher actions missing: ' . $key);
}
$assert(!isset($activeActions['teacher-restore']), 'An active teacher must not expose restore.');
$archivedActions = RecordActionRegistry::forRecord('teacher', $archived);
$assert(array_keys($archivedActions) === array('teacher-restore'), 'Archived teachers must expose restore only.');
$pendingActions = RecordActionRegistry::forRecord('teacher', $pending);
foreach (array('teacher-approve-onboarding', 'teacher-return-onboarding', 'teacher-reject-onboarding') as $key) {
    $assert(isset($pendingActions[$key]), 'Pending-review actions missing: ' . $key);
}
$studentActions = RecordActionRegistry::forRecord('student', $active);
foreach (array('student-profile', 'student-deactivate', 'student-archive', 'student-classify-capacity', 'student-guardian-grant') as $key) {
    $assert(isset($studentActions[$key]), 'Active student actions missing: ' . $key);
}
$studentArchived = RecordActionRegistry::forRecord('student', $archived);
$assert(array_keys($studentArchived) === array('student-restore'), 'Archived students must expose restore only.');
$assert(!isset($studentActions['student-restore']), 'An active student must not expose restore.');

$profileFields = RecordActionRegistry::fieldsFor('teacher-profile', $active);
$profileNames = array_column($profileFields, 'name');
foreach (array('display_name', 'email', 'country_code', 'timezone', 'locale', 'calendar_preference') as $name) {
    $assert(in_array($name, $profileNames, true), 'Teacher profile fields missing: ' . $name);
}
foreach (array('teacher_id', 'idempotency_key', 'evidence_reference', 'evidence_at', 'evidence_channel') as $forbidden) {
    $assert(!in_array($forbidden, $profileNames, true), 'Teacher profile fields must not expose technical input: ' . $forbidden);
}

$definition = RecordActionRegistry::definition('teacher-archive');
$assert(is_array($definition), 'The archive action must be registered.');
$assert(($definition['capability'] ?? '') === 'dzn_manage_teachers', 'The archive action must use the narrow teacher authority.');
$assert(($definition['record_capability'] ?? '') === 'dzn_manage_teachers', 'The archive action must re-check the teacher read authority.');
$assert(($definition['require_confirmation'] ?? false) === true, 'The archive action must require explicit confirmation.');
$assert(strlen((string) ($definition['consequence'] ?? '')) > 20, 'The archive action must explain its consequence.');
$assert(str_contains((string) ($definition['consequence'] ?? ''), 'audit'), 'The archive consequence must state that audit history is retained.');
$restore = RecordActionRegistry::definition('teacher-restore');
$assert(str_contains((string) ($restore['consequence'] ?? ''), 'does not restore Teacher access automatically'), 'Restore must not claim that access returns automatically.');
$assert(RecordActionRegistry::definition('teacher-approve-onboarding')['capability'] === 'dzn_manage_onboarding', 'Onboarding approval must use the onboarding authority.');
$assert(RecordActionRegistry::definition('unknown-action') === null, 'Unknown actions must not resolve.');

$failed = false;
try {
    $context = new RecordActionContext('unknown-action', 'teacher', 11, 'dzn-teachers-menu', '123456789012345678901234', $active);
    RecordActionRegistry::handle('unknown-action', $context, array());
} catch (RecordActionException) {
    $failed = true;
}
$assert($failed, 'Unknown actions must fail closed before dispatch.');

echo "Record action registry unit passed\n";
