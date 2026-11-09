<?php
/** Term-lesson retry must reuse an existing event/space and never create a second one. */
namespace Delnavazan\Platform\Core\Application {
    final class ProviderReferenceVault {
        public function open(string $kind, int $mappingId, object|array $row): array { return (array) ($row->references ?? array()); }
        public function seal(string $kind, int $mappingId, array $references): array { return array('references' => $references); }
    }
    final class RecordingDecisionAuthorityService {
        public function lessonRecordingEligibility(int $lessonId): array { return array('eligible' => false, 'reason' => 'recording_not_configured'); }
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
        public array $members = array();
        public bool $legacyReplacement = false;
        public bool $replacementAttached = false;
        public function createSpace(): array {
            $this->calls[] = array('createSpace');
            if (!$this->legacyReplacement) throw new \RuntimeException('An app-owned lesson retry must not create another space.');
            return array('status' => 200, 'body' => array('name' => 'spaces/AppSpace_123', 'meetingUri' => 'https://meet.google.com/abc-defg-hij', 'meetingCode' => 'abc-defg-hij'), 'error_class' => null);
        }
        public function createCalendarEvent(array $event): array { $this->calls[] = array('createCalendarEvent'); throw new \RuntimeException('A lesson retry must not create another Calendar event.'); }
        public function getCalendarEvent(string $eventId): array {
            $this->calls[] = array('getCalendarEvent', $eventId);
            if (!$this->legacyReplacement) throw new \RuntimeException('An app-owned lesson retry must reuse the sealed event.');
            $code = $this->replacementAttached ? 'abc-defg-hij' : 'old-meet-xyz';
            return array('status' => 200, 'body' => array('id' => $eventId, 'conferenceData' => array('conferenceId' => $code), 'hangoutLink' => 'https://meet.google.com/' . $code), 'error_class' => null);
        }
        public function updateCalendarEvent(string $eventId, array $event): array {
            $this->calls[] = array('updateCalendarEvent', $eventId, $event);
            $this->replacementAttached = true;
            return array('status' => 200, 'body' => array('id' => $eventId, 'conferenceData' => $event['conferenceData'], 'hangoutLink' => 'https://meet.google.com/abc-defg-hij'), 'error_class' => null);
        }
        public function getSpace(string $spaceOrCode): array {
            $this->calls[] = array('getSpace', $spaceOrCode);
            return array('status' => 200, 'body' => array('name' => 'spaces/AppSpace_123', 'meetingUri' => 'https://meet.google.com/abc-defg-hij', 'meetingCode' => 'abc-defg-hij'), 'error_class' => null);
        }
        public function patchSpace(string $space, array $config, ?string $mask = null): array { $this->calls[] = array('patchSpace', $space); return array('status' => 200, 'body' => array(), 'error_class' => null); }
        public function createMember(string $space, string $email, string $role = 'COHOST'): array {
            $this->calls[] = array('createMember', $space, $email, $role);
            $this->members[] = array('email' => $email, 'role' => $role);
            return array('status' => 200, 'body' => array(), 'error_class' => null);
        }
        public function listMembers(string $space): array { $this->calls[] = array('listMembers', $space); return array('status' => 200, 'body' => array('members' => $this->members), 'error_class' => null); }
    }

    final class GoogleWorkspaceRepository {
        public array $rows = array();
        public ?object $referenceRow = null;
        public int $nextId = 100;
        public array $events = array();

