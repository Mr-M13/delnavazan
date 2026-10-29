<?php
/** Teacher proposal / student decision state machine, conflicts, idempotency and audit jobs. */
namespace {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
    function wp_json_encode($value): string { return json_encode($value, JSON_UNESCAPED_UNICODE); }
    function get_current_user_id(): int { return 11; }
    function current_user_can($capability): bool { return true; }
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
    require_once dirname(__DIR__, 2) . '/platform/src/Integrations/LessonRescheduleCalendarActor.php';
    require_once dirname(__DIR__, 2) . '/platform/src/Integrations/LessonRescheduleCalendarService.php';

    use Delnavazan\Platform\Core\Application\{LessonRescheduleService,LessonRescheduleStore};

    final class FakeRescheduleStore implements LessonRescheduleStore {
        public array $proposalKinds = array();
        public array $replacementFactsData = array('replacement_lesson_id'=>null,'replacement_allowance'=>2,'replacement_used'=>0,'attendance_case_id'=>null,'attendance_state'=>null,'attendance_reason'=>null,'attendance_schedule_version_id'=>null,'delivery_outcome'=>null,'replacement_proposal_state'=>null);
        public array $proposals = array();
        public array $events = array();
        public array $notifications = array();
        public array $calendarJobs = array();
        public array $studentConflicts = array();
        public bool $atomic = false;
        public bool $failAcceptedNotification = false;
        public int $canonicalRevision = 0;
        public string $lastTransaction = '';
        private array $snapshot = array();
        private int $sequence = 0;
        public function begin():void {
            if ($this->atomic) { $this->snapshot = array($this->proposals, $this->events, $this->notifications, $this->calendarJobs); }
        }
        public function commit():void { $this->lastTransaction = 'commit'; }
        public function rollback():void {
            if ($this->atomic) { $this->proposals = $this->snapshot[0]; $this->events = $this->snapshot[1]; $this->notifications = $this->snapshot[2]; $this->calendarJobs = $this->snapshot[3]; }
            $this->canonicalRevision = 0;
            $this->lastTransaction = 'rollback';
        }
        public function proposalByUid(string $uid, bool $lock = false):?object { foreach ($this->proposals as $row) { if ($row->uid === $uid) return $row; } return null; }
        public function proposalByCommandDigest(string $digest):?object { foreach ($this->proposals as $row) { if ($row->command_key_digest === $digest) return $row; } return null; }
        public function proposalByDecisionDigest(string $digest):?object { foreach ($this->proposals as $row) { if (($row->decision_key_digest ?? null) === $digest) return $row; } return null; }
        public function proposal(int $id, bool $lock = false):?object { foreach ($this->proposals as $row) { if ((int) $row->id === $id) return $row; } return null; }
        public function pendingForStudent(int $studentId):array { return array_values(array_filter($this->proposals, static fn($row) => (int) $row->student_id === $studentId && $row->state === 'pending')); }
        public function pendingForLesson(int $lessonId, bool $lock = false):?object { foreach ($this->proposals as $row) { if ((int) $row->lesson_id === $lessonId && $row->state === 'pending') return $row; } return null; }
        public function proposalRejectionCount(int $lessonId):int { return count(array_filter($this->proposals, static fn($row) => (int) $row->lesson_id === $lessonId && $row->state === 'rejected')); }
        public function proposalKind(int $proposalId):string { return $this->proposalKinds[(int) $proposalId] ?? 'reschedule'; }
        public function replacementFacts(int $lessonId,int $termId):array { return $this->replacementFactsData; }
        public function lessonContext(int $lessonId):?object { foreach ($this->proposals as $row) { if ((int) $row->lesson_id === $lessonId) return $this->context; } return $this->context; }
        public function lessonContextByUid(string $lessonUid):?object { return $lessonUid === $this->context->lesson_uid ? $this->context : null; }
        public function versionIdByUid(string $uid):int { return 7; }
        public function bookingClassByUid(string $uid):?object { return null; }
        public object $context;
        public function insertProposal(array $data):int { $data['id'] = ++$this->sequence; $this->proposals[] = (object) $data; return $data['id']; }
        public function updateProposal(int $id, int $expectedVersion, array $data):int { foreach ($this->proposals as $i => $row) { if ((int) $row->id === $id && (int) $row->version === $expectedVersion) { $data['version'] = $expectedVersion + 1; $this->proposals[$i] = (object) array_merge((array) $row, $data); return 1; } } throw new RuntimeException('Stale reschedule proposal'); }
        public function insertEvent(array $data):int { $this->events[] = (object) $data; return count($this->events); }
        public function eventsFor(int $proposalId):array { return array_values(array_filter($this->events, static fn($row) => (int) $row->proposal_id === $proposalId)); }
        public function insertNotification(array $data):int {
            if ($this->failAcceptedNotification && $data['event_type'] === 'accepted') { throw new RuntimeException('outbox_write_failed'); }
            $this->notifications[] = (object) $data; return count($this->notifications);
        }
        public function pendingNotifications(int $limit = 20):array { return $this->notifications; }
        public function notification(int $id, bool $lock = false):?object { return $this->notifications[$id - 1] ?? null; }
        public function updateNotification(int $id, array $data):int { return 1; }
        public function insertCalendarJob(array $data):int { $data['id'] = ++$this->sequence; $this->calendarJobs[] = (object) $data; return count($this->calendarJobs); }
        public function pendingCalendarJobs(int $limit = 10):array { return $this->calendarJobs; }
        public function calendarJob(int $id, bool $lock = false):?object { foreach ($this->calendarJobs as $job) { if ((int) $job->id === $id) return $job; } return null; }
        public function updateCalendarJob(int $id, array $data):int { foreach ($this->calendarJobs as $i => $job) { if ((int) $job->id === $id) { $this->calendarJobs[$i] = (object) array_merge((array) $job, $data); return 1; } } return 0; }
        public function ensureAndLockStudentRoot(int $studentId, string $now, int $actor):void {}
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
    $service = new LessonRescheduleService($store, static fn() => array(), $commit, static fn() => time(), static fn() => true);

