<?php
/**
 * Focused proof for the Calendar-created-space co-host repair.
 *
 * The Meet REST API's meetings.space.created scope only manages spaces created by the app. These
 * tests prove that new provisioning creates the app space first, attaches that exact space to the
 * Calendar event through conferenceData.entryPoints, verifies the COHOST member, and never falls
 * back to a Calendar-created space or a second meeting.
 */
namespace Delnavazan\Platform\Core\Application {
    final class ProviderReferenceVault {
        public function open(string $kind, int $mappingId, object|array $row): array { return (array) ($row->references ?? array()); }
        public function seal(string $kind, int $mappingId, array $references): array { return array('references' => $references); }
    }
    final class RecordingDecisionAuthorityService {
        public bool $eligible = false;
        public function lessonRecordingEligibility(int $lessonId): array {
            return array('eligible' => $this->eligible, 'reason' => $this->eligible ? 'recording_consent_current' : 'booking_recording_not_configured', 'evaluated_at' => gmdate('Y-m-d H:i:s'));
        }
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
        public array $calendarBodies = array();
        public array $rescheduleBodies = array();
        public int $createSpaceStatus = 200;
        public int $spaceStatus = 200;
        public string $spaceName = 'spaces/AppSpace_123';
        public ?string $getSpaceName = null;
        public string $meetingCode = 'abc-defg-hij';
        public int $calendarStatus = 200;
        public ?array $existingEvent = null;
        public int $memberStatus = 200;
        public string $memberErrorClass = 'permission_denied';
        public int $configStatus = 200;
        public array $members = array();

        public function createSpace(): array {
            $this->calls[] = array('createSpace');
            return array('status' => $this->createSpaceStatus, 'body' => $this->createSpaceStatus >= 200 && $this->createSpaceStatus < 300 ? array(
                'name' => $this->spaceName,
                'meetingUri' => 'https://meet.google.com/' . $this->meetingCode,
                'meetingCode' => $this->meetingCode,
            ) : array(), 'error_class' => $this->createSpaceStatus >= 200 && $this->createSpaceStatus < 300 ? null : 'permission_denied');
        }
        public function getSpace(string $spaceOrCode): array {
            $this->calls[] = array('getSpace', $spaceOrCode);
            return array('status' => $this->spaceStatus, 'body' => $this->spaceStatus >= 200 && $this->spaceStatus < 300 ? array(
                'name' => $this->getSpaceName ?? $this->spaceName,
                'meetingUri' => 'https://meet.google.com/' . $this->meetingCode,
                'meetingCode' => $this->meetingCode,
            ) : array(), 'error_class' => $this->spaceStatus >= 200 && $this->spaceStatus < 300 ? null : 'permission_denied');
        }
        public function createCalendarEvent(array $event): array {
            $this->calls[] = array('createCalendarEvent', $event);
            $this->calendarBodies[] = $event;
            if ($this->calendarStatus >= 200 && $this->calendarStatus < 300) {
                return array('status' => $this->calendarStatus, 'body' => $this->eventBody($event), 'error_class' => null);
            }
            return array('status' => $this->calendarStatus, 'body' => array(), 'error_class' => $this->calendarStatus === 409 ? 'duplicate' : 'backend_error');
        }
        public function getCalendarEvent(string $eventId): array {
            $this->calls[] = array('getCalendarEvent', $eventId);
            if ($this->existingEvent !== null) return array('status' => 200, 'body' => $this->existingEvent, 'error_class' => null);
            $body = $this->calendarBodies[0] ?? array('id' => $eventId);
            return array('status' => 200, 'body' => $this->eventBody($body), 'error_class' => null);
        }
        public function updateCalendarEvent(string $eventId, array $event): array {
            $this->calls[] = array('updateCalendarEvent', $eventId, $event);
            $this->rescheduleBodies[] = $event;
            return array('status' => 200, 'body' => $this->eventBody($event + array('id' => $eventId)), 'error_class' => null);
        }
        public function cancelCalendarEvent(string $eventId): array {
            $this->calls[] = array('cancelCalendarEvent', $eventId);
            return array('status' => 200, 'body' => array(), 'error_class' => null);
        }
        public function patchSpace(string $space, array $config, ?string $mask = null): array {
            $this->calls[] = array('patchSpace', $space, $config, $mask);
            return array('status' => $this->configStatus, 'body' => array(), 'error_class' => $this->configStatus >= 200 && $this->configStatus < 300 ? null : 'backend_error');
        }
        public function createMember(string $space, string $email, string $role = 'COHOST'): array {
            $this->calls[] = array('createMember', $space, $email, $role);
            if ($this->memberStatus >= 200 && $this->memberStatus < 300) $this->members[] = array('email' => $email, 'role' => $role, 'name' => $space . '/members/1');
            return array('status' => $this->memberStatus, 'body' => array(), 'error_class' => $this->memberStatus >= 200 && $this->memberStatus < 300 ? null : $this->memberErrorClass);
        }
        public function listMembers(string $space): array {
            $this->calls[] = array('listMembers', $space);
            if ($this->memberStatus < 200 || $this->memberStatus >= 300) return array('status' => $this->memberStatus, 'body' => array(), 'error_class' => $this->memberErrorClass);
            return array('status' => 200, 'body' => array('members' => $this->members), 'error_class' => null);
        }
        public function deleteMember(string $memberName): array {
            $this->calls[] = array('deleteMember', $memberName);
            return array('status' => 200, 'body' => array(), 'error_class' => null);
        }
        public function endActiveConference(string $spaceName): array {
            $this->calls[] = array('endActiveConference', $spaceName);
            return array('status' => 200, 'body' => array(), 'error_class' => null);
        }
        private function eventBody(array $event): array {
            $body = $event;
            $body['id'] = (string) ($event['id'] ?? 'event-default');
            $body['conferenceData'] = array(
                'conferenceId' => $this->meetingCode,
                'conferenceSolution' => array('key' => array('type' => 'hangoutsMeet')),
                'entryPoints' => array(array('entryPointType' => 'video', 'uri' => 'https://meet.google.com/' . $this->meetingCode)),
            );
            $body['hangoutLink'] = 'https://meet.google.com/' . $this->meetingCode;
            return $body;
        }
    }

