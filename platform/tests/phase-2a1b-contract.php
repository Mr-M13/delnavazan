<?php
/* Source contract only; database/browser behaviour requires isolated WordPress/MySQL validation. */
$root = dirname( __DIR__ );
$migration = file_get_contents( $root . '/src/Core/Infrastructure/Migration/Migrator.php' );
$service = file_get_contents( $root . '/src/Core/Application/TeacherAvailabilityService.php' );
$repository = file_get_contents( $root . '/src/Core/Infrastructure/Repository/TeacherAvailabilityRepository.php' );
$time = file_get_contents( $root . '/src/Core/Application/AvailabilityLocalTime.php' );
$admin = file_get_contents( $root . '/src/Admin/Controller/TeacherAvailabilityController.php' );
foreach ( array( '005_teacher_availability_foundation', 'teacher_availability_profiles', 'teacher_availability_rules', 'teacher_availability_exceptions', 'UNIQUE KEY rule_identity', 'UNIQUE KEY exception_identity', 'verify_teacher_availability_schema', 'has_index_columns', 'information_schema.TABLES', 'TABLE_SCHEMA = DATABASE()', 'availability table must use InnoDB', 'dzn_manage_teacher_availability' ) as $needle ) if ( strpos( $migration, $needle ) === false ) throw new RuntimeException( 'Missing availability migration contract: ' . $needle );
foreach ( array( 'preferred', 'requestable', 'blocked', 'effective(', 'source_rank', 'state_rank', 'FOR UPDATE', 'profileForMutation', 'Active Teacher availability profile with matching timezone required' ) as $needle ) if ( strpos( $service . $repository, $needle ) === false ) throw new RuntimeException( 'Missing availability authority contract: ' . $needle );
foreach ( array( 'Ambiguous local time', 'Invalid or nonexistent local time', 'getTransitions', 'fullDay', 'Availability end must follow start' ) as $needle ) if ( strpos( $time, $needle ) === false ) throw new RuntimeException( 'Missing DST/interval contract: ' . $needle );
foreach ( array( 'check_admin_referer', 'dzn_manage_teacher_availability', 'set_availability_profile', 'set_availability_rule', 'set_availability_exception', 'esc_html', 'wp_safe_redirect' ) as $needle ) if ( strpos( $admin, $needle ) === false ) throw new RuntimeException( 'Missing protected admin contract: ' . $needle );
$phase2a1bSchema = substr( $migration, strpos( $migration, 'private static function install_teacher_availability_foundation' ), strpos( $migration, 'private static function install_booking_request_core' ) - strpos( $migration, 'private static function install_teacher_availability_foundation' ) );
if ( preg_match( '/(?:amelia_|wp_amelia|Amelia\\\\|booking_requests|teacher_offers|platform_payments)/i', $phase2a1bSchema . $service . $repository . $admin ) ) throw new RuntimeException( 'Phase 2A.1-B introduced prohibited authority' );
echo "Phase 2A.1-B source contract passed\n";
