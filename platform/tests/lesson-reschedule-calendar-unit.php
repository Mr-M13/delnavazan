<?php
/** Reschedule calendar hand-off retry and convergence. */
namespace {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
    require_once dirname(__DIR__, 2) . '/platform/src/Core/Application/LessonRescheduleStore.php';
    require_once dirname(__DIR__, 2) . '/platform/src/Integrations/LessonRescheduleCalendarActor.php';
    require_once dirname(__DIR__, 2) . '/platform/src/Integrations/LessonRescheduleCalendarService.php';

    use Delnavazan\Platform\Core\Application\LessonRescheduleStore;
    use Delnavazan\Platform\Integrations\LessonRescheduleCalendarService;

    final class CalendarStore implements LessonRescheduleStore {
        public array $proposalKinds = array();
        public array $replacementFactsData = array('replacement_lesson_id'=>null,'replacement_allowance'=>2,'replacement_used'=>0,'attendance_case_id'=>null,'attendance_state'=>null,'attendance_reason'=>null,'attendance_schedule_version_id'=>null,'delivery_outcome'=>null,'replacement_proposal_state'=>null);
        public array $jobs = array();
        public array $forcePending = array();
        public function begin():void {}
        public function commit():void {}
        public function rollback():void {}
        public function proposalByUid(string $uid, bool $lock = false):?object { return null; }
        public function proposalByCommandDigest(string $digest):?object { return null; }
        public function proposalByDecisionDigest(string $digest):?object { return null; }
        public function proposal(int $id, bool $lock = false):?object { return null; }
        public function pendingForStudent(int $studentId):array { return array(); }
        public function pendingForLesson(int $lessonId, bool $lock = false):?object { return null; }
        public function proposalRejectionCount(int $lessonId):int { return 0; }
        public function proposalKind(int $proposalId):string { return $this->proposalKinds[(int) $proposalId] ?? 'reschedule'; }
        public function replacementFacts(int $lessonId,int $termId):array { return $this->replacementFactsData; }
        public function lessonContext(int $lessonId):?object { return null; }
        public function lessonContextByUid(string $lessonUid):?object { return null; }
        public function versionIdByUid(string $uid):int { return 0; }
        public function bookingClassByUid(string $uid):?object { return null; }
        public function insertProposal(array $data):int { return 0; }
        public function updateProposal(int $id, int $expectedVersion, array $data):int { return 1; }
        public function insertEvent(array $data):int { return 0; }
        public function eventsFor(int $proposalId):array { return array(); }
        public function insertNotification(array $data):int { return 0; }
        public function pendingNotifications(int $limit = 20):array { return array(); }
        public function notification(int $id, bool $lock = false):?object { return null; }
        public function updateNotification(int $id, array $data):int { return 0; }
        public function insertCalendarJob(array $data):int { $this->jobs[] = (object) $data; return count($this->jobs); }
        public function pendingCalendarJobs(int $limit = 10):array { $stale = $this->forcePending; $this->forcePending = array(); return array_merge($stale, array_values(array_filter($this->jobs, static fn($row) => in_array($row->state, array('queued', 'retry'), true)))); }
        public function calendarJob(int $id, bool $lock = false):?object { return $this->jobs[$id - 1] ?? null; }
        public function updateCalendarJob(int $id, array $data):int { $this->jobs[$id - 1] = (object) array_merge((array) $this->jobs[$id - 1], $data); return 1; }
        public function ensureAndLockStudentRoot(int $studentId, string $now, int $actor):void {}
        public function studentConflict(int $studentId, string $startsAtUtc, string $endsAtUtc, int $excludeLessonId):array { return array(); }
        public function duplicate(Throwable $e):?string { return null; }
    }