    final class GoogleWorkspaceRepository {
        public array $rows = array();
        public ?object $referenceRow = null;
        public array $updates = array();
        public array $events = array();
        public array $alerts = array();
        public bool $confirmationConfirmed = false;
        public int $nextId = 100;

        public function __construct(array $rows = array()) { $this->rows = $rows; }

        public function bookingConfirmation(int $id, bool $lock = false): ?object {
            return (object) array(
                'id' => $id, 'uid' => 'CONFIRM-' . $id, 'booking_request_id' => $id, 'teacher_id' => 10,
                'state' => $this->confirmationConfirmed ? 'confirmed' : 'pending_projection', 'version' => 5,
                'starts_at_utc' => gmdate('Y-m-d H:i:s', time() + 3600), 'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 5400),
                'schedule_timezone' => 'Asia/Tehran', 'teacher_email' => 'teacher@example.test',
                'student_email' => 'student@example.test', 'teacher_name' => 'Teacher', 'student_name' => 'Student',
            );
        }
        public function activeClassroomForBooking(int $confirmationId, bool $lock = false): ?object {
            foreach ($this->rows as $row) if ((int) ($row->booking_confirmation_id ?? 0) === $confirmationId && (int) ($row->active_slot ?? 0) === 1) return $row;
            return null;
        }
        public function latestClassroomForBooking(int $confirmationId): ?object {
            $found = null;
            foreach ($this->rows as $row) if ((int) ($row->booking_confirmation_id ?? 0) === $confirmationId) $found = $row;
            return $found;
        }
        public function classroom(int $id, bool $lock = false): ?object {
            foreach ($this->rows as $row) if ((int) $row->id === $id) return $row;
            return null;
        }
        public function insertClassroom(array $data): int {
            $id = $this->nextId++;
            $row = (object) array_merge(array(
                'id' => $id, 'uid' => 'CLASS-' . $id, 'lesson_id' => null, 'schedule_version_id' => null,
                'booking_confirmation_id' => null, 'classroom_state' => 'provisioning', 'cohost_state' => 'pending',
                'failure_reason_code' => null, 'cohost_error_code' => null, 'cohost_error_detail' => null,
                'cohost_attempt_count' => 0, 'cohost_next_attempt_at' => null, 'cohost_exception_state' => null,
                'cohost_exception_approved_by' => null, 'cohost_exception_approved_at' => null, 'cohost_exception_reason' => null,
                'active_slot' => 1, 'version' => 1, 'recording_state' => 'disabled', 'last_provider_response_code' => null,
                'space_name' => null,
            ), $data);
            $this->rows[] = $row;
            return $id;
        }
        public function updateClassroom(int $id, array $data, array $where): int {
            foreach ($this->rows as $row) {
                if ((int) $row->id !== $id) continue;
                foreach ($data as $key => $value) $row->{$key} = $value;
                $this->updates[] = $data;
                return 1;
            }
            return 0;
        }
        public function reference(int $classroomId, bool $lock = false): ?object { return $this->referenceRow; }
        public function insertReference(array $data): int {
            $this->referenceRow = (object) array(
                'id' => 1, 'classroom_id' => (int) ($data['classroom_id'] ?? 0), 'state' => 'active', 'active_slot' => 1,
                'references' => $data['references'] ?? array(),
            );
            return 1;
        }
        public function retireReference(int $id, int $actor, string $now): int { $this->referenceRow = null; return 1; }
        public function insertEvent(array $data): int { $this->events[] = $data; return 1; }
        public function openAlert(int $classroomId, string $code): ?object { return null; }
        public function insertAlert(array $data): int { $this->alerts[] = $data; return 1; }
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
    $service = static function (GoogleWorkspaceRepository $repo, GoogleWorkspaceApiClient $api, ?RecordingDecisionAuthorityService $recording = null): GoogleWorkspaceClassroomService {
        return new GoogleWorkspaceClassroomService($repo, $api, new GoogleWorkspaceOAuthService(), new ProviderReferenceVault(), $recording ?? new RecordingDecisionAuthorityService());
    };
    $count = static function (array $calls, string $name): int {
        return count(array_filter($calls, static fn(array $call): bool => ($call[0] ?? '') === $name));
    };
    $blockedRow = static function (bool $appOwned): object {
        return (object) array(
            'id' => 9, 'uid' => 'CLASS-9', 'booking_confirmation_id' => 9, 'lesson_id' => 1,
            'classroom_state' => 'blocked', 'cohost_state' => 'blocked',
            'failure_reason_code' => 'meet_cohost_assignment_blocked', 'cohost_error_code' => 'meet_cohost_assignment_refused',
            'cohost_error_detail' => null, 'cohost_attempt_count' => 6, 'cohost_next_attempt_at' => null,
            'cohost_exception_state' => null, 'cohost_exception_approved_by' => null, 'cohost_exception_approved_at' => null,
            'cohost_exception_reason' => null, 'active_slot' => null, 'version' => 3, 'recording_state' => 'disabled',
            'starts_at_utc' => gmdate('Y-m-d H:i:s', time() + 3600), 'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 5400),
            'schedule_timezone' => 'Asia/Tehran', 'last_provider_response_code' => 403, 'space_name' => $appOwned ? 'spaces/AppSpace_123' : 'spaces/CalendarSpace_123',
        );
    };
    $readyReference = static function (bool $appOwned): object {
        $refs = array('provider_object_reference' => 'event-9', 'join_uri_reference' => 'https://meet.google.com/abc-defg-hij');
        if ($appOwned) {
            $refs['space_name'] = 'spaces/AppSpace_123';
            $refs['space_origin'] = 'app';
            $refs['space_organizer'] = 'classroom@delnavazan.com';
        }
        return (object) array('id' => 1, 'classroom_id' => 9, 'state' => 'active', 'active_slot' => 1, 'references' => $refs);
    };

