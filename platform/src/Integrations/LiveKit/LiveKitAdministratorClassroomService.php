<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

use Delnavazan\Platform\Core\Application\CanonicalLessonScheduleValidator;
use Delnavazan\Platform\Core\Application\ProviderReferenceVault;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonAuthorityRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonScheduleRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\ProviderIntegrationRepository;

/**
 * Read-only administrator classroom projection and privileged pre-class entry.
 *
 * This is an additive administrator boundary over the existing canonical Lesson, booking,
 * Google Meet projection and LiveKit room derivation. It never creates a second classroom,
 * never writes attendance or delivery facts, and never returns an API secret. Every privileged
 * entry is audited in platform_audit_events.
 */
final class LiveKitAdministratorClassroomService {
    public const CAPABILITY = 'dzn_manage_lessons';

    public function __construct(
        private ?LiveKitClassroomGrantPolicy $policy = null,
        private ?LiveKitMeetFallbackService $meet = null,
        private ?BookingTeacherDeliveryRepository $bookings = null,
        private ?ProviderIntegrationRepository $integrations = null,
        private ?ProviderReferenceVault $vault = null,
        private $clock = null
    ) {
        $this->policy ??= new LiveKitClassroomGrantPolicy();
        $this->meet ??= new LiveKitMeetFallbackService();
        $this->bookings ??= new BookingTeacherDeliveryRepository();
        $this->integrations ??= new ProviderIntegrationRepository();
        $this->vault ??= new ProviderReferenceVault();
    }

    public function authorized(): bool {
        return function_exists('current_user_can') && current_user_can(self::CAPABILITY);
    }

    /**
     * Display-safe administrator state for one canonical Lesson.
     *
     * @return array<string,mixed>
     */
    public function forLesson(int $lessonId): array {
        if (!$this->authorized()) throw new \RuntimeException('Unauthorized');
        $empty = $this->emptyState('lesson');
        if ($lessonId < 1) return array_merge($empty, array('state' => 'not_found'));
        [$facts, $state] = $this->lessonFacts($lessonId);
        if ($facts === null) return array_merge($empty, array('state' => $state));
        return $this->shape($facts, $state);
    }

    /**
     * Display-safe administrator state for one confirmed introductory booking.
     *
     * @return array<string,mixed>
     */
    public function forBooking(string $confirmationUid): array {
        if (!$this->authorized()) throw new \RuntimeException('Unauthorized');
        $empty = $this->emptyState('booking');
        [$facts, $state] = $this->bookingFacts($confirmationUid);
        if ($facts === null) return array_merge($empty, array('state' => $state));
        return $this->shape($facts, $state);
    }

    /**
     * Short-lived administrator grant for the LiveKit launch boundary.
     *
     * The grant deliberately ignores the participant joining window. It still requires a
     * currently joinable canonical resource and is bound to the authenticated administrator.
     *
     * @return array<string,mixed>
     */
    public function grantForLesson(int $lessonId): array {
        if (!$this->authorized()) throw new \RuntimeException('Unauthorized');
        [$facts, $state] = $this->lessonFacts($lessonId);
        if ($facts === null) throw new \InvalidArgumentException('livekit_lesson_not_joinable');
        return $this->grant($facts);
    }

    /** @return array<string,mixed> */
    public function grantForBooking(string $confirmationUid): array {
        if (!$this->authorized()) throw new \RuntimeException('Unauthorized');
        [$facts, $state] = $this->bookingFacts($confirmationUid);
        if ($facts === null) throw new \InvalidArgumentException('livekit_lesson_not_joinable');
        return $this->grant($facts);
    }

    /**
     * Resolve a canonical Lesson UID to the same administrator grant used by the launch action.
     *
     * @return array<string,mixed>
     */
    public function grantForLessonUid(string $lessonUid): array {
        if (!$this->authorized()) throw new \RuntimeException('Unauthorized');
        return $this->grantForLesson($this->lessonIdForUid($lessonUid));
    }

