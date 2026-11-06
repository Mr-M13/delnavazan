<?php
declare(strict_types=1);

function wp_salt(string $scheme = 'auth'): string { return 'meet-reference-vault-test-salt-which-is-long-enough'; }
function wp_json_encode($value, int $flags = 0): string|false { return json_encode($value, $flags); }
require_once __DIR__ . '/../src/Core/Application/ProviderReferenceVault.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$vault = new \Delnavazan\Platform\Core\Application\ProviderReferenceVault();
$row = $vault->seal('meet_classroom', 42, array(
    'provider_object_reference' => '',
    'join_uri_reference' => 'https://meet.google.com/abc-defg-hij',
    'space_name' => 'spaces/AppSpace_123',
    'space_origin' => 'app',
    'space_organizer' => 'classroom@delnavazan.com',
));
$assert(!str_contains($row['ciphertext'], 'AppSpace_123'), 'Raw space name leaked into the ciphertext field.');
$opened = $vault->open('meet_classroom', 42, $row);
$assert(($opened['join_uri_reference'] ?? '') === 'https://meet.google.com/abc-defg-hij', 'Meet join URI did not round-trip.');
$assert(($opened['space_name'] ?? '') === 'spaces/AppSpace_123', 'Exact app space name did not round-trip.');
$assert(($opened['space_origin'] ?? '') === 'app', 'Space provenance did not round-trip.');
$assert(($opened['space_organizer'] ?? '') === 'classroom@delnavazan.com', 'Organizer identity did not round-trip.');
$assert(!isset($opened['provider_object_reference']), 'An empty pending event reference must not be persisted as a fake id.');

foreach (array(
    array('space_name' => 'spaces/../etc'),
    array('space_origin' => 'calendar-created'),
    array('space_organizer' => 'not-an-email'),
) as $bad) {
    $rejected = false;
    try {
        $vault->seal('meet_classroom', 42, array_merge(array('join_uri_reference' => 'https://meet.google.com/abc-defg-hij'), $bad));
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $assert($rejected, 'Invalid Meet reference field was accepted: ' . json_encode($bad));
}

$rejected = false;
try { $vault->open('meet_classroom', 43, $row); } catch (RuntimeException) { $rejected = true; }
$assert($rejected, 'Reference ciphertext opened under a different classroom id.');

echo "Meet classroom reference vault unit passed\n";
