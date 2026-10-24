<?php
/** Permission, CSRF and signed-context ordering at the record-action boundary. */
if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string { static $n = 0; return sprintf('00000000-0000-4000-8000-%012d', ++$n); }
}
if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string { return 'dzn-test-' . $scheme . '-salt'; }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-z0-9_-]/', '', $value)); }
}
if (!function_exists('absint')) {
    function absint(mixed $value): int { return abs((int) $value); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash(string $value): string { return stripslashes($value); }
}

require_once dirname(__DIR__) . '/src/Admin/RecordAction/RecordActionException.php';
require_once dirname(__DIR__) . '/src/Admin/RecordAction/RecordActionToken.php';
require_once dirname(__DIR__) . '/src/Admin/RecordAction/RecordActionRequest.php';

use Delnavazan\Platform\Admin\RecordAction\RecordActionException;
use Delnavazan\Platform\Admin\RecordAction\RecordActionRequest;
use Delnavazan\Platform\Admin\RecordAction\RecordActionToken;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$definition = array('capability' => 'dzn_manage_teachers', 'record_capability' => 'dzn_manage_teachers', 'record_type' => 'teacher');
$token = RecordActionToken::issue('teacher-archive', 'teacher', 42, 'dzn-teachers-menu', 7);
$post = array(
    'record_type' => 'teacher',
    'record_id' => '42',
    'page' => 'dzn-teachers-menu',
    'idempotency_key' => $token['idempotency_key'],
    'issued_at' => (string) $token['issued_at'],
    'context_token' => $token['context_token'],
);

$nonceCalled = false;
$failed = false;
try {
    RecordActionRequest::fromPost('teacher-archive', $definition, $post, 7, static fn(string $cap): bool => false, static function () use (&$nonceCalled): bool { $nonceCalled = true; return true; });
} catch (RecordActionException $e) { $failed = $e->getMessage() === 'capability_denied'; }
$assert($failed && !$nonceCalled, 'A capability failure must fail before any CSRF check.');

$failed = false;
try {
    RecordActionRequest::fromPost('teacher-archive', $definition, $post, 7, static fn(string $cap): bool => true, static fn(): bool => false);
} catch (RecordActionException $e) { $failed = $e->getMessage() === 'nonce_failed'; }
$assert($failed, 'A failed CSRF nonce must stop the request.');

$tampered = $post; $tampered['record_id'] = '43';
$failed = false;
try {
    RecordActionRequest::fromPost('teacher-archive', $definition, $tampered, 7, static fn(string $cap): bool => true, static fn(): bool => true);
} catch (RecordActionException $e) { $failed = $e->getMessage() === 'context_invalid'; }
$assert($failed, 'A tampered record id must invalidate the signed context.');

$wrongType = $post; $wrongType['record_type'] = 'student';
$failed = false;
try {
    RecordActionRequest::fromPost('teacher-archive', $definition, $wrongType, 7, static fn(string $cap): bool => true, static fn(): bool => true);
} catch (RecordActionException $e) { $failed = $e->getMessage() === 'context_incomplete'; }
$assert($failed, 'A mismatched record type must fail closed.');

$failed = false;
try {
    RecordActionRequest::fromPost('teacher-archive', $definition, $post, 8, static fn(string $cap): bool => true, static fn(): bool => true);
} catch (RecordActionException $e) { $failed = $e->getMessage() === 'context_invalid'; }
$assert($failed, 'Another actor must not replay the signed context.');

$valid = RecordActionRequest::fromPost('teacher-archive', $definition, $post, 7, static fn(string $cap): bool => true, static fn(): bool => true);
$assert($valid['record_type'] === 'teacher' && $valid['record_id'] === 42, 'A valid request must resolve the signed record context.');
$assert($valid['idempotency_key'] === $token['idempotency_key'], 'The generated idempotency key must survive verification unchanged.');

echo "Record action request unit passed\n";
