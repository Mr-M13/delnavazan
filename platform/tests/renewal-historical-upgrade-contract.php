<?php
/** Static contract for the additive historical renewal repair. */
$root = dirname(__DIR__);
$migrator = file_get_contents($root . '/src/Core/Infrastructure/Migration/Migrator.php');
$plugin = file_get_contents($root . '/delnavazan-platform.php');
$rehearsal = file_get_contents($root . '/runtime/bin/schema-upgrade-rehearsal.sh');
$fixtureAdjuster = file_get_contents($root . '/runtime/bin/adjust-historical-migrator.py');

$assert = static function (bool $condition, string $message): void {
	if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($plugin, "DZN_PLATFORM_SCHEMA_VERSION', '46'"), 'The current additive migrations must publish Schema 46');
$assert(str_contains($plugin, "DZN_PLATFORM_BUILD_ID', 'google-oauth-openid-subject-20261019.1'"), 'The package needs an explicit build identity');

$matched = preg_match('/^\s*("CREATE TABLE \{\$p\}renewal_cycles .*",)$/m', $migrator, $ddl);
$assert($matched === 1, 'The historical renewal CREATE TABLE statement must remain present');
$assert(hash('sha256', trim($ddl[1])) === '7044bcda6fa513245c3505124a3228ccba3ca79fb2725fc06cc484cf1c02fb01', 'The historical renewal CREATE TABLE statement changed byte-for-byte');
$assert(str_contains($fixtureAdjuster, 'historical CREATE TABLE declarations changed'), 'The disposable historical-fixture adjuster must guard byte-exact DDL');

foreach (array(
	'045_renewal_cycle_automatic_charge_at',
	'install_renewal_cycle_automatic_charge_at',
	'ALTER TABLE {$table} ADD COLUMN automatic_charge_at datetime NULL AFTER boundary_derived_at',
	"verify_renewal_recurring_enrolment_schema( ! in_array( '045_renewal_cycle_automatic_charge_at', \$completed, true ) )",
	"\$required[] = '045_renewal_cycle_automatic_charge_at'",
	"\$required[] = '046_google_oauth_openid_subject'",
) as $needle) $assert(str_contains($migrator, $needle), 'Missing historical renewal repair contract: ' . $needle);

$start = strpos($migrator, 'private static function install_renewal_cycle_automatic_charge_at');
$end = strpos($migrator, 'private static function install_payment_secret_cipher_version_width', $start);
$assert($start !== false && $end !== false, 'The repair installer boundary is missing');
$installer = substr($migrator, $start, $end - $start);
$assert(substr_count($installer, 'ADD COLUMN automatic_charge_at') === 1, 'The repair must add exactly one column');
$assert(!preg_match('/\b(?:UPDATE|DELETE|DROP|MODIFY|RENAME)\b/i', $installer), 'The repair must not rewrite data or existing schema');

$assert(str_contains($migrator, "array('renewal_cycles','cycle_sequence',true)"), 'The audited renewal-cycle uniqueness index disappeared');
$assert(str_contains($migrator, "array('renewal_cycle_events','cycle_sequence',true)"), 'The audited renewal event-sequence index disappeared');
$assert(str_contains($migrator, "array('renewal_cycle_commands','command_key_digest',true)"), 'The audited renewal command idempotency index disappeared');
$assert(str_contains($migrator, "array('automatic_charge_at','guarantee_deadline_at')"), 'Both durable tier-F instants must remain strictly verified');
$assert(str_contains($migrator, "\$column->Default !== null") && str_contains($migrator, "(string) \$column->Extra !== ''"), 'Tier-F instant shape verification must reject defaults or generated attributes');

$assert(str_contains($rehearsal, '"dzn_renewal_cycles"=>array("automatic_charge_at")'), 'The historical rehearsal must declare the one allowed renewal addition');
$assert(str_contains($rehearsal, 'unset($r[$column])'), 'The historical rehearsal must compare all pre-existing row data');
$assert(str_contains($rehearsal, 'COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLUMN_KEY,EXTRA'), 'The historical rehearsal must compare the remaining column identities');
$assert(str_contains($rehearsal, '!str_ends_with($table,"options")&&$after[$table]!==$shape'), 'The historical rehearsal must still reject every other business-table schema or data change');
$assert(str_contains($rehearsal, 'preserved option changed during upgrade'), 'The historical rehearsal must retain an explicit WordPress-option preservation sentinel');

echo "Historical renewal upgrade contract passed\n";