        public function __construct(object $row, ?object $reference = null) {
            $this->rows = array($row);
            $this->referenceRow = $reference;
        }
        public function acquireLock(string $name, int $timeout = 5): bool { return true; }
        public function releaseLock(string $name): void {}
        public function begin(): void {}
        public function commit(): void {}
        public function rollback(): void {}
        public function lessonWithParticipants(int $lessonId, bool $lock = false): ?object {
            return (object) array(
                'id' => $lessonId, 'uid' => 'LESSON-42', 'record_model' => 'canonical_term_lesson_v1',
                'lifecycle_state' => 'authorised', 'status' => 'scheduled', 'archived_at' => null,
                'teacher_id' => 10, 'student_id' => 20, 'teacher_email' => 'teacher@example.test',
                'student_email' => 'student@example.test', 'teacher_name' => 'Teacher', 'student_name' => 'Student',
                'course_name' => 'Delnavazan',
            );
        }
        public function currentScheduleVersion(int $lessonId, bool $lock = false): ?object { return $this->scheduleVersion(1); }
        public function scheduleVersion(int $scheduleVersionId): ?object {
            return (object) array(
                'id' => 1, 'lesson_id' => 42, 'applicable_slot' => 1, 'superseded_at' => null,
                'starts_at_utc' => gmdate('Y-m-d H:i:s', time() + 3600), 'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 5400),
                'schedule_timezone' => 'Asia/Tehran',
            );
        }
        public function activeClassroomForLesson(int $lessonId, bool $lock = false): ?object {
            foreach ($this->rows as $row) if ((int) ($row->lesson_id ?? 0) === $lessonId && (int) ($row->active_slot ?? 0) === 1) return $row;
            return null;
        }
        public function latestClassroomForLesson(int $lessonId): ?object {
            $found = null;
            foreach ($this->rows as $row) if ((int) ($row->lesson_id ?? 0) === $lessonId) $found = $row;
            return $found;
        }
        public function classroom(int $id, bool $lock = false): ?object {
            foreach ($this->rows as $row) if ((int) $row->id === $id) return $row;
            return null;
        }
        public function insertClassroom(array $data): int {
            $id = $this->nextId++;
            $this->rows[] = (object) array_merge(array('id' => $id, 'classroom_state' => 'provisioning', 'cohost_state' => 'pending', 'active_slot' => 1, 'version' => 1), $data);
            return $id;
        }
        public function updateClassroom(int $id, array $data, array $where): int {
            foreach ($this->rows as $row) {
                if ((int) $row->id !== $id) continue;
                if (isset($where['version']) && (int) $row->version !== (int) $where['version']) return 0;
                if (array_key_exists('active_slot', $where) && (int) ($row->active_slot ?? 0) !== (int) $where['active_slot']) return 0;
                foreach ($data as $key => $value) $row->{$key} = $value;
                return 1;
            }
            return 0;
        }
        public function reference(int $classroomId, bool $lock = false): ?object { return $this->referenceRow; }
        public function insertReference(array $data): int {
            $this->referenceRow = (object) array('id' => 1, 'classroom_id' => (int) ($data['classroom_id'] ?? 0), 'state' => 'active', 'active_slot' => 1, 'references' => $data['references'] ?? array());
            return 1;
        }
        public function retireReference(int $id, int $actor, string $now): int { $this->referenceRow = null; return 1; }
        public function insertEvent(array $data): int { $this->events[] = $data; return 1; }
        public function openAlert(int $classroomId, string $code): ?object { return null; }
        public function insertAlert(array $data): int { return 1; }
    }
}

namespace {
    function current_user_can(string $capability): bool { return true; }
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
    $count = static function (array $calls, string $name): int {
        return count(array_filter($calls, static fn(array $call): bool => ($call[0] ?? '') === $name));
    };
    $row = static function (array $overrides = array()): object {
        return (object) array_merge(array(
            'id' => 42, 'uid' => 'CLASS-42', 'lesson_id' => 42, 'schedule_version_id' => 1,
            'booking_confirmation_id' => null, 'classroom_state' => 'blocked', 'cohost_state' => 'blocked',
            'failure_reason_code' => 'meet_cohost_assignment_blocked', 'cohost_error_code' => 'meet_cohost_assignment_refused',
            'cohost_error_detail' => null, 'cohost_attempt_count' => 6, 'cohost_next_attempt_at' => null,
            'cohost_exception_state' => null, 'cohost_exception_approved_by' => null, 'cohost_exception_approved_at' => null,
            'cohost_exception_reason' => null, 'active_slot' => null, 'version' => 3, 'recording_state' => 'disabled',
            'starts_at_utc' => gmdate('Y-m-d H:i:s', time() + 3600), 'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 5400),
            'schedule_timezone' => 'Asia/Tehran', 'last_provider_response_code' => 403,
            'space_name' => 'spaces/AppSpace_123', 'event_id_digest' => hash('sha256', 'dzn-meet-event:event42'),
            'meet_uri_digest' => hash('sha256', 'dzn-meet-uri:https://meet.google.com/abc-defg-hij'),
        ), $overrides);
    };
    $reference = static function (bool $appOwned): object {
        $refs = array('provider_object_reference' => 'event42', 'join_uri_reference' => $appOwned ? 'https://meet.google.com/abc-defg-hij' : 'https://meet.google.com/old-meet-xyz');
        if ($appOwned) {
            $refs['space_name'] = 'spaces/AppSpace_123';
            $refs['space_origin'] = 'app';
            $refs['space_organizer'] = 'classroom@delnavazan.com';
        }
        return (object) array('id' => 1, 'classroom_id' => 42, 'state' => 'active', 'active_slot' => 1, 'references' => $refs);
    };
    $service = static function (GoogleWorkspaceRepository $repo, GoogleWorkspaceApiClient $api): GoogleWorkspaceClassroomService {
        return new GoogleWorkspaceClassroomService($repo, $api, new GoogleWorkspaceOAuthService(), new ProviderReferenceVault(), new RecordingDecisionAuthorityService());
    };

