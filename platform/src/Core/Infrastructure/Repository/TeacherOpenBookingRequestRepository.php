<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Read projection for requests that currently have no match but belong to one of the
 * Teacher's eligible courses. It never mutates allocation state; the claim authority
 * re-reads and locks the same request inside its own transaction.
 */
final class TeacherOpenBookingRequestRepository {
    private string $prefix;

    public function __construct() { global $wpdb; $this->prefix = $wpdb->prefix . 'dzn_'; }

    /**
     * One future preference per unmatched request, for an active Teacher eligible for the
     * request's exact course.
     *
     * Unmatched means: still submitted/unresolved, not privacy-erased, identity resolved,
     * no active allocation offer, no live confirmation, no prior offer to this Teacher and
     * at least one preference far enough ahead for the projection buffer.
     */
    public function openForTeacher(int $teacherId, int $limit = 20): array {
        global $wpdb;
        if ($teacherId < 1) { return array(); }
        $limit = max(1, min(50, $limit));
        $sql = "SELECT r.id booking_request_id,r.uid request_uid,r.reference_code request_reference,r.created_at,
                       r.requested_instrument_id,r.selected_intro_course_id,
                       s.full_name,
                       COALESCE(NULLIF(i.name_fa,''),i.name_en) instrument_name,
                       COALESCE(NULLIF(c.name_fa,''),c.name_en) course_name,
                       rt.id requested_time_id,rt.sequence_number,rt.local_date,rt.local_start_time,
                       rt.timezone student_timezone,rt.starts_at_utc,rt.instructional_ends_at_utc,rt.occupied_ends_at_utc
                FROM {$this->prefix}booking_requests r
                INNER JOIN {$this->prefix}booking_request_contact_snapshots s ON s.booking_request_id=r.id AND s.snapshot_sequence=1
                INNER JOIN {$this->prefix}booking_request_identity_resolution_events identity
                        ON identity.id=r.current_identity_resolution_id
                       AND identity.booking_request_id=r.id
                       AND identity.student_id=r.student_id
                       AND identity.outcome='resolved'
                INNER JOIN {$this->prefix}teacher_course_eligibilities e
                        ON e.course_id=r.selected_intro_course_id
                       AND e.teacher_id=%d
                       AND e.status='active'
                       AND (e.effective_from IS NULL OR e.effective_from<=UTC_TIMESTAMP())
                       AND (e.effective_until IS NULL OR e.effective_until>UTC_TIMESTAMP())
                INNER JOIN {$this->prefix}teachers t ON t.id=e.teacher_id AND t.status='active' AND t.archived_at IS NULL
                INNER JOIN {$this->prefix}teacher_onboarding_states o ON o.teacher_id=t.id AND o.state='active' AND o.readiness_state='ready'
                LEFT JOIN {$this->prefix}teacher_accepting_states a ON a.teacher_id=t.id
                INNER JOIN {$this->prefix}courses c ON c.id=r.selected_intro_course_id AND c.status='active' AND c.archived_at IS NULL
                INNER JOIN {$this->prefix}instruments i ON i.id=r.requested_instrument_id
                INNER JOIN {$this->prefix}booking_request_requested_times rt ON rt.id=(
                    SELECT rt0.id FROM {$this->prefix}booking_request_requested_times rt0
                    WHERE rt0.booking_request_id=r.id
                      AND rt0.starts_at_utc>DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3600 SECOND)
                    ORDER BY rt0.sequence_number ASC,rt0.id ASC LIMIT 1
                )
                WHERE r.lifecycle_status='submitted' AND r.resolution_state='unresolved' AND r.privacy_erased_at IS NULL
                  AND r.student_id IS NOT NULL AND r.student_id>0
                  AND r.selected_intro_course_id IS NOT NULL
                  AND COALESCE(a.state,'accepting') IN ('accepting','limited')
                  AND NOT EXISTS(SELECT 1 FROM {$this->prefix}booking_allocation_offers offer WHERE offer.booking_request_id=r.id AND offer.active_slot=1)
                  AND NOT EXISTS(SELECT 1 FROM {$this->prefix}booking_request_confirmations confirmation WHERE confirmation.booking_request_id=r.id AND confirmation.state<>'failed')
                  AND NOT EXISTS(SELECT 1 FROM {$this->prefix}booking_request_teacher_deliveries existing WHERE existing.booking_request_id=r.id AND existing.state IN ('accepting','confirmed'))
                  AND NOT EXISTS(SELECT 1 FROM {$this->prefix}booking_allocation_offers prior WHERE prior.booking_request_id=r.id AND prior.teacher_id=%d)
                ORDER BY rt.starts_at_utc ASC,r.id ASC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $teacherId, $teacherId, $limit));
        if ($wpdb->last_error !== '') { throw new \RuntimeException('Teacher open booking requests unavailable'); }
        return is_array($rows) ? $rows : array();
    }

    /** Every ranked Student preference remains visible to a Teacher considering a claim. */
    public function requestedTimes(int $requestId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,sequence_number,local_date,local_start_time,timezone,starts_at_utc,
                    instructional_ends_at_utc,occupied_ends_at_utc
             FROM {$this->prefix}booking_request_requested_times
             WHERE booking_request_id=%d
             ORDER BY sequence_number ASC LIMIT 3",
            $requestId
        ), ARRAY_A);
        if ($wpdb->last_error !== '') { throw new \RuntimeException('Teacher open booking preference read unavailable'); }
        return is_array($rows) ? $rows : array();
    }
}
