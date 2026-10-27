<?php
$root = dirname(__DIR__);
if (!function_exists('wp_salt')) { function wp_salt(string $scheme = 'auth'): string { return 'isolated-salt-' . $scheme . '-0123456789abcdef'; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); } }
if (!function_exists('current_user_can')) { function current_user_can(string $capability): bool { return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id(): int { return 1; } }
require $root . '/src/Core/Application/RecordingConsentService.php';
require $root . '/src/Core/Application/RecordingConsentPolicyService.php';
require $root . '/src/Core/Application/Port/RecordingProviderPort.php';
require $root . '/src/Integrations/ContractRecordingProvider.php';

use Delnavazan\Platform\Core\Application\RecordingConsentService;
use Delnavazan\Platform\Integrations\ContractRecordingProvider;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Assertion failed: ' . $message);
};

// digest determinism
$a = RecordingConsentService::keyDigest('abc');
$b = RecordingConsentService::keyDigest('abc');
$assert($a === $b, 'keyDigest deterministic');
$assert(strlen($a) === 64, 'keyDigest sha256 length');
$assert(RecordingConsentService::keyDigest('abc') !== RecordingConsentService::keyDigest('def'), 'keyDigest distinct');

$p1 = RecordingConsentService::payloadDigest(array('a' => 1, 'b' => 2));
$p2 = RecordingConsentService::payloadDigest(array('b' => 2, 'a' => 1));
$assert($p1 === $p2, 'payloadDigest order-independent');

// provider adapter
$adapter = new ContractRecordingProvider();
$cap = $adapter->capture(array('uid' => 'u1'));
$assert(isset($cap['object_key_raw']) && isset($cap['object_key_mixed']), 'capture returns keys');
$assert($adapter->purge('any') === true, 'purge returns true');

echo "phase-2a2y isolated-runtime: OK (" . $assertions . " assertions)\n";
