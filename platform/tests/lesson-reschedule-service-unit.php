<?php
/** Teacher proposal / student decision state machine, conflicts, idempotency and audit jobs. */
namespace {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
    function wp_json_encode($value): string { return json_encode($value, JSON_UNESCAPED_UNICODE); }
    function get_current_user_id(): int { return 11; }
    function wp_timezone_string(): string { return 'Asia/Tehran'; }
    function get_option($key, $default = false) { return $default; }
    function get_user_by($field, $id) { return null; }
    function user_can($user, $cap): bool { return false; }
    function wp_set_current_user($id): void {}
    require_once dirname(__DIR__, 2) . '/platform/src/Core/Support/Identifier.php';
    require_once dirname(__DIR__, 2) . '/platform/src/Core/Application/AvailabilityLocalTime.php';
    require_once dirname(__DIR__, 2) . '/platform/src/Core/Application/LessonRescheduleStore.php';
    require_once dirname(__DIR__, 2) . '/platform/src/Core/Application/LessonRescheduleSystemActor.php';
    require_once dirname(__DIR__, 2) . '/platform/src/Core/Application/LessonRescheduleService.php';

    use Delnavazan\Platform\Core\Application\{LessonRescheduleService,LessonRescheduleStore};

    final class FakeRescheduleStore implements LessonRescheduleStore {
        public array $proposals = array();
        public array $events = array();
        public array $notifications = array();
        public array $calendarJobs = array();
        public array $studentConflicts = array();
        private int $sequence = 0;
        public function begin():void {}
        public function commit():void {}
        public function rollback():void {}
        public function proposalByUid(string $uid, bool $lock = false):?object { foreach ($this->proposals as $row) { if ($row->uid === $uid) return $row; } return null; }
        public function proposalByCommandDigest(string $digest):?object { foreach ($this->proposals as $row) { if ($row->command_key_digest === $digest) return $row; } return null; }
        public function proposalByDecisionDigest(string $digest):?object { foreach ($this->proposals as $row) { if (($row->decision_key_digest ?? null) === $digest) return $row; } return null; }
        public function proposal(int $id, bool $lock = false):?object { foreach ($this->proposals as $row) { if ((int) $row->id === $id) return $row; } return null; }
        public function pendingForStudent(int $studentId):array { return array_values(array_filter($this->proposals, static fn($row) => (int) $row->student_id === $studentId && $row->state === 'pending')); }
        public function lessonContext(int $lessonId):?object { foreach ($this->proposals as $row) { if ((int) $row->lesson_id === $lessonId) return $this->context; } return $this->context; }
        public function lessonContextByUid(string $lessonUid):?object { return $lessonUid === $this->context->lesson_uid ? $this->context : null; }
        public function versionIdByUid(string $uid):int { return 7; }
        public function bookingClassByUid(string $uid):?object { return null; }
        public object $context;
        public function insertProposal(array $data):int { $data['id'] = ++$this->sequence; $this->proposals[] = (object) $data; return $data['id']; }
        public function updateProposal(int $id, int $expectedVersion, array $data):int { foreach ($this->proposals as $i => $row) { if ((int) $row->id === $id && (int) $row->version === $expectedVersion) { $data['version'] = $expectedVersion + 1; $this->proposals[$i] = (object) array_merge((array) $row, $data); return 1; } } throw new RuntimeException('Stale reschedule proposal'); }
        public function insertEvent(array $data):int { $this->events[] = (object) $data; return count($this->events); }
        public function eventsFor(int $proposalId):array { return array_values(array_filter($this->events, static fn($row) => (int) $row->proposal_id === $proposalId)); }
        public function insertNotification(array $data):int { $this->notifications[] = (object) $data; return count($this->notifications); }
        public function pendingNotifications(int $limit = 20):array { return $this->notifications; }
        public function notification(int $id, bool $lock = false):?object { return $this->notifications[$id - 1] ?? null; }
        public function updateNotification(int $id, array $data):int { return 1; }
        public function insertCalendarJob(array $data):int { $this->calendarJobs[] = (object) $data; return count($this->calendarJobs); }
        public function pendingCalendarJobs(int $limit = 10):array { return $this->calendarJobs; }
        public function calendarJob(int $id, bool $lock = false):?object { return $this->calendarJobs[$id - 1] ?? null; }
        public function updateCalendarJob(int $id, array $data):int { return 1; }
        public function studentConflict(int $studentId, string $startsAtUtc, string $endsAtUtc, int $excludeLessonId):array { return $this->studentConflicts; }
        public function duplicate(Throwable $e):?string { return null; }
    }