    // 1. New booking: create the app space, attach the exact space, configure moderation, verify COHOST.
    $repo = new GoogleWorkspaceRepository();
    $api = new GoogleWorkspaceApiClient();
    $result = $service($repo, $api)->ensureForBooking(9);
    $assert(($result['state'] ?? '') === 'ready' && ($result['cohost_state'] ?? '') === 'verified', 'A supported app-space booking must reach verified readiness.');
    $assert($count($api->calls, 'createSpace') === 1, 'New provisioning must create exactly one app-owned Meet space.');
    $assert($count($api->calls, 'createCalendarEvent') === 1, 'New provisioning must create exactly one Calendar event.');
    $body = $api->calendarBodies[0];
    $assert(!isset($body['conferenceData']['createRequest']), 'New provisioning must not ask Calendar to create a second conference.');
    $assert(($body['conferenceData']['conferenceId'] ?? '') === 'abc-defg-hij', 'The Calendar event must carry the app space meeting code.');
    $assert(($body['conferenceData']['conferenceSolution']['key']['type'] ?? '') === 'hangoutsMeet', 'The Calendar event must use the Meet conference solution.');
    $assert(($body['conferenceData']['entryPoints'][0]['uri'] ?? '') === 'https://meet.google.com/abc-defg-hij', 'The Calendar event must attach the app space join URI.');
    $assert(($body['attendees'][0]['email'] ?? '') === 'teacher@example.test' && ($body['attendees'][1]['email'] ?? '') === 'student@example.test', 'The Calendar invitation must retain Teacher and Student attendees.');
    $memberCall = null;
    foreach ($api->calls as $call) if (($call[0] ?? '') === 'createMember') $memberCall = $call;
    $assert($memberCall !== null && $memberCall[1] === 'spaces/AppSpace_123' && $memberCall[2] === 'teacher@example.test' && $memberCall[3] === 'COHOST', 'The teacher must be assigned COHOST on the exact app space resource name.');
    $assert($memberCall[1] !== 'spaces/abc-defg-hij', 'Member operations must never use the meeting-code alias.');
    $patchIndex = null; $memberIndex = null;
    foreach ($api->calls as $index => $call) {
        if (($call[0] ?? '') === 'patchSpace') $patchIndex = $index;
        if (($call[0] ?? '') === 'createMember') $memberIndex = $index;
    }
    $assert($patchIndex !== null && $memberIndex !== null && $patchIndex < $memberIndex, 'Moderation and access must be configured before COHOST assignment.');
    $patch = $api->calls[$patchIndex];
    $assert(($patch[2]['config']['moderation'] ?? '') === 'ON', 'Moderation must be explicitly ON for co-host management.');
    $assert(($patch[2]['config']['artifactConfig']['recordingConfig']['autoRecordingGeneration'] ?? '') === 'OFF', 'A booking without recording consent must force recording OFF.');
    $refs = $repo->referenceRow->references;
    $assert(($refs['space_origin'] ?? '') === 'app' && ($refs['space_name'] ?? '') === 'spaces/AppSpace_123', 'The sealed reference must record the app-owned exact space.');
    $assert(($refs['space_organizer'] ?? '') === 'classroom@delnavazan.com', 'The sealed reference must bind the organizer identity.');
    $assert(($refs['provider_object_reference'] ?? '') !== '' && ($refs['join_uri_reference'] ?? '') !== '', 'The full reference must be sealed only after the Calendar event exists.');

