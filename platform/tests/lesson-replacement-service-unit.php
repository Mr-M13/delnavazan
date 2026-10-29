<?php
/**
 * Missed-lesson replacement lifecycle.
 *
 * Reproduces the production failure: a completed/past occurrence is intentionally refused by the
 * reschedule lifecycle, while the Teacher portal still needs a self-service path. The same input
 * must now produce a replacement proposal, and Student acceptance must issue a linked replacement
 * Lesson without mutating the original Lesson, its completion state or its attendance evidence.
 */
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
    require_once dirname(__DIR__, 2) . '/platform/src/Core/Application/LessonReplacementSystemActor.php';
    require_once dirname(__DIR__, 2) . '/platform/src/Core/Application/LessonRescheduleService.php';

    use Delnavazan\Platform\Core\Application\{LessonRescheduleService,LessonRescheduleStore};

    final class ReplacementFakeStore implements LessonRescheduleStore {
        public array $proposals = array();
        public array $events = array();
        public array $notifications = array();
        public array $calendarJobs = array();
        public array $proposalKinds = array();
        public array $facts = array();
        public array $studentConflicts = array();
        public object $context;
        private int $sequence = 0;
        private array $snapshot = array();

        public function __construct() {
            $this->facts = array(
                'replacement_lesson_id' => null, 'replacement_allowance' => 2, 'replacement_used' => 0,
                'attendance_case_id' => 77, 'attendance_state' => 'ready_for_review',
                'attendance_reason' => 'student_reports_missed_class', 'attendance_schedule_version_id' => 71,
                'delivery_outcome' => null, 'replacement_proposal_state' => null,
            );
        }
        public function begin():void { $this->snapshot = array($this->proposals, $this->events, $this->notifications, $this->calendarJobs, $this->proposalKinds); }
        public function commit():void {}
        public function rollback():void { $this->proposals = $this->snapshot[0] ?? $this->proposals; $this->events = $this->snapshot[1] ?? $this->events; $this->notifications = $this->snapshot[2] ?? $this->notifications; $this->calendarJobs = $this->snapshot[3] ?? $this->calendarJobs; $this->proposalKinds = $this->snapshot[4] ?? $this->proposalKinds; }
        public function proposalByUid(string $uid, bool $lock = false):?object { foreach ($this->proposals as $row) { if ($row->uid === $uid) return $row; } return null; }
        public function proposalByCommandDigest(string $digest):?object { foreach ($this->proposals as $row) { if ($row->command_key_digest === $digest) return $row; } return null; }
        public function proposalByDecisionDigest(string $digest):?object { foreach ($this->proposals as $row) { if (($row->decision_key_digest ?? null) === $digest) return $row; } return null; }
        public function proposal(int $id, bool $lock = false):?object { foreach ($this->proposals as $row) { if ((int) $row->id === $id) return $row; } return null; }
        public function pendingForStudent(int $studentId):array { return array_values(array_filter($this->proposals, static fn($row) => (int) $row->student_id === $studentId && $row->state === 'pending')); }
        public function pendingForLesson(int $lessonId, bool $lock = false):?object { foreach ($this->proposals as $row) { if ((int) $row->lesson_id === $lessonId && $row->state === 'pending') return $row; } return null; }
        public function proposalRejectionCount(int $lessonId):int { return count(array_filter($this->proposals, static fn($row) => (int) $row->lesson_id === $lessonId && $row->state === 'rejected')); }
        public function proposalKind(int $proposalId):string { return $this->proposalKinds[$proposalId] ?? 'reschedule'; }
        public function replacementFacts(int $lessonId,int $termId):array { return $this->facts; }
        public function lessonContext(int $lessonId):?object { return $this->context; }
        public function lessonContextByUid(string $lessonUid):?object { return $lessonUid === $this->context->lesson_uid ? $this->context : null; }
        public function versionIdByUid(string $uid):int { return 71; }
        public function bookingClassByUid(string $uid):?object { return null; }
        public function insertProposal(array $data):int { $data['id'] = ++$this->sequence; $this->proposals[] = (object) $data; return $data['id']; }
        public function updateProposal(int $id, int $expectedVersion, array $data):int { foreach ($this->proposals as $i => $row) { if ((int) $row->id === $id && (int) $row->version === $expectedVersion) { $data['version'] = $expectedVersion + 1; $this->proposals[$i] = (object) array_merge((array) $row, $data); return 1; } } throw new RuntimeException('Stale reschedule proposal'); }
        public function insertEvent(array $data):int { $this->events[] = (object) $data; if (($data['event_type'] ?? '') === 'proposed') { $this->proposalKinds[(int) $data['proposal_id']] = ($data['reason_code'] ?? '') === 'teacher_replacement_proposed' ? 'replacement' : 'reschedule'; } return count($this->events); }
        public function eventsFor(int $proposalId):array { return array_values(array_filter($this->events, static fn($row) => (int) $row->proposal_id === $proposalId)); }
        public function insertNotification(array $data):int { $this->notifications[] = (object) $data; return count($this->notifications); }
        public function pendingNotifications(int $limit = 20):array { return $this->notifications; }
        public function notification(int $id, bool $lock = false):?object { return $this->notifications[$id - 1] ?? null; }
        public function updateNotification(int $id, array $data):int { return 1; }
        public function insertCalendarJob(array $data):int { $data['id'] = ++$this->sequence; $this->calendarJobs[] = (object) $data; return count($this->calendarJobs); }
        public function pendingCalendarJobs(int $limit = 10):array { return $this->calendarJobs; }
        public function calendarJob(int $id, bool $lock = false):?object { foreach ($this->calendarJobs as $job) { if ((int) $job->id === $id) return $job; } return null; }
        public function updateCalendarJob(int $id, array $data):int { return 1; }
        public function ensureAndLockStudentRoot(int $studentId, string $now, int $actor):void {}
        public function studentConflict(int $studentId, string $startsAtUtc, string $endsAtUtc, int $excludeLessonId):array { return $this->studentConflicts; }
        public function duplicate(Throwable $e):?string { return null; }
    }

    $fail = static function (string $name, bool $ok): void { if (!$ok) { throw new RuntimeException('Lesson replacement service unit failed: ' . $name); } };
    $context = static function (string $lifecycle = 'completed'): object {
        return (object) array(
            'lesson_id' => 41, 'lesson_uid' => 'LESSON-41', 'lesson_reference' => 'L-41', 'teacher_id' => 5,
            'student_id' => 9, 'enrolment_id' => 3, 'term_id' => 2, 'teacher_assignment_id' => 8,
            'lifecycle_state' => $lifecycle, 'record_model' => 'canonical_term_lesson_v1', 'schedule_version_id' => 71,
            'schedule_version_uid' => 'SCH-71',
            'starts_at_utc' => gmdate('Y-m-d H:i:s', time() - 3 * 86400),
            'ends_at_utc' => gmdate('Y-m-d H:i:s', time() - 3 * 86400 + 1800),
            'occupied_ends_at_utc' => gmdate('Y-m-d H:i:s', time() - 3 * 86400 + 2700),
            'duration_minutes' => 30, 'buffer_minutes' => 15, 'schedule_timezone' => 'Asia/Tehran',
        );
    };
    $input = array('timezone' => 'Asia/Tehran', 'calendar' => 'gregorian', 'local_date' => gmdate('Y-m-d', time() + 5 * 86400), 'local_time' => '18:30');
    $exceptions = array();
    $exception = static function (string $type, string $code, string $summary, int $lessonId) use (&$exceptions): void { $exceptions[] = array($type, $code, $lessonId); };

    // The original failure: a past occurrence is not a reschedule candidate. It must become a
    // replacement proposal instead of returning reschedule_lesson_not_eligible.
    $store = new ReplacementFakeStore(); $store->context = $context('completed');
    $replacementCalls = array(); $rescheduleCalls = 0;
    $replacementCommit = static function (array $payload) use (&$replacementCalls): array { $replacementCalls[] = $payload; return array('lesson_id' => 900, 'schedule_version_id' => 901); };
    $rescheduleCommit = static function (array $payload) use (&$rescheduleCalls): array { $rescheduleCalls++; return array('schedule_version_id' => 999); };
    $service = new LessonRescheduleService($store, static fn() => array(), $rescheduleCommit, static fn() => time(), static fn() => true, $replacementCommit, $exception);

    $proposal = $service->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-replacement-0001');
    $fail('a completed missed occurrence creates a replacement proposal', $proposal['state'] === 'pending' && ($proposal['kind'] ?? '') === 'replacement');
    $fail('the proposed event names the replacement lifecycle', $store->events[0]->reason_code === 'teacher_replacement_proposed');
    $fail('proposal creation does not revise the original schedule', $rescheduleCalls === 0 && $replacementCalls === array());
    $fail('the Student is notified about the replacement proposal', $store->notifications[0]->audience === 'student');

    $accepted = $service->decideForStudent(9, (string) $store->proposals[0]->uid, 'accept', 'decision-key-replacement-0001');
    $fail('Student acceptance issues the linked replacement in the same transaction', $accepted['state'] === 'accepted' && count($replacementCalls) === 1 && $rescheduleCalls === 0);
    $fail('replacement issuance targets the original Lesson and Term', $replacementCalls[0]['origin_lesson_id'] === 41 && $replacementCalls[0]['term_id'] === 2 && $replacementCalls[0]['teacher_assignment_id'] === 8);
    $fail('the original Lesson is never mutated by replacement acceptance', $store->context->lifecycle_state === 'completed' && (string) $store->context->schedule_version_uid === 'SCH-71' && (string) $store->context->starts_at_utc === (string) $context('completed')->starts_at_utc);
    $fail('acceptance records the new schedule version and calendar hand-off', $accepted['result_schedule_version_id'] === 901 && (int) $store->calendarJobs[0]->lesson_id === 900 && (int) $store->calendarJobs[0]->to_schedule_version_id === 901);
    $fail('acceptance notifies both parties and admin', count(array_filter($store->notifications, static fn($row) => $row->event_type === 'accepted')) === 3);
    $replay = $service->decideForStudent(9, (string) $store->proposals[0]->uid, 'accept', 'decision-key-replacement-0001');
    $fail('repeated replacement acceptance is idempotent', $replay['idempotent'] === true && count($replacementCalls) === 1);

    // Entitlement exhaustion is an actionable exception, never a silent failure.
    $exhausted = new ReplacementFakeStore(); $exhausted->context = $context('completed');
    $exhausted->facts['replacement_used'] = 2;
    $exhaustedService = new LessonRescheduleService($exhausted, static fn() => array(), $rescheduleCommit, static fn() => time(), static fn() => true, $replacementCommit, $exception);
    $threw = false; try { $exhaustedService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-replacement-0002'); } catch (Throwable $e) { $threw = $e->getMessage() === 'replacement_entitlement_exhausted'; }
    $fail('exhausted replacement entitlement is refused', $threw && end($exceptions)[1] === 'replacement_entitlement_exhausted');

    // A disputed attendance report must not silently become a replacement.
    $disputed = new ReplacementFakeStore(); $disputed->context = $context('completed');
    $disputed->facts['attendance_reason'] = 'student_disputes_class';
    $disputedService = new LessonRescheduleService($disputed, static fn() => array(), $rescheduleCommit, static fn() => time(), static fn() => true, $replacementCommit, $exception);
    $threw = false; try { $disputedService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-replacement-0003'); } catch (Throwable $e) { $threw = $e->getMessage() === 'attendance_outcome_disputed'; }
    $fail('a disputed absence report creates an administrator exception', $threw && end($exceptions)[1] === 'attendance_outcome_disputed');

    // No Student report means no entitlement, even for a completed occurrence.
    $unreported = new ReplacementFakeStore(); $unreported->context = $context('completed');
    $unreported->facts['attendance_reason'] = null; $unreported->facts['attendance_case_id'] = null; $unreported->facts['attendance_state'] = null;
    $unreportedService = new LessonRescheduleService($unreported, static fn() => array(), $rescheduleCommit, static fn() => time(), static fn() => true, $replacementCommit, $exception);
    $threw = false; try { $unreportedService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-replacement-0004'); } catch (Throwable $e) { $threw = $e->getMessage() === 'replacement_attendance_unresolved'; }
    $fail('a report is required before replacement entitlement is considered', $threw);

    // A delivered outcome can never be replaced.
    $delivered = new ReplacementFakeStore(); $delivered->context = $context('completed');
    $delivered->facts['delivery_outcome'] = 'delivered';
    $deliveredService = new LessonRescheduleService($delivered, static fn() => array(), $rescheduleCommit, static fn() => time(), static fn() => true, $replacementCommit, $exception);
    $threw = false; try { $deliveredService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-replacement-0005'); } catch (Throwable $e) { $threw = $e->getMessage() === 'replacement_not_owed_delivered'; }
    $fail('an authoritative delivered outcome refuses a replacement', $threw);

    // Rejection and a second proposal are first-class self-service behaviour.
    $rejectStore = new ReplacementFakeStore(); $rejectStore->context = $context('completed');
    $rejectService = new LessonRescheduleService($rejectStore, static fn() => array(), $rescheduleCommit, static fn() => time(), static fn() => true, $replacementCommit, $exception);
    $first = $rejectService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-replacement-0010');
    $rejected = $rejectService->decideForStudent(9, (string) $rejectStore->proposals[0]->uid, 'reject', 'decision-key-replacement-0010');
    $fail('rejection preserves the original schedule and notifies the Teacher', $rejected['state'] === 'rejected' && count($replacementCalls) >= 1 && count(array_filter($rejectStore->notifications, static fn($row) => $row->event_type === 'rejected' && $row->audience === 'teacher')) === 1);
    $secondInput = $input; $secondInput['local_time'] = '19:30';
    $second = $rejectService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $secondInput, 'request-key-replacement-0011');
    $fail('the Teacher can propose another replacement after a rejection', $second['state'] === 'pending' && ($second['kind'] ?? '') === 'replacement');

    // Three rejections become a durable administrator exception.
    $escalation = new ReplacementFakeStore(); $escalation->context = $context('completed');
    $escalationExceptions = array();
    $escalationException = static function (string $type, string $code, string $summary, int $lessonId) use (&$escalationExceptions): void { $escalationExceptions[] = $code; };
    $escalationService = new LessonRescheduleService($escalation, static fn() => array(), $rescheduleCommit, static fn() => time(), static fn() => true, $replacementCommit, $escalationException);
    for ($i = 0; $i < 3; $i++) {
        $p = $escalationService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-escalation-00' . $i);
        $escalationService->decideForStudent(9, (string) $escalation->proposals[$i]->uid, 'reject', 'decision-key-escalation-00' . $i);
    }
    $fail('three rejections record an administrator escalation', in_array('reschedule_rejections_exhausted', $escalationExceptions, true));

    // A concurrent Student acceptance makes the old version stale for a Teacher proposal.
    $staleStore = new ReplacementFakeStore(); $staleStore->context = $context('completed');
    $staleService = new LessonRescheduleService($staleStore, static fn() => array(), $rescheduleCommit, static fn() => time(), static fn() => true, $replacementCommit, $exception);
    $staleService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-replacement-0020');
    $staleService->decideForStudent(9, (string) $staleStore->proposals[0]->uid, 'accept', 'decision-key-replacement-0020');
    $staleStore->context->schedule_version_uid = 'SCH-72'; $staleStore->context->schedule_version_id = 72;
    $threw = false; try { $staleService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-replacement-0021'); } catch (Throwable $e) { $threw = $e->getMessage() === 'stale_schedule_version'; }
    $fail('a concurrent accepted change refuses a stale Teacher proposal', $threw);

    // Unauthorised actors cannot propose, and duplicate submissions converge.
    $ownerStore = new ReplacementFakeStore(); $ownerStore->context = $context('completed');
    $ownerService = new LessonRescheduleService($ownerStore, static fn() => array(), $rescheduleCommit, static fn() => time(), static fn() => true, $replacementCommit, $exception);
    $threw = false; try { $ownerService->proposeForTeacher(6, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-replacement-0030'); } catch (Throwable $e) { $threw = $e->getMessage() === 'portal_object_not_owned'; }
    $fail('another Teacher cannot propose a replacement', $threw);
    $once = $ownerService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-replacement-0031');
    $twice = $ownerService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-replacement-0031');
    $fail('duplicate replacement submissions are idempotent', $twice['idempotent'] === true && count($ownerStore->proposals) === 1);

    // A missing/underprivileged replacement actor is retryable, not a silent rollback.
    $retryStore = new ReplacementFakeStore(); $retryStore->context = $context('completed');
    $retryService = new LessonRescheduleService($retryStore, static fn() => array(), $rescheduleCommit, static fn() => time(), static fn() => true, static function (array $payload): array { throw new RuntimeException('replacement_system_actor_unconfigured'); }, $exception);
    $retryProposal = $retryService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-replacement-0040');
    $threw = false; try { $retryService->decideForStudent(9, (string) $retryProposal['proposal_uid'], 'accept', 'decision-key-replacement-0040'); } catch (Throwable $e) { $threw = $e->getMessage() === 'replacement_system_actor_unconfigured'; }
    $fail('a retryable actor failure leaves the proposal pending', $threw && $retryStore->proposals[0]->state === 'pending');

    // Jalali input remains the Teacher's chosen presentation calendar.
    if (class_exists('IntlCalendar')) {
        $jalaliStore = new ReplacementFakeStore(); $jalaliStore->context = $context('completed');
        $jalaliService = new LessonRescheduleService($jalaliStore, static fn() => array(), $rescheduleCommit, static fn() => time(), static fn() => true, $replacementCommit, $exception);
        $jalali = array('timezone' => 'Asia/Tehran', 'calendar' => 'persian', 'jalali_year' => 1405, 'jalali_month' => 9, 'jalali_day' => 1, 'local_time' => '18:30');
        $jalaliService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $jalali, 'request-key-replacement-0050');
        $expected = '';
        $cal = IntlCalendar::createInstance(new DateTimeZone('Asia/Tehran'), 'fa_IR@calendar=persian');
        $cal->setLenient(false); $cal->set(1405, 8, 1, 0, 0, 0);
        $expected = (new DateTimeImmutable('@' . (int) floor($cal->getTime() / 1000)))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d');
        $fail('Jalali input converts to the exact Gregorian wall date', (string) $jalaliStore->proposals[0]->local_wall_date === $expected);
    }

    // A future authorised occurrence still follows the reschedule lifecycle.
    $futureStore = new ReplacementFakeStore(); $futureStore->context = $context('authorised');
    $futureStore->context->starts_at_utc = gmdate('Y-m-d H:i:s', time() + 3 * 86400);
    $futureStore->context->ends_at_utc = gmdate('Y-m-d H:i:s', time() + 3 * 86400 + 1800);
    $futureService = new LessonRescheduleService($futureStore, static fn() => array(), $rescheduleCommit, static fn() => time(), static fn() => true, $replacementCommit, $exception);
    $future = $futureService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-replacement-0060');
    $fail('a future authorised occurrence remains a reschedule', ($future['kind'] ?? '') === 'reschedule' && $futureStore->events[0]->reason_code === 'teacher_reschedule_proposed');

    // A lesson that is currently in progress is neither a reschedule nor a replacement.
    $progressStore = new ReplacementFakeStore(); $progressStore->context = $context('authorised');
    $progressStore->context->starts_at_utc = gmdate('Y-m-d H:i:s', time() - 300);
    $progressStore->context->ends_at_utc = gmdate('Y-m-d H:i:s', time() + 900);
    $progressService = new LessonRescheduleService($progressStore, static fn() => array(), $rescheduleCommit, static fn() => time(), static fn() => true, $replacementCommit, $exception);
    $threw = false; try { $progressService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-replacement-0070'); } catch (Throwable $e) { $threw = $e->getMessage() === 'reschedule_lesson_in_progress'; }
    $fail('an in-progress occurrence is refused', $threw);


    // An expired proposal is terminal and cannot be accepted.
    $expiredStore = new ReplacementFakeStore(); $expiredStore->context = $context('completed');
    $expiredService = new LessonRescheduleService($expiredStore, static fn() => array(), $rescheduleCommit, static fn() => time(), static fn() => true, $replacementCommit, $exception);
    $expiredProposal = $expiredService->proposeForTeacher(5, 'Asia/Tehran', 'LESSON-41', 'SCH-71', $input, 'request-key-replacement-expired-01');
    $expiredStore->proposals[0]->expires_at = gmdate('Y-m-d H:i:s', time() - 60);
    $threw = false; try { $expiredService->decideForStudent(9, (string) $expiredProposal['proposal_uid'], 'accept', 'decision-key-replacement-expired-01'); } catch (Throwable $e) { $threw = $e->getMessage() === 'reschedule_proposal_expired'; }
    $fail('an expired replacement proposal is refused and closed', $threw && (string) $expiredStore->proposals[0]->state === 'failed' && (string) $expiredStore->proposals[0]->failure_code === 'reschedule_proposal_expired');

    echo "Lesson replacement service unit passed\n";
}
