<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

use Delnavazan\Platform\Core\Application\CanonicalLessonScheduleValidator;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonAuthorityRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonScheduleRepository;
use Delnavazan\Platform\Portals\{PortalAccessPolicy, PortalPrincipalResolver};

/**
 * Re-proves classroom authority from the existing canonical authorities on every token request.
 *
 * Canonical term Lessons are read through PortalAccessPolicy, which composes the canonical Lesson,
 * schedule, assignment, delivery and attendance validators. Confirmed introductory bookings are
 * read through the existing BookingTeacherDeliveryRepository. This class introduces no parallel
 * lesson or booking authority.
 */
final class LiveKitClassroomAuthorizer {
    public function __construct(
        private ?LiveKitClassroomGrantPolicy $policy = null,
        private ?LiveKitMeetFallbackService $meet = null,
        private ?BookingTeacherDeliveryRepository $bookings = null,
        private ?string $signingSecret = null,
        private $clock = null
    ) {
        $this->policy ??= new LiveKitClassroomGrantPolicy();
        $this->meet ??= new LiveKitMeetFallbackService();
        $this->bookings ??= new BookingTeacherDeliveryRepository();
    }

    /**
     * @param array{kind?:string,lesson_uid?:string,schedule_version_uid?:string,confirmation_uid?:string} $input
     */
    public function authorize(string $role, array $input): array {
        if (!in_array($role, array('teacher', 'student'), true)) throw new \InvalidArgumentException('livekit_role_not_permitted');
        $kind = (string)($input['kind'] ?? 'lesson');
        $principal = (new PortalPrincipalResolver())->resolve($role);
        $facts = $kind === 'booking'
            ? $this->bookingFacts($role, $principal, (string)($input['confirmation_uid'] ?? ''))
            : $this->lessonFacts($role, $principal, (string)($input['lesson_uid'] ?? ''), (string)($input['schedule_version_uid'] ?? ''));
        return $this->policy->authorize($facts, $role, $principal, $this->now(), $this->secret());
    }

    /** Lifecycle revalidation used by the signed LiveKit webhook. */
    public function grantStillActive(array $grant): bool {
        $now = $this->now();
        if ((string)($grant['kind'] ?? '') === 'booking') {
            $role = (string)($grant['role'] ?? '');
            if (!in_array($role, array('teacher', 'student'), true)) $role = 'teacher';
            $participantId = $role === 'teacher' ? (int)($grant['teacher_id'] ?? 0) : (int)($grant['student_id'] ?? 0);
            if (!in_array($role, array('teacher', 'student'), true) || $participantId < 1) return false;
            $row = $this->bookings->confirmedParticipantContext((string)($grant['resource_uid'] ?? ''), $role, $participantId);
            if (!$row) return false;
            return $this->insideWindow((string)$row->starts_at_utc, (string)$row->ends_at_utc, $now);
        }
        $lesson = (new CanonicalLessonAuthorityRepository())->lesson((int)($grant['resource_id'] ?? 0));
        if (!$lesson || (string)$lesson->record_model !== 'canonical_term_lesson_v1' || (string)$lesson->lifecycle_state !== 'authorised') return false;
        $lessons = new CanonicalLessonAuthorityRepository();
        $schedules = new CanonicalLessonScheduleRepository();
        if (!CanonicalLessonScheduleValidator::validForLesson((int)$lesson->id, $schedules, $lessons)) return false;
        $active = $schedules->applicableVersion((int)$lesson->id);
        if (!$active || (string)$active->uid !== (string)($grant['schedule_key'] ?? '')) return false;
        return $this->insideWindow((string)$active->starts_at_utc, (string)$active->ends_at_utc, $now);
    }

