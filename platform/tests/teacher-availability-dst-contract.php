<?php
/**
 * Source-level regression for recurring availability across DST transitions.
 *
 * A saved weekly rule can be valid in general while one future occurrence lands
 * in a civil-time gap or fold. TeacherAvailabilityService::effective() must
 * skip only that invalid occurrence rather than abort the whole availability read.
 */
$root = dirname( __DIR__ );
$service = file_get_contents( $root . '/src/Core/Application/TeacherAvailabilityService.php' );
if ( false === $service ) {
    throw new RuntimeException( 'Unable to read TeacherAvailabilityService' );
}

$needle = "catch ( UnavailableLocalTimeException ) { continue; }";
if ( strpos( $service, $needle ) === false ) {
    throw new RuntimeException( 'Recurring availability must skip only invalid DST occurrences' );
}
if ( strpos( $service, 'AvailabilityLocalTime::interval( $date, (string) $rule->local_start_time, (string) $rule->local_end_time' ) === false ) {
    throw new RuntimeException( 'Recurring availability must continue using canonical local-time conversion' );
}

require $root . '/src/Core/Application/AvailabilityLocalTime.php';

use Delnavazan\Platform\Core\Application\AvailabilityLocalTime;

$gapRejected = false;
try {
    AvailabilityLocalTime::interval( '2026-10-04', '02:15:00', '03:00:00', 'Australia/Sydney' );
} catch ( InvalidArgumentException ) {
    $gapRejected = true;
}
if ( ! $gapRejected ) {
    throw new RuntimeException( 'Sydney DST-gap occurrence must remain invalid' );
}

$normal = AvailabilityLocalTime::interval( '2026-10-11', '02:15:00', '03:00:00', 'Australia/Sydney' );
if ( empty( $normal['starts_at_utc'] ) || empty( $normal['ends_at_utc'] ) ) {
    throw new RuntimeException( 'Normal recurring occurrence must remain convertible' );
}

echo "Recurring availability DST contract passed\n";
