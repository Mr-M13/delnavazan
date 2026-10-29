<?php
namespace {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
    require_once dirname(__DIR__) . '/src/Integrations/ProviderCredentialRefresher.php';
    use Delnavazan\Platform\Integrations\ProviderCredentialRefresher;
    $fail = static function (string $name, bool $ok): void { if (!$ok) throw new RuntimeException('Provider credential refresher unit failed: ' . $name); };
    $calls = array();
    $success = new ProviderCredentialRefresher(static function (int $id, string $ref) use (&$calls): bool { $calls[] = array($id, $ref); return true; });
    $fail('successful refresh delegates the exact connection and reference', $success->refresh(7, 'google-calendar-refresh:9:2') === true && $calls === array(array(7, 'google-calendar-refresh:9:2')));
    $failure = new ProviderCredentialRefresher(static fn(int $id, string $ref): bool => false);
    $fail('failed refresh is reported as false', $failure->refresh(7, 'ref') === false);
    echo "Provider credential refresher unit passed\n";
}
