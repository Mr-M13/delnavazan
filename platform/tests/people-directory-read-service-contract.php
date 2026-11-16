<?php
/**
 * People directory read-service contract.
 *
 * Source-level guard: the read service must remain SELECT-only, must express the
 * exact derived-status and lead rules against the real tables, must expose the
 * reusable notification-targeting groups, and the screen must stay gated behind
 * the narrow administrator-only capability.
 */
$root = dirname( __DIR__ );
$read = (string) file_get_contents( $root . '/src/Core/Application/PeopleDirectoryReadService.php' );
$import = (string) file_get_contents( $root . '/src/Core/Application/PeopleDirectoryImportService.php' );
$controller = (string) file_get_contents( $root . '/src/Admin/Controller/PeopleDirectoryController.php' );
$menu = (string) file_get_contents( $root . '/src/Admin/Controller/Menu.php' );
$migrator = (string) file_get_contents( $root . '/src/Core/Infrastructure/Migration/Migrator.php' );

$fail = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) throw new RuntimeException( $message );
};

// The read service is SELECT-only: it reads the tables that own each fact and
// never inserts, updates, deletes, migrates or alters storage.
$fail( str_contains( $read, '$wpdb->get_results' ), 'Read service must read rows' );
foreach ( array( '$wpdb->insert', '$wpdb->update(', '$wpdb->delete', '$wpdb->replace', '$wpdb->query(', 'dbDelta', 'CREATE TABLE', 'ALTER TABLE', 'DROP TABLE', 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ) as $write ) {
	$fail( ! str_contains( $read, $write ), 'Read service must never write: ' . $write );
}

// Derived-status SQL against the real tables.
$fail( str_contains( $read, "COALESCE(e.lifecycle_state,e.status) IN ('authorised','current','paused','active')" ), 'Student active must check a current operational Enrolment' );
$fail( str_contains( $read, "ao.state='active' AND ao.readiness_state='ready'" ), 'Teacher active must require completed onboarding' );
$fail( str_contains( $read, "al.status='active' AND al.revoked_at IS NULL" ), 'Teacher active must require a live principal link' );
$fail( str_contains( $read, "o.outcome='delivered'" ), 'Lead rule must require a delivered introductory outcome' );
$fail( str_contains( $read, 'NOT EXISTS (SELECT 1 FROM {$p}enrolments' ), 'Lead rule must require no completed enrolment' );

// The reusable groups for the follow-up notification PR are explicit and stable.
foreach ( array( "'active_students'", "'former_students'", "'active_teachers'", "'former_teachers'", "'leads'", "'imported_contacts'" ) as $group ) {
	$fail( str_contains( $read, $group ), 'Missing reusable group: ' . $group );
}
$fail( str_contains( $read, 'recipientsForGroup' ), 'Group recipients must be reusable for custom notifications' );

// Import matching keeps one human from being duplicated.
$fail( str_contains( $import, 'matched_student_id' ) && str_contains( $import, 'matched_teacher_id' ), 'Imported contacts must carry a nullable matched Student/Teacher id' );
$fail( str_contains( $import, 'normalisePhone' ) && str_contains( $import, 'normaliseEmail' ), 'Import must match by normalised phone/email' );
$fail( str_contains( $import, 'dedupeRows' ), 'Import must collapse duplicate identities' );

// The screen is gated behind the narrow, administrator-only capability.
$fail( str_contains( $controller, "current_user_can( self::CAPABILITY )" ), 'Controller must gate the screen by capability' );
$fail( str_contains( $controller, "check_admin_referer" ), 'Import must be nonce-protected' );
$fail( str_contains( $controller, "'dzn_view_people_directory'" ) || str_contains( $controller, 'PeopleDirectoryReadService::CAPABILITY' ), 'Controller must reference the narrow capability' );
$fail( str_contains( $menu, 'dzn_view_people_directory' ), 'Menu must register People under the narrow capability' );

// The contacts table is additive and verified.
$fail( str_contains( $migrator, 'academy_directory_contacts' ), 'Migration must define the contacts table' );
$fail( str_contains( $migrator, 'install_people_directory_contacts' ) && str_contains( $migrator, 'verify_people_directory_contacts_schema' ), 'Migration must install and verify the contacts table' );
$fail( str_contains( $migrator, '078_people_directory_contacts' ), 'Migration must be registered in the ordered runner' );

// The importer is generic: no real personal data is committed to the repository.
$fail( ! preg_match( '/@(?!invalid\b)[a-z0-9.-]+\./i', $import ), 'Import must not commit a real email literal' );

echo "People directory read-service contract passed\n";
