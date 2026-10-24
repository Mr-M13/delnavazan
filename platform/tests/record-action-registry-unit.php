<?php
/** Registry visibility and capability metadata for contextual record actions. */
require_once dirname(__DIR__) . '/src/Admin/RecordAction/RecordActionException.php';
require_once dirname(__DIR__) . '/src/Admin/RecordAction/RecordActionContext.php';
require_once dirname(__DIR__) . '/src/Admin/RecordAction/RecordActionRegistry.php';

use Delnavazan\Platform\Admin\RecordAction\RecordActionContext;
use Delnavazan\Platform\Admin\RecordAction\RecordActionException;
use Delnavazan\Platform\Admin\RecordAction\RecordActionRegistry;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$active = (object) array('id' => 11, 'archived_at' => null, 'status' => 'active');
$archived = (object) array('id' => 12, 'archived_at' => '2026-10-24 00:00:00', 'status' => 'archived');

$activeActions = RecordActionRegistry::forRecord('student', $active);
$assert(array_keys($activeActions) === array('student-archive'), 'Active students must expose only the archive action.');
$archivedActions = RecordActionRegistry::forRecord('student', $archived);
$assert(array_keys($archivedActions) === array('student-restore'), 'Archived students must expose only the restore action.');
$assert(RecordActionRegistry::forRecord('teacher', $active) === array(), 'A record type without registered actions must return no actions.');

$definition = RecordActionRegistry::definition('student-archive');
$assert(is_array($definition), 'The archive action must be registered.');
$assert(($definition['capability'] ?? '') === 'dzn_manage_students', 'The archive action must use the narrow student authority.');
$assert(($definition['record_capability'] ?? '') === 'dzn_manage_students', 'The archive action must re-check the student read authority.');
$assert(($definition['require_confirmation'] ?? false) === true, 'The archive action must require explicit confirmation.');
$assert(strlen((string) ($definition['consequence'] ?? '')) > 20, 'The archive action must explain its consequence.');
$assert(RecordActionRegistry::definition('unknown-action') === null, 'Unknown actions must not resolve.');

$failed = false;
try {
    $context = new RecordActionContext('unknown-action', 'student', 11, 'dzn-student', '123456789012345678901234', $active);
    RecordActionRegistry::handle('unknown-action', $context, array());
} catch (RecordActionException) {
    $failed = true;
}
$assert($failed, 'Unknown actions must fail closed before dispatch.');

echo "Record action registry unit passed\n";
