<?php
/** Reschedule notification retry and idempotency. */
namespace {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
    function wp_date(string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null): string { return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone($timezone ?? new DateTimeZone('UTC'))->format($format); }
    function wp_mail(...$args): bool { return false; }
    require_once dirname(__DIR__, 2) . '/platform/src/Core/Application/LessonRescheduleStore.php';
    require_once dirname(__DIR__, 2) . '/platform/src/Integrations/Notifications/LessonRescheduleNotificationService.php';

    use Delnavazan\Platform\Core\Application\LessonRescheduleStore;
    use Delnavazan\Platform\Integrations\Notifications\LessonRescheduleNotificationService;

    final class NotificationStore implements LessonRescheduleStore {
        public array $notifications = array();
        public object $proposal;
        public function begin():void {}
        public function commit():void {}
        public function rollback():void {}
        public function proposalByUid(string $uid, bool $lock = false):?object { return $this->proposal; }
        public function proposalByCommandDigest(string $digest):?object { return null; }
        public function proposalByDecisionDigest(string $digest):?object { return null; }
        public function proposal(int $id, bool $lock = false):?object { return $this->proposal; }
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
        public function pendingNotifications(int $limit = 20):array { return array_values(array_filter($this->notifications, static fn($row) => in_array($row->state, array('queued', 'retry'), true))); }
        public function notification(int $id, bool $lock = false):?object { return $this->notifications[$id - 1] ?? null; }
        public function updateNotification(int $id, array $data):int { $this->notifications[$id - 1] = (object) array_merge((array) $this->notifications[$id - 1], $data); return 1; }
        public function insertCalendarJob(array $data):int { return 0; }
        public function pendingCalendarJobs(int $limit = 10):array { return array(); }
        public function calendarJob(int $id, bool $lock = false):?object { return null; }
        public function updateCalendarJob(int $id, array $data):int { return 0; }
        public function ensureAndLockStudentRoot(int $studentId, string $now, int $actor):void {}
        public function studentConflict(int $studentId, string $startsAtUtc, string $endsAtUtc, int $excludeLessonId):array { return array(); }
        public function duplicate(Throwable $e):?string { return null; }
    }

    $fail = static function (string $name, bool $ok): void { if (!$ok) { throw new RuntimeException('Lesson reschedule notification unit failed: ' . $name); } };
    $store = new NotificationStore();
    $store->proposal = (object) array('uid' => 'PROPOSAL-1', 'student_id' => 9, 'teacher_id' => 5, 'schedule_timezone' => 'Asia/Tehran', 'proposed_starts_at_utc' => '2026-11-05 15:00:00');
    $store->notifications = array(
        (object) array('id' => 1, 'proposal_id' => 1, 'event_type' => 'proposed', 'audience' => 'student', 'state' => 'queued', 'attempt_count' => 0),
        (object) array('id' => 2, 'proposal_id' => 1, 'event_type' => 'accepted', 'audience' => 'teacher', 'state' => 'queued', 'attempt_count' => 0),
    );
    $sent = array();
    $transport = static function (string $email, string $subject, string $body) use (&$sent): bool {
        $sent[] = array($email, $subject, $body);
        return count($sent) !== 2; // second delivery fails once
    };
    $recipient = static fn(object $proposal, string $audience): array => array('email' => $audience . '@example.invalid', 'name' => $audience);
    $service = new LessonRescheduleNotificationService($store, $transport, $recipient, static fn(): int => time());
    $first = $service->dispatchPending(10);
    $fail('one notification is sent and one retries', $first['sent'] === 1 && $first['failed'] === 1);
    $fail('sent notification is terminal', $store->notifications[0]->state === 'sent' && $store->notifications[0]->sent_at !== null);
    $fail('failed notification is retryable with backoff', $store->notifications[1]->state === 'retry' && $store->notifications[1]->attempt_count === 1 && $store->notifications[1]->next_attempt_at !== null);
    $second = $service->dispatchPending(10);
    $fail('the retry converges without resending the sent row', $second['sent'] === 1 && $store->notifications[1]->state === 'sent' && count($sent) === 3);
    $fail('notification body carries the Persian presentation date', str_contains($sent[0][2], 'آبان') && !str_contains($sent[0][2], 'November'));
    $third = $service->dispatchPending(10);
    $fail('no notification is emitted twice after convergence', $third['sent'] === 0 && count($sent) === 3);

    $adminStore = new NotificationStore();
    $adminStore->proposal = (object) array('uid' => 'PROPOSAL-2', 'student_id' => 9, 'teacher_id' => 5, 'schedule_timezone' => 'Asia/Tehran', 'proposed_starts_at_utc' => '2026-11-05 15:00:00');
    $adminStore->notifications = array(
        (object) array('id' => 1, 'proposal_id' => 1, 'event_type' => 'proposed', 'audience' => 'admin', 'state' => 'queued', 'attempt_count' => 0),
    );
    $adminSent = array();
    $adminService = new LessonRescheduleNotificationService(
        $adminStore,
        static function (string $email, string $subject, string $body) use (&$adminSent): bool { $adminSent[] = array($email, $subject, $body); return true; },
        static fn(object $proposal, string $audience): array => array('email' => 'admin@example.invalid', 'name' => 'Admin'),
        static fn(): int => time()
    );
    $adminResult = $adminService->dispatchPending(10);
    $fail('admin receives the pending proposal notice', $adminResult['sent'] === 1 && str_contains($adminSent[0][2], 'از سوی مدرس'));
    $fail('admin reschedule recipient is resolved from the site administrator address', str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/platform/src/Integrations/Notifications/LessonRescheduleNotificationService.php'), "get_option('admin_email')"));

    echo "Lesson reschedule notification unit passed\n";
}