    // 2. Duplicate run converges without another provider mutation.
    $api->calls = array();
    $again = $service($repo, $api)->ensureForBooking(9);
    $assert(!empty($again['idempotent']), 'A second ensure must be idempotent.');
    $assert($api->calls === array(), 'A ready classroom must not call the provider again.');

    // 3. Transient member failure retries on the same event/space.
    $repoRetry = new GoogleWorkspaceRepository();
    $apiRetry = new GoogleWorkspaceApiClient();
    $apiRetry->memberStatus = 503;
    $apiRetry->memberErrorClass = 'backend_error';
    $retryable = false;
    try { $service($repoRetry, $apiRetry)->ensureForBooking(9); }
    catch (RuntimeException $error) { $retryable = $error->getMessage() === 'meet_cohost_assignment_retryable'; }
    $assert($retryable, 'A transient member failure must remain retryable.');
    $assert((string) $repoRetry->rows[0]->classroom_state === 'provisioning' && (string) $repoRetry->rows[0]->cohost_state === 'retryable', 'A transient member failure must persist retryable state.');
    $apiRetry->memberStatus = 200;
    $apiRetry->calls = array();
    $recovered = $service($repoRetry, $apiRetry)->ensureForBooking(9);
    $assert(($recovered['state'] ?? '') === 'ready', 'The retry must converge to ready.');
    $assert($count($apiRetry->calls, 'createSpace') === 0 && $count($apiRetry->calls, 'createCalendarEvent') === 0, 'A retry must not create another space or Calendar event.');
    $assert($count($apiRetry->calls, 'createMember') === 1, 'A retry must assign the co-host on the existing space.');

