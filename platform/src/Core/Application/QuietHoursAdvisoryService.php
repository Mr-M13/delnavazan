<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Pure Quiet Hours advisory projection.
 *
 * Quiet Hours are a soft, teacher-local advisory. This service never decides
 * whether a slot is available or whether a booking is allowed. It only answers:
 * "Would this lesson start fall inside the configured Quiet Hours for the actual
 * candidate teacher timezones, and how does that window look in the student's
 * timezone?"
 *
 * No Teacher identity or availability fact is exposed. Distinct candidate
 * timezones are already part of the public availability response and are the
 * only context used here.
 */
final class QuietHoursAdvisoryService {
    private ?array $policyCache = null;

    /** @return array<string,mixed> */
    public function currentPolicy(): array {
        if ( $this->policyCache !== null ) return $this->policyCache;
        $policy = BookingAvailabilityPolicy::current();
        $this->policyCache = array(
            'enabled' => ! empty( $policy['quiet_hours_enabled'] ),
            'student_advisory' => ! empty( $policy['quiet_student_advisory'] ),
            'start' => (string) ( $policy['quiet_start'] ?? '01:00:00' ),
            'end' => (string) ( $policy['quiet_end'] ?? '06:00:00' ),
            'timezone_basis' => (string) ( $policy['quiet_timezone_basis'] ?? 'teacher_local' ),
            'academy_timezone' => (string) ( $policy['academy_timezone'] ?? 'Asia/Tehran' ),
        );
        return $this->policyCache;
    }

    /**
     * @param list<string> $teacherTimezones Distinct candidate timezones, no identities.
     * @param null|array<string,mixed> $policy Raw/normalized policy for deterministic tests.
     * @return array<string,mixed>
     */
    public function forCandidates( string $startsAtUtc, array $teacherTimezones, string $studentTimezone, ?array $policy = null ): array {
        $policy = $this->normalizePolicy( $policy ?? $this->currentPolicy() );
        if ( ! $policy['enabled'] || ! $policy['student_advisory'] ) return self::disabled( $policy );
        $instant = self::instant( $startsAtUtc );
        if ( $instant === null ) return self::disabled( $policy );
        try { $studentZone = new \DateTimeZone( $studentTimezone ); }
        catch ( \Throwable ) { return self::disabled( $policy ); }

        $candidates = array();
        $seen = array();
        foreach ( $teacherTimezones as $teacherTimezone ) {
            $teacherTimezone = trim( (string) $teacherTimezone );
            if ( $teacherTimezone === '' || isset( $seen[ $teacherTimezone ] ) ) continue;
            $seen[ $teacherTimezone ] = true;
            $candidate = self::evaluate( $instant, $teacherTimezone, $studentZone, $policy );
            if ( $candidate !== null ) $candidates[] = $candidate;
        }
        $affected = 0;
        foreach ( $candidates as $candidate ) if ( ! empty( $candidate['applies'] ) ) $affected++;
        $count = count( $candidates );
        $scope = $count === 0 || $affected === 0 ? 'none' : ( $affected === $count ? 'all' : 'some' );
        return array(
            'enabled' => true,
            'policy_enabled' => true,
            'student_advisory_enabled' => true,
            'scope' => $scope,
            'candidate_count' => $count,
            'affected_candidate_count' => $affected,
            'timezone_basis' => $policy['timezone_basis'],
            'quiet_start' => $policy['start'],
            'quiet_end' => $policy['end'],
            'candidates' => $candidates,
        );
    }

