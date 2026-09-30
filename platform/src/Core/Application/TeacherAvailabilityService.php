<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherAvailabilityRepository;

/** Core authority for availability facts; it intentionally neither routes nor commits a schedule. */
final class TeacherAvailabilityService {
    private const STATES = array( 'preferred', 'requestable', 'blocked' );
    public function __construct( private ?TeacherAvailabilityRepository $repo = null ) { $this->repo ??= new TeacherAvailabilityRepository(); }

    public function setProfile(array $input): int {
        $this->admin(); $teacher = Normalizer::id( $input['teacher_id'] ?? null ); $timezone = $this->timezone( $input['timezone'] ?? null ); $status = Normalizer::one( $input['status'] ?? 'active', array( 'active', 'inactive' ), 'availability profile status' ); $reason = Normalizer::text( $input['reason_code'] ?? null, 64 ); $now = current_time( 'mysql', true );
        $this->repo->begin(); try { $teacherRow = $this->usableTeacher( $teacher ); $current = $this->repo->profileForUpdate( $teacher ); if ( $current && $current->timezone !== $timezone && $this->repo->childrenExist( (int) $current->id ) ) throw new \InvalidArgumentException( 'Timezone cannot change while availability facts exist' ); $id = $this->repo->saveProfile( $teacher, $timezone, $status, $reason, $now, get_current_user_id() ?: null ); $this->repo->commit(); return $id; } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    public function setRecurringRule(array $input): int {
        $this->admin(); $d = $this->rule( $input ); $now = current_time( 'mysql', true ); $this->repo->begin(); try { $profile = $this->profileForMutation( $d['teacher_id'], $d['timezone'] ); $d['profile_id'] = (int) $profile->id; $id = $this->repo->saveRule( $d, $now, get_current_user_id() ?: null ); $this->repo->commit(); return $id; } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    public function setDatedException(array $input): int {
        $this->admin(); $d = $this->exception( $input ); $now = current_time( 'mysql', true ); $this->repo->begin(); try { $profile = $this->profileForMutation( $d['teacher_id'], $d['timezone'] ); $d['profile_id'] = (int) $profile->id; $id = $this->repo->saveException( $d, $now, get_current_user_id() ?: null ); $this->repo->commit(); return $id; } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    /** @return list<array{state:string,starts_at_utc:string,ends_at_utc:string,source:string}> */
    public function effective(int $teacherId, string $fromUtc, string $untilUtc): array {
        $teacher = Normalizer::id( $teacherId ); $from = $this->utc( $fromUtc ); $until = $this->utc( $untilUtc ); if ( $until <= $from ) throw new \InvalidArgumentException( 'Availability range end must follow start' );
        if ( ! $this->repo->evaluableTeacher( $teacher ) ) return array();
        $profile = $this->repo->profile( $teacher ); if ( ! $profile || $profile->status !== 'active' ) return array();
        $zone = new \DateTimeZone( (string) $profile->timezone ); $utc = new \DateTimeZone( 'UTC' ); $fromLocal = ( new \DateTimeImmutable( $from, $utc ) )->setTimezone( $zone ); $untilLocal = ( new \DateTimeImmutable( $until, $utc ) )->setTimezone( $zone );
        $startDate = $fromLocal->modify( '-1 day' )->format( 'Y-m-d' ); $endDate = $untilLocal->modify( '+1 day' )->format( 'Y-m-d' );
        $facts = array(); $rules = $this->repo->activeRules( $teacher ); $exceptions = $this->repo->activeExceptions( $teacher, $startDate, $endDate );
        for ( $day = new \DateTimeImmutable( $startDate, $zone ); $day->format( 'Y-m-d' ) <= $endDate; $day = $day->modify( '+1 day' ) ) {
            $date = $day->format( 'Y-m-d' ); $weekday = (int) $day->format( 'N' );
            foreach ( $rules as $rule ) if ( (int) $rule->weekday === $weekday ) $this->append( $facts, AvailabilityLocalTime::interval( $date, (string) $rule->local_start_time, (string) $rule->local_end_time, (string) $rule->timezone ), (string) $rule->state, 'recurring', (int) $rule->id );
        }
        foreach ( $exceptions as $exception ) { $interval = (int) $exception->all_day === 1 ? AvailabilityLocalTime::fullDay( (string) $exception->local_date, (string) $exception->timezone ) : AvailabilityLocalTime::interval( (string) $exception->local_date, (string) $exception->local_start_time, (string) $exception->local_end_time, (string) $exception->timezone ); $this->append( $facts, $interval, (string) $exception->state, 'exception', (int) $exception->id ); }
        return $this->resolve( $facts, $from, $until );
    }

    private function rule(array $input): array {
        $teacher = Normalizer::id( $input['teacher_id'] ?? null ); $weekday = Normalizer::count( $input['weekday'] ?? null, 1, 7 ); $timezone = $this->timezone( $input['timezone'] ?? null ); $start = $this->time( $input['local_start_time'] ?? null ); $end = $this->time( $input['local_end_time'] ?? null );
        // Validate a representative date as well as same-day half-open ordering.
        AvailabilityLocalTime::interval( '2026-01-05', $start, $end, $timezone );
        return array( 'id' => Normalizer::id( $input['id'] ?? null, false ), 'teacher_id' => $teacher, 'weekday' => $weekday, 'local_start_time' => $start, 'local_end_time' => $end, 'state' => $this->state( $input['state'] ?? null ), 'timezone' => $timezone, 'status' => Normalizer::one( $input['status'] ?? 'active', array( 'active', 'inactive' ), 'availability rule status' ), 'reason_code' => Normalizer::text( $input['reason_code'] ?? null, 64 ) );
    }
    private function exception(array $input): array {
        $teacher = Normalizer::id( $input['teacher_id'] ?? null ); $timezone = $this->timezone( $input['timezone'] ?? null ); $date = AvailabilityLocalTime::date( (string) ( $input['local_date'] ?? '' ) ); $allDay = ! empty( $input['all_day'] ) ? 1 : 0; $state = $this->state( $input['state'] ?? null );
        if ( $allDay ) { $start = '00:00:00'; $end = '00:00:00'; AvailabilityLocalTime::fullDay( $date, $timezone ); } else { $start = $this->time( $input['local_start_time'] ?? null ); $end = $this->time( $input['local_end_time'] ?? null ); AvailabilityLocalTime::interval( $date, $start, $end, $timezone ); }
        return array( 'id' => Normalizer::id( $input['id'] ?? null, false ), 'teacher_id' => $teacher, 'local_date' => $date, 'all_day' => $allDay, 'local_start_time' => $start, 'local_end_time' => $end, 'state' => $state, 'timezone' => $timezone, 'status' => Normalizer::one( $input['status'] ?? 'active', array( 'active', 'inactive' ), 'availability exception status' ), 'reason_code' => Normalizer::text( $input['reason_code'] ?? null, 64 ) );
    }
    private function profileForMutation(int $teacher, string $timezone): object { $this->usableTeacher( $teacher ); $profile = $this->repo->profileForUpdate( $teacher ); if ( ! $profile || $profile->status !== 'active' || $profile->timezone !== $timezone ) throw new \InvalidArgumentException( 'Active Teacher availability profile with matching timezone required' ); return $profile; }
    private function usableTeacher(int $teacher): object { $row = $this->repo->teacherForUpdate( $teacher ); if ( ! $row || $row->status !== 'active' || $row->archived_at !== null ) throw new \InvalidArgumentException( 'Active Teacher required' ); return $row; }
    private function admin(): void { if ( ! current_user_can( 'dzn_manage_teacher_availability' ) ) throw new \RuntimeException( 'Unauthorized' ); }
    private function timezone(mixed $value): string { $timezone = Normalizer::timezone( $value ); if ( ! $timezone ) throw new \InvalidArgumentException( 'IANA timezone required' ); return $timezone; }
    private function time(mixed $value): string { $time = Normalizer::time( $value ); if ( ! $time ) throw new \InvalidArgumentException( 'Local time required' ); return $time; }
    private function state(mixed $value): string { return Normalizer::one( $value, self::STATES, 'availability state' ); }
    private function utc(string $value): string { $date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) ); if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) throw new \InvalidArgumentException( 'Canonical UTC datetime required' ); return $value; }
    private function append(array &$facts, array $interval, string $state, string $source, int $id): void { $facts[] = $interval + array( 'state' => $state, 'source' => $source, 'id' => $id, 'source_rank' => $source === 'exception' ? 2 : 1, 'state_rank' => array( 'requestable' => 1, 'preferred' => 2, 'blocked' => 3 )[ $state ] ); }
    private function resolve(array $facts, string $from, string $until): array {
        $facts = array_values( array_filter( $facts, static fn( array $f ): bool => $f['starts_at_utc'] < $until && $f['ends_at_utc'] > $from ) ); $points = array( $from, $until ); foreach ( $facts as $fact ) { $points[] = max( $from, $fact['starts_at_utc'] ); $points[] = min( $until, $fact['ends_at_utc'] ); } $points = array_values( array_unique( $points ) ); sort( $points ); $segments = array();
        for ( $i = 0, $last = count( $points ) - 1; $i < $last; $i++ ) { $start = $points[$i]; $end = $points[$i + 1]; if ( $end <= $start ) continue; $matches = array_values( array_filter( $facts, static fn( array $f ): bool => $f['starts_at_utc'] <= $start && $f['ends_at_utc'] >= $end ) ); if ( ! $matches ) continue; usort( $matches, static fn( array $a, array $b ): int => array( $b['source_rank'], $b['state_rank'], $a['id'] ) <=> array( $a['source_rank'], $a['state_rank'], $b['id'] ) ); $winner = $matches[0]; $previous = $segments ? $segments[count( $segments ) - 1] : null; if ( $previous && $previous['state'] === $winner['state'] && $previous['source'] === $winner['source'] && $previous['ends_at_utc'] === $start ) { $segments[count( $segments ) - 1]['ends_at_utc'] = $end; } else { $segments[] = array( 'state' => $winner['state'], 'starts_at_utc' => $start, 'ends_at_utc' => $end, 'source' => $winner['source'] ); } }
        return $segments;
    }
}
