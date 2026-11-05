<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

use Delnavazan\Platform\Core\Application\CanonicalLessonScheduleValidator;

/**
 * Teacher occupancy that exists before an introductory booking becomes a
 * canonical Lesson. Pending offers reserve the Teacher briefly; a non-failed
 * confirmation reserves the Teacher until its explicit cancellation/failure.
 *
 * Callers that authorise a write must hold the per-Teacher scheduling root.
 */
final class TeacherBookingOccupancyQuery {
    /**
     * @return list<array{source:string,conflict_id:int,starts_at_utc:string,occupied_ends_at_utc:string}>
     */
    public static function conflicts(int $teacherId, string $startsAtUtc, string $occupiedEndUtc, int $excludeBookingRequestId = 0, bool $includePendingOffers = true): array {
        if ( $teacherId < 1 || ! CanonicalLessonScheduleValidator::utc( $startsAtUtc ) || ! CanonicalLessonScheduleValidator::utc( $occupiedEndUtc ) || $occupiedEndUtc <= $startsAtUtc ) {
            throw new \InvalidArgumentException( 'teacher_booking_occupancy_interval_invalid' );
        }
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $offerSql = $includePendingOffers ? "SELECT 'booking_offer' source,o.id conflict_id,rt.starts_at_utc,rt.occupied_ends_at_utc
                  FROM {$p}booking_allocation_offers o
                  INNER JOIN {$p}booking_requests r ON r.id=o.booking_request_id
                  INNER JOIN {$p}booking_request_requested_times rt ON rt.id=o.requested_time_id AND rt.booking_request_id=r.id
                 WHERE o.teacher_id=%d AND o.state='pending' AND o.active_slot=1
                   AND r.id<>%d AND r.lifecycle_status='submitted' AND r.resolution_state='unresolved' AND r.privacy_erased_at IS NULL
                   AND rt.starts_at_utc<%s AND rt.occupied_ends_at_utc>%s UNION ALL " : '';
        $sql = $offerSql . "SELECT 'booking_confirmation',c.id,rt.starts_at_utc,rt.occupied_ends_at_utc
                  FROM {$p}booking_request_confirmations c
                  INNER JOIN {$p}booking_requests r ON r.id=c.booking_request_id
                  INNER JOIN {$p}booking_request_requested_times rt ON rt.id=c.requested_time_id AND rt.booking_request_id=r.id
                 WHERE c.teacher_id=%d AND c.booking_request_id<>%d AND c.state NOT IN ('failed','cancelled')
                   AND r.privacy_erased_at IS NULL
                   AND rt.starts_at_utc<%s AND rt.occupied_ends_at_utc>%s
                 LIMIT 20";
        $args = $includePendingOffers ? array( $teacherId, $excludeBookingRequestId, $occupiedEndUtc, $startsAtUtc ) : array();
        $args = array_merge( $args, array( $teacherId, $excludeBookingRequestId, $occupiedEndUtc, $startsAtUtc ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
        if ( $wpdb->last_error !== '' ) throw new \RuntimeException( 'Teacher booking occupancy read failed' );
        return array_map( static fn( array $row ): array => array(
            'source' => (string) $row['source'], 'conflict_id' => (int) $row['conflict_id'],
            'starts_at_utc' => (string) $row['starts_at_utc'], 'occupied_ends_at_utc' => (string) $row['occupied_ends_at_utc'],
        ), (array) $rows );
    }
}