    // 4. Permanent 403 refuses closed and is not retried automatically.
    $repo403 = new GoogleWorkspaceRepository();
    $api403 = new GoogleWorkspaceApiClient();
    $api403->memberStatus = 403;
    $blocked = false;
    try { $service($repo403, $api403)->ensureForBooking(9); }
    catch (RuntimeException $error) { $blocked = $error->getMessage() === 'meet_cohost_assignment_blocked'; }
    $assert($blocked, 'A permanent member 403 must block readiness.');
    $assert((string) $repo403->rows[0]->classroom_state === 'blocked' && (string) $repo403->rows[0]->cohost_state === 'blocked', 'A permanent 403 must persist a blocked co-host state.');
    $assert((string) $repo403->rows[0]->failure_reason_code === 'meet_cohost_assignment_blocked', 'The permanent block must use the terminal co-host code.');
    $assert((int) $repo403->rows[0]->last_provider_response_code === 403, 'The exact provider status must be preserved.');
    $assert((string) $repo403->rows[0]->cohost_error_code === 'meet_cohost_assignment_refused', 'The provider refusal reason must be preserved.');
    $api403->calls = array();
    $stillBlocked = false;
    try { $service($repo403, $api403)->ensureForBooking(9); }
    catch (RuntimeException $error) { $stillBlocked = $error->getMessage() === 'meet_cohost_assignment_blocked'; }
    $assert($stillBlocked, 'An explicit recovery attempt must remain blocked when Google still refuses.');
    $assert($count($api403->calls, 'createSpace') === 0 && $count($api403->calls, 'createCalendarEvent') === 0, 'A refused recovery must reuse the existing space and event.');
    $assert($count($api403->calls, 'createMember') === 1, 'A refused recovery must retry only the exact membership decision.');