    // 1. Blocked lesson with an app-owned event/space is re-opened in place, never duplicated.
    $repo = new GoogleWorkspaceRepository($row(), $reference(true));
    $api = new GoogleWorkspaceApiClient();
    $result = $service($repo, $api)->retryClassroom(42, 1, 'Documented organizer permission correction');
    $assert(($result['state'] ?? '') === 'ready' && (int) ($result['classroom_id'] ?? 0) === 42, 'A blocked lesson retry must reuse the exact row and become ready.');
    $assert(!empty($result['reset']), 'The blocked lesson retry must report that it reset the existing row.');
    $assert(count($repo->rows) === 1, 'A blocked lesson retry must not create a second classroom row.');
    $assert($count($api->calls, 'createSpace') === 0, 'A blocked lesson retry must not create a new Meet space.');
    $assert($count($api->calls, 'createCalendarEvent') === 0 && $count($api->calls, 'getCalendarEvent') === 0, 'A blocked lesson retry must not create or re-read a different Calendar event.');
    $assert($count($api->calls, 'getSpace') === 1 && $count($api->calls, 'createMember') === 1 && $count($api->calls, 'listMembers') === 1, 'The retry must re-verify the existing space and COHOST membership once.');
    $assert((string) $repo->rows[0]->classroom_state === 'ready' && (string) $repo->rows[0]->cohost_state === 'verified', 'The reused row must become provider-verified ready.');

    // 2. A legacy Calendar-created lesson keeps its event while replacing the Meet space.
    $repoLegacy = new GoogleWorkspaceRepository($row(), $reference(false));
    $apiLegacy = new GoogleWorkspaceApiClient();
    $apiLegacy->legacyReplacement = true;
    $legacy = $service($repoLegacy, $apiLegacy)->retryClassroom(42, 1, 'Replace the legacy Calendar-created Meet space');
    $assert(($legacy['state'] ?? '') === 'ready', 'A legacy Calendar-created lesson must recover to ready.');
    $assert($count($apiLegacy->calls, 'createSpace') === 1 && $count($apiLegacy->calls, 'updateCalendarEvent') === 1, 'Legacy recovery must create one app space and patch the existing event once.');
    $assert($count($apiLegacy->calls, 'createCalendarEvent') === 0, 'Legacy recovery must not create another Calendar event.');
    $assert(count($repoLegacy->rows) === 1 && (string) $repoLegacy->rows[0]->classroom_state === 'ready', 'A legacy lesson retry must keep the same row and verify readiness.');

    // 3. Provider identity without an open reference fails before creating another space/event.
    $repoMissing = new GoogleWorkspaceRepository($row(), null);
    $apiMissing = new GoogleWorkspaceApiClient();
    $missing = false;
    try { $service($repoMissing, $apiMissing)->retryClassroom(42, 1, 'Documented organizer permission correction'); }
    catch (RuntimeException $error) { $missing = $error->getMessage() === 'meet_classroom_reference_missing'; }
    $assert($missing, 'A lesson with provider evidence but no sealed reference must fail before creation.');
    $assert($apiMissing->calls === array(), 'A missing-reference lesson retry must make no provider call.');
    $assert(count($repoMissing->rows) === 1 && (string) $repoMissing->rows[0]->failure_reason_code === 'meet_classroom_reference_missing', 'A missing-reference retry must keep the same blocked row.');

    echo "Google Meet lesson retry unit passed\n";
}
