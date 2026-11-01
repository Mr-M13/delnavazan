<?php
// Isolated, synthetic recovery and state-refresh acceptance. It never calls wp_mail.
defined( 'ABSPATH' ) || exit( 1 );

use Delnavazan\Platform\Core\Application\PrincipalInvitationService;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityService;
use Delnavazan\Platform\Core\Application\TeacherInvitationDeliveryWorker;
use Delnavazan\Platform\Core\Application\TeacherOnboardingLifecycleService;
use Delnavazan\Platform\Core\Application\CatalogueService;
use Delnavazan\Platform\Core\Application\StudentService;
use Delnavazan\Platform\Core\Application\TeachingEligibilityService;
use Delnavazan\Platform\Core\Application\TeacherService;
use Delnavazan\Platform\Core\Application\TeacherUnavailabilityService;
use Delnavazan\Platform\Core\Application\TeacherWorkspaceReadService;
use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherRepository;

if ( wp_get_environment_type() !== 'local' ) throw new RuntimeException( 'Teacher workspace acceptance is local-only.' );
global $wpdb;

$expect = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) throw new RuntimeException( 'Teacher workspace acceptance failed: ' . $message );
};
$refuse = static function ( callable $operation, string $message ) use ( $expect ): void {
	try { $operation(); } catch ( InvalidArgumentException | RuntimeException ) { return; }
	$expect( false, $message );
};
$overview = static function () use ( $expect ): array {
	$data = ( new TeacherWorkspaceReadService() )->onboardingOverview();
	$rows = array();
	foreach ( $data['rows'] as $row ) $rows[(int) $row['teacher_id']] = $row;
	return $rows;
};
$row = static function ( int $teacherId ) use ( $overview ): ?array {
	$rows = $overview();
	return $rows[ $teacherId ] ?? null;
};
$teacherRow = static function ( int $teacherId ): ?object {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dzn_teachers WHERE id=%d", $teacherId ) );
};
$countTeachers = static function ( string $email ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dzn_teachers WHERE email=%s", $email ) );
};
wp_set_current_user( 1 );
$expect( current_user_can( 'dzn_manage_teachers' ) && current_user_can( 'dzn_manage_onboarding' ), 'fixture administrator lacks Teacher authority' );
$invitations = new PrincipalInvitationService();
$repository = new PrincipalInvitationRepository();
$claimSeed = 'acceptance-workspace-claim';
$claimInvitation = static function ( array $issued, string $login ) use ( $expect, $wpdb, $invitations, $repository, $claimSeed ): int {
	$prepared = $invitations->prepareDelivery( (int) $issued['generation_id'] );
	$repository->finishPreparedDelivery( (int) $issued['generation_id'], 'failed', 'acceptance_local_no_send' );
	$claim = $invitations->beginProvisioning( (string) $prepared['secret'], $claimSeed . '-' . $login );
	$userId = wp_create_user( $login, 'dzn-acceptance-local-only', (string) $claim['recipient'] );
	$expect( ! is_wp_error( $userId ), 'synthetic user could not be created: ' . $login );
	$invitations->recordProvisioningResult( $claimSeed . '-' . $login, (int) $userId, true );
	$invitations->finalizeClaim( $claimSeed . '-' . $login, (int) $userId );
	return (int) $userId;
};
$lifecycle = new TeacherOnboardingLifecycleService();

