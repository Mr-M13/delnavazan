<?php
/** Signed record-action context and idempotency metadata. */
if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string {
        static $sequence = 0;
        $sequence++;
        return sprintf('00000000-0000-4000-8000-%012d', $sequence);
    }
}
if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string {
        return 'dzn-test-' . $scheme . '-salt';
    }
}

require_once dirname(__DIR__) . '/src/Admin/RecordAction/RecordActionException.php';
require_once dirname(__DIR__) . '/src/Admin/RecordAction/RecordActionToken.php';

use Delnavazan\Platform\Admin\RecordAction\RecordActionToken;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$issued = RecordActionToken::issue('teacher-archive', 'teacher', 42, 'dzn-teachers-menu', 7);
$context = $issued;
unset($context['context_token']);

$assert(strlen($issued['idempotency_key']) === 36, 'A generated idempotency key must be a complete UUID.');
$assert($issued['context_token'] !== '', 'A context token must be issued.');
$assert(RecordActionToken::verify($issued['context_token'], $context, 7), 'The issued context must verify for the same actor.');
$second = RecordActionToken::issue('teacher-archive', 'teacher', 42, 'dzn-teachers-menu', 7);
$assert($second['idempotency_key'] !== $issued['idempotency_key'], 'Each rendered form must receive a fresh idempotency key.');

$tampered = $context; $tampered['record_id'] = 43;
$assert(!RecordActionToken::verify($issued['context_token'], $tampered, 7), 'A client cannot retarget the action to another record.');
$tampered = $context; $tampered['idempotency_key'] = '11111111-1111-4111-8111-111111111111';
$assert(!RecordActionToken::verify($issued['context_token'], $tampered, 7), 'A client cannot substitute its own idempotency key.');
$assert(!RecordActionToken::verify($issued['context_token'], $context, 8), 'A token cannot be replayed by another actor.');
$assert(!RecordActionToken::verify('', $context, 7), 'A missing token must fail closed.');
$assert(!RecordActionToken::verify($issued['context_token'], $context, 7, (int) $issued['issued_at'] + 7201), 'Expired action forms must fail closed.');

$future = RecordActionToken::issue('teacher-archive', 'teacher', 42, 'dzn-teachers-menu', 7);
$futureContext = $future; unset($futureContext['context_token']);
$assert(!RecordActionToken::verify($future['context_token'], $futureContext, 7, (int) $future['issued_at'] - 301), 'Forms issued too far in the future must fail closed.');

echo "Record action token and idempotency unit passed\n";
