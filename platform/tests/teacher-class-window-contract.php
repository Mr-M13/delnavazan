<?php
$root = dirname( __DIR__ );
$bridge = file_get_contents( dirname( $root ) . '/theme/inc/platform-bridge.php' );
$join = file_get_contents( $root . '/src/Integrations/TeacherClassJoinService.php' );
if ( ! is_string( $bridge ) || '' === $bridge || ! is_string( $join ) || '' === $join ) {
    throw new RuntimeException( 'Teacher class join sources unavailable.' );
}
$fragments = array(
    <<<'SRC'
$end = strtotime( (string) ( $row['ends_at_utc'] ?? '' ) . ' UTC' );
SRC,
    <<<'SRC'
return $start && $end && $end >= $now;
SRC,
    <<<'SRC'
'start_available' => ! empty( $lesson['join_available'] ) && $soon
SRC,
);
foreach ( $fragments as $fragment ) {
    if ( strpos( $bridge, $fragment ) === false ) {
        throw new RuntimeException( 'Teacher ongoing-class visibility contract missing: ' . $fragment );
    }
}
if ( strpos( $bridge, 'return $start && $start >= $now;' ) !== false ) {
    throw new RuntimeException( 'Teacher class list still drops a lesson at its start instant.' );
}
foreach ( array( 'PortalAccessPolicy::assertObject', 'EARLY_SECONDS', 'LATE_SECONDS', 'portal_action_outside_window', 'activeCalendarMapping' ) as $fragment ) {
    if ( strpos( $join, $fragment ) === false ) {
        throw new RuntimeException( 'Teacher join authority contract missing: ' . $fragment );
    }
}
echo "Teacher class window contract passed\n";