// Scenario A: invitation only. Resend supersedes the previous claimable generation,
// revoke removes the row from the active onboarding view, and revoke-and-remove
// releases the email without leaving a duplicate Teacher.
$emailA = 'workspace-invite-only@example.invalid';
$issuedA = $invitations->issueForEmail( $emailA, 600, 'workspace-a-invite' );
$expect( ! empty( $issuedA['teacher_created'] ), 'invitation-only Teacher was not created' );
$rowA = $row( (int) $issuedA['teacher_id'] );
$expect( is_array( $rowA ) && $rowA['invitation']['state'] === 'queued_for_delivery', 'staff did not see the queued invitation' );
$invitations->issueForEmail( $emailA, 600, 'workspace-a-resend' );
$expect( $countTeachers( $emailA ) === 1, 'resend created a duplicate Teacher' );
$rowA = $row( (int) $issuedA['teacher_id'] );
$expect( is_array( $rowA ) && $rowA['invitation']['state'] === 'queued_for_delivery', 'resend did not keep one current invitation row' );
$invitations->revoke( (int) $issuedA['teacher_id'], 'acceptance_revoke' );
$expect( $row( (int) $issuedA['teacher_id'] ) === null, 'revoked invitation remained in the active onboarding view' );
$issuedA2 = $invitations->issueForEmail( $emailA, 600, 'workspace-a-reinvite' );
$expect( (int) $issuedA2['teacher_id'] === (int) $issuedA['teacher_id'], 'reinvite after revoke created a new Teacher identity' );
$removed = $lifecycle->revokeAndRemove( (int) $issuedA2['teacher_id'], 'acceptance_invitation_only' );
$expect( $removed['removed'] === true, 'invitation-only Teacher was not removed' );
$expect( $teacherRow( (int) $issuedA2['teacher_id'] ) === null, 'invitation-only Teacher row still exists' );
$issuedA3 = $invitations->issueForEmail( $emailA, 600, 'workspace-a-fresh' );
$expect( (int) $issuedA3['teacher_id'] !== (int) $issuedA2['teacher_id'], 'email was not released for a fresh invitation' );
$expect( $countTeachers( $emailA ) === 1, 'fresh invitation after removal left a duplicate identity' );

// Scenario B: account created with another WordPress role. Reset must revoke the
// Teacher link and Teacher role but preserve the account and its unrelated role.
$emailB = 'workspace-reset-subscriber@example.invalid';
$issuedB = $invitations->issueForEmail( $emailB, 600, 'workspace-b-invite' );
$userIdB = $claimInvitation( $issuedB, 'dzn-workspace-reset-subscriber' );
$userB = get_userdata( $userIdB );
$userB->add_role( 'subscriber' );
if ( get_role( 'dzn_teacher' ) ) $userB->add_role( 'dzn_teacher' );
$rowB = $row( (int) $issuedB['teacher_id'] );
$expect( is_array( $rowB ) && $rowB['account_label'] === 'Linked', 'staff did not see the linked account' );
$resetB = $lifecycle->resetOnboarding( (int) $issuedB['teacher_id'], 'acceptance_wrong_email' );
$expect( $resetB['deleted_user'] === false, 'account with another role was deleted' );
$userB = get_userdata( $userIdB );
$expect( $userB && in_array( 'subscriber', (array) $userB->roles, true ) && ! in_array( 'dzn_teacher', (array) $userB->roles, true ), 'reset did not preserve unrelated role or remove Teacher role' );
$expect( $row( (int) $issuedB['teacher_id'] ) === null, 'reset attempt remained in the active onboarding view' );
$expect( (string) $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$wpdb->prefix}dzn_teacher_onboarding_states WHERE teacher_id=%d", (int) $issuedB['teacher_id'] ) ) === 'reset_pending', 'reset did not mark the failed onboarding attempt as reset' );
$issuedB2 = $invitations->issueForEmail( $emailB, 600, 'workspace-b-fresh' );
$expect( (int) $issuedB2['teacher_id'] === (int) $issuedB['teacher_id'] && $countTeachers( $emailB ) === 1, 'reset did not allow a fresh invitation without duplicate identity' );

// Scenario B2: an account whose only role is the Teacher role is exclusive and may
// be deleted. Any content or additional role blocks deletion.
$emailC = 'workspace-reset-exclusive@example.invalid';
$issuedC = $invitations->issueForEmail( $emailC, 600, 'workspace-c-invite' );
$userIdC = $claimInvitation( $issuedC, 'dzn-workspace-reset-exclusive' );
$userC = get_userdata( $userIdC );
$userC->set_role( 'dzn_teacher' );
$resetC = $lifecycle->resetOnboarding( (int) $issuedC['teacher_id'], 'acceptance_exclusive_account' );
$expect( $resetC['deleted_user'] === true && get_userdata( $userIdC ) === false, 'exclusive onboarding account was not deleted' );
$expect( $teacherRow( (int) $issuedC['teacher_id'] ) !== null, 'reset deleted the canonical Teacher record' );

