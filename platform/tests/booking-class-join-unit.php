<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository {
    final class BookingTeacherDeliveryRepository {
        public array $rows = array();
        public array $calls = array();
        public function confirmedForParticipant(string $uid, string $role, int $participantId): ?object {
            $this->calls[] = array($uid, $role, $participantId);
            return $this->rows[$role . ':' . $participantId] ?? null;
        }
    }
}
namespace Delnavazan\Platform\Core\Application {
    class ProviderReferenceVault {
        public array $references = array('join_uri_reference' => 'https://meet.google.com/abc-defg-hij');
        public function open(string $kind, int $mappingId, object|array $row): array { return $this->references; }
    }
}
namespace Delnavazan\Platform\Portals {
    final class PortalPrincipalResolver {
        public function resolve(string $role): array { return array('kind' => $role, 'id' => $role === 'teacher' ? 14 : 27); }
    }
}
namespace {
    require_once dirname(__DIR__) . '/src/Integrations/BookingClassJoinService.php';
    $assert = static function(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); };
    $repository = new \Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository();
    $vault = new \Delnavazan\Platform\Core\Application\ProviderReferenceVault();
    $row = (object) array(
        'confirmation_id' => 17,
        'starts_at_utc' => gmdate('Y-m-d H:i:s', time() + 900),
        'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 3600),
        'projection_secret_id' => 91,
        'projection_secret_state' => 'active',
        'projection_secret_key_version' => 'v1',
        'projection_secret_cipher_version' => 'v1',
        'projection_secret_nonce' => 'nonce',
        'projection_secret_ciphertext' => 'ciphertext',
    );
    $repository->rows['teacher:14'] = $row;
    $repository->rows['student:27'] = $row;
    $service = new \Delnavazan\Platform\Integrations\BookingClassJoinService($repository, $vault);
    $uid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $assert($service->target($uid, 'teacher') === 'https://meet.google.com/abc-defg-hij', 'Owning Teacher could not open the vaulted Meet target.');
    $assert($service->target($uid, 'student') === 'https://meet.google.com/abc-defg-hij', 'Owning Student could not open the vaulted Meet target.');
    $vault->references['join_uri_reference'] = 'https://evil.example/steal';
    try { $service->target($uid, 'teacher'); throw new \RuntimeException('A non-Meet redirect target was allowed.'); }
    catch (\InvalidArgumentException $e) { $assert($e->getMessage() === 'portal_capability_unknown', 'Unexpected unsafe-target refusal.'); }
    $vault->references['join_uri_reference'] = 'https://meet.google.com/abc-defg-hij';
    unset($repository->rows['student:27']);
    try { $service->target($uid, 'student'); throw new \RuntimeException('Non-owner joined a booking.'); }
    catch (\InvalidArgumentException $e) { $assert($e->getMessage() === 'portal_object_not_owned', 'Unexpected ownership refusal.'); }
    $repository->rows['teacher:14'] = (object) array_merge((array) $row, array('starts_at_utc' => gmdate('Y-m-d H:i:s', time() + 7200), 'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 9000)));
    try { $service->target($uid, 'teacher'); throw new \RuntimeException('Outside-window join was allowed.'); }
    catch (\InvalidArgumentException $e) { $assert($e->getMessage() === 'portal_action_outside_window', 'Unexpected time-window refusal.'); }
    $repository->rows['teacher:14'] = (object) array_merge((array) $row, array('starts_at_utc' => gmdate('Y-m-d H:i:s', time() - 3600), 'ends_at_utc' => gmdate('Y-m-d H:i:s', time() - 1800)));
    $assert($service->target($uid, 'teacher') === 'https://meet.google.com/abc-defg-hij', 'A started class inside the late join window was blocked.');
    echo "Booking class join unit passed\n";
}
