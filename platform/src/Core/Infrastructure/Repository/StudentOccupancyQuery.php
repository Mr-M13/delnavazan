<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

use Delnavazan\Platform\Core\Application\CanonicalLessonScheduleValidator;

/**
 * One read model for Student occupancy.
 *
 * Canonical scheduling, legacy scheduling, rescheduling, replacement issuance,
 * introductory intake and Teacher acceptance all ask this class instead of
 * re-implementing the overlap rules. Callers must already hold the per-Student
 * scheduling root and their own transaction where the result authorises a write.
 */
final class StudentOccupancyQuery {
    public const SOURCE_CANONICAL = 'canonical_lesson';
    public const SOURCE_LEGACY = 'legacy_lesson';
    public const SOURCE_CONFIRMATION = 'booking_confirmation';
    public const SOURCE_REQUEST = 'booking_request';

    /**
     * @param list<array{starts_at_utc:string,occupied_ends_at_utc:string}> $times
     * @param array{exclude_lesson_id?:int,exclude_booking_request_id?:int,include_open_requests?:bool} $options
     * @return list<array{source:string,conflict_id:int,starts_at_utc:string,occupied_ends_at_utc:string}>
     */
    public static function conflicts(int $studentId, array $times, array $options = array()): array {
        if ( $studentId < 1 ) return array();
        $excludeLessonId = max(0, (int) ($options['exclude_lesson_id'] ?? 0));
        $excludeBookingRequestId = max(0, (int) ($options['exclude_booking_request_id'] ?? 0));
        $includeOpenRequests = (bool) ($options['include_open_requests'] ?? false);
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $conflicts = array();
        foreach ( $times as $time ) {
            $start = (string) ( $time['starts_at_utc'] ?? '' );
            $end = (string) ( $time['occupied_ends_at_utc'] ?? '' );
            if ( ! CanonicalLessonScheduleValidator::utc( $start ) || ! CanonicalLessonScheduleValidator::utc( $end ) || $end <= $start ) throw new \InvalidArgumentException( 'student_occupancy_interval_invalid' );
            $sql = "SELECT '".self::SOURCE_CANONICAL."' source,l.id conflict_id,v.starts_at_utc,v.occupied_ends_at_utc
                      FROM {$p}lessons l
                      INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
                     WHERE l.student_id=%d AND l.id<>%d AND l.archived_at IS NULL
                       AND v.starts_at_utc<%s AND v.occupied_ends_at_utc>%s
                    UNION ALL
                    SELECT '".self::SOURCE_LEGACY."',l.id,v.starts_at_utc,DATE_ADD(v.ends_at_utc,INTERVAL 15 MINUTE)
                      FROM {$p}lessons l
                      INNER JOIN {$p}lesson_schedule_versions v ON v.id=l.current_schedule_version_id AND v.lesson_id=l.id AND v.superseded_at IS NULL
                     WHERE l.student_id=%d AND l.id<>%d AND l.record_model='legacy_phase1'
                       AND l.status IN ('scheduled','completed') AND l.archived_at IS NULL
                       AND v.starts_at_utc<%s AND DATE_ADD(v.ends_at_utc,INTERVAL 15 MINUTE)>%s
                    UNION ALL
                    SELECT '".self::SOURCE_CONFIRMATION."',c.id,rt.starts_at_utc,rt.occupied_ends_at_utc
                      FROM {$p}booking_request_confirmations c
                      INNER JOIN {$p}booking_requests r ON r.id=c.booking_request_id
                      INNER JOIN {$p}booking_request_requested_times rt ON rt.id=c.requested_time_id AND rt.booking_request_id=r.id
                     WHERE r.student_id=%d AND r.privacy_erased_at IS NULL
                       AND r.id<>%d AND c.booking_request_id<>%d
                       AND c.state IN ('pending_projection','awaiting_conference','retryable','awaiting_payment','confirmed')
                       AND rt.starts_at_utc<%s AND rt.occupied_ends_at_utc>%s";
            $args = array(
                $studentId, $excludeLessonId, $end, $start,
                $studentId, $excludeLessonId, $end, $start,
                $studentId, $excludeBookingRequestId, $excludeBookingRequestId, $end, $start,
            );
            if ( $includeOpenRequests ) {
                // Intake is the only caller that treats an unresolved request as a
                // reservation. Scheduling paths must not let two historical dupes
                // block each other forever.
                $sql .= " UNION ALL
                    SELECT '".self::SOURCE_REQUEST."',r.id,rt.starts_at_utc,rt.occupied_ends_at_utc
                      FROM {$p}booking_requests r
                      INNER JOIN {$p}booking_request_requested_times rt ON rt.booking_request_id=r.id
                     WHERE r.student_id=%d AND r.privacy_erased_at IS NULL AND r.id<>%d
                       AND r.lifecycle_status='submitted' AND r.resolution_state='unresolved'
                       AND rt.starts_at_utc<%s AND rt.occupied_ends_at_utc>%s";
                $args = array_merge( $args, array( $studentId, $excludeBookingRequestId, $end, $start ) );
            }
            $sql .= ' LIMIT 20';
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
            if ( $wpdb->last_error !== '' ) throw new \RuntimeException( 'Student occupancy read failed' );
            foreach ( (array) $rows as $row ) $conflicts[] = array(
                'source' => (string) $row['source'],
                'conflict_id' => (int) $row['conflict_id'],
                'starts_at_utc' => (string) $row['starts_at_utc'],
                'occupied_ends_at_utc' => (string) $row['occupied_ends_at_utc'],
            );
            if ( $conflicts ) break;
        }
        return $conflicts;
    }
}