$emailD = 'workspace-reset-content@example.invalid';
$issuedD = $invitations->issueForEmail( $emailD, 600, 'workspace-d-invite' );
$userIdD = $claimInvitation( $issuedD, 'dzn-workspace-reset-content' );
$userD = get_userdata( $userIdD );
$userD->add_role( 'dzn_teacher' );
$postId = wp_insert_post( array( 'post_title' => 'Synthetic content', 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => $userIdD ) );
$expect( is_int( $postId ) && $postId > 0, 'synthetic content could not be created' );
$resetD = $lifecycle->resetOnboarding( (int) $issuedD['teacher_id'], 'acceptance_content_account' );
$expect( $resetD['deleted_user'] === false && get_userdata( $userIdD ) !== false, 'account with content was deleted' );
$expect( get_post( $postId ) !== null, 'content was removed during reset' );

// Scenario C: protected history blocks reset and points to the archive path.
$emailE = 'workspace-protected@example.invalid';
$issuedE = $invitations->issueForEmail( $emailE, 600, 'workspace-e-invite' );
$userIdE = $claimInvitation( $issuedE, 'dzn-workspace-protected' );
$userE = get_userdata( $userIdE ); $userE->add_role( 'dzn_teacher' );
$now = current_time( 'mysql', true );
$wpdb->insert( $wpdb->prefix . 'dzn_finance_teacher_rates', array(
	'uid' => strtoupper( bin2hex( random_bytes( 13 ) ) ), 'reference_code' => null,
	'teacher_id' => (int) $issuedE['teacher_id'], 'scope_kind' => 'teacher', 'course_scope_id' => 0,
	'compensation_basis' => 'per_session', 'amount_minor' => 1000, 'currency' => 'TOM',
	'effective_from' => $now, 'effective_until' => null, 'status' => 'active',
	'reason_code' => 'acceptance', 'evidence_channel' => 'staff_record',
	'evidence_reference_digest' => hash( 'sha256', 'acceptance-protected-rate' ), 'evidence_at' => $now,
	'rate_version' => 1, 'rate_origin' => 'explicit', 'origin_setting_version' => null,
	'active_slot' => 1, 'recorded_at' => $now, 'recorded_by' => 1, 'created_at' => $now, 'created_by' => 1, 'updated_at' => $now,
) );
$expect( $wpdb->last_error === '', 'synthetic protected rate could not be created: ' . $wpdb->last_error );
$rowE = $row( (int) $issuedE['teacher_id'] );
$expect( is_array( $rowE ) && in_array( 'finance rates', $rowE['protected_history'], true ), 'staff did not see protected history' );
$refuse( static fn() => $lifecycle->resetOnboarding( (int) $issuedE['teacher_id'], 'acceptance_protected' ), 'reset with protected history was allowed' );
$expect( get_userdata( $userIdE ) !== false, 'protected-history refusal deleted the WordPress account' );

