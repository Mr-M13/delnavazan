<?php
/** Booking recovery after a blocked co-host reuses the exact classroom row and provider references. */
namespace Delnavazan\Platform\Core\Application {
    final class ProviderReferenceVault {
        public function open(string $kind,int $mappingId,object|array $row): array { return (array) ($row->references ?? array()); }
        public function seal(string $kind,int $mappingId,array $references): array { return array('references' => $references); }
    }
    final class RecordingDecisionAuthorityService {
        public function lessonRecordingEligibility(int $lessonId): array { return array('eligible' => false); }
    }
}
namespace Delnavazan\Platform\Core\Support {
    final class Identifier { public static function uid(): string { return '01ARZ3NDEKTSV4RRFFQ69G5FAA'; } }
}
namespace Delnavazan\Platform\Integrations\GoogleWorkspace {
    final class GoogleWorkspaceOAuthService {
        public static function configured(): bool { return true; }
        public function health(): array { return array('state' => 'connected'); }
    }
    final class GoogleWorkspaceApiClient {
        public array $calls = array();
        public int $memberStatus = 200;
        public string $memberErrorClass = '';
        public int $spaceStatus = 200;
        public string $spaceErrorClass = '';
        public function getSpace(string $code): array { $this->calls[] = array('getSpace', $code); return array('status' => $this->spaceStatus, 'body' => $this->spaceStatus >= 200 && $this->spaceStatus < 300 ? array('name' => 'spaces/abc-defg-hij') : array(), 'error_class' => $this->spaceErrorClass); }
        public function createMember(string $space,string $email,string $role='COHOST'): array {
            $this->calls[] = array('createMember', $space, $email, $role);
            return array('status' => $this->memberStatus, 'body' => array(), 'error_class' => $this->memberErrorClass);
        }
        public function listMembers(string $space): array {
            $this->calls[] = array('listMembers', $space);
            if ($this->memberStatus < 200 || $this->memberStatus >= 300) return array('status' => $this->memberStatus, 'body' => array(), 'error_class' => $this->memberErrorClass);
            return array('status' => 200, 'body' => array('members' => array(array('email' => 'teacher@example.test', 'role' => 'COHOST'))));
        }
        public function patchSpace(string $space,array $config,?string $mask=null): array { $this->calls[] = array('patchSpace', $space); return array('status' => 200, 'body' => array()); }
        public function createCalendarEvent(array $body): array { throw new \RuntimeException('A recovered booking must reuse its existing event.'); }
        public function getCalendarEvent(string $eventId): array { throw new \RuntimeException('A recovered booking must reuse its existing event.'); }
    }
    final class GoogleWorkspaceRepository {
        public array $rows;
        public int $inserted = 0;
        public array $updates = array();
        public function __construct() {
            $this->rows = array((object) array(
                'id' => 9, 'uid' => 'CLASS-9', 'booking_confirmation_id' => 9, 'lesson_id' => null,
                'classroom_state' => 'blocked', 'cohost_state' => 'blocked',
                'failure_reason_code' => 'meet_cohost_assignment_blocked',
                'cohost_error_code' => 'meet_cohost_assignment_refused',
                'cohost_attempt_count' => 6, 'cohost_next_attempt_at' => null,
                'cohost_exception_state' => null, 'cohost_exception_approved_by' => null,
                'cohost_exception_approved_at' => null, 'cohost_exception_reason' => null,
                'active_slot' => null, 'version' => 3, 'recording_state' => 'disabled',
                'starts_at_utc' => gmdate('Y-m-d H:i:s', time()+3600), 'ends_at_utc' => gmdate('Y-m-d H:i:s', time()+5400),
                'schedule_timezone' => 'Asia/Tehran', 'last_provider_response_code' => 403,
            ));
        }
        public function bookingConfirmation(int $id,bool $lock=false): ?object {
            if ($id !== 9) return null;
            return (object) array(
                'id' => 9, 'uid' => 'CONFIRM-9', 'booking_request_id' => 9, 'teacher_id' => 10,
                'state' => 'failed', 'version' => 5, 'starts_at_utc' => gmdate('Y-m-d H:i:s', time()+3600),
                'ends_at_utc' => gmdate('Y-m-d H:i:s', time()+5400), 'schedule_timezone' => 'Asia/Tehran',
                'teacher_email' => 'teacher@example.test', 'student_email' => 'student@example.test',
                'teacher_name' => 'Teacher', 'student_name' => 'Student',
            );
        }
        public function activeClassroomForBooking(int $confirmationId,bool $lock=false): ?object {
            foreach ($this->rows as $row) if ((int) ($row->booking_confirmation_id ?? 0) === $confirmationId && (int) ($row->active_slot ?? 0) === 1) return $row;
            return null;
        }
        public function latestClassroomForBooking(int $confirmationId): ?object {
            $found = null;
            foreach ($this->rows as $row) if ((int) ($row->booking_confirmation_id ?? 0) === $confirmationId) $found = $row;
            return $found;
        }
        public function classroom(int $id,bool $lock=false): ?object {
            foreach ($this->rows as $row) if ((int) $row->id === $id) return $row;
            return null;
        }
        public function updateClassroom(int $id,array $data,array $where): int {
            foreach ($this->rows as $row) {
                if ((int) $row->id !== $id) continue;
                foreach ($data as $key => $value) $row->{$key} = $value;
                $this->updates[] = $data;
                return 1;
            }
            return 0;
        }
        public function insertClassroom(array $data): int { $this->inserted++; return 100 + $this->inserted; }
        public function reference(int $classroomId,bool $lock=false): ?object {
            return (object) array(
                'id' => 1, 'classroom_id' => $classroomId, 'state' => 'active', 'active_slot' => 1,
                'references' => array(
                    'provider_object_reference' => 'event-9',
                    'join_uri_reference' => 'https://meet.google.com/abc-defg-hij',
                ),
            );
        }
        public function insertReference(array $data): int { throw new \RuntimeException('Recovery must reuse the existing reference.'); }
        public function retireReference(int $id,int $actor,string $now): int { throw new \RuntimeException('Recovery must reuse the existing reference.'); }
        public array $events = array();
        public function insertEvent(array $data): int { $this->events[] = $data; return 1; }
        public function openAlert(int $classroomId,string $code): ?object { return null; }
        public function insertAlert(array $data): int { return 1; }
        public function teacherEmail(int $teacherId): string { return 'teacher@example.test'; }
    }
}

