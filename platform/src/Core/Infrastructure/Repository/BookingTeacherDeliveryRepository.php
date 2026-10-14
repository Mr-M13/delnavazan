<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Persistence and bounded read projection for teacher-facing booking requests. */
final class BookingTeacherDeliveryRepository {
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
                       r.reference_code request_reference,s.full_name,
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
}
