<?php
$schema = file_get_contents( dirname( __DIR__ ) . '/src/Core/Infrastructure/Migration/Migrator.php' );
$required = array( 'teachers','students','instruments','courses','enrolments','terms','lessons','lesson_schedule_versions','operational_exceptions','teacher_profile_links' );
foreach ( $required as $table ) { if ( ! str_contains( $schema, "'{$table}'" ) ) { throw new RuntimeException( "Missing schema table: {$table}" ); } }
foreach ( array( 'teachers','students','instruments','courses','enrolments','terms','lessons' ) as $table ) { if ( ! str_contains( $schema, '{$p}' . $table . ' (id bigint unsigned NOT NULL AUTO_INCREMENT,uid char(26) NOT NULL,reference_code varchar(32) NULL' ) ) { throw new RuntimeException( "Reference nullable migration absent: {$table}" ); } }
foreach ( array( 'schedule_timezone','payment_state','current_schedule_version_id','replacement_for_lesson_id','exception_type varchar(64)','severity varchar(16)','fingerprint char(64)','error_code varchar(64)' ) as $fragment ) { if ( ! str_contains( $schema, $fragment ) ) { throw new RuntimeException( "Missing schema fragment: {$fragment}" ); } }
if ( str_contains( $schema, 'LIKE {$p}teachers' ) || str_contains( $schema, 'finance' ) || str_contains( $schema, 'amelia' ) ) { throw new RuntimeException( 'Forbidden schema direction.' ); }
if ( str_contains( $schema, 'new\\RuntimeException' ) ) { throw new RuntimeException( 'Migrator contains malformed RuntimeException construction.' ); }
$runtimeExceptionPaths = array(
    'install_enrolment_conversion_authority' => 1,
    'install_teacher_assignment_foundation' => 1,
    'verify_enrolment_conversion_schema' => 4,
    'verify_teacher_assignment_schema' => 5,
);
foreach ( $runtimeExceptionPaths as $method => $expected ) {
    $start = strpos( $schema, 'private static function ' . $method );
    if ( $start === false ) { throw new RuntimeException( 'Migrator RuntimeException failure path missing: ' . $method ); }
    $end = strpos( $schema, 'private static function ', $start + 1 );
    $body = substr( $schema, $start, $end === false ? null : $end - $start );
    if ( substr_count( $body, 'new \\RuntimeException' ) !== $expected ) { throw new RuntimeException( 'Migrator RuntimeException failure path regression: ' . $method ); }
}
echo "Schema contract static test passed\n";