namespace {
    function get_current_user_id(): int { return 1; }
    function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
    function do_action(string $hook, mixed ...$args): void {}

    require_once dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/GoogleWorkspaceFeatureFlags.php';
    require_once dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/GoogleWorkspaceClassroomRule.php';
    require_once dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/MeetCoHostRule.php';
    require_once dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/MeetProvisioningRule.php';
    require_once dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/MeetClassroomRetryRule.php';
    require_once dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/GoogleWorkspaceClassroomService.php';

    use Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceApiClient;
    use Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomService;
    use Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceOAuthService;
    use Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceRepository;
    use Delnavazan\Platform\Core\Application\ProviderReferenceVault;
    use Delnavazan\Platform\Core\Application\RecordingDecisionAuthorityService;

    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

    $repo = new GoogleWorkspaceRepository();
    $api = new GoogleWorkspaceApiClient();
    $service = new GoogleWorkspaceClassroomService($repo, $api, new GoogleWorkspaceOAuthService(), new ProviderReferenceVault(), new RecordingDecisionAuthorityService());

    $recovered = $service->ensureForBooking(9);
    $assert(($recovered['state'] ?? '') === 'ready' && ($recovered['cohost_state'] ?? '') === 'verified', 'A corrected policy must recover the blocked booking.');
    $assert($repo->inserted === 0, 'Booking recovery must not create a second classroom row.');
    $assert(count($repo->rows) === 1 && (int) $repo->rows[0]->active_slot === 1, 'Booking recovery must reactivate the existing row.');
    $assert($api->calls === array(array('getSpace', 'abc-defg-hij'), array('createMember', 'spaces/abc-defg-hij', 'teacher@example.test', 'COHOST'), array('listMembers', 'spaces/abc-defg-hij'), array('patchSpace', 'spaces/abc-defg-hij')), 'Booking recovery must reuse the existing space and call the provider once.');
    $assert(($repo->rows[0]->cohost_state ?? '') === 'verified' && property_exists($repo->rows[0], 'failure_reason_code') && $repo->rows[0]->failure_reason_code === null, 'Verified co-host recovery must clear the block only after provider verification.');
    $assert(property_exists($repo->rows[0], 'cohost_exception_state') && $repo->rows[0]->cohost_exception_state === null, 'Recovery must not silently create a co-host exception.');
    $assert(!empty($repo->events) && $repo->events[0]['created_by'] === 1 && $repo->events[0]['reason_code'] === 'booking_projection_retry', 'The row reuse must be audited with its administrator actor.');