// Scenario D: open-ended unavailability stays blocked until reactivated and never
// rewrites an existing weekly availability rule.
$teacherId = ( new TeacherService() )->create( array(
	'display_name' => 'Acceptance Display Name', 'english_name' => 'Acceptance English Name', 'persian_name' => 'نام فارسی',
	'email' => 'workspace-unavailable@example.invalid', 'country_code' => 'AU', 'city' => 'Brisbane',
	'timezone' => 'Australia/Brisbane', 'locale' => 'en_AU', 'calendar_preference' => 'gregorian', 'status' => 'active',
) );
$availability = new TeacherAvailabilityService();
$wpdb->insert( $wpdb->prefix . 'dzn_teacher_onboarding_states', array(
	'teacher_id' => $teacherId, 'state' => 'active', 'readiness_state' => 'ready',
	'profile_state' => 'complete', 'availability_state' => 'complete', 'agreement_state' => 'not_required',
	'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1,
) );
$wpdb->insert( $wpdb->prefix . 'dzn_teacher_accepting_states', array(
	'teacher_id' => $teacherId, 'state' => 'accepting', 'version' => 1,
	'reason_code' => 'acceptance', 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1,
) );
$availability->setProfile( array( 'teacher_id' => $teacherId, 'timezone' => 'Australia/Brisbane', 'status' => 'active' ) );
$availability->setRecurringRule( array(
	'teacher_id' => $teacherId, 'weekday' => 1, 'local_start_time' => '09:00:00', 'local_end_time' => '11:00:00',
	'state' => 'requestable', 'timezone' => 'Australia/Brisbane', 'status' => 'active',
) );
$fromDate = ( new DateTimeImmutable( '+7 days', new DateTimeZone( 'Australia/Brisbane' ) ) )->format( 'Y-m-d' );
$unavailable = new TeacherUnavailabilityService();
$preview = $unavailable->preview( $teacherId, $fromDate, '' );
$expect( $preview['open_ended'] === true && $preview['to'] === '', 'omitted end date did not produce an open-ended preview' );
$unavailable->apply( $teacherId, $fromDate, '', 'acceptance_medical_leave', false );
$openRow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dzn_teacher_availability_exceptions WHERE teacher_id=%d AND status='active' AND reason_code='temporary_unavailability_open'", $teacherId ) );
$expect( $openRow !== null, 'open-ended sentinel exception was not recorded' );
$startUtc = ( new DateTimeImmutable( $fromDate . ' 00:00:00', new DateTimeZone( 'Australia/Brisbane' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
$endUtc = ( new DateTimeImmutable( $fromDate . ' 23:59:59', new DateTimeZone( 'Australia/Brisbane' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
$segments = $availability->effective( $teacherId, $startUtc, $endUtc );
$expect( (bool) array_filter( $segments, static fn( array $segment ): bool => $segment['state'] === 'blocked' ), 'open-ended unavailability did not block booking eligibility' );
$cleared = $unavailable->clearFuture( $teacherId );
$expect( $cleared > 0, 'reactivation did not clear the open-ended sentinel' );
$segments = $availability->effective( $teacherId, $startUtc, $endUtc );
$expect( ! (bool) array_filter( $segments, static fn( array $segment ): bool => $segment['state'] === 'blocked' ), 'reactivation left the Teacher blocked' );

// Class history: the profile exposes more than ten records without leaving the page.
$workspace = new TeacherWorkspaceReadService();
$catalogue = ( new CatalogueService() )->setupInstrument( array( 'name' => 'Acceptance Instrument', 'standard_duration_minutes' => 30, 'gap_between_lessons_minutes' => 5, 'introductory_lesson_billing' => 'free' ) );
$studentId = ( new StudentService() )->create( array(
	'display_name' => 'Acceptance Student', 'first_name' => 'Acceptance', 'last_name' => 'Student',
	'email' => 'workspace-student@example.invalid', 'country_code' => 'AU', 'timezone' => 'Australia/Brisbane',
	'locale' => 'en_AU', 'calendar_preference' => 'gregorian', 'status' => 'active',
) );
( new TeachingEligibilityService() )->setEligibility( array( 'teacher_id' => $teacherId, 'course_id' => (int) $catalogue['standard_course_id'], 'status' => 'active' ) );
for ( $index = 1; $index <= 12; $index++ ) {
	$wpdb->insert( $wpdb->prefix . 'dzn_lessons', array(
		'uid' => strtoupper( bin2hex( random_bytes( 13 ) ) ), 'reference_code' => null,
		'student_id' => $studentId, 'teacher_id' => $teacherId, 'course_id' => (int) $catalogue['standard_course_id'],
		'enrolment_id' => null, 'term_id' => null, 'lesson_type' => 'standard', 'status' => 'completed',
		'sequence_number' => null, 'current_schedule_version_id' => null, 'replacement_for_lesson_id' => null,
		'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1,
		'cancelled_at' => null, 'completed_at' => $now, 'archived_at' => null, 'archived_by' => null,
		'record_model' => 'legacy_phase1', 'lifecycle_state' => null, 'canonical_sequence' => null,
		'teacher_assignment_id' => null, 'canonical_replacement_origin_lesson_id' => null,
	) );
	$expect( $wpdb->last_error === '', 'synthetic class history could not be created: ' . $wpdb->last_error );
}
$historyProfile = $workspace->profile( $teacherId );
$expect( count( (array) $historyProfile['classes']['recent'] ) >= 12, 'profile class history was silently truncated before the view could offer all records' );

// Name handling: the Teacher-entered display name is the directory/profile name;
// distinct canonical name fields remain unchanged.
$workspace = new TeacherWorkspaceReadService();
$directoryNames = array();
foreach ( $workspace->directory( false ) as $teacher ) $directoryNames[ (int) $teacher->id ] = (string) $teacher->display_name_effective;
$expect( ( $directoryNames[ $teacherId ] ?? '' ) === 'Acceptance Display Name', 'directory did not use the Teacher-entered display name' );
$stored = $teacherRow( $teacherId );
$expect( (string) $stored->english_name === 'Acceptance English Name' && (string) $stored->persian_name === 'نام فارسی', 'distinct canonical name fields were overwritten' );

// Current-state onboarding projection must update from records, not a stored snapshot.
$issuedF = $invitations->issueForEmail( 'workspace-current-state@example.invalid', 600, 'workspace-f-invite' );
$userIdF = $claimInvitation( $issuedF, 'dzn-workspace-current-state' );
$rowF = $row( (int) $issuedF['teacher_id'] );
$expect( is_array( $rowF ) && $rowF['google']['ready_for_activation'] === false && $rowF['review']['label'] === 'Not ready', 'initial current-state row was not honest' );
$expect( str_starts_with( (string) $rowF['delivery']['label'], 'Failed' ), 'failed invitation delivery was not shown to staff' );
$expect( $rowF['availability']['state'] === 'pending', 'missing availability was not shown as pending' );
$wpdb->update( $wpdb->prefix . 'dzn_teachers', array( 'country_code' => 'AU', 'timezone' => 'Australia/Brisbane', 'locale' => 'en_AU', 'calendar_preference' => 'gregorian' ), array( 'id' => (int) $issuedF['teacher_id'] ) );
$availability->setProfile( array( 'teacher_id' => (int) $issuedF['teacher_id'], 'timezone' => 'Australia/Brisbane', 'status' => 'active' ) );
$availability->setRecurringRule( array( 'teacher_id' => (int) $issuedF['teacher_id'], 'weekday' => 3, 'local_start_time' => '12:00:00', 'local_end_time' => '14:00:00', 'state' => 'requestable', 'timezone' => 'Australia/Brisbane', 'status' => 'active' ) );
$connectionUid = strtoupper( bin2hex( random_bytes( 13 ) ) );
$wpdb->insert( $wpdb->prefix . 'dzn_integration_connections', array(
	'uid' => $connectionUid, 'provider_code' => 'google_calendar', 'teacher_id' => (int) $issuedF['teacher_id'],
	'lifecycle_sequence' => 1, 'connection_state' => 'connected', 'connection_version' => 1, 'active_slot' => 1,
	'scope_snapshot' => \Delnavazan\Platform\Core\Application\ProviderIntegrationRule::GOOGLE_CALENDAR_SCOPE,
	'consent_version' => 'acceptance_local', 'identity_digest' => hash( 'sha256', 'acceptance-google-' . $issuedF['teacher_id'] ),
	'identity_state' => 'verified', 'authorized_at' => $now, 'connected_at' => $now,
	'created_at' => $now, 'created_by' => 1, 'updated_at' => $now, 'updated_by' => 1,
) );
$connectionId = (int) $wpdb->insert_id;
$wpdb->insert( $wpdb->prefix . 'dzn_integration_credentials', array(
	'uid' => strtoupper( bin2hex( random_bytes( 13 ) ) ), 'connection_id' => $connectionId, 'credential_sequence' => 1,
	'state' => 'active', 'key_version' => 'acceptance', 'cipher_version' => 'acceptance', 'nonce' => 'acceptance',
	'ciphertext' => 'acceptance-local-not-a-real-credential', 'valid_until_utc' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
	'created_at' => $now, 'created_by' => 1,
) );
$rowF = $row( (int) $issuedF['teacher_id'] );
$expect( is_array( $rowF ) && $rowF['google']['ready_for_activation'] === true, 'Google readiness did not refresh from the connection record' );
$expect( $rowF['availability']['state'] === 'complete', 'availability readiness did not refresh from the rule record' );
$expect( $rowF['blockers'] === array(), 'resolved profile/availability/Google blockers were still shown' );
$wpdb->update( $wpdb->prefix . 'dzn_teacher_onboarding_states', array( 'state' => 'pending_review', 'readiness_state' => 'not_ready' ), array( 'teacher_id' => (int) $issuedF['teacher_id'] ) );
$rowF = $row( (int) $issuedF['teacher_id'] );
$expect( is_array( $rowF ) && $rowF['review']['label'] === 'Awaiting review', 'review state did not refresh from records' );
$wpdb->update( $wpdb->prefix . 'dzn_teacher_invitation_generations', array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'invitation_id' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dzn_teacher_invitations WHERE teacher_id=%d", (int) $issuedF['teacher_id'] ) ) ) );
$rowF = $row( (int) $issuedF['teacher_id'] );
$expect( is_array( $rowF ) && $rowF['expiry']['state'] === 'error', 'expiry did not refresh from the invitation generation' );

echo "Teacher workspace recovery acceptance: PASS\n";
