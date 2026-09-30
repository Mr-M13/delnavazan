<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CourseRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\InstrumentRepository;

/** Read-only public presentation of the current active introductory catalogue. */
final class PublicBookingOptionsReadService {
    /** @return list<array<string,int|string>> */
    public function introductoryInstruments(): array {
        $instruments = ( new InstrumentRepository() )->recent( 100 );
        $courses = new CourseRepository();
        $defaults = new InstrumentIntroCourseDefaultService();
        $result = array();
        foreach ( $instruments as $instrument ) {
            if ( $instrument->status !== 'active' || $instrument->archived_at !== null ) continue;
            $default = $defaults->resolve( (int) $instrument->id );
            if ( ! $default ) continue;
            $course = $courses->usable( (int) $default->course_id );
            if ( ! $course || $course->status !== 'active' || $course->archived_at !== null || $course->course_type !== 'introductory' || (int) $course->instrument_id !== (int) $instrument->id ) continue;
            $result[] = array(
                'id' => (int) $instrument->id,
                'slug' => (string) $instrument->slug,
                'name_fa' => (string) ( $instrument->name_fa ?? '' ),
                'name_en' => (string) ( $instrument->name_en ?? '' ),
                'course_id' => (int) $course->id,
                'course_name_fa' => (string) ( $course->name_fa ?? '' ),
                'duration_minutes' => (int) $course->default_duration_minutes,
                'buffer_minutes' => (int) $course->default_buffer_minutes,
            );
        }
        usort( $result, static fn( array $a, array $b ): int => strcmp( (string) $a['name_fa'], (string) $b['name_fa'] ) );
        return $result;
    }
}