    // 5. A Calendar 409 converges on the same deterministic event when the conference matches.
    $repo409 = new GoogleWorkspaceRepository();
    $api409 = new GoogleWorkspaceApiClient();
    $api409->calendarStatus = 409;
    $eventId = \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomRule::eventId('booking:9');
    $api409->existingEvent = array(
        'id' => $eventId,
        'conferenceData' => array(
            'conferenceId' => 'abc-defg-hij',
            'conferenceSolution' => array('key' => array('type' => 'hangoutsMeet')),
            'entryPoints' => array(array('entryPointType' => 'video', 'uri' => 'https://meet.google.com/abc-defg-hij')),
        ),
        'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
    );
    $converged = $service($repo409, $api409)->ensureForBooking(9);
    $assert(($converged['state'] ?? '') === 'ready' && ($converged['provider_object_reference'] ?? '') === $eventId, 'A 409 must converge on the existing deterministic event.');
    $assert($count($api409->calls, 'createCalendarEvent') === 1 && $count($api409->calls, 'getCalendarEvent') === 1, 'A 409 must re-read the exact event, not create another.');
    $assert($count($api409->calls, 'createSpace') === 1, 'The app space must still be created exactly once.');

    // 6. A 409 with a different conference is a permanent identity conflict, never silent substitution.
    $repoMismatch = new GoogleWorkspaceRepository();
    $apiMismatch = new GoogleWorkspaceApiClient();
    $apiMismatch->calendarStatus = 409;
    $apiMismatch->existingEvent = array('id' => $eventId, 'conferenceData' => array('conferenceId' => 'zzz-yyyy-xxx'));
    $mismatch = false;
    try { $service($repoMismatch, $apiMismatch)->ensureForBooking(9); }
    catch (RuntimeException $error) { $mismatch = $error->getMessage() === 'meet_calendar_conference_mismatch'; }
    $assert($mismatch, 'A conflicting existing conference must fail closed.');
    $pendingRefs = $repoMismatch->referenceRow?->references ?? array();
    $assert(($pendingRefs['provider_object_reference'] ?? '') === '' && $count($apiMismatch->calls, 'createMember') === 0, 'A conference conflict must not seal a Calendar reference or assign a co-host.');

    // 7. A wrong space resource name is an identity failure before any member mutation.
    $repoIdentity = new GoogleWorkspaceRepository();
    $apiIdentity = new GoogleWorkspaceApiClient();
    $apiIdentity->getSpaceName = 'spaces/OtherSpace_999';
    $identity = false;
    try { $service($repoIdentity, $apiIdentity)->ensureForBooking(9); }
    catch (RuntimeException $error) { $identity = $error->getMessage() === 'meet_space_identity_mismatch'; }
    $assert($identity, 'A different space resource name must fail closed.');
    $assert($count($apiIdentity->calls, 'createMember') === 0, 'A space identity mismatch must not assign a co-host.');

    // 8. Blocked app-owned booking recovers in place on the same row, event and space.
    $repoRecover = new GoogleWorkspaceRepository(array($blockedRow(true)));
    $repoRecover->referenceRow = $readyReference(true);
    $apiRecover = new GoogleWorkspaceApiClient();
    $recovered = $service($repoRecover, $apiRecover)->ensureForBooking(9);
    $assert(($recovered['state'] ?? '') === 'ready' && (int) $recovered['classroom_id'] === 9, 'An app-owned blocked booking must recover on the same row.');
    $assert($count($apiRecover->calls, 'createSpace') === 0 && $count($apiRecover->calls, 'createCalendarEvent') === 0, 'Blocked recovery must reuse the existing space and event.');
    $assert($count($apiRecover->calls, 'createMember') === 1, 'Blocked recovery must verify COHOST membership again.');
    $assert($repoRecover->rows[0]->failure_reason_code === null && (string) $repoRecover->rows[0]->cohost_state === 'verified', 'Recovery must clear the block only after provider verification.');

    // 9. Historical Calendar-created blocked bookings stay closed and create no new provider object.
    $repoLegacy = new GoogleWorkspaceRepository(array($blockedRow(false)));
    $repoLegacy->referenceRow = $readyReference(false);
    $apiLegacy = new GoogleWorkspaceApiClient();
    $legacy = false;
    try { $service($repoLegacy, $apiLegacy)->ensureForBooking(9); }
    catch (RuntimeException $error) { $legacy = $error->getMessage() === 'meet_cohost_calendar_space_unsupported'; }
    $assert($legacy, 'A historical Calendar-created space must fail with the documented unsupported-space code.');
    $assert($apiLegacy->calls === array(), 'A historical Calendar-created space must not call Calendar or Meet.');
    $assert((string) $repoLegacy->rows[0]->classroom_state === 'blocked' && (string) $repoLegacy->rows[0]->failure_reason_code === 'meet_cohost_calendar_space_unsupported', 'The historical row must remain blocked on the same identity.');
    $assert((int) $repoLegacy->rows[0]->active_slot === 0, 'The historical row must not be reopened.');

