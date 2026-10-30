<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

use Delnavazan\Platform\Core\Application\ConfirmedIntroBookingReadPort;

/** Persistence and bounded read projection for teacher-facing booking requests. */
final class BookingTeacherDeliveryRepository implements ConfirmedIntroBookingReadPort {
    private string $prefix;

    public function __construct() { global $wpdb; $this->prefix = $wpdb->prefix . 'dzn_'; }

    public function create(array $data): int {
        global $wpdb;
        if ($wpdb->insert($this->prefix . 'booking_request_teacher_deliveries', $data) === false) {
            throw new \RuntimeException('Booking request teacher delivery persistence failed');
        }
        return (int) $wpdb->insert_id;
    }

    /** One public request is delivered to at most one Teacher and remains a request, never a Lesson. */
    public function pendingForTeacher(int $teacherId, int $limit = 20): array {
        global $wpdb;
        $limit = max(1, min(50, $limit));
        $sql = "SELECT d.uid delivery_uid,d.state delivery_state,d.match_strength,d.created_at,
                       r.id booking_request_id,r.reference_code request_reference,s.full_name,
                       COALESCE(NULLIF(i.name_fa,''),i.name_en) instrument_name,
                       COALESCE(NULLIF(c.name_fa,''),c.name_en) course_name,
                       rt.local_date,rt.local_start_time,rt.timezone student_timezone,
                       rt.starts_at_utc,rt.instructional_ends_at_utc,
                       p.timezone teacher_timezone
                FROM {$this->prefix}booking_request_teacher_deliveries d
                INNER JOIN {$this->prefix}booking_requests r ON r.id=d.booking_request_id
                INNER JOIN {$this->prefix}booking_request_requested_times rt ON rt.id=d.requested_time_id AND rt.booking_request_id=r.id
                INNER JOIN {$this->prefix}booking_request_contact_snapshots s ON s.booking_request_id=r.id AND s.snapshot_sequence=1
                INNER JOIN {$this->prefix}instruments i ON i.id=r.requested_instrument_id
                LEFT JOIN {$this->prefix}courses c ON c.id=r.selected_intro_course_id
                INNER JOIN {$this->prefix}teacher_availability_profiles p ON p.teacher_id=d.teacher_id
                WHERE d.teacher_id=%d AND d.state='pending_request'
                  AND r.lifecycle_status='submitted' AND r.resolution_state='unresolved' AND r.privacy_erased_at IS NULL
                ORDER BY rt.starts_at_utc ASC,d.id ASC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $teacherId, $limit));
        if ($wpdb->last_error !== '') throw new \RuntimeException('Teacher booking inbox unavailable');
        return is_array($rows) ? $rows : array();
    }

    /** All ranked student preferences remain visible to the selected Teacher. */
    public function requestedTimes(int $requestId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT sequence_number,local_date,local_start_time,timezone,starts_at_utc
             FROM {$this->prefix}booking_request_requested_times
             WHERE booking_request_id=%d
             ORDER BY sequence_number ASC LIMIT 3",
            $requestId
        ), ARRAY_A);
        if ($wpdb->last_error !== '') throw new \RuntimeException('Teacher booking preference read unavailable');
        return is_array($rows) ? $rows : array();
    }

    /**
     * Provider-complete introductory classes for one Teacher's upcoming schedule.
     *
     * A response is not a confirmed class merely because the Teacher accepted it.
     * The full confirmation/delivery/request chain and its active vaulted provider
     * reference must agree before this read projection exposes the booking.
     */
    public function confirmedUpcomingForTeacher(int $teacherId, int $limit = 50, ?string $fromUtc = null, ?string $toUtc = null): array {
        global $wpdb;
        $limit = max(1, min(80, $limit));
        $window = null !== $fromUtc && null !== $toUtc;
        $bounds = $window ? ' AND c.starts_at_utc>=%s AND c.starts_at_utc<%s' : '';
        // A bounded local-day read must include earlier completed classes from today. Only the
        // unbounded compatibility read needs the short late-join history window.
        $historyWindow = $window ? '' : ' AND c.ends_at_utc>=UTC_TIMESTAMP() - INTERVAL 2 HOUR';
        $args = array($teacherId);
        if ($window) { $args[] = $fromUtc; $args[] = $toUtc; }
        $args[] = $limit;
        $sql = "SELECT c.id confirmation_id,c.uid confirmation_uid,c.teacher_id,c.state confirmation_state,
                       c.starts_at_utc,c.ends_at_utc,c.schedule_timezone,c.provider_code,
                       c.provider_event_digest,c.confirmed_at,
                       d.teacher_id delivery_teacher_id,d.state delivery_state,
                       r.reference_code request_reference,r.lifecycle_status,r.resolution_state,r.privacy_erased_at,
                       s.full_name student_name,s.full_name student_display_reference,
                       COALESCE(NULLIF(i.name_fa,''),i.name_en,NULLIF(course.name_fa,''),course.name_en) instrument_name,
                       COALESCE(NULLIF(course.name_fa,''),NULLIF(course.name_en,''),NULLIF(i.name_fa,''),i.name_en) course_reference,
                       secret.id projection_secret_id,secret.state projection_secret_state,
                       secret.key_version projection_secret_key_version,secret.cipher_version projection_secret_cipher_version,
                       secret.nonce projection_secret_nonce,secret.ciphertext projection_secret_ciphertext
                FROM {$this->prefix}booking_request_confirmations c
                INNER JOIN {$this->prefix}booking_request_teacher_deliveries d
                        ON d.id=c.delivery_id
                       AND d.booking_request_id=c.booking_request_id
                       AND d.requested_time_id=c.requested_time_id
                       AND d.teacher_id=c.teacher_id
                INNER JOIN {$this->prefix}booking_requests r ON r.id=c.booking_request_id
                INNER JOIN {$this->prefix}booking_request_contact_snapshots s
                        ON s.booking_request_id=r.id AND s.snapshot_sequence=1
                INNER JOIN {$this->prefix}instruments i ON i.id=r.requested_instrument_id
                LEFT JOIN {$this->prefix}courses course ON course.id=r.selected_intro_course_id
                LEFT JOIN {$this->prefix}provider_projection_secrets secret
                        ON secret.mapping_kind='booking_calendar'
                       AND secret.mapping_id=c.id
                       AND secret.state='active'
                       AND secret.active_slot=1
                WHERE c.teacher_id=%d
                  AND c.state='confirmed'
                  AND d.state='confirmed'
                  AND r.lifecycle_status='closed'
                  AND r.resolution_state='teacher_confirmed'
                  AND r.privacy_erased_at IS NULL
                  AND c.provider_code='google_calendar'
                  AND c.provider_event_digest IS NOT NULL
                  AND c.confirmed_at IS NOT NULL{$historyWindow}{$bounds}
                ORDER BY c.starts_at_utc ASC,c.id ASC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args));
        if ($wpdb->last_error !== '') throw new \RuntimeException('Teacher confirmed booking schedule unavailable');
        return is_array($rows) ? $rows : array();
    }

    /** Provider-complete introductory classes owned by one resolved Student. */
    public function confirmedUpcomingForStudent(int $studentId, int $limit = 50): array {
        global $wpdb;
        $limit = max(1, min(80, $limit));
        $sql = "SELECT c.id confirmation_id,c.uid confirmation_uid,c.teacher_id,c.state confirmation_state,
                       c.starts_at_utc,c.ends_at_utc,c.schedule_timezone,c.provider_code,
                       c.provider_event_digest,c.confirmed_at,
                       d.teacher_id delivery_teacher_id,d.state delivery_state,
                       r.student_id,r.reference_code request_reference,r.lifecycle_status,r.resolution_state,r.privacy_erased_at,
                       COALESCE(NULLIF(t.display_name,''),t.reference_code) teacher_display_reference,
                       COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),NULLIF(t.persian_name,''),t.reference_code) teacher_name,
                       COALESCE(NULLIF(i.name_fa,''),i.name_en,NULLIF(course.name_fa,''),course.name_en) instrument_name,
                       COALESCE(NULLIF(course.name_fa,''),NULLIF(course.name_en,''),NULLIF(i.name_fa,''),i.name_en) course_reference,
                       secret.id projection_secret_id,secret.state projection_secret_state,
                       secret.key_version projection_secret_key_version,secret.cipher_version projection_secret_cipher_version,
                       secret.nonce projection_secret_nonce,secret.ciphertext projection_secret_ciphertext
                FROM {$this->prefix}booking_request_confirmations c
                INNER JOIN {$this->prefix}booking_request_teacher_deliveries d
                        ON d.id=c.delivery_id
                       AND d.booking_request_id=c.booking_request_id
                       AND d.requested_time_id=c.requested_time_id
                       AND d.teacher_id=c.teacher_id
                INNER JOIN {$this->prefix}booking_requests r ON r.id=c.booking_request_id
                INNER JOIN {$this->prefix}booking_request_identity_resolution_events identity
                        ON identity.id=r.current_identity_resolution_id
                       AND identity.booking_request_id=r.id
                       AND identity.student_id=r.student_id
                       AND identity.outcome='resolved'
                INNER JOIN {$this->prefix}teachers t ON t.id=c.teacher_id AND t.status='active' AND t.archived_at IS NULL
                INNER JOIN {$this->prefix}instruments i ON i.id=r.requested_instrument_id
                LEFT JOIN {$this->prefix}courses course ON course.id=r.selected_intro_course_id
                LEFT JOIN {$this->prefix}provider_projection_secrets secret
                        ON secret.mapping_kind='booking_calendar'
                       AND secret.mapping_id=c.id
                       AND secret.state='active'
                       AND secret.active_slot=1
                WHERE r.student_id=%d
                  AND c.state='confirmed'
                  AND d.state='confirmed'
                  AND r.lifecycle_status='closed'
                  AND r.resolution_state='teacher_confirmed'
                  AND r.privacy_erased_at IS NULL
                  AND c.provider_code='google_calendar'
                  AND c.provider_event_digest IS NOT NULL
                  AND c.confirmed_at IS NOT NULL
                  AND c.ends_at_utc>=UTC_TIMESTAMP() - INTERVAL 2 HOUR
                ORDER BY c.starts_at_utc ASC,c.id ASC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $studentId, $limit));
        if ($wpdb->last_error !== '') throw new \RuntimeException('Student confirmed booking schedule unavailable');
        return is_array($rows) ? $rows : array();
    }

    /**
     * Provider-confirmed introductory bookings for the operator Classes/Academy schedule.
     *
     * The booking is authoritative in its own confirmation chain until the post-intro workflow
     * creates the canonical Lesson. This read deliberately does not select provider ciphertext:
     * only the presence of a join reference is exposed as a safe boolean.
     */
    public function confirmedUpcomingForOperator(int $limit = 80): array {
        global $wpdb;
        $limit = max(1, min(80, $limit));
        $sql = "SELECT c.id confirmation_id,c.uid confirmation_uid,c.teacher_id,c.state confirmation_state,
                       c.starts_at_utc,c.ends_at_utc,c.schedule_timezone,c.local_wall_date,c.local_wall_time,
                       c.provider_code,c.confirmed_at,
                       d.state delivery_state,r.id booking_request_id,r.student_id,
                       r.reference_code request_reference,r.lifecycle_status,r.resolution_state,r.privacy_erased_at,
                       COALESCE(NULLIF(s.display_name,''),NULLIF(snap.full_name,'')) student_display_reference,
                       COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_display_reference,
                       COALESCE(NULLIF(course.name_fa,''),NULLIF(course.name_en,''),NULLIF(i.name_fa,''),i.name_en) course_reference,
                       secret.id projection_secret_id
                FROM {$this->prefix}booking_request_confirmations c
                INNER JOIN {$this->prefix}booking_request_teacher_deliveries d
                        ON d.id=c.delivery_id
                       AND d.booking_request_id=c.booking_request_id
                       AND d.requested_time_id=c.requested_time_id
                       AND d.teacher_id=c.teacher_id
                INNER JOIN {$this->prefix}booking_requests r ON r.id=c.booking_request_id
                INNER JOIN {$this->prefix}booking_request_contact_snapshots snap
                        ON snap.booking_request_id=r.id AND snap.snapshot_sequence=1
                INNER JOIN {$this->prefix}instruments i ON i.id=r.requested_instrument_id
                LEFT JOIN {$this->prefix}students s ON s.id=r.student_id
                LEFT JOIN {$this->prefix}teachers t ON t.id=c.teacher_id
                LEFT JOIN {$this->prefix}courses course ON course.id=r.selected_intro_course_id
                LEFT JOIN {$this->prefix}provider_projection_secrets secret
                        ON secret.mapping_kind='booking_calendar'
                       AND secret.mapping_id=c.id
                       AND secret.state='active'
                       AND secret.active_slot=1
                WHERE c.state='confirmed'
                  AND d.state='confirmed'
                  AND r.lifecycle_status='closed'
                  AND r.resolution_state='teacher_confirmed'
                  AND r.privacy_erased_at IS NULL
                  AND c.provider_code='google_calendar'
                  AND c.provider_event_digest IS NOT NULL
                  AND c.confirmed_at IS NOT NULL
                  AND c.ends_at_utc>=UTC_TIMESTAMP() - INTERVAL 2 HOUR
                  AND NOT EXISTS (
                      SELECT 1 FROM {$this->prefix}lessons l
                      WHERE l.booking_request_id=r.id AND l.archived_at IS NULL
                  )
                ORDER BY c.starts_at_utc ASC,c.id ASC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $limit));
        if ($wpdb->last_error !== '') throw new \RuntimeException('Operator confirmed booking schedule unavailable');
        return is_array($rows) ? $rows : array();
    }

    /**
     * Display-safe context for an authenticated classroom participant.
     *
     * This is the same canonical confirmation chain as confirmedForParticipant(), with the names
     * and course title needed to label a room. It never opens the vaulted Meet reference.
     */
    public function confirmedParticipantContext(string $confirmationUid, string $role, int $participantId): ?object {
        global $wpdb;
        if (!in_array($role, array('teacher','student'), true) || $participantId < 1) return null;
        $owner = $role === 'teacher' ? 'c.teacher_id' : 'r.student_id';
        $identity = $role === 'student'
            ? " INNER JOIN {$this->prefix}booking_request_identity_resolution_events identity ON identity.id=r.current_identity_resolution_id AND identity.booking_request_id=r.id AND identity.student_id=r.student_id AND identity.outcome='resolved'"
            : '';
        $sql = "SELECT c.id confirmation_id,c.uid confirmation_uid,c.version confirmation_version,c.teacher_id,
                       c.starts_at_utc,c.ends_at_utc,c.provider_code,c.provider_event_digest,c.confirmed_at,
                       d.teacher_id delivery_teacher_id,d.state delivery_state,
                       r.student_id,r.lifecycle_status,r.resolution_state,r.privacy_erased_at,
                       COALESCE(NULLIF(s.display_name,''),NULLIF(snap.full_name,''),s.reference_code,'هنرجو') student_name,
                       COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),NULLIF(t.persian_name,''),t.reference_code,'مدرس') teacher_name,
                       COALESCE(NULLIF(course.name_fa,''),NULLIF(course.name_en,''),NULLIF(i.name_fa,''),i.name_en,'کلاس موسیقی') course_name,
                       secret.id projection_secret_id,secret.state projection_secret_state
                FROM {$this->prefix}booking_request_confirmations c
                INNER JOIN {$this->prefix}booking_request_teacher_deliveries d ON d.id=c.delivery_id AND d.booking_request_id=c.booking_request_id AND d.requested_time_id=c.requested_time_id AND d.teacher_id=c.teacher_id
                INNER JOIN {$this->prefix}booking_requests r ON r.id=c.booking_request_id
                {$identity}
                INNER JOIN {$this->prefix}booking_request_contact_snapshots snap ON snap.booking_request_id=r.id AND snap.snapshot_sequence=1
                INNER JOIN {$this->prefix}teachers t ON t.id=c.teacher_id
                LEFT JOIN {$this->prefix}students s ON s.id=r.student_id
                INNER JOIN {$this->prefix}instruments i ON i.id=r.requested_instrument_id
                LEFT JOIN {$this->prefix}courses course ON course.id=r.selected_intro_course_id
                LEFT JOIN {$this->prefix}provider_projection_secrets secret ON secret.mapping_kind='booking_calendar' AND secret.mapping_id=c.id AND secret.state='active' AND secret.active_slot=1
                WHERE c.uid=%s AND {$owner}=%d AND c.state='confirmed' AND d.state='confirmed'
                  AND r.lifecycle_status='closed' AND r.resolution_state='teacher_confirmed' AND r.privacy_erased_at IS NULL
                  AND c.provider_code='google_calendar' AND c.provider_event_digest IS NOT NULL AND c.confirmed_at IS NOT NULL
                LIMIT 1";
        $row = $wpdb->get_row($wpdb->prepare($sql, $confirmationUid, $participantId));
        if ($wpdb->last_error !== '') throw new \RuntimeException('Confirmed booking classroom context unavailable');
        return $row ?: null;
    }

    /** One confirmed booking row for an authenticated join action. */
    public function confirmedForParticipant(string $confirmationUid, string $role, int $participantId): ?object {
        global $wpdb;
        if (!in_array($role, array('teacher','student'), true) || $participantId < 1) return null;
        $owner = $role === 'teacher' ? 'c.teacher_id' : 'r.student_id';
        $identity = $role === 'student'
            ? " INNER JOIN {$this->prefix}booking_request_identity_resolution_events identity ON identity.id=r.current_identity_resolution_id AND identity.booking_request_id=r.id AND identity.student_id=r.student_id AND identity.outcome='resolved'"
            : '';
        $sql = "SELECT c.id confirmation_id,c.uid confirmation_uid,c.teacher_id,c.state confirmation_state,
                       c.starts_at_utc,c.ends_at_utc,c.provider_code,c.provider_event_digest,c.confirmed_at,
                       d.teacher_id delivery_teacher_id,d.state delivery_state,
                       r.student_id,r.lifecycle_status,r.resolution_state,r.privacy_erased_at,
                       secret.id projection_secret_id,secret.state projection_secret_state,
                       secret.key_version projection_secret_key_version,secret.cipher_version projection_secret_cipher_version,
                       secret.nonce projection_secret_nonce,secret.ciphertext projection_secret_ciphertext
                FROM {$this->prefix}booking_request_confirmations c
                INNER JOIN {$this->prefix}booking_request_teacher_deliveries d ON d.id=c.delivery_id AND d.booking_request_id=c.booking_request_id AND d.requested_time_id=c.requested_time_id AND d.teacher_id=c.teacher_id
                INNER JOIN {$this->prefix}booking_requests r ON r.id=c.booking_request_id
                {$identity}
                LEFT JOIN {$this->prefix}provider_projection_secrets secret ON secret.mapping_kind='booking_calendar' AND secret.mapping_id=c.id AND secret.state='active' AND secret.active_slot=1
                WHERE c.uid=%s AND {$owner}=%d AND c.state='confirmed' AND d.state='confirmed'
                  AND r.lifecycle_status='closed' AND r.resolution_state='teacher_confirmed' AND r.privacy_erased_at IS NULL
                  AND c.provider_code='google_calendar' AND c.provider_event_digest IS NOT NULL AND c.confirmed_at IS NOT NULL
                LIMIT 1";
        $row = $wpdb->get_row($wpdb->prepare($sql, $confirmationUid, $participantId));
        if ($wpdb->last_error !== '') throw new \RuntimeException('Confirmed booking join unavailable');
        return $row ?: null;
    }
}
