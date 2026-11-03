<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\StudentOccupancyQuery;

/**
 * The single application rule for Student time ownership.
 *
 * Callers that authorise a write must hold the Student scheduling root and
 * call this inside the same transaction. The default scheduled-occupancy view
 * is confirmed/in-flight introductory classes plus canonical and legacy
 * Lessons; intake explicitly opts into unresolved requests as well.
 */
final class StudentOccupancyAuthority {
    /** @param list<array{starts_at_utc:string,occupied_ends_at_utc:string}> $times */
    public function conflicts(int $studentId, array $times, array $options = array()): array {
        return StudentOccupancyQuery::conflicts( $studentId, $times, $options );
    }

    /** @param list<array{starts_at_utc:string,occupied_ends_at_utc:string}> $times */
    public function assertAvailable(int $studentId, array $times, array $options = array()): void {
        if ( $this->conflicts( $studentId, $times, $options ) ) throw new \InvalidArgumentException( 'student_slot_conflict' );
    }

    /** Scheduled occupancy for one Lesson interval, excluding that Lesson and its own booking source. */
    public function assertLessonIntervalAvailable(int $studentId, string $startsAtUtc, string $occupiedEndUtc, int $lessonId = 0, int $bookingRequestId = 0): void {
        $options = array( 'exclude_lesson_id' => $lessonId, 'exclude_booking_request_id' => $bookingRequestId );
        $this->assertAvailable( $studentId, array( array( 'starts_at_utc' => $startsAtUtc, 'occupied_ends_at_utc' => $occupiedEndUtc ) ), $options );
    }
}