    private function lessonFacts(string $role, array $principal, string $lessonUid, string $scheduleUid): array {
        $lessonUid = trim($lessonUid);
        $scheduleUid = trim($scheduleUid);
        if (!preg_match('/^[0-9A-Z]{26}$/D', $lessonUid) || !preg_match('/^[0-9A-Z]{26}$/D', $scheduleUid)) {
            throw new \InvalidArgumentException('portal_object_not_portal_visible');
        }
        global $wpdb;
        if (!isset($wpdb->prefix)) throw new \RuntimeException('livekit_authority_unavailable');
        $lessonRow = $wpdb->get_row($wpdb->prepare(
            "SELECT id,teacher_id,student_id,lesson_type FROM {$wpdb->prefix}dzn_lessons WHERE uid=%s AND archived_at IS NULL AND record_model='canonical_term_lesson_v1' LIMIT 1",
            $lessonUid
        ));
        if (!$lessonRow || $wpdb->last_error !== '') throw new \InvalidArgumentException('portal_object_not_portal_visible');
        $projection = PortalAccessPolicy::assertObject($role . '_portal', 'lesson', (int)$lessonRow->id, $principal);
        if ((string)($projection['lifecycle_state'] ?? '') !== 'authorised') {
            throw new \InvalidArgumentException('livekit_lesson_not_joinable');
        }
        $scheduleRow = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dzn_canonical_lesson_schedule_versions WHERE uid=%s AND lesson_id=%d AND applicable_slot=1 AND superseded_at IS NULL LIMIT 1",
            $scheduleUid,
            (int)$lessonRow->id
        ));
        if (!$scheduleRow || $wpdb->last_error !== '') throw new \InvalidArgumentException('livekit_stale_schedule');
        $facts = array(
            'kind' => 'lesson',
            'resource_id' => (int)$lessonRow->id,
            'resource_uid' => (string)$projection['lesson_uid'],
            'schedule_key' => (string)$projection['schedule_version_uid'],
            'requested_schedule_key' => $scheduleUid,
            'teacher_id' => (int)$lessonRow->teacher_id,
            'student_id' => (int)$lessonRow->student_id,
            'teacher_name' => (string)($projection['teacher_name'] ?? ''),
            'student_name' => (string)($projection['student_name'] ?? ''),
            'course_name' => (string)($projection['instrument_name'] ?? $projection['course_name'] ?? ''),
            'starts_at_utc' => (string)$projection['starts_at_utc'],
            'ends_at_utc' => (string)$projection['ends_at_utc'],
            'joinable' => true,
            'is_replacement' => (string)($lessonRow->lesson_type ?? '') === 'replacement' || (string)($projection['lesson_kind'] ?? '') === 'replacement',
            'meet_available' => $this->meet->lessonAvailable((int)$lessonRow->id, (int)$scheduleRow->id),
        );
        if ($this->insideWindow($facts['starts_at_utc'], $facts['ends_at_utc'], $this->now()) !== true) {
            throw new \InvalidArgumentException('livekit_outside_window');
        }
        return $facts;
    }

    private function bookingFacts(string $role, array $principal, string $confirmationUid): array {
        $confirmationUid = trim($confirmationUid);
        if (!preg_match('/^[0-9A-Z]{26}$/D', $confirmationUid)) throw new \InvalidArgumentException('portal_object_not_portal_visible');
        $row = $this->bookings->confirmedParticipantContext($confirmationUid, $role, (int)$principal['id']);
        if (!$row) throw new \InvalidArgumentException('portal_object_not_owned');
        if ($this->insideWindow((string)$row->starts_at_utc, (string)$row->ends_at_utc, $this->now()) !== true) {
            throw new \InvalidArgumentException('livekit_outside_window');
        }
        return array(
            'kind' => 'booking',
            'resource_id' => (int)$row->confirmation_id,
            'resource_uid' => (string)$row->confirmation_uid,
            'schedule_key' => 'booking:' . (int)$row->confirmation_id . ':' . (int)$row->confirmation_version,
            'teacher_id' => (int)$row->teacher_id,
            'student_id' => (int)$row->student_id,
            'teacher_name' => (string)$row->teacher_name,
            'student_name' => (string)$row->student_name,
            'course_name' => (string)$row->course_name,
            'starts_at_utc' => (string)$row->starts_at_utc,
            'ends_at_utc' => (string)$row->ends_at_utc,
            'joinable' => true,
            'is_replacement' => false,
            'meet_available' => $this->meet->bookingAvailable(
                isset($row->projection_secret_id) ? (int)$row->projection_secret_id : null,
                (string)($row->projection_secret_state ?? '')
            ),
        );
    }

    private function insideWindow(string $startsAtUtc, string $endsAtUtc, int $now): bool {
        $start = strtotime($startsAtUtc . ' UTC');
        $end = strtotime($endsAtUtc . ' UTC');
        return $start !== false && $end !== false && $end > $start
            && $now >= $start - LiveKitClassroomGrantPolicy::EARLY_SECONDS
            && $now <= $end + LiveKitClassroomGrantPolicy::LATE_SECONDS;
    }

    private function secret(): string {
        if ($this->signingSecret !== null && $this->signingSecret !== '') return $this->signingSecret;
        return (string)wp_salt('auth');
    }

    private function now(): int {
        return $this->clock !== null ? (int)($this->clock)() : time();
    }
}
