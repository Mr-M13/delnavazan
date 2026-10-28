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
        public array $jobs = array();
        public function begin():void {}
        public function commit():void {}
        public function rollback():void {}
        public function proposalByUid(string $uid, bool $lock = false):?object { return null; }
        public function proposalByCommandDigest(string $digest):?object { return null; }
        public function proposalByDecisionDigest(string $digest):?object { return null; }
        public function proposal(int $id, bool $lock = false):?object { return null; }
        public function pendingForStudent(int $studentId):array { return array(); }
        public function pendingForLesson(int $lessonId, bool $lock = false):?object { return null; }
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
        public function pendingCalendarJobs(int $limit = 10):array { return array_values(array_filter($this->jobs, static fn($row) => in_array($row->state, array('queued', 'retry'), true))); }
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

    echo "Lesson reschedule calendar unit passed\n";
}
