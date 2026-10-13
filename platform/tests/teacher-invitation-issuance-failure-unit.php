<?php
/** Offline proof that issuance failures are categorized without logging PII or database text. */
require_once dirname(__DIR__) . '/src/Core/Application/TeacherInvitationIssuanceFailure.php';
require_once dirname(__DIR__) . '/src/Core/Infrastructure/Repository/PersistenceException.php';

use Delnavazan\Platform\Core\Application\TeacherInvitationIssuanceFailure;

$cases = array(
    'Invalid email address' => 'invalid_recipient',
    'Multiple active teacher profiles match this email' => 'duplicate_active_teacher_profiles',
    'Teacher already has a linked account' => 'teacher_already_linked',
    'Teacher is not onboarding eligible' => 'teacher_not_onboarding_eligible',
    'Persistence failed: duplicate key for teacher@example.test' => 'persistence_failed',
    'Transaction commit failed' => 'transaction_failed',
    'unexpected database response recipient=teacher@example.test' => 'unexpected_failure',
);
foreach ( $cases as $message => $expected ) {
    $category = TeacherInvitationIssuanceFailure::category( new RuntimeException( $message ) );
    if ( $category !== $expected ) throw new RuntimeException( 'Unexpected invitation issuance category: ' . $category );
    $notice = TeacherInvitationIssuanceFailure::notice( $category );
    if ( str_contains( $notice, 'teacher@example.test' ) || str_contains( $notice, 'duplicate key' ) ) throw new RuntimeException( 'Invitation issuance notice leaked diagnostic data.' );
}
$persistence = new \Delnavazan\Platform\Core\Infrastructure\Repository\PersistenceException( 1062, 'Duplicate entry teacher@example.test', 'insert' );
if ( TeacherInvitationIssuanceFailure::category( $persistence ) !== 'persistence_failed' ) throw new RuntimeException( 'Repository persistence exception was not safely categorized.' );

$source = (string) file_get_contents( dirname(__DIR__) . '/src/Admin/Controller/ScreenController.php' );
foreach ( array( 'TeacherInvitationIssuanceFailure::category', 'TeacherInvitationIssuanceFailure::log', 'invitation_dispatch_failed' ) as $needle ) {
    if ( ! str_contains( $source, $needle ) ) throw new RuntimeException( 'Invitation issuance controller diagnostic missing: ' . $needle );
}
if ( ! str_contains( $source, "\$action === 'issue_teacher_invitation_by_email'" ) ) throw new RuntimeException( 'Invitation diagnostic must remain limited to email invitation issuance.' );
$failureSource = (string) file_get_contents( dirname(__DIR__) . '/src/Core/Application/TeacherInvitationIssuanceFailure.php' );
if ( ! str_contains( $failureSource, 'in_array($category, self::CATEGORIES, true)' ) || ! str_contains( $failureSource, "error_log('[Delnavazan] teacher_invitation_issuance_failed category=' . \$category)" ) || str_contains( $failureSource, 'error_log($error' ) ) throw new RuntimeException( 'Invitation issuance logging must contain only the fixed category.' );
echo "teacher-invitation-issuance-failure-unit: pass\n";
