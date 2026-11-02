<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\EnrolmentRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\LessonRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\TermRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Narrow historical-course import authority.
 *
 * A course that is already running in the old system is represented in the existing legacy
 * Enrolment/Term/Lesson compatibility model. It is never disguised as a newly confirmed
 * introductory booking. Past attendance and payment are not manufactured: the import records
 * only what a person explicitly evidenced and otherwise stores "historical status unknown" in
 * append-only audit evidence.
 */
final class HistoricalEnrolmentImportService {
    private const CAPABILITY = 'dzn_manage_enrolments';
    private const PAYMENT_STATES = array('unknown', 'not_required', 'pending', 'paid', 'failed', 'refunded');
    private const ATTENDANCE_STATES = array('historical_status_unknown', 'evidence_provided');

    public function __construct(
        private ?EnrolmentRepository $enrolments = null,
        private ?TermRepository $terms = null,
        private ?LessonRepository $lessons = null
    ) {
        $this->enrolments ??= new EnrolmentRepository();
        $this->terms ??= new TermRepository();
        $this->lessons ??= new LessonRepository();
    }

    /** @return array<string,mixed> */
    public function preview(array $input): array {
        $this->guard();
        $data = $this->normalize($input);
        $this->assertNoDuplicate($data);
        $this->assertTeacherAndCourse($data);
        $slots = $this->futureSlots($data);
        $this->assertNoScheduleConflicts($data['teacher_id'], $data['course_id'], $slots);
        return array(
            'student_id' => $data['student_id'],
            'teacher_id' => $data['teacher_id'],
            'course_id' => $data['course_id'],
            'term_label' => 'Imported Term 1',
            'completed_classes' => $data['completed_classes'],
            'remaining_classes' => $data['remaining_classes'],
            'replacement_allowance' => $data['replacement_allowance'],
            'payment_status' => $data['payment_status'],
            'attendance_evidence_state' => $data['attendance_evidence_state'],
            'slots' => $slots,
            'warnings' => array_merge(
                $data['attendance_evidence_state'] === 'historical_status_unknown' ? array('Past attendance is recorded as historical status unknown; no past lesson will be marked attended.') : array(),
                $data['payment_status'] === 'unknown' ? array('Historical payment is recorded as unknown; the import does not claim the course was paid.') : array()
            ),
        );
    }