    $fail = static function (string $name, bool $ok): void { if (!$ok) { throw new RuntimeException('Lesson reschedule service unit failed: ' . $name); } };
    $context = static fn(): object => (object) array(
        'lesson_id' => 41, 'lesson_uid' => 'LESSON-41', 'lesson_reference' => 'L-41', 'teacher_id' => 5,
        'student_id' => 9, 'enrolment_id' => 3, 'term_id' => 2, 'teacher_assignment_id' => 8,
        'lifecycle_state' => 'authorised', 'record_model' => 'canonical_term_lesson_v1', 'schedule_version_id' => 71,
        'schedule_version_uid' => 'SCH-71', 'starts_at_utc' => gmdate('Y-m-d H:i:s', time() + 3 * 86400),
        'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 3 * 86400 + 1800), 'occupied_ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 3 * 86400 + 2700),
        'duration_minutes' => 30, 'buffer_minutes' => 15, 'schedule_timezone' => 'Asia/Tehran',
    );
    $input = array('timezone' => 'Asia/Tehran', 'calendar' => 'gregorian', 'local_date' => gmdate('Y-m-d', time() + 5 * 86400), 'local_time' => '18:30');

    $store = new FakeRescheduleStore(); $store->context = $context();
    $commits = array();
    $commit = static function (array $payload) use (&$commits): array { $commits[] = $payload; return array('schedule_version_id' => 99, 'created' => true); };
    $service = new LessonRescheduleService($store, static fn() => array(), $commit, static fn() => time());

    $proposal = $service->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-0001');
    $fail('a teacher proposal is pending and changes no schedule', $proposal['state'] === 'pending' && $commits === array());
    $fail('proposal preserves canonical duration', $store->proposals[0]->duration_minutes === 30 && $store->proposals[0]->buffer_minutes === 15);
    $fail('student notification and audit event exist', count($store->notifications) === 1 && $store->notifications[0]->audience === 'student' && count($store->events) === 1);
    $again = $service->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-0001');
    $fail('proposal request key is idempotent', $again['idempotent'] === true && count($store->proposals) === 1);
    $threw = false; try { $service->proposeForTeacher(6, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-0002'); } catch (Throwable $e) { $threw = $e->getMessage() === 'portal_object_not_owned'; }
    $fail('another teacher cannot propose a change', $threw);

    $store->studentConflicts = array(array('lesson_id' => 12));
    $conflictService = new LessonRescheduleService($store, static fn() => array(), $commit, static fn() => time());
    $threw = false; try { $conflictService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-0003'); } catch (Throwable $e) { $threw = $e->getMessage() === 'student_slot_conflict'; }
    $fail('student conflict is refused before any proposal is written', $threw);
    $store->studentConflicts = array();

    $accepted = $service->decideForStudent(9, (string) $store->proposals[0]->uid, 'accept', 'decision-key-0001');
    $fail('acceptance commits through the canonical service', $accepted['state'] === 'accepted' && count($commits) === 1 && $accepted['result_schedule_version_id'] === 99);
    $fail('acceptance preserves duration in the canonical payload', $commits[0]['input']['duration_minutes'] === 30 && $commits[0]['input']['expected_schedule_version_id'] === 71);
    $fail('acceptance queues both parties and calendar sync', count($store->notifications) === 3 && count($store->calendarJobs) === 1);
    $replay = $service->decideForStudent(9, (string) $store->proposals[0]->uid, 'accept', 'decision-key-0001');
    $fail('repeated decision is idempotent', $replay['idempotent'] === true && count($commits) === 1);
    $threw = false; try { $service->decideForStudent(9, (string) $store->proposals[0]->uid, 'reject', 'decision-key-0002'); } catch (Throwable $e) { $threw = $e->getMessage() === 'reschedule_proposal_already_decided'; }
    $fail('a second concurrent decision cannot move an accepted proposal', $threw);

    $store2 = new FakeRescheduleStore(); $store2->context = $context(); $commits2 = array();
    $service2 = new LessonRescheduleService($store2, static fn() => array(), static function (array $payload) use (&$commits2): array { $commits2[] = $payload; return array('schedule_version_id' => 100); }, static fn() => time());
    $p2 = $service2->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-0010');
    $rejected = $service2->decideForStudent(9, (string) $store2->proposals[0]->uid, 'reject', 'decision-key-0010');
    $fail('rejection never commits schedule authority', $rejected['state'] === 'rejected' && $commits2 === array());

    $store3 = new FakeRescheduleStore(); $store3->context = $context(); $stale = $context(); $stale->schedule_version_id = 72;
    $service3 = new LessonRescheduleService($store3, static fn() => array(), static fn(array $p): array => array('schedule_version_id' => 101), static fn() => time());
    $service3->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-0020');
    $store3->context = $stale;
    $failed = $service3->decideForStudent(9, (string) $store3->proposals[0]->uid, 'accept', 'decision-key-0020');
    $fail('acceptance revalidates the current schedule version', $failed['state'] === 'failed' && $failed['failure_code'] === 'stale_schedule_version');

    $store4 = new FakeRescheduleStore(); $store4->context = $context();
    $service4 = new LessonRescheduleService($store4, static fn() => array(), static fn(array $p): array => array('schedule_version_id' => 102), static fn() => time());
    $service4->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-0030');
    $store4->proposals[0]->state = 'committing';
    $store4->proposals[0]->decision_key_digest = hash('sha256', 'another-decision-key');
    $threw = false; try { $service4->decideForStudent(9, (string) $store4->proposals[0]->uid, 'accept', 'decision-key-0031'); } catch (Throwable $e) { $threw = $e->getMessage() === 'reschedule_proposal_in_progress'; }
    $fail('a concurrent second acceptance is refused while the first commits', $threw);

    echo "Lesson reschedule service unit passed\n";
}
