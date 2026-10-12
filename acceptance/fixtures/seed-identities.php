<?php
// Deterministic, synthetic identities for the disposable acceptance database.
// This is intentionally invoked only by acceptance/bin/seed-identities.sh.
defined( 'ABSPATH' ) || exit( 1 );

use Delnavazan\Platform\Core\Application\PrincipalInvitationService;
use Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService;
use Delnavazan\Platform\Core\Application\StudentService;
use Delnavazan\Platform\Core\Application\TeacherService;

$actor = 1;
wp_set_current_user( $actor );
if ( ! current_user_can( 'dzn_manage_students' ) || ! current_user_can( 'dzn_manage_teachers' ) || ! current_user_can( 'dzn_manage_onboarding' ) ) {
	throw new RuntimeException( 'The disposable administrator lacks Platform setup capabilities.' );
}

$create_user = static function ( string $name ): int {
	$login = 'dzn-acceptance-' . $name;
	$email = $login . '@example.invalid';
	if ( username_exists( $login ) || email_exists( $email ) ) {
		throw new RuntimeException( 'Acceptance fixture already exists; reset the disposable database before reseeding.' );
	}
	$id = wp_create_user( $login, 'dzn-acceptance-local-only', $email );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	wp_update_user( array( 'ID' => $id, 'display_name' => 'Acceptance ' . ucfirst( $name ) ) );
	return (int) $id;
};

$student_user = $create_user( 'student' );
$teacher_user = $create_user( 'teacher' );
$dual_user = $create_user( 'dual' );
$now = gmdate( 'Y-m-d H:i:s' );

$students = new StudentService();
$student_authority = new StudentAcceptanceAuthorityService();
$teachers = new TeacherService();
$teacher_principals = new PrincipalInvitationService();

$make_student = static function ( string $label, int $user_id ) use ( $students, $student_authority, $now, $actor ): int {
	$id = $students->create( array(
		'display_name' => 'Acceptance ' . $label,
		'email' => 'dzn-acceptance-' . sanitize_title( $label ) . '@example.invalid',
		'status' => 'active',
	) );
	$student_authority->establishPrincipal( $id, $user_id, 'synthetic_fixture', 'synthetic_fixture', $now, $actor );
	return $id;
};
$make_teacher = static function ( string $label, int $user_id ) use ( $teachers, $teacher_principals ): int {
	$id = $teachers->create( array(
		'display_name' => 'Acceptance ' . $label,
		'email' => 'dzn-acceptance-' . sanitize_title( $label ) . '-teacher@example.invalid',
		'status' => 'active',
	) );
	$teacher_principals->linkExistingPrincipal( $id, $user_id );
	return $id;
};

$fixture = array(
	'student_user_id' => $student_user,
	'teacher_user_id' => $teacher_user,
	'dual_user_id' => $dual_user,
	'student_id' => $make_student( 'Student', $student_user ),
	'teacher_id' => $make_teacher( 'Teacher', $teacher_user ),
	'dual_student_id' => $make_student( 'Dual Student', $dual_user ),
	'dual_teacher_id' => $make_teacher( 'Dual Teacher', $dual_user ),
);
update_option( 'dzn_acceptance_fixture_ids', $fixture, false );
echo "Seeded synthetic acceptance identities.\n";