    /** @return array<string,mixed> */
    public function import(array $input, string $idempotencyKey): array {
        $this->guard();
        $key = trim($idempotencyKey);
        if ($key === '' || strlen($key) > 191) throw new \InvalidArgumentException('Import idempotency key required');
        $data = $this->normalize($input);
        $digest = hash_hmac('sha256', 'historical-enrolment-import:' . $key, wp_salt('dzn_platform_audit'));
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dzn_platform_audit_events WHERE idempotency_key=%s",
            $digest
        ));
        if ($existing) {
            $detail = json_decode((string) $existing->safe_detail, true);
            return array(
                'enrolment_id' => (int) ($detail['enrolment_id'] ?? 0),
                'term_id' => (int) ($detail['term_id'] ?? 0),
                'lesson_ids' => array_map('intval', (array) ($detail['lesson_ids'] ?? array())),
                'completed_classes' => (int) ($detail['completed_classes'] ?? 0),
                'remaining_classes' => (int) ($detail['remaining_classes'] ?? 0),
                'idempotent' => true,
            );
        }
        $this->assertNoDuplicate($data);
        $this->assertTeacherAndCourse($data);
        $slots = $this->futureSlots($data);
        $this->assertNoScheduleConflicts($data['teacher_id'], $data['course_id'], $slots);
        $now = gmdate('Y-m-d H:i:s');
        $actor = (int) get_current_user_id();
        $this->enrolments->begin();
        try {
            $enrolmentId = $this->enrolments->insertLegacyBootstrap(array(
                'uid' => Identifier::uid(),
                'reference_code' => null,
                'student_id' => $data['student_id'],
                'teacher_id' => $data['teacher_id'],
                'course_id' => $data['course_id'],
                'status' => 'active',
                'started_at' => $data['original_start_date'] . ' 00:00:00',
                'ended_at' => null,
                'preferred_weekday' => $data['weekday'],
                'preferred_local_time' => $data['local_time'] . ':00',
                'schedule_timezone' => $data['timezone'],
                'record_model' => 'legacy_phase1',
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $actor,
                'updated_by' => $actor,
            ));
            $this->enrolments->assignReference($enrolmentId, Identifier::reference('ENR-', $enrolmentId));
            $this->enrolments->commit();
        } catch (\Throwable $exception) {
            $this->enrolments->rollback();
            throw $exception;
        }
        $totalClasses = max(1, $data['completed_classes'] + $data['remaining_classes']);
        $termId = (new TermService())->create(array(
            'enrolment_id' => $enrolmentId,
            'sequence_number' => 1,
            'status' => 'active',
            'lesson_allocation' => $totalClasses,
            'replacement_allowance' => $data['replacement_allowance'],
            'payment_state' => $data['payment_status'] === 'unknown' ? 'not_required' : $data['payment_status'],
        ));
        $lessonIds = array();
        foreach ($slots as $slot) {
            $lessonId = (new LessonService())->create(array(
                'student_id' => $data['student_id'],
                'teacher_id' => $data['teacher_id'],
                'course_id' => $data['course_id'],
                'lesson_type' => 'standard',
                'status' => 'draft',
                'enrolment_id' => $enrolmentId,
                'term_id' => $termId,
            ));
            (new LessonScheduleService())->initial($lessonId, array(
                'local_wall_date' => $slot['local_date'],
                'local_wall_time' => $slot['local_time'],
                'schedule_timezone' => $data['timezone'],
                'reason' => 'historical_enrolment_import',
            ));
            $lessonIds[] = $lessonId;
        }
        $inserted = $wpdb->insert($wpdb->prefix . 'dzn_platform_audit_events', array(
            'aggregate_type' => 'enrolment',
            'aggregate_id' => $enrolmentId,
            'event_type' => 'enrolment.historical_import',
            'actor_type' => 'administrator',
            'actor_id' => $actor,
            'reason_code' => 'historical_course_import',
            'safe_detail' => wp_json_encode(array(
                'enrolment_id' => $enrolmentId,
                'term_id' => $termId,
                'lesson_ids' => $lessonIds,
                'completed_classes' => $data['completed_classes'],
                'remaining_classes' => $data['remaining_classes'],
                'original_start_date' => $data['original_start_date'],
                'payment_status' => $data['payment_status'],
                'payment_evidence_digest' => $data['payment_evidence_reference'] !== '' ? hash('sha256', $data['payment_evidence_reference']) : null,
                'attendance_evidence_state' => $data['attendance_evidence_state'],
                'attendance_evidence_digest' => $data['attendance_evidence_reference'] !== '' ? hash('sha256', $data['attendance_evidence_reference']) : null,
                'payment_claimed_paid' => $data['payment_status'] === 'paid',
            )),
            'idempotency_key' => $digest,
            'occurred_at' => $now,
        ));
        if ($inserted === false) throw new \RuntimeException('Import evidence could not be recorded');
        return array(
            'enrolment_id' => $enrolmentId,
            'term_id' => $termId,
            'lesson_ids' => $lessonIds,
            'completed_classes' => $data['completed_classes'],
            'remaining_classes' => $data['remaining_classes'],
            'idempotent' => false,
        );
    }

    /** @return array<string,mixed> */
    private function normalize(array $input): array {
        $studentId = (int) ($input['student_id'] ?? 0);
        $teacherId = (int) ($input['teacher_id'] ?? 0);
        $courseId = (int) ($input['course_id'] ?? 0);
        if ($studentId < 1 || $teacherId < 1 || $courseId < 1) throw new \InvalidArgumentException('Student, Teacher and Course are required');
        $completed = max(0, min(200, (int) ($input['completed_classes'] ?? 0)));
        $remaining = max(1, min(200, (int) ($input['remaining_classes'] ?? 0)));
        $allowance = max(0, min(50, (int) ($input['replacement_allowance'] ?? 0)));
        $originalStart = (string) ($input['original_start_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $originalStart)) throw new \InvalidArgumentException('Original start date required');
        $nextDate = (string) ($input['next_class_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $nextDate) || $nextDate <= gmdate('Y-m-d')) throw new \InvalidArgumentException('Next class must be a future local date');
        $time = (string) ($input['local_time'] ?? '');
        $time = preg_match('/^\d{2}:\d{2}$/D', $time) ? $time . ':00' : $time;
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/D', $time)) throw new \InvalidArgumentException('Weekly local time required');
        $timezone = trim((string) ($input['timezone'] ?? ''));
        if (!in_array($timezone, timezone_identifiers_list(), true)) throw new \InvalidArgumentException('Valid IANA timezone required');
        $weekday = (int) ($input['weekday'] ?? 0);
        if ($weekday < 1 || $weekday > 7) throw new \InvalidArgumentException('Weekly class day required');
        $paymentStatus = sanitize_key((string) ($input['payment_status'] ?? 'unknown'));
        if (!in_array($paymentStatus, self::PAYMENT_STATES, true)) throw new \InvalidArgumentException('Choose the recorded payment status');
        $paymentReference = trim((string) ($input['payment_evidence_reference'] ?? ''));
        if (in_array($paymentStatus, array('paid', 'refunded'), true) && $paymentReference === '') throw new \InvalidArgumentException('Payment evidence reference is required before recording paid or refunded');
        $attendanceState = sanitize_key((string) ($input['attendance_evidence_state'] ?? ''));
        if (!in_array($attendanceState, self::ATTENDANCE_STATES, true)) throw new \InvalidArgumentException('Choose historical attendance evidence or explicit unknown status');
        $attendanceReference = trim((string) ($input['attendance_evidence_reference'] ?? ''));
        if ($attendanceState === 'evidence_provided' && $attendanceReference === '') throw new \InvalidArgumentException('Attendance evidence reference required');
        return array(
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'course_id' => $courseId,
            'completed_classes' => $completed,
            'remaining_classes' => $remaining,
            'replacement_allowance' => $allowance,
            'original_start_date' => $originalStart,
            'next_class_date' => $nextDate,
            'local_time' => $time,
            'timezone' => $timezone,
            'weekday' => $weekday,
            'payment_status' => $paymentStatus,
            'payment_evidence_reference' => $paymentReference,
            'attendance_evidence_state' => $attendanceState,
            'attendance_evidence_reference' => $attendanceReference,
        );
    }

    private function assertTeacherAndCourse(array $data): void {
        global $wpdb;
        $teacher = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dzn_teachers WHERE id=%d", $data['teacher_id']));
        if (!$teacher || (string) $teacher->status !== 'active' || $teacher->archived_at !== null) throw new \InvalidArgumentException('Choose an active Teacher');
        $course = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dzn_courses WHERE id=%d", $data['course_id']));
        if (!$course || (string) $course->status !== 'active' || $course->archived_at !== null) throw new \InvalidArgumentException('Choose an active Course');
        if (!(new TeachingEligibilityService())->effectivelyEligible($data['teacher_id'], $data['course_id'])) throw new \InvalidArgumentException('The Teacher is not eligible for this Course');
    }

    private function assertNoDuplicate(array $data): void {
        global $wpdb;
        $duplicate = $wpdb->get_var($wpdb->prepare(
            "SELECT e.id FROM {$wpdb->prefix}dzn_enrolments e
             WHERE e.student_id=%d AND e.course_id=%d AND e.teacher_id=%d
               AND e.record_model='legacy_phase1' AND e.archived_at IS NULL
               AND EXISTS (SELECT 1 FROM {$wpdb->prefix}dzn_terms t WHERE t.enrolment_id=e.id AND t.archived_at IS NULL AND t.status NOT IN ('completed','cancelled','archived'))
             LIMIT 1",
            $data['student_id'], $data['course_id'], $data['teacher_id']
        ));
        if ($duplicate) throw new \InvalidArgumentException('An active imported or legacy Enrolment already exists for this Student, Course and Teacher');
    }

    /** @return array<int,array<string,string>> */
    private function futureSlots(array $data): array {
        $zone = new \DateTimeZone($data['timezone']);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $data['next_class_date'], $zone);
        if (!$date) throw new \InvalidArgumentException('Invalid next class date');
        $slots = array();
        for ($index = 0; $index < $data['remaining_classes']; $index++) {
            $candidate = $date->modify('+' . (7 * $index) . ' days');
            $local = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $candidate->format('Y-m-d') . ' ' . $data['local_time'], $zone);
            if (!$local || $local->format('Y-m-d H:i:s') !== $candidate->format('Y-m-d') . ' ' . $data['local_time']) throw new \InvalidArgumentException('The weekly local time is invalid or ambiguous on ' . $candidate->format('Y-m-d'));
            $slots[] = array(
                'local_date' => $candidate->format('Y-m-d'),
                'local_time' => $data['local_time'],
                'timezone' => $data['timezone'],
                'starts_at_utc' => $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            );
        }
        return $slots;
    }

    private function assertNoScheduleConflicts(int $teacherId, int $courseId, array $slots): void {
        $occupancy = new CanonicalTeacherOccupancyReadService();
        global $wpdb;
        $duration = (int) $wpdb->get_var($wpdb->prepare("SELECT default_duration_minutes FROM {$wpdb->prefix}dzn_courses WHERE id=%d", $courseId));
        $duration = $duration > 0 ? $duration : 45;
        foreach ($slots as $slot) {
            $start = $slot['starts_at_utc'];
            $end = (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))->modify('+' . $duration . ' minutes')->format('Y-m-d H:i:s');
            if ($occupancy->overlapping($teacherId, $start, $end)) throw new \InvalidArgumentException('The Teacher already has a class at ' . $slot['local_date'] . ' ' . substr($slot['local_time'], 0, 5) . '. Choose another schedule.');
            try {
                CanonicalContinuationCapacityAuthority::assertNoActiveHold($teacherId, $start, $end);
            } catch (\InvalidArgumentException $exception) {
                if ($exception->getMessage() === 'teacher_slot_conflict') throw new \InvalidArgumentException('A reserved continuation slot conflicts at ' . $slot['local_date'] . '. Choose another schedule.');
                throw $exception;
            }
        }
    }

    private function guard(): void {
        foreach (array(self::CAPABILITY, 'dzn_manage_terms', 'dzn_manage_lessons') as $capability) {
            if (!current_user_can($capability)) throw new \RuntimeException('Unauthorized');
        }
    }
}
