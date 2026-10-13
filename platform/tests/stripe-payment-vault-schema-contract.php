<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = (string) file_get_contents($root . '/delnavazan-platform.php');
$migrator = (string) file_get_contents($root . '/src/Core/Infrastructure/Migration/Migrator.php');
$rule = (string) file_get_contents($root . '/src/Core/Application/PaymentExecution/PaymentExecutionRule.php');
$runner = (string) file_get_contents($root . '/runtime/bin/run-stripe-checkout-test-mode-e2e.sh');
$freshInstall = (string) file_get_contents($root . '/runtime/bin/run-fresh-install.sh');
$schemaVerifier = (string) file_get_contents($root . '/runtime/bin/verify-current-schema.sh');

if (strpos($plugin, "DZN_PLATFORM_SCHEMA_VERSION', '39'") === false) {
    throw new RuntimeException('Stripe vault width repair must publish Schema 39');
}
foreach (array(
    "'039_payment_secret_cipher_version_width'=>array(__CLASS__,'install_payment_secret_cipher_version_width')",
    "if(\$id==='039_payment_secret_cipher_version_width')self::verify_payment_secret_cipher_version_width()",
    "'039_payment_secret_cipher_version_width'",
    'ALTER TABLE {$table} MODIFY COLUMN cipher_version varchar(32) NOT NULL',
) as $needle) {
    if (strpos($migrator, $needle) === false) throw new RuntimeException('Schema 39 vault migration contract missing ' . $needle);
}
if (strpos($migrator, "strtolower( (string ) \$column->COLUMN_TYPE ) !== 'varchar(32)'") === false
    && strpos($migrator, "strtolower( (string) \$column->COLUMN_TYPE ) !== 'varchar(32)'") === false) {
    throw new RuntimeException('Schema 39 vault migration must verify the installed column width');
}
if (!preg_match('/payment_provider_secrets .*?cipher_version varchar\(16\) NOT NULL/s', $migrator)) {
    throw new RuntimeException('Migration 028 historical vault width must remain explicit for upgrade coverage');
}
if (!preg_match("/public const CIPHER='([^']+)'/", $rule, $cipher) || strlen($cipher[1]) <= 16 || strlen($cipher[1]) > 32) {
    throw new RuntimeException('The payment vault cipher identifier must require and fit the repaired width');
}
foreach (array('run-fresh-install.sh', 'DZN_PLUGIN_SOURCE="$DZN_PLUGIN_WORKTREE/platform"') as $needle) {
    if (strpos($runner, $needle) === false) throw new RuntimeException('Offline Stripe runner does not select the current monorepo plugin candidate');
}
if (strpos($freshInstall, 'verify-current-schema.sh') === false
    || strpos($schemaVerifier, 'verify_current_schema') === false
    || strpos($schemaVerifier, 'DZN_PLATFORM_SCHEMA_VERSION') === false) {
    throw new RuntimeException('Disposable runtime must verify the candidate current schema rather than a frozen historical schema');
}

echo "Stripe payment vault Schema 39 contract passed\n";
