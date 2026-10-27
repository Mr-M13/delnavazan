<?php
/** Bounded retry classification for provider/Calendar transport failures. */
$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/src/Integrations/PostIntroActivationWorker.php');
require $root . '/src/Integrations/PostIntroActivationWorker.php';

use Delnavazan\Platform\Integrations\PostIntroActivationWorker;

$fail = static function (string $message): never { throw new RuntimeException($message); };
$method = new ReflectionMethod(PostIntroActivationWorker::class, 'retryable');
$method->setAccessible(true);
$retryable = static fn(string $code): bool => (bool) $method->invoke(null, new RuntimeException($code));

foreach (array(
    'google_calendar_transport_failed',
    'google_calendar_projection_inspection_failed',
    'provider_projection_retryable',
    'provider_connection_unusable',
) as $code) {
    if (!$retryable($code)) $fail('Expected retryable provider/Calendar failure: ' . $code);
}
foreach (array(
    'google_calendar_projection_refused',
    'google_calendar_projection_identity_mismatch',
    'google_calendar_request_not_allowed',
    'Unauthorized',
    'projection_already_recorded',
) as $code) {
    if ($retryable($code)) $fail('Expected non-retryable failure: ' . $code);
}
foreach (array(
    'min(1800, 30 * (2 ** ($attempt - 1)))',
    "'retryable'",
    "'failed'",
    'MAX_ATTEMPTS = 8',
) as $needle) {
    if (!str_contains($source, $needle)) $fail('Missing bounded retry control: ' . $needle);
}

echo "Calendar transport retry classification: PASS\n";
