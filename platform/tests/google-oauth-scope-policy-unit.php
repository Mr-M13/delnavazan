<?php
$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/ProviderIntegrationRule.php';

use Delnavazan\Platform\Core\Application\ProviderIntegrationRule;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$calendar = ProviderIntegrationRule::GOOGLE_CALENDAR_SCOPE;
$legacyMeet = 'https://www.googleapis.com/auth/meetings.space.created';
$assert(ProviderIntegrationRule::permittedScopes('google_calendar', $calendar), 'The exact Calendar events grant must be usable.');
$assert(!ProviderIntegrationRule::permittedScopes('google_calendar', $calendar . ' ' . $legacyMeet), 'A legacy Meet scope must require re-consent.');
$assert(!ProviderIntegrationRule::permittedScopes('google_calendar', $calendar . ' https://www.googleapis.com/auth/drive'), 'Any additional Google scope must require re-consent.');
$assert(!ProviderIntegrationRule::permittedScopes('google_meet', $calendar), 'The evidence-only Meet provider must not authorize OAuth.');

echo "Google OAuth exact-scope policy unit passed\n";