    // 10. Recording eligibility still controls the app-space configuration.
    $recording = new RecordingDecisionAuthorityService();
    $recording->eligible = true;
    $repoRecording = new GoogleWorkspaceRepository(array((object) array_merge((array) $blockedRow(true), array(
        'id' => 9, 'classroom_state' => 'ready', 'cohost_state' => 'verified', 'active_slot' => 1,
        'failure_reason_code' => null, 'lesson_id' => 77,
    ))));
    $repoRecording->referenceRow = $readyReference(true);
    $apiRecording = new GoogleWorkspaceApiClient();
    $recordingResult = $service($repoRecording, $apiRecording, $recording)->syncRecordingConfiguration(9);
    $assert(!empty($recordingResult['updated']) && ($recordingResult['recording_state'] ?? '') === 'configured', 'Recording eligibility must still configure the app space.');
    $recordingPatch = null;
    foreach ($apiRecording->calls as $call) if (($call[0] ?? '') === 'patchSpace') $recordingPatch = $call;
    $assert($recordingPatch !== null && ($recordingPatch[2]['config']['artifactConfig']['recordingConfig']['autoRecordingGeneration'] ?? '') === 'ON', 'Current recording consent must enable auto-recording.');
    $assert($recordingPatch[1] === 'spaces/AppSpace_123', 'Recording configuration must target the exact app space.');

    // 11. Reschedule and cancellation keep the same Calendar event and app space.
    $repoMove = new GoogleWorkspaceRepository(array((object) array_merge((array) $blockedRow(true), array(
        'id' => 9, 'classroom_state' => 'ready', 'cohost_state' => 'verified', 'active_slot' => 1,
        'failure_reason_code' => null, 'starts_at_utc' => gmdate('Y-m-d H:i:s', time() + 3600),
        'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 5400),
    ))));
    $repoMove->referenceRow = $readyReference(true);
    $repoMove->confirmationConfirmed = true;
    $apiMove = new GoogleWorkspaceApiClient();
    $moved = $service($repoMove, $apiMove)->rescheduleForBooking(9, gmdate('Y-m-d H:i:s', time() + 7200), gmdate('Y-m-d H:i:s', time() + 9000), 'Asia/Tehran');
    $assert(($moved['state'] ?? '') === 'ready', 'Reschedule must keep the classroom ready.');
    $assert($count($apiMove->calls, 'createSpace') === 0 && $count($apiMove->calls, 'createCalendarEvent') === 0, 'Reschedule must not create another space or event.');
    $assert(($apiMove->calls[0][0] ?? '') === 'updateCalendarEvent' && ($apiMove->calls[0][1] ?? '') === 'event-9', 'Reschedule must update the existing event.');
    $cancelled = $service($repoMove, $apiMove)->cancelForBooking(9);
    $assert(!empty($cancelled['cancelled']) && (string) $repoMove->rows[0]->classroom_state === 'cancelled', 'Cancellation must preserve the row and mark it cancelled.');
    $assert($count($apiMove->calls, 'cancelCalendarEvent') === 1 && $count($apiMove->calls, 'endActiveConference') === 1, 'Cancellation must cancel the same event and end its active conference.');
    $assert($count($apiMove->calls, 'createSpace') === 0 && $count($apiMove->calls, 'createCalendarEvent') === 0, 'Cancellation must not create a second meeting.');

    echo "Google Meet co-host Calendar-space unit passed\n";
}