    $fail = static function (string $name, bool $ok): void { if (!$ok) { throw new RuntimeException('Lesson reschedule calendar unit failed: ' . $name); } };
    $store = new CalendarStore();
    $store->jobs[] = (object) array('id' => 1, 'uid' => 'JOB-1', 'proposal_id' => 3, 'lesson_id' => 41, 'from_schedule_version_id' => 71, 'to_schedule_version_id' => 99, 'state' => 'queued', 'attempt_count' => 0);
    $calls = 0;
    $dispatch = static function (array $job) use (&$calls): array { $calls++; if ($calls === 1) throw new RuntimeException('provider_unavailable'); return array('state' => 'handed_off'); };
    $service = new LessonRescheduleCalendarService($store, $dispatch, static fn(): int => time());
    $first = $service->dispatchPending(5);
    $fail('a calendar failure becomes retryable', $first['failed'] === 1 && $store->jobs[0]->state === 'retry' && $store->jobs[0]->attempt_count === 1);
    $second = $service->dispatchPending(5);
    $fail('a retry completes the calendar hand-off', $second['completed'] === 1 && $store->jobs[0]->state === 'completed' && $store->jobs[0]->completed_at !== null);
    $third = $service->dispatchPending(5);
    $fail('a completed job is never dispatched twice', $third['completed'] === 0 && $calls === 2);

    $missingActor = new CalendarStore();
    $missingActor->jobs[] = (object) array('id' => 1, 'uid' => 'JOB-2', 'proposal_id' => 4, 'lesson_id' => 42, 'from_schedule_version_id' => 72, 'to_schedule_version_id' => 100, 'state' => 'queued', 'attempt_count' => 0);
    $missingService = new LessonRescheduleCalendarService($missingActor, static function (array $job): array { throw new RuntimeException('reschedule_calendar_actor_unconfigured'); }, static fn(): int => time());
    $missing = $missingService->dispatchPending(5);
    $fail('a missing calendar actor leaves the job queued and un-attempted', $missing['failed'] === 1 && $missingActor->jobs[0]->state === 'queued' && $missingActor->jobs[0]->attempt_count === 0 && $missingActor->jobs[0]->last_error_code === 'reschedule_calendar_actor_unconfigured');


    // Worker crash after the provider event exists: the retry must reuse the stable event.
    $crashStore = new CalendarStore();
    $crashStore->jobs[] = (object) array('id' => 1, 'uid' => 'JOB-CRASH', 'proposal_id' => 5, 'lesson_id' => 43, 'from_schedule_version_id' => 73, 'to_schedule_version_id' => 101, 'state' => 'queued', 'attempt_count' => 0, 'idempotency_key' => 'stable-calendar-key');
    $providerEvents = array(); $crashed = false; $crashCalls = 0;
    $crashDispatch = static function (array $job) use (&$providerEvents, &$crashed, &$crashCalls): array {
        $crashCalls++;
        $key = (string) ($job['idempotency_key'] ?? $job['uid']);
        if (!isset($providerEvents[$key])) $providerEvents[$key] = 'EVENT-' . $job['uid'];
        if (!$crashed) { $crashed = true; throw new RuntimeException('provider_timeout_after_create'); }
        return array('state' => 'handed_off', 'provider_event' => $providerEvents[$key]);
    };
    $crashService = new LessonRescheduleCalendarService($crashStore, $crashDispatch, static fn(): int => time());
    $crashService->dispatchPending(5);
    $fail('a crash after provider creation leaves a retryable job', $crashStore->jobs[0]->state === 'retry' && $crashCalls === 1);
    $crashService->dispatchPending(5);
    $fail('the retry reuses the stable provider event and completes', $crashStore->jobs[0]->state === 'completed' && $crashCalls === 2 && count($providerEvents) === 1 && $providerEvents['stable-calendar-key'] === 'EVENT-JOB-CRASH');
    $crashService->dispatchPending(5);
    $fail('a completed crash-retry job is never dispatched again', $crashCalls === 2);

