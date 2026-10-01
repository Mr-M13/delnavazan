<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CourseRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\InstrumentRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeachingEligibilityRepository;

/**
 * One-shot staging bootstrap for the approved public booking catalogue.
 * Deliberately host-locked and removed after the staging data is established.
 */
final class StagingBookingCatalogueBootstrap {
    private const OPTION = 'dzn_staging_booking_catalogue_seed_20261001';

    private const INSTRUMENTS = array(
        array( 'avaz', 'آواز', 'Avaz' ),
        array( 'tanbour', 'تنبور', 'Tanbour' ),
        array( 'guitar', 'گیتار', 'Guitar' ),
        array( 'piano', 'پیانو', 'Piano' ),
        array( 'tar', 'تار', 'Tar' ),
        array( 'setar', 'سه‌تار', 'Setar' ),
        array( 'santur', 'سنتور', 'Santur' ),
        array( 'tombak', 'تنبک', 'Tombak' ),
        array( 'kamancheh', 'کمانچه', 'Kamancheh' ),
        array( 'daf', 'دف', 'Daf' ),
        array( 'ney', 'نی', 'Ney' ),
        array( 'violin', 'ویولن', 'Violin' ),
    );

    public static function maybeRun(): void {
        if ( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) !== 'staging.delnavazan.com' ) return;
        if ( get_option( self::OPTION ) === 'complete' ) return;

        $instrumentRepo = new InstrumentRepository();
        $courseRepo = new CourseRepository();
        $eligibilityRepo = new TeachingEligibilityRepository();

        foreach ( self::INSTRUMENTS as array( $slug, $nameFa, $nameEn ) ) {
            $instrument = self::instrumentBySlug( $instrumentRepo, $slug );
            if ( ! $instrument ) {
                $instrumentId = Creator::create( $instrumentRepo, array(
                    'slug' => $slug,
                    'name_fa' => $nameFa,
                    'name_en' => $nameEn,
                    'status' => 'active',
                ), 'DZN-INS-' );
                $instrument = $instrumentRepo->find( $instrumentId );
            }
            if ( ! $instrument || $instrument->archived_at !== null || $instrument->status !== 'active' ) {
                throw new \RuntimeException( 'Staging booking instrument is not usable: ' . $slug );
            }

            $course = self::introCourseForInstrument( $courseRepo, (int) $instrument->id );
            if ( ! $course ) {
                $courseId = Creator::create( $courseRepo, array(
                    'instrument_id' => (int) $instrument->id,
                    'name_fa' => 'جلسهٔ معارفه ' . $nameFa,
                    'name_en' => $nameEn . ' introductory lesson',
                    'course_type' => 'introductory',
                    'status' => 'active',
                    'default_duration_minutes' => 30,
                    'default_buffer_minutes' => 15,
                ), 'DZN-CRS-' );
                $course = $courseRepo->find( $courseId );
            }
            if ( ! $course || $course->archived_at !== null || $course->status !== 'active' ) {
                throw new \RuntimeException( 'Staging introductory course is not usable: ' . $slug );
            }

            $currentDefault = $eligibilityRepo->resolveDefault( (int) $instrument->id );
            if ( ! $currentDefault || (int) $currentDefault->course_id !== (int) $course->id ) {
                $now = current_time( 'mysql', true );
                $eligibilityRepo->begin();
                try {
                    $eligibilityRepo->saveDefault( (int) $instrument->id, (int) $course->id, 'active', 'staging_catalogue_seed', $now, null );
                    $eligibilityRepo->commit();
                } catch ( \Throwable $e ) {
                    $eligibilityRepo->rollback();
                    throw $e;
                }
            }
        }

        update_option( self::OPTION, 'complete', false );
    }

    private static function instrumentBySlug( InstrumentRepository $repo, string $slug ): ?object {
        foreach ( $repo->recent( 100 ) as $instrument ) {
            if ( (string) $instrument->slug === $slug ) return $instrument;
        }
        return null;
    }

    private static function introCourseForInstrument( CourseRepository $repo, int $instrumentId ): ?object {
        foreach ( $repo->recent( 100 ) as $course ) {
            if ( (int) $course->instrument_id === $instrumentId && (string) $course->course_type === 'introductory' ) return $course;
        }
        return null;
    }
}
