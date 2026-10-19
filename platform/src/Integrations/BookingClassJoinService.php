<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\ProviderReferenceVault;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository;
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/** Opens the vaulted Meet reference only for an owning participant and near the lesson time. */
final class BookingClassJoinService {
    private const EARLY_SECONDS = 3600;
    private const LATE_SECONDS = 7200;

    public function __construct(
        private ?BookingTeacherDeliveryRepository $repository = null,
        private ?ProviderReferenceVault $vault = null
    ) {
        $this->repository ??= new BookingTeacherDeliveryRepository();
        $this->vault ??= new ProviderReferenceVault();
    }

    public function target(string $confirmationUid, string $role): string {
        $confirmationUid = trim($confirmationUid);
        if (preg_match('/^[0-9A-Z]{26}$/D', $confirmationUid) !== 1 || !in_array($role, array('teacher','student'), true)) {
            throw new \InvalidArgumentException('portal_object_not_portal_visible');
        }
        $principal = (new PortalPrincipalResolver())->resolve($role);
        $row = $this->repository->confirmedForParticipant($confirmationUid, $role, (int) $principal['id']);
        if (!$row) throw new \InvalidArgumentException('portal_object_not_owned');
        $start = strtotime((string) $row->starts_at_utc . ' UTC');
        $end = strtotime((string) $row->ends_at_utc . ' UTC');
        $now = time();
        if (!$start || !$end || $now < $start - self::EARLY_SECONDS || $now > $end + self::LATE_SECONDS) {
            throw new \InvalidArgumentException('portal_action_outside_window');
        }
        $secretId = (int) ($row->projection_secret_id ?? 0);
        if ($secretId < 1 || (string) ($row->projection_secret_state ?? '') !== 'active') {
            throw new \InvalidArgumentException('portal_capability_unknown');
        }
        $secret = (object) array(
            'key_version' => (string) ($row->projection_secret_key_version ?? ''),
            'cipher_version' => (string) ($row->projection_secret_cipher_version ?? ''),
            'nonce' => (string) ($row->projection_secret_nonce ?? ''),
            'ciphertext' => (string) ($row->projection_secret_ciphertext ?? ''),
        );
        $references = $this->vault->open('booking_calendar', (int) $row->confirmation_id, $secret);
        $uri = trim((string) ($references['join_uri_reference'] ?? ''));
        if (!$this->isAllowedMeetUri($uri)) throw new \InvalidArgumentException('portal_capability_unknown');
        return $uri;
    }

    private function isAllowedMeetUri(string $uri): bool {
        if ($uri === '' || strlen($uri) > 2048) return false;
        $parts = parse_url($uri);
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'meet.google.com'
            && !isset($parts['user']) && !isset($parts['pass'])
            && trim((string) ($parts['path'] ?? ''), '/') !== '';
    }
}