    // A deterministic provider refusal is terminal and visible, not retried forever.
    $permanent = new CalendarStore();
    $permanent->jobs[] = (object) array('id' => 1, 'uid' => 'JOB-PERMANENT', 'proposal_id' => 6, 'lesson_id' => 44, 'from_schedule_version_id' => 74, 'to_schedule_version_id' => 102, 'state' => 'queued', 'attempt_count' => 0);
    $permanentCalls = 0;
    $permanentService = new LessonRescheduleCalendarService($permanent, static function (array $job) use (&$permanentCalls): array { $permanentCalls++; throw new RuntimeException('google_calendar_projection_refused'); }, static fn(): int => time());
    $permanentService->dispatchPending(5);
    $fail('a permanent provider refusal fails the job immediately with a visible code', $permanent->jobs[0]->state === 'failed' && $permanent->jobs[0]->attempt_count === 1 && $permanent->jobs[0]->last_error_code === 'google_calendar_projection_refused');
    $permanentService->dispatchPending(5);
    $fail('a failed permanent job is not retried', $permanentCalls === 1);

    $calendarSource = (string) file_get_contents(dirname(__DIR__) . '/src/Integrations/LessonRescheduleCalendarService.php');
    $fail('provider commands use deterministic retry keys', str_contains($calendarSource, "reschedule-retract-calendar:'.\$job['uid']") && str_contains($calendarSource, "reschedule-project-calendar:'.\$job['uid']") && str_contains($calendarSource, "reschedule-project-meeting:'.\$job['uid']"));


    // A worker with a stale queue snapshot must not dispatch an already-completed job.
    $staleStore = new CalendarStore();
    $staleStore->jobs[] = (object) array('id' => 1, 'uid' => 'JOB-STALE', 'proposal_id' => 7, 'lesson_id' => 45, 'from_schedule_version_id' => 75, 'to_schedule_version_id' => 103, 'state' => 'completed', 'attempt_count' => 1);
    $staleDispatches = 0;
    $staleStore->forcePending = array($staleStore->jobs[0]);
    $staleService = new LessonRescheduleCalendarService($staleStore, static function (array $job) use (&$staleDispatches): array { $staleDispatches++; return array('state' => 'handed_off'); }, static fn(): int => time());
    $staleResult = $staleService->dispatchPending(5);
    $fail('a stale queue snapshot cannot dispatch a completed job', $staleDispatches === 0 && $staleResult['completed'] === 0 && $staleResult['failed'] === 0);


    // Direct recovery dispatch: only a queued/retry job may be dispatched, exactly once.
    $directStore = new CalendarStore();
    $directStore->jobs[] = (object) array('id' => 1, 'uid' => 'JOB-DIRECT', 'proposal_id' => 8, 'lesson_id' => 46, 'from_schedule_version_id' => 76, 'to_schedule_version_id' => 104, 'state' => 'retry', 'attempt_count' => 2);
    $directDispatches = 0;
    $directService = new LessonRescheduleCalendarService($directStore, static function (array $job) use (&$directDispatches): array { $directDispatches++; return array('state' => 'handed_off'); }, static fn(): int => time());
    $fail('direct recovery dispatch completes a retry job', $directService->dispatchJob(1) === 'completed' && $directStore->jobs[0]->state === 'completed' && $directDispatches === 1);
    $fail('direct recovery dispatch never repeats a completed job', $directService->dispatchJob(1) === 'skipped' && $directDispatches === 1);
    $directStore->jobs[0]->state = 'failed';
    $fail('direct recovery refuses a failed job until it is explicitly requeued', $directService->dispatchJob(1) === 'skipped' && $directDispatches === 1);

    $directFailureStore = new CalendarStore();
    $directFailureStore->jobs[] = (object) array('id' => 1, 'uid' => 'JOB-DIRECT-FAIL', 'proposal_id' => 9, 'lesson_id' => 47, 'from_schedule_version_id' => 77, 'to_schedule_version_id' => 105, 'state' => 'retry', 'attempt_count' => 0);
    $directFailureService = new LessonRescheduleCalendarService($directFailureStore, static function (array $job): array { throw new RuntimeException('provider_unavailable'); }, static fn(): int => time());
    $threw = false; try { $directFailureService->dispatchJob(1); } catch (Throwable $e) { $threw = $e->getMessage() === 'provider_unavailable'; }
    $fail('direct recovery failure remains retryable', $threw && $directFailureStore->jobs[0]->state === 'retry' && $directFailureStore->jobs[0]->attempt_count === 1);

    echo "Lesson reschedule calendar unit passed\n";
}