    /**
     * Evaluate one teacher candidate. Public for unit testing and assigned-Teacher
     * reuse without exposing it through the booking response.
     *
     * @return null|array<string,mixed>
     */
    public static function evaluate( \DateTimeImmutable $startsAtUtc, string $teacherTimezone, \DateTimeZone $studentZone, array $policy ): ?array {
        try {
            $teacherZone = new \DateTimeZone( $teacherTimezone );
            $quietZone = $policy['timezone_basis'] === 'academy'
                ? new \DateTimeZone( (string) $policy['academy_timezone'] )
                : $teacherZone;
        } catch ( \Throwable ) {
            return null;
        }
        $startMinute = self::minutes( (string) $policy['start'] );
        $endMinute = self::minutes( (string) $policy['end'] );
        if ( $startMinute === null || $endMinute === null || $startMinute === $endMinute ) return null;

        $teacherLocal = $startsAtUtc->setTimezone( $teacherZone );
        $quietLocal = $startsAtUtc->setTimezone( $quietZone );
        $localMinute = (int) $quietLocal->format( 'G' ) * 60 + (int) $quietLocal->format( 'i' );
        $applies = $startMinute < $endMinute
            ? ( $localMinute >= $startMinute && $localMinute < $endMinute )
            : ( $localMinute >= $startMinute || $localMinute < $endMinute );

        // Resolve the occurrence of the Quiet Hours window that contains (or is
        // nearest to) this lesson start. A window may start on the previous local
        // date when it crosses midnight and the lesson is after midnight.
        $quietDate = $quietLocal->format( 'Y-m-d' );
        $windowStartDate = $quietDate;
        $windowEndDate = $quietDate;
        if ( $startMinute > $endMinute ) {
            if ( $localMinute >= $startMinute ) {
                $windowEndDate = ( new \DateTimeImmutable( $quietDate . ' 00:00:00', new \DateTimeZone( 'UTC' ) ) )->modify( '+1 day' )->format( 'Y-m-d' );
            } else {
                $windowStartDate = ( new \DateTimeImmutable( $quietDate . ' 00:00:00', new \DateTimeZone( 'UTC' ) ) )->modify( '-1 day' )->format( 'Y-m-d' );
            }
        }

        $rangeAvailable = true;
        $quietStartUtc = null;
        $quietEndUtc = null;
        try {
            $quietStart = AvailabilityLocalTime::wall( $windowStartDate, self::wallTime( $startMinute ), $quietZone->getName() );
            $quietEnd = AvailabilityLocalTime::wall( $windowEndDate, self::wallTime( $endMinute ), $quietZone->getName() );
            $quietStartUtc = $quietStart->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
            $quietEndUtc = $quietEnd->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        } catch ( \Throwable ) {
            // A DST gap/fold makes a boundary instant ambiguous. Do not guess.
            $rangeAvailable = false;
        }

        $studentStart = null;
        $studentEnd = null;
        if ( $rangeAvailable && $quietStartUtc !== null && $quietEndUtc !== null ) {
            $studentStart = ( new \DateTimeImmutable( $quietStartUtc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $studentZone );
            $studentEnd = ( new \DateTimeImmutable( $quietEndUtc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $studentZone );
        }

        return array(
            'timezone' => $teacherTimezone,
            'quiet_timezone' => $quietZone->getName(),
            'applies' => $applies,
            'starts_at_utc' => $startsAtUtc->format( 'Y-m-d H:i:s' ),
            'teacher_local_start' => $teacherLocal->format( 'H:i' ),
            'teacher_local_date' => $teacherLocal->format( 'Y-m-d' ),
            'student_quiet_start_at_utc' => $quietStartUtc,
            'student_quiet_end_at_utc' => $quietEndUtc,
            'student_quiet_start' => $studentStart?->format( 'H:i' ),
            'student_quiet_end' => $studentEnd?->format( 'H:i' ),
            'student_quiet_start_date' => $studentStart?->format( 'Y-m-d' ),
            'student_quiet_end_date' => $studentEnd?->format( 'Y-m-d' ),
            'student_crosses_midnight' => $studentStart !== null && $studentEnd !== null && $studentStart->format( 'Y-m-d' ) !== $studentEnd->format( 'Y-m-d' ),
            'student_range_available' => $rangeAvailable,
        );
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    private function normalizePolicy( array $policy ): array {
        return array(
            'enabled' => ! empty( $policy['enabled'] ?? $policy['quiet_hours_enabled'] ?? true ),
            'student_advisory' => ! empty( $policy['student_advisory'] ?? $policy['quiet_student_advisory'] ?? true ),
            'start' => (string) ( $policy['start'] ?? $policy['quiet_start'] ?? '01:00:00' ),
            'end' => (string) ( $policy['end'] ?? $policy['quiet_end'] ?? '06:00:00' ),
            'timezone_basis' => (string) ( $policy['timezone_basis'] ?? $policy['quiet_timezone_basis'] ?? 'teacher_local' ),
            'academy_timezone' => (string) ( $policy['academy_timezone'] ?? 'Asia/Tehran' ),
        );
    }

    private static function instant( string $value ): ?\DateTimeImmutable {
        if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) !== 1 ) return null;
        $instant = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
        return $instant && $instant->format( 'Y-m-d H:i:s' ) === $value ? $instant : null;
    }

    private static function minutes( string $value ): ?int {
        $value = trim( $value );
        if ( preg_match( '/^([01]?[0-9]|2[0-3]):([0-5][0-9])(?::[0-5][0-9])?$/D', $value, $matches ) !== 1 ) return null;
        return (int) $matches[1] * 60 + (int) $matches[2];
    }

    private static function wallTime( int $minute ): string {
        return sprintf( '%02d:%02d:00', intdiv( $minute, 60 ), $minute % 60 );
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    private static function disabled( array $policy ): array {
        return array(
            'enabled' => false,
            'policy_enabled' => ! empty( $policy['enabled'] ),
            'student_advisory_enabled' => ! empty( $policy['student_advisory'] ),
            'scope' => 'none',
            'candidate_count' => 0,
            'affected_candidate_count' => 0,
            'timezone_basis' => (string) ( $policy['timezone_basis'] ?? 'teacher_local' ),
            'quiet_start' => (string) ( $policy['start'] ?? '01:00:00' ),
            'quiet_end' => (string) ( $policy['end'] ?? '06:00:00' ),
            'candidates' => array(),
        );
    }
}
