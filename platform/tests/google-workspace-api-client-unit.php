<?php
/** Exact Meet/Calendar REST paths used by organizer provisioning. */
namespace Delnavazan\Platform\Core\Application {
    final class IntegrationSecretService {
        public function open(int $connectionId, string $purpose, object|array $credential): string {
            return json_encode(array('access_token' => 'test-access-token'));
        }
    }
}
namespace Delnavazan\Platform\Integrations\GoogleWorkspace {
    final class GoogleWorkspaceRepository {
        public function activeConnection(bool $lock = false): ?object { return (object) array('id' => 1, 'connection_state' => 'connected'); }
        public function credential(int $connectionId, bool $lock = false): ?object { return (object) array('id' => 1, 'valid_until_utc' => null); }
    }
    final class GoogleWorkspaceOAuthService {
        public function __construct(mixed ...$args) {}
        public function refreshConnection(int $connectionId): void {}
    }
}
namespace {
    function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }

    require_once dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/GoogleWorkspaceClassroomRule.php';
    require_once dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/GoogleWorkspaceApiClient.php';

    use Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceApiClient;
    use Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceOAuthService;
    use Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceRepository;
    use Delnavazan\Platform\Core\Application\IntegrationSecretService;

    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
    $requests = array();
    $transport = static function (string $method, string $uri, array $headers, ?string $body) use (&$requests): array {
        $requests[] = compact('method', 'uri', 'headers', 'body');
        return array('status' => 200, 'body' => array('name' => 'spaces/AppSpace_123', 'meetingUri' => 'https://meet.google.com/abc-defg-hij', 'meetingCode' => 'abc-defg-hij'), 'raw' => '{}');
    };
    $client = new GoogleWorkspaceApiClient(new GoogleWorkspaceRepository(), new IntegrationSecretService(), new GoogleWorkspaceOAuthService(), $transport);

    $client->createSpace();
    $assert($requests[0]['method'] === 'POST' && $requests[0]['uri'] === 'https://meet.googleapis.com/v2/spaces', 'spaces.create must POST /v2/spaces.');
    $assert($requests[0]['body'] === '{}', 'spaces.create with no configuration must send an empty JSON object body.');

    $requests = array();
    $client->getSpace('spaces/AppSpace_123');
    $assert($requests[0]['uri'] === 'https://meet.googleapis.com/v2/spaces/AppSpace_123', 'A full space resource name must resolve to /v2/spaces/{space}, not an encoded slash.');
    $client->getSpace('abc-defg-hij');
    $assert($requests[1]['uri'] === 'https://meet.googleapis.com/v2/spaces/abc-defg-hij', 'The meeting-code alias must resolve to the documented path.');

    $requests = array();
    $client->createMember('spaces/AppSpace_123', 'teacher@example.test', 'COHOST');
    $assert($requests[0]['uri'] === 'https://meet.googleapis.com/v2/spaces/AppSpace_123/members', 'Member creation must use the exact spaces/{space} parent.');
    $assert(json_decode((string) $requests[0]['body'], true) === array('email' => 'teacher@example.test', 'role' => 'COHOST'), 'Member creation must send the normalized teacher and COHOST role.');

    $requests = array();
    $client->patchSpace('spaces/AppSpace_123', array('config' => array('moderation' => 'ON')), 'config.moderation');
    $assert($requests[0]['method'] === 'PATCH' && $requests[0]['uri'] === 'https://meet.googleapis.com/v2/spaces/AppSpace_123?updateMask=config.moderation', 'Space configuration must PATCH the exact resource name.');

    echo "Google Workspace API client unit passed\n";
}