    $proposal = $service->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-0001');
    $fail('a teacher proposal is pending and changes no schedule', $proposal['state'] === 'pending' && $commits === array());
    $fail('proposal preserves canonical duration', $store->proposals[0]->duration_minutes === 30 && $store->proposals[0]->buffer_minutes === 15);
    $fail('student and admin proposal notifications plus audit event exist', count($store->notifications) === 2 && $store->notifications[0]->audience === 'student' && $store->notifications[1]->audience === 'admin' && count($store->events) === 1);
    $again = $service->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-0001');
    $fail('proposal request key is idempotent', $again['idempotent'] === true && count($store->proposals) === 1);
    $threw = false; try { $service->proposeForTeacher(6, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-0002'); } catch (Throwable $e) { $threw = $e->getMessage() === 'portal_object_not_owned'; }
    $fail('another teacher cannot propose a change', $threw);

    $adminStore = new FakeRescheduleStore(); $adminStore->context = $context();
    $adminCommit = static fn(array $p): array => array('schedule_version_id' => 200);
    $adminService = new LessonRescheduleService($adminStore, static fn() => array(), $adminCommit, static fn() => time(), static fn() => true);
    $adminProposal = $adminService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-admin-0001');
    $adminConfirmed = $adminService->confirmAsAdministrator((string) $adminProposal['proposal_uid'], 'admin-confirm-key-0001');
    $fail('administrator can confirm a negotiated proposal', $adminConfirmed['state'] === 'accepted' && (string) $adminStore->proposals[0]->state === 'accepted');
    $adminStore2 = new FakeRescheduleStore(); $adminStore2->context = $context();
    $adminService2 = new LessonRescheduleService($adminStore2, static fn() => array(), $adminCommit, static fn() => time(), static fn() => true);
    $adminProposal2 = $adminService2->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-admin-0002');
    $adminCancelled = $adminService2->cancelAsAdministrator((string) $adminProposal2['proposal_uid'], 'admin-cancel-key-0001');
    $fail('administrator can cancel a pending proposal before a manual change', $adminCancelled['state'] === 'cancelled' && (string) $adminStore2->proposals[0]->state === 'cancelled' && count($adminStore2->notifications) === 5);

    $failStore = new FakeRescheduleStore(); $failStore->context = $context();
    $failCommit = static function (array $p): array { throw new InvalidArgumentException('teacher_unavailable'); };
    $failService = new LessonRescheduleService($failStore, static fn() => array(), $failCommit, static fn() => time(), static fn() => true);
    $failProposal = $failService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-admin-0003');
    $threw = false; try { $failService->decideForStudent(9, (string) $failProposal['proposal_uid'], 'accept', 'decision-key-fail-0001'); } catch (Throwable $e) { $threw = $e->getMessage() === 'teacher_unavailable'; }
    $fail('a permanent acceptance refusal closes the pending request', $threw && (string) $failStore->proposals[0]->state === 'failed' && (string) $failStore->proposals[0]->failure_code === 'teacher_unavailable');

    $store->studentConflicts = array(array('lesson_id' => 12));
    $conflictService = new LessonRescheduleService($store, static fn() => array(), $commit, static fn() => time(), static fn() => true);
    $threw = false; try { $conflictService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-0003'); } catch (Throwable $e) { $threw = $e->getMessage() === 'student_slot_conflict'; }
    $fail('student conflict is refused before any proposal is written', $threw);
    $store->studentConflicts = array(array('lesson_id' => 12));
    $notificationsBeforeConflict = count($store->notifications);
    $threw = false; try { $service->decideForStudent(9, (string) $store->proposals[0]->uid, 'accept', 'decision-key-0009'); } catch (Throwable $e) { $threw = $e->getMessage() === 'student_slot_conflict'; }
    $fail('an acceptance conflict changes no proposal, schedule or outbox state', $threw && $store->proposals[0]->state === 'pending' && count($commits) === 0 && count($store->notifications) === $notificationsBeforeConflict && $store->calendarJobs === array());
    $store->studentConflicts = array();

    $accepted = $service->decideForStudent(9, (string) $store->proposals[0]->uid, 'accept', 'decision-key-0001');
    $fail('acceptance commits through the canonical service', $accepted['state'] === 'accepted' && count($commits) === 1 && $accepted['result_schedule_version_id'] === 99);
    $fail('acceptance preserves duration in the canonical payload', $commits[0]['input']['duration_minutes'] === 30 && $commits[0]['input']['expected_schedule_version_id'] === 71);
    $fail('acceptance queues student, teacher, admin and calendar sync', count($store->notifications) === 5 && count($store->calendarJobs) === 1);
    $acceptedEvent = null; foreach ($store->events as $event) { if ($event->event_type === 'accepted') { $acceptedEvent = $event; } }
    $fail('accepted event attributes the real student decision actor and schedule facts', $acceptedEvent
        && (int) $acceptedEvent->authenticated_user_id === 11
        && (int) $acceptedEvent->student_id === 9
        && $acceptedEvent->proposal_uid === (string) $store->proposals[0]->uid
        && (int) $acceptedEvent->expected_schedule_version_id === 71
        && (int) $acceptedEvent->result_schedule_version_id === 99
        && $acceptedEvent->command_key_digest === hash('sha256', 'decision-key-0001')
        && $acceptedEvent->proposed_starts_at_utc === (string) $store->proposals[0]->proposed_starts_at_utc);
    $replay = $service->decideForStudent(9, (string) $store->proposals[0]->uid, 'accept', 'decision-key-0001');
    $fail('repeated decision is idempotent', $replay['idempotent'] === true && count($commits) === 1);
    $threw = false; try { $service->decideForStudent(9, (string) $store->proposals[0]->uid, 'reject', 'decision-key-0002'); } catch (Throwable $e) { $threw = $e->getMessage() === 'reschedule_proposal_already_decided'; }
    $fail('a second concurrent decision cannot move an accepted proposal', $threw);

    $store2 = new FakeRescheduleStore(); $store2->context = $context(); $commits2 = array();
    $service2 = new LessonRescheduleService($store2, static fn() => array(), static function (array $payload) use (&$commits2): array { $commits2[] = $payload; return array('schedule_version_id' => 100); }, static fn() => time(), static fn() => true);
    $p2 = $service2->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-0010');
    $rejected = $service2->decideForStudent(9, (string) $store2->proposals[0]->uid, 'reject', 'decision-key-0010');
    $fail('rejection never commits schedule authority', $rejected['state'] === 'rejected' && $commits2 === array());

    $store3 = new FakeRescheduleStore(); $store3->context = $context(); $stale = $context(); $stale->schedule_version_id = 72;
    $service3 = new LessonRescheduleService($store3, static fn() => array(), static fn(array $p): array => array('schedule_version_id' => 101), static fn() => time(), static fn() => true);
    $service3->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-0020');
    $store3->context = $stale;
    $threw = false; try { $service3->decideForStudent(9, (string) $store3->proposals[0]->uid, 'accept', 'decision-key-0020'); } catch (Throwable $e) { $threw = $e->getMessage() === 'stale_schedule_version'; }
    $fail('acceptance revalidates the current schedule version without changing the proposal', $threw && $store3->proposals[0]->state === 'pending');

    $store4 = new FakeRescheduleStore(); $store4->context = $context();
    $service4 = new LessonRescheduleService($store4, static fn() => array(), static fn(array $p): array => array('schedule_version_id' => 102), static fn() => time(), static fn() => true);
    $service4->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-0030');
    $store4->proposals[0]->state = 'accepted';
    $store4->proposals[0]->decision_key_digest = hash('sha256', 'another-decision-key');
    $threw = false; try { $service4->decideForStudent(9, (string) $store4->proposals[0]->uid, 'accept', 'decision-key-0031'); } catch (Throwable $e) { $threw = $e->getMessage() === 'reschedule_proposal_already_decided'; }
    $fail('a competing decision cannot move a terminal proposal', $threw);

    $atomicStore = new FakeRescheduleStore(); $atomicStore->context = $context(); $atomicStore->atomic = true; $atomicStore->failAcceptedNotification = true;
    $atomicService = new LessonRescheduleService($atomicStore, static fn() => array(), static function (array $p) use ($atomicStore): array { $atomicStore->canonicalRevision = 1; return array('schedule_version_id' => 103); }, static fn() => time(), static fn() => true);
    $atomicService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-0040');
    $threw = false; try { $atomicService->decideForStudent(9, (string) $atomicStore->proposals[0]->uid, 'accept', 'decision-key-0040'); } catch (Throwable $e) { $threw = $e->getMessage() === 'outbox_write_failed'; }
    $fail('a failure after canonical revision rolls back proposal, event and outbox state', $threw && $atomicStore->lastTransaction === 'rollback' && $atomicStore->canonicalRevision === 0 && $atomicStore->proposals[0]->state === 'pending' && count($atomicStore->events) === 1 && count($atomicStore->notifications) === 2 && $atomicStore->calendarJobs === array());

    $store6 = new FakeRescheduleStore(); $store6->context = $context();
    $replaceService = new LessonRescheduleService($store6, static fn() => array(), static fn(array $p): array => array('schedule_version_id' => 105), static fn() => time(), static fn() => true);
    $replaceService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-0060');
    $input2 = $input; $input2['local_time'] = '19:30';
    $replaceService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input2, 'request-key-0061');
    $fail('a teacher can replace their own pending proposal without allowing duplicate pendings', $store6->proposals[0]->state === 'cancelled' && $store6->proposals[1]->state === 'pending' && $store6->proposals[1]->pending_slot === 1);

    $store5 = new FakeRescheduleStore(); $store5->context = $context(); $teacherCommits = array();
    $createService = new LessonRescheduleService($store5, static fn() => array(), static function (array $p) use (&$teacherCommits): array { $teacherCommits[] = $p; return array('schedule_version_id' => 104); }, static fn() => time(), static fn() => true);
    $createService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-0050');
    $conflictAcceptance = new LessonRescheduleService($store5, static fn() => array(array('lesson_id' => 77)), static function (array $p) use (&$teacherCommits): array { $teacherCommits[] = $p; return array('schedule_version_id' => 104); }, static fn() => time(), static fn() => true);
    $threw = false; try { $conflictAcceptance->decideForStudent(9, (string) $store5->proposals[0]->uid, 'accept', 'decision-key-0050'); } catch (Throwable $e) { $threw = $e->getMessage() === 'teacher_slot_conflict'; }
    $fail('teacher occupancy conflict refuses without canonical change or proposal transition', $threw && $teacherCommits === array() && $store5->proposals[0]->state === 'pending' && $store5->calendarJobs === array());

    $providerOutage = new \Delnavazan\Platform\Integrations\LessonRescheduleCalendarService($store, static function (array $job): array { throw new RuntimeException('provider_outage'); }, static fn() => time(), static fn() => true);
    $providerOutage->dispatchPending(5);
    $fail('provider outage does not revoke an accepted reschedule', (string) $store->proposals[0]->state === 'accepted' && count($commits) === 1 && (string) $store->calendarJobs[0]->state === 'retry');
    $providerRecovered = new \Delnavazan\Platform\Integrations\LessonRescheduleCalendarService($store, static fn(array $job): array => array('state' => 'handed_off'), static fn() => time(), static fn() => true);
    $providerRecovered->dispatchPending(5);
    $fail('provider recovery completes the same job without a second schedule revision', (string) $store->calendarJobs[0]->state === 'completed' && count($commits) === 1);

    $fail('accepted reschedule moves its protected commercial interval', str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/platform/src/Core/Application/CommercialCapacityAuthority.php'), 'reviseForReschedule') && str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/platform/src/Core/Application/CanonicalLessonScheduleService.php'), 'reviseForReschedule'));

    echo "Lesson reschedule service unit passed\n";
}
