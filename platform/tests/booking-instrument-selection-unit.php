<?php
/**
 * Regression: homepage instrument tiles must hand the canonical catalogue
 * instrument name to /enrol/ so the booking page preselects the same option.
 *
 * Run: php platform/tests/booking-instrument-selection-unit.php
 */

namespace Delnavazan\Platform\Core\Application {
	final class PublicBookingOptionsReadService {
		public function introductoryInstruments(): array {
			return array(
				array( 'id' => 15, 'slug' => '%d8%b3%d9%87-%d8%aa%d8%a7%d8%b1', 'name_fa' => 'سه تار', 'name_en' => 'سه تار' ),
				array( 'id' => 17, 'slug' => '%d8%aa%d9%85%d8%a8%da%a9', 'name_fa' => 'تمبک', 'name_en' => 'تمبک' ),
				array( 'id' => 22, 'slug' => '%d8%aa%d9%86%d8%a8%d9%88%d8%b1', 'name_fa' => 'تنبور', 'name_en' => 'تنبور' ),
			);
		}
	}
}

namespace {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
	function home_url( string $path = '' ): string { return 'https://example.invalid' . $path; }
	function add_query_arg( array $args, string $url ): string {
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
	}

	require dirname( __DIR__, 2 ) . '/theme/inc/booking.php';

	$query = static function ( string $url ): array {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $parsed );
		return $parsed;
	};
	$fail = static function ( string $message ): void { throw new RuntimeException( $message ); };

	$setar = $query( dzn_theme_booking_enrol_url( 'سه‌تار', 'setar' ) );
	if ( ( $setar['instrument_name'] ?? '' ) !== 'سه تار' ) {
		$fail( 'the zero-width-joiner tile label must resolve to the catalogue name «سه تار»' );
	}
	$tombak = $query( dzn_theme_booking_enrol_url( 'تنبک', 'tombak' ) );
	if ( ( $tombak['instrument_name'] ?? '' ) !== 'تمبک' ) {
		$fail( 'the تنبک tile label must resolve to the catalogue name «تمبک»' );
	}
	$tanboor = $query( dzn_theme_booking_enrol_url( 'تنبور', 'tanboor' ) );
	if ( ( $tanboor['instrument_name'] ?? '' ) !== 'تنبور' ) {
		$fail( 'the تنبور tile label must resolve to the catalogue name «تنبور»' );
	}
	$fallback = $query( dzn_theme_booking_enrol_url( 'ساز ناشناخته', 'unknown' ) );
	if ( ( $fallback['instrument'] ?? '' ) !== 'unknown' || ( $fallback['instrument_name'] ?? '' ) !== 'ساز ناشناخته' ) {
		$fail( 'an unmatched tile must keep a safe fallback query' );
	}
	if ( str_contains( dzn_theme_booking_enrol_url( 'سه‌تار', 'setar' ), 'instrument=%25' ) ) {
		$fail( 'catalogue names must not be double-encoded' );
	}

	echo "booking-instrument-selection-unit: pass\n";
}