    // Still-refused provider membership remains blocked on the same row and never falls back.
    $repo2 = new GoogleWorkspaceRepository();
    $api2 = new GoogleWorkspaceApiClient();
    $api2->memberStatus = 403;
    $api2->memberErrorClass = 'permission_denied';
    $service2 = new GoogleWorkspaceClassroomService($repo2, $api2, new GoogleWorkspaceOAuthService(), new ProviderReferenceVault(), new RecordingDecisionAuthorityService());
    $refused = false;
    try { $service2->ensureForBooking(9); }
    catch (RuntimeException $error) { $refused = $error->getMessage() === 'meet_cohost_assignment_blocked'; }
    $assert($refused, 'A still-refused co-host must remain a terminal block.');
    $assert($repo2->inserted === 0 && count($repo2->rows) === 1, 'A refused recovery must not duplicate the classroom row.');
    $assert((string) $repo2->rows[0]->classroom_state === 'blocked' && (string) $repo2->rows[0]->cohost_state === 'blocked', 'A refused recovery must persist the block on the same row.');

    // Cancelled history is never revived.
    $repo3 = new GoogleWorkspaceRepository();
    $repo3->rows[0]->classroom_state = 'cancelled';
    $repo3->rows[0]->cohost_state = 'pending';
    $service3 = new GoogleWorkspaceClassroomService($repo3, new GoogleWorkspaceApiClient(), new GoogleWorkspaceOAuthService(), new ProviderReferenceVault(), new RecordingDecisionAuthorityService());
    $historyRejected = false;
    try { $service3->ensureForBooking(9); }
    catch (InvalidArgumentException $error) { $historyRejected = $error->getMessage() === 'meet_classroom_history_not_retryable'; }
    $assert($historyRejected && $repo3->inserted === 0, 'Cancelled classroom history must never be revived.');

    // A space-lookup refusal is persisted against the same row and never retried as unknown.
    $repo4 = new GoogleWorkspaceRepository();
    $api4 = new GoogleWorkspaceApiClient();
    $api4->spaceStatus = 403;
    $api4->spaceErrorClass = 'permission_denied';
    $service4 = new GoogleWorkspaceClassroomService($repo4, $api4, new GoogleWorkspaceOAuthService(), new ProviderReferenceVault(), new RecordingDecisionAuthorityService());
    $lookupRefused = false;
    try { $service4->ensureForBooking(9); }
    catch (RuntimeException $error) { $lookupRefused = $error->getMessage() === 'meet_space_lookup_refused'; }
    $assert($lookupRefused, 'A permanent space-lookup refusal must be classified as terminal.');
    $assert($repo4->inserted === 0 && count($repo4->rows) === 1, 'A space-lookup refusal must not duplicate the classroom row.');
    $assert((string) $repo4->rows[0]->classroom_state === 'blocked' && (string) $repo4->rows[0]->failure_reason_code === 'meet_space_lookup_refused', 'A space-lookup refusal must persist its exact failure on the same row.');

    echo "Google Meet booking recovery unit passed\n";
}