    /** Resolve one canonical Lesson UID to its primary key without inventing a lesson. */
    public function lessonIdForUid(string $lessonUid): int {
        $lessonUid = trim($lessonUid);
        if (preg_match('/^[0-9A-Z]{26}$/D', $lessonUid) !== 1) throw new \InvalidArgumentException('livekit_lesson_not_joinable');
        global $wpdb;
        $lessonId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dzn_lessons WHERE uid=%s AND archived_at IS NULL LIMIT 1",
            $lessonUid
        ));
        if ($lessonId < 1) throw new \InvalidArgumentException('livekit_lesson_not_joinable');
        return $lessonId;
    }

    /**
     * Resolve the existing vaulted Google Meet URL for an administrator.
     *
     * This is the only administrator path that decrypts the provider reference. It is called
     * only after the capability check and is audited before the URL is returned.
     *
     * @param array{lesson_id?:int,confirmation_uid?:string} $input
     */
    public function meetUrl(string $kind, array $input): string {
        if (!$this->authorized()) throw new \RuntimeException('Unauthorized');
        if ($kind === 'lesson') {
            $lessonId = (int) ($input['lesson_id'] ?? 0);
            if ($lessonId < 1 && !empty($input['lesson_uid'])) $lessonId = $this->lessonIdForUid((string) $input['lesson_uid']);
            [$facts, $state] = $this->lessonFacts($lessonId);
            if ($facts === null) throw new \InvalidArgumentException('classroom_meet_not_provisioned');
            $scheduleVersionId = (int) ($facts['schedule_version_id'] ?? 0);
            $mapping = $this->integrations->activeCalendarMapping((int) $facts['resource_id'], $scheduleVersionId);
            if (!$mapping || (string) $mapping->provider_code !== 'google_calendar' || (string) $mapping->projection_state !== 'verified') {
                throw new \InvalidArgumentException('classroom_meet_not_provisioned');
            }
            $secret = $this->integrations->projectionSecret('calendar_event', (int) $mapping->id);
            if (!$secret || (string) $secret->state !== 'active') throw new \InvalidArgumentException('classroom_meet_not_provisioned');
            $references = $this->vault->open('calendar_event', (int) $mapping->id, $secret);
            $url = trim((string) ($references['join_uri_reference'] ?? ''));
            if ($url === '') throw new \InvalidArgumentException('classroom_meet_not_provisioned');
            $this->audit('lesson', (int) $facts['resource_id'], 'admin_classroom.meet_link_accessed', array('resource' => 'lesson'));
            return $url;
        }
        if ($kind === 'booking') {
            [$facts, $state] = $this->bookingFacts((string) ($input['confirmation_uid'] ?? ''));
            if ($facts === null) throw new \InvalidArgumentException('classroom_meet_not_provisioned');
            $secret = $this->integrations->projectionSecret('booking_calendar', (int) $facts['resource_id']);
            if (!$secret || (string) $secret->state !== 'active') throw new \InvalidArgumentException('classroom_meet_not_provisioned');
            $references = $this->vault->open('booking_calendar', (int) $facts['resource_id'], $secret);
            $url = trim((string) ($references['join_uri_reference'] ?? ''));
            if ($url === '') throw new \InvalidArgumentException('classroom_meet_not_provisioned');
            $this->audit('booking_confirmation', (int) $facts['resource_id'], 'admin_classroom.meet_link_accessed', array('resource' => 'booking'));
            return $url;
        }
        throw new \InvalidArgumentException('classroom_resource_invalid');
    }

    /**
     * A normal participant portal entry URL. It carries no administrator token and therefore
     * does not bypass participant sign-in, identity checks or the classroom joining window.
     *
     * @param array{lesson_uid?:string,confirmation_uid?:string} $context
     */
    public function participantEntryUrl(string $role, array $context): string {
        if (!in_array($role, array('student', 'teacher'), true)) throw new \InvalidArgumentException('classroom_role_invalid');
        if (!function_exists('home_url')) return '';
        $args = array();
        if (!empty($context['lesson_uid'])) $args['lesson'] = (string) $context['lesson_uid'];
        if (!empty($context['confirmation_uid'])) $args['booking'] = (string) $context['confirmation_uid'];
        $base = home_url($role === 'teacher' ? '/teacher-portal/' : '/student-portal/');
        $url = $args === array() ? $base : add_query_arg($args, $base);
        return $url . '#dzn-upcoming-title';
    }

    /**
     * @return array{0:?array<string,mixed>,1:string}
     */
    private function lessonFacts(int $lessonId): array {
        if ($lessonId < 1) return array(null, 'not_found');
        $repository = new CanonicalLessonAuthorityRepository();
        $lesson = $repository->lesson($lessonId);
        if (!$lesson || (string) ($lesson->record_model ?? '') !== 'canonical_term_lesson_v1') return array(null, 'not_supported');
        if ((string) ($lesson->lifecycle_state ?? '') !== 'authorised') return array(null, 'not_joinable');
        $schedules = new CanonicalLessonScheduleRepository();
        if (!CanonicalLessonScheduleValidator::validForLesson($lessonId, $schedules, $repository)) return array(null, 'not_joinable');
        $active = $schedules->applicableVersion($lessonId);
        if (!$active) return array(null, 'missing_schedule');
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $labels = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(NULLIF(s.display_name,''),NULLIF(s.first_name,''),s.last_name,'هنرجو') student_name,
                    COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name,'مدرس') teacher_name,
                    COALESCE(NULLIF(i.name_fa,''),NULLIF(i.name_en,''),NULLIF(c.name_fa,''),c.name_en,'کلاس موسیقی') course_name
             FROM {$p}lessons l
             LEFT JOIN {$p}students s ON s.id=l.student_id
             LEFT JOIN {$p}teachers t ON t.id=l.teacher_id
             LEFT JOIN {$p}courses c ON c.id=l.course_id
             LEFT JOIN {$p}instruments i ON i.id=c.instrument_id
             WHERE l.id=%d",
            $lessonId
        ));
        if (!$labels || $wpdb->last_error !== '') return array(null, 'unavailable');
        return array(array(
            'kind' => 'lesson',
            'resource_id' => $lessonId,
            'resource_uid' => (string) $lesson->uid,
            'lesson_uid' => (string) $lesson->uid,
            'schedule_version_id' => (int) $active->id,
            'schedule_key' => (string) $active->uid,
            'schedule_version_uid' => (string) $active->uid,
            'teacher_id' => (int) $lesson->teacher_id,
            'student_id' => (int) $lesson->student_id,
            'teacher_name' => (string) $labels->teacher_name,
            'student_name' => (string) $labels->student_name,
            'course_name' => (string) $labels->course_name,
            'starts_at_utc' => (string) $active->starts_at_utc,
            'ends_at_utc' => (string) $active->ends_at_utc,
            'joinable' => true,
            'is_replacement' => (string) ($lesson->lesson_type ?? '') === 'replacement',
            'meet_available' => $this->meet->lessonAvailable($lessonId, (int) $active->id),
        ), 'available');
    }

    /**
     * @return array{0:?array<string,mixed>,1:string}
     */
    private function bookingFacts(string $confirmationUid): array {
        $row = $this->bookings->confirmedOperatorContext($confirmationUid);
        if (!$row) return array(null, 'not_found');
        return array(array(
            'kind' => 'booking',
            'resource_id' => (int) $row->confirmation_id,
            'resource_uid' => (string) $row->confirmation_uid,
            'confirmation_uid' => (string) $row->confirmation_uid,
            'schedule_key' => 'booking:' . (int) $row->confirmation_id . ':' . (int) $row->confirmation_version,
            'teacher_id' => (int) $row->teacher_id,
            'student_id' => (int) $row->student_id,
            'teacher_name' => (string) $row->teacher_name,
            'student_name' => (string) $row->student_name,
            'course_name' => (string) $row->course_name,
            'starts_at_utc' => (string) $row->starts_at_utc,
            'ends_at_utc' => (string) $row->ends_at_utc,
            'joinable' => true,
            'is_replacement' => false,
            'meet_available' => $this->meet->bookingAvailable(
                isset($row->projection_secret_id) ? (int) $row->projection_secret_id : null,
                (string) ($row->projection_secret_state ?? '')
            ),
        ), 'available');
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function shape(array $facts, string $state): array {
        $lessonUid = (string) ($facts['lesson_uid'] ?? '');
        $confirmationUid = (string) ($facts['confirmation_uid'] ?? '');
        $context = array();
        if ($lessonUid !== '') $context['lesson_uid'] = $lessonUid;
        if ($confirmationUid !== '') $context['confirmation_uid'] = $confirmationUid;
        return array(
            'kind' => (string) $facts['kind'],
            'state' => $state,
            'available' => $state === 'available',
            'livekit_available' => $state === 'available' && ($facts['joinable'] ?? false) === true,
            'meet_available' => ($facts['meet_available'] ?? false) === true,
            'resource_id' => (int) $facts['resource_id'],
            'lesson_id' => (string) $facts['kind'] === 'lesson' ? (int) $facts['resource_id'] : 0,
            'lesson_uid' => $lessonUid,
            'schedule_version_uid' => (string) ($facts['schedule_version_uid'] ?? ''),
            'confirmation_uid' => $confirmationUid,
            'teacher_name' => (string) ($facts['teacher_name'] ?? ''),
            'student_name' => (string) ($facts['student_name'] ?? ''),
            'course_name' => (string) ($facts['course_name'] ?? ''),
            'starts_at_utc' => (string) ($facts['starts_at_utc'] ?? ''),
            'ends_at_utc' => (string) ($facts['ends_at_utc'] ?? ''),
            'student_entry_url' => $this->participantEntryUrl('student', $context),
            'teacher_entry_url' => $this->participantEntryUrl('teacher', $context),
        );
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function grant(array $facts): array {
        $grant = $this->policy->authorizeAdministrator($facts, (int) get_current_user_id(), $this->now(), $this->secret());
        $this->audit(
            (string) $facts['kind'] === 'lesson' ? 'lesson' : 'booking_confirmation',
            (int) $facts['resource_id'],
            'admin_classroom.livekit_access',
            array('resource' => (string) $facts['kind'])
        );
        return $grant;
    }

    /** @return array<string,mixed> */
    private function emptyState(string $kind): array {
        return array(
            'kind' => $kind,
            'state' => 'unavailable',
            'available' => false,
            'livekit_available' => false,
            'meet_available' => false,
            'resource_id' => 0,
            'lesson_id' => 0,
            'lesson_uid' => '',
            'schedule_version_uid' => '',
            'confirmation_uid' => '',
            'teacher_name' => '',
            'student_name' => '',
            'course_name' => '',
            'starts_at_utc' => '',
            'ends_at_utc' => '',
            'student_entry_url' => '',
            'teacher_entry_url' => '',
        );
    }

    /** Write one bounded audit fact. Failure to record a privileged entry fails closed. */
    private function audit(string $aggregateType, int $aggregateId, string $event, array $detail = array()): void {
        if (!function_exists('get_current_user_id')) return;
        $actor = (int) get_current_user_id();
        if ($actor < 1) throw new \RuntimeException('classroom_administrator_required');
        global $wpdb;
        $table = $wpdb->prefix . 'dzn_platform_audit_events';
        $bucket = (int) floor($this->now() / 300);
        $key = hash('sha256', 'admin-classroom|' . $event . '|' . $aggregateType . '|' . $aggregateId . '|' . $actor . '|' . $bucket);
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE idempotency_key=%s", $key)) > 0) return;
        $safe = json_encode(array('actor' => 'administrator') + $detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ok = $wpdb->insert($table, array(
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $event,
            'actor_type' => 'user',
            'actor_id' => $actor,
            'reason_code' => 'administrative_classroom_testing',
            'safe_detail' => is_string($safe) ? $safe : null,
            'idempotency_key' => $key,
            'occurred_at' => gmdate('Y-m-d H:i:s', $this->now()),
        ));
        if ($ok === false && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE idempotency_key=%s", $key)) === 0) {
            throw new \RuntimeException('classroom_audit_unavailable');
        }
    }

    private function secret(): string {
        return function_exists('wp_salt') ? (string) wp_salt('auth') : '';
    }

    private function now(): int {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
