<?php
// Isolated, synthetic account-claim authority test. It never hands a notification to a transport.
defined( 'ABSPATH' ) || exit( 1 );

use Delnavazan\Platform\Core\Application\NotificationService;
use Delnavazan\Platform\Core\Application\NotificationRule;
use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Application\NotificationTemplateService;
use Delnavazan\Platform\Core\Application\NotificationWorkflowService;
use Delnavazan\Platform\Core\Application\BookingRequestSubmissionService;
use Delnavazan\Platform\Core\Application\CatalogueService;
use Delnavazan\Platform\Core\Application\CoordinationCaseService;
use Delnavazan\Platform\Core\Application\EnrolmentConversionService;
use Delnavazan\Platform\Core\Application\FinalAcceptanceService;
use Delnavazan\Platform\Core\Application\PrincipalInvitationService;
use Delnavazan\Platform\Core\Application\ProposalAcceptanceService;
use Delnavazan\Platform\Core\Application\ProposalService;
use Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService;
use Delnavazan\Platform\Core\Application\StudentAccountClaimInvitationService;
use Delnavazan\Platform\Core\Application\StudentAccountClaimValidationService;
use Delnavazan\Platform\Core\Application\StudentIdentityResolutionService;
use Delnavazan\Platform\Core\Application\StudentService;
use Delnavazan\Platform\Core\Application\TeacherAcceptingStateService;
use Delnavazan\Platform\Core\Application\TeacherAssignmentService;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityAssentService;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityService;
use Delnavazan\Platform\Core\Application\TeacherOnboardingService;
use Delnavazan\Platform\Core\Application\TeachingEligibilityService;
use Delnavazan\Platform\Core\Infrastructure\ReadModel\StudentAccountClaimNotificationSubjectReadModel;
use Delnavazan\Platform\Core\Infrastructure\ReadModel\StudentNotificationRecipientReadModel;
use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;

if ( wp_get_environment_type() !== 'local' ) throw new RuntimeException( 'Student account claim acceptance is local-only.' );

$expect = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) throw new RuntimeException( 'Student account claim acceptance failed: ' . $message );
};
$refuse = static function ( callable $operation, string $message, ?string $reason = null ) use ( $expect ): void {
	try {
		$operation();
	} catch ( InvalidArgumentException | RuntimeException $error ) {
		if ( $reason !== null ) $expect( str_contains( $error->getMessage(), $reason ), $message . ' (unexpected reason)' );
		return;
	}
	$expect( false, $message );
};

// A test-only route makes the synthetic email resolvable. Consent and guardian-authority filters remain
// deliberately absent, so enqueue must refuse the notification before any transport can be involved.
add_filter( 'dzn_notification_student_route', static function ( $route, int $studentId, array $row ) {
	return str_ends_with( (string) ( $row['email'] ?? '' ), '@example.invalid' ) ? 'email' : $route;
}, 10, 3 );
$mailCalls = 0;
add_filter( 'pre_wp_mail', static function ( $return ) use ( &$mailCalls ) {
	$mailCalls++;
	return false;
}, PHP_INT_MAX );

// Issuance is intentionally fail-closed when no active workflow exists. Register the smallest
// synthetic workflow/template pair needed by this local fixture; no production fallback is added.
$fixtureKey = 'student-claim-acceptance-' . wp_generate_uuid4();
$commandKey = static fn ( string $label ): string => $fixtureKey . '-' . $label;
$templates = new NotificationTemplateService();
$template = $templates->registerTemplate( array(
	'template_key' => 'acceptance.student_claim.' . substr( hash( 'sha256', $fixtureKey ), 0, 12 ),
	'purpose' => 'acceptance',
	'locale' => 'fa',
), $commandKey( 'template' ) );
$templateVersion = $templates->registerVersion( (int) $template['template_id'], array(
	'subject_template_digest' => hash( 'sha256', 'student claim acceptance subject' ),
	'body_template_digest' => hash( 'sha256', 'student claim acceptance body {{claim_code}}' ),
	'variable_codes' => array( 'claim_code' ),
	'locale' => 'fa',
), $commandKey( 'template-version' ) );
$templates->activateVersion( (int) $template['template_id'], (int) $templateVersion['template_version_id'], $commandKey( 'template-activate' ) );

$workflows = new NotificationWorkflowService();
$workflow = $workflows->registerWorkflow( array(
	'workflow_key' => 'identity.student_claim.acceptance.' . substr( hash( 'sha256', $fixtureKey ), 0, 12 ),
	'purpose' => 'acceptance',
), $commandKey( 'workflow' ) );
$workflowVersion = $workflows->registerVersion( (int) $workflow['workflow_id'], array(
	'intent_key' => 'STUDENT_ACCOUNT_CLAIM_ISSUED',
	'audience' => 'student',
	'recipient_kind' => 'student',
	'template_id' => (int) $template['template_id'],
	'locale' => 'fa',
), $commandKey( 'workflow-version' ) );
$workflowVersionId = (int) $workflowVersion['workflow_version_id'];
$binding = NotificationRule::requiredBinding( 'STUDENT_ACCOUNT_CLAIM_ISSUED' );
foreach ( array(
	array( 'rule_code' => 'subject_exists', 'parameter_a' => $binding['aggregate'] ),
	array( 'rule_code' => 'subject_state_is', 'parameter_a' => $binding['aggregate'], 'parameter_b' => implode( ',', $binding['to_states'] ) ),
	array( 'rule_code' => 'recipient_resolvable' ),
	array( 'rule_code' => 'recipient_opted_in' ),
	array( 'rule_code' => 'guardian_authority_present' ),
	array( 'rule_code' => 'not_suppressed' ),
) as $rule ) {
	$workflows->setEligibilityRule( $workflowVersionId, $rule, $commandKey( 'eligibility-' . $rule['rule_code'] ) );
}
$workflows->setScheduleRule( $workflowVersionId, array( 'rule_code' => 'immediate' ), $commandKey( 'schedule-immediate' ) );
$workflows->setScheduleRule( $workflowVersionId, array( 'rule_code' => 'expiry', 'parameter_a' => '10' ), $commandKey( 'schedule-expiry' ) );
$workflows->activateVersion( $workflowVersionId, $commandKey( 'workflow-activate' ) );

$createUser = static function ( string $label, string $email ) use ( $expect ): int {
	$id = wp_create_user( 'dzn-claim-' . $label, 'dzn-acceptance-local-only', $email );
	$expect( ! is_wp_error( $id ) && (int) $id > 0, 'could not create synthetic WordPress user ' . $label );
	return (int) $id;
};
$students = new StudentService();
$createStudent = static function ( string $label, string $email ) use ( $students ): int {
	return $students->create( array( 'display_name' => 'Claim ' . $label, 'email' => $email, 'status' => 'active' ) );
};
$secretFor = static function ( int $notificationId ) use ( $expect ): string {
	global $wpdb;
	$snapshot = $wpdb->get_row( $wpdb->prepare(
		"SELECT s.rendered_params_envelope,s.cipher_version FROM {$wpdb->prefix}dzn_notifications n INNER JOIN {$wpdb->prefix}dzn_notification_rendered_snapshots s ON s.id=n.rendered_snapshot_id WHERE n.id=%d",
		$notificationId
	) );
	$expect( $snapshot !== null, 'issued notification has no encrypted snapshot' );
	$parameters = NotificationSupport::decryptEnvelope( (string) $snapshot->rendered_params_envelope, $snapshot->cipher_version === null ? null : (string) $snapshot->cipher_version );
	$secret = is_array( $parameters ) ? (string) ( $parameters['claim_code'] ?? '' ) : '';
	$expect( preg_match( '/^[a-f0-9]{64}$/D', $secret ) === 1, 'encrypted snapshot did not contain the synthetic claim code' );
	return $secret;
};
$issue = static function ( int $studentId, string $command ) use ( $secretFor, $expect ): array {
	wp_set_current_user( 1 );
	$result = (new StudentAccountClaimInvitationService())->issue( $studentId, 600, $command );
	$expect( empty( $result['idempotent'] ) && (string) $result['state'] === 'scheduled', 'claim issuance did not commit one scheduled notification' );
	$expect( ! array_key_exists( 'secret', $result ), 'claim issuance exposed its secret' );
	$result['fixture_secret'] = $secretFor( (int) $result['notification_id'] );
	return $result;
};
$claim = static function ( array $issued, int $userId ) use ( $expect ): int {
	wp_set_current_user( $userId );
	$evidence = (new StudentAccountClaimValidationService())->validateForAcceptance( (string) $issued['fixture_secret'], $userId );
	$expect( (int) $evidence['generation_id'] === (int) $issued['generation_id'], 'validation returned another generation' );
	return (new StudentAcceptanceAuthorityService())->establishPrincipalFromAccountClaim( (string) $issued['fixture_secret'], $userId );
};

wp_set_current_user( 1 );
$expect( current_user_can( 'dzn_issue_student_account_claims' ), 'fixture administrator lacks claim issuance authority' );
$expect( current_user_can( 'dzn_manage_notifications' ), 'fixture administrator lacks notification authority' );

// Positive issuance is idempotent and contains a durable encrypted snapshot, but absent consent keeps the
// notification scheduled and out of every dispatch/transport path.
$positiveEmail = 'student-claim-positive@example.invalid';
$positiveUser = $createUser( 'positive', $positiveEmail );
$positiveStudent = $createStudent( 'Positive', $positiveEmail );
$positive = $issue( $positiveStudent, 'acceptance-student-claim-positive' );
$replayedIssue = (new StudentAccountClaimInvitationService())->issue( $positiveStudent, 600, 'acceptance-student-claim-positive' );
$expect( ! empty( $replayedIssue['idempotent'] ) && (int) $replayedIssue['generation_id'] === (int) $positive['generation_id'] && (int) $replayedIssue['notification_id'] === (int) $positive['notification_id'], 'exact issuance replay did not converge' );
$notifications = new NotificationService( null, null, null, null, null, null, new StudentNotificationRecipientReadModel(), new StudentAccountClaimNotificationSubjectReadModel() );
$enqueue = $notifications->enqueue( (int) $positive['notification_id'], array(
	'evidence_channel' => 'system', 'evidence_reference' => 'acceptance-no-recipient-authority', 'evidence_at' => NotificationSupport::now(),
), 'acceptance-student-claim-enqueue' );
$expect( (string) $enqueue['state'] === 'scheduled' && (string) $enqueue['outcome'] === 'consent_absent', 'missing recipient authority did not fail closed before dispatch' );
$expect( $mailCalls === 0, 'claim fixture attempted to send mail' );

$principalLink = $claim( $positive, $positiveUser );
global $wpdb;
$claimed = $wpdb->get_row( $wpdb->prepare( "SELECT i.state AS invitation_state,g.state AS generation_state,i.claimed_principal_id FROM {$wpdb->prefix}dzn_student_account_claim_invitations i INNER JOIN {$wpdb->prefix}dzn_student_account_claim_generations g ON g.id=%d AND g.invitation_id=i.id", (int) $positive['generation_id'] ) );
$expect( $principalLink > 0 && $claimed && $claimed->invitation_state === 'claimed' && $claimed->generation_state === 'claimed' && (int) $claimed->claimed_principal_id === $positiveUser, 'positive claim did not atomically consume the invitation and establish its principal' );
$refuse( static fn() => (new StudentAccountClaimValidationService())->validateForAcceptance( (string) $positive['fixture_secret'], $positiveUser ), 'claimed token passed validation replay' );
$refuse( static fn() => (new StudentAcceptanceAuthorityService())->establishPrincipalFromAccountClaim( (string) $positive['fixture_secret'], $positiveUser ), 'claimed token was accepted twice' );
unset( $positive['fixture_secret'] );

// Continue through the real booking, accepted-arrangement, canonical Enrolment, and Teacher
// Assignment authorities using the claimed Student and an invitation-claimed ready Teacher.
wp_set_current_user( 1 );
$catalogue = new CatalogueService();
$instrumentId = $catalogue->instrument( array(
	'slug' => 'acceptance-journey-' . substr( hash( 'sha256', $fixtureKey ), 0, 12 ),
	'name_fa' => 'آزمون پذیرش', 'name_en' => 'Acceptance Journey', 'status' => 'active',
) );
$courseId = $catalogue->course( array(
	'instrument_id' => $instrumentId, 'name_fa' => 'کلاس آزمایشی', 'name_en' => 'Acceptance Class',
	'course_type' => 'introductory', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 5,
) );

$teacherEmail = 'teacher-journey@example.invalid';
$teacherInvitations = new PrincipalInvitationService();
$teacherIssued = $teacherInvitations->issueForEmail( $teacherEmail, 600, $commandKey( 'teacher-invitation' ) );
$teacherPrepared = $teacherInvitations->prepareDelivery( (int) $teacherIssued['generation_id'] );
( new PrincipalInvitationRepository() )->finishPreparedDelivery( (int) $teacherIssued['generation_id'], 'failed', 'acceptance_local_no_send' );
$teacherUser = $createUser( 'journey-teacher', $teacherEmail );
$teacherClaimKey = $commandKey( 'teacher-claim' );
wp_set_current_user( $teacherUser );
$teacherInvitations->beginExistingClaim( (string) $teacherPrepared['secret'], $teacherUser, $teacherClaimKey );
$teacherInvitations->finalizeClaim( $teacherClaimKey, $teacherUser );
$teacherAccount = get_userdata( $teacherUser );
if ( $teacherAccount && get_role( 'dzn_teacher' ) ) $teacherAccount->add_role( 'dzn_teacher' );
unset( $teacherPrepared['secret'] );

$teacherOnboarding = new TeacherOnboardingService();
$teacherOnboarding->updateOwnProfile( array(
	'display_name' => 'Acceptance Journey Teacher', 'email' => $teacherEmail,
	'country_code' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane',
	'locale' => 'en_AU', 'calendar_preference' => 'gregorian',
) );
$teacherAvailability = new TeacherAvailabilityService();
$teacherAvailability->setOwnProfile( array( 'timezone' => 'Australia/Brisbane' ) );
$teacherAvailability->setOwnRecurringRule( array(
	'weekday' => 3, 'local_start_time' => '09:00:00', 'local_end_time' => '12:00:00',
	'state' => 'requestable', 'timezone' => 'Australia/Brisbane',
) );
$teacherOnboarding->refreshOwnProgress();

wp_set_current_user( 1 );
$teacherId = (int) $teacherIssued['teacher_id'];
( new TeachingEligibilityService() )->setEligibility( array(
	'teacher_id' => $teacherId, 'course_id' => $courseId, 'status' => 'active', 'reason_code' => 'synthetic_fixture',
) );
( new TeacherAcceptingStateService() )->set( array(
	'teacher_id' => $teacherId, 'state' => 'accepting', 'reason_code' => 'synthetic_fixture',
) );

$requestResult = ( new BookingRequestSubmissionService() )->submitPublic( array(
	'requested_instrument_id' => $instrumentId, 'selected_intro_course_id' => $courseId,
	'full_name' => 'Claim Positive', 'email' => $positiveEmail, 'mobile' => '+61400000000',
	'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane',
	'communication_language' => 'en', 'whatsapp_same_as_mobile' => true, 'whatsapp_number' => '',
	'privacy_notice_accepted' => true, 'privacy_notice_version' => '2026-09-05',
	'requested_times' => array( array(
		'local_date' => gmdate( 'Y-m-d', strtotime( '+14 days' ) ),
		'local_start_time' => '09:00', 'timezone' => 'Australia/Brisbane',
	) ),
), $commandKey( 'booking-request' ) );
$requestId = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$wpdb->prefix}dzn_booking_requests WHERE reference_code=%s",
	(string) $requestResult['request_reference']
) );
$expect( $requestId > 0, 'booking request was not persisted' );
$coordination = new CoordinationCaseService();
$case = $coordination->open( $requestId, 'admin_opened' );
$candidate = $coordination->addCandidate( (int) $case['case_id'], $teacherId, 'manual_search', 'manual_review' );
$now = gmdate( 'Y-m-d H:i:s' );
$assent = ( new TeacherAvailabilityAssentService() )->recordAdministratorAttestation( array(
	'candidate_id' => (int) $candidate['candidate_id'], 'candidate_version' => (int) $candidate['version'],
	'teacher_id' => $teacherId, 'course_id' => $courseId, 'delivery_mode' => 'online',
	'location_scope' => 'not_applicable', 'frequency_per_week' => 1, 'expected_duration_minutes' => 30,
	'commencement_window_start' => gmdate( 'Y-m-d H:i:s', strtotime( '+14 days' ) ),
	'commencement_window_end' => gmdate( 'Y-m-d H:i:s', strtotime( '+21 days' ) ),
	'valid_until' => gmdate( 'Y-m-d H:i:s', strtotime( '+60 days' ) ), 'timezone' => 'Australia/Brisbane',
	'conditions_code' => 'none', 'evidence_channel' => 'message_reference',
	'evidence_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 minute' ) ), 'attribution_basis' => 'direct_teacher_statement',
) );
$proposal = ( new ProposalService() )->issueInitial( (int) $candidate['candidate_id'], (string) $assent['fingerprint'], $commandKey( 'proposal' ) );
$proposalVersion = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dzn_proposal_versions WHERE id=%d", (int) $proposal['version_id'] ) );
$expect( $proposalVersion !== null, 'proposal version was not persisted' );
$provisional = ( new ProposalAcceptanceService() )->record(
	(string) $proposal['family_uid'], (string) $proposal['option_uid'], (int) $proposal['version_number'],
	(string) $proposalVersion->prospective_subject_ref, 'message_reference', $now, $commandKey( 'provisional-acceptance' )
);
( new StudentIdentityResolutionService() )->resolveExisting( $requestId, $positiveStudent, 'synthetic_fixture', 'synthetic_fixture', $now, 1 );
( new StudentAcceptanceAuthorityService() )->classify( $positiveStudent, 'adult', 'synthetic_fixture', 'synthetic_fixture', $now, 1 );
$provisionalUid = (string) $wpdb->get_var( $wpdb->prepare(
	"SELECT uid FROM {$wpdb->prefix}dzn_proposal_acceptance_events WHERE id=%d", (int) $provisional['event_id']
) );
$accepted = ( new FinalAcceptanceService() )->accept(
	$requestId, (int) $case['case_id'], (string) $proposal['family_uid'], (string) $proposal['option_uid'],
	(int) $proposal['version_number'], $provisionalUid, $positiveStudent, $positiveUser,
	'affirmed', 'message_reference', $now, $commandKey( 'final-acceptance' )
);
$converted = ( new EnrolmentConversionService() )->convert( (int) $accepted['arrangement_id'], $commandKey( 'enrolment-conversion' ) );
$assignment = ( new TeacherAssignmentService() )->assignInitial( (int) $converted['enrolment_id'], $commandKey( 'teacher-assignment' ) );
$association = $wpdb->get_row( $wpdb->prepare(
	"SELECT e.student_id,e.teacher_id,e.course_id,e.lifecycle_state,a.teacher_id AS assigned_teacher_id,a.state AS assignment_state
	FROM {$wpdb->prefix}dzn_enrolments e
	INNER JOIN {$wpdb->prefix}dzn_teacher_assignments a ON a.enrolment_id=e.id AND a.applicable_slot=1
	WHERE e.id=%d AND a.id=%d",
	(int) $converted['enrolment_id'], (int) $assignment['assignment_id']
) );
$expect(
	$association && (int) $association->student_id === $positiveStudent
		&& (int) $association->teacher_id === $teacherId && (int) $association->assigned_teacher_id === $teacherId
		&& (int) $association->course_id === $courseId && (string) $association->lifecycle_state === 'authorised'
		&& (string) $association->assignment_state === 'assigned',
	'claimed Student, invited Teacher, canonical Enrolment, and class assignment did not converge'
);
$expect( $mailCalls === 0, 'integrated journey attempted to send mail' );
$transportProbe = wp_mail(
	'transport-intercept@example.invalid',
	'Acceptance transport interception',
	'This synthetic message must be stopped by pre_wp_mail.'
);
$expect( $transportProbe === false && $mailCalls === 1, 'synthetic mail was not intercepted before transport' );

// A logged-in account with a different email is not the invitation recipient. Both the preview validator
// and the final transactional authority independently enforce that binding; the matching account can still claim.
wp_set_current_user( 1 );
$boundEmail = 'student-claim-bound@example.invalid';
$boundUser = $createUser( 'bound', $boundEmail );
$wrongUser = $createUser( 'wrong', 'student-claim-wrong@example.invalid' );
$boundStudent = $createStudent( 'Bound', $boundEmail );
$bound = $issue( $boundStudent, 'acceptance-student-claim-bound' );
wp_set_current_user( $wrongUser );
$refuse( static fn() => (new StudentAccountClaimValidationService())->validateForAcceptance( (string) $bound['fixture_secret'], $wrongUser ), 'wrong account passed claim validation', 'does not match account claim recipient' );
$refuse( static fn() => (new StudentAcceptanceAuthorityService())->establishPrincipalFromAccountClaim( (string) $bound['fixture_secret'], $wrongUser ), 'wrong account consumed the claim', 'does not match account claim recipient' );
$expect( $claim( $bound, $boundUser ) > 0, 'matching account could not claim after wrong-principal refusal' );
unset( $bound['fixture_secret'] );

// Expiry is checked by both boundaries and leaves no principal link behind.
wp_set_current_user( 1 );
$expiredEmail = 'student-claim-expired@example.invalid';
$expiredUser = $createUser( 'expired', $expiredEmail );
$expiredStudent = $createStudent( 'Expired', $expiredEmail );
$expired = $issue( $expiredStudent, 'acceptance-student-claim-expired' );
$changed = $wpdb->update( $wpdb->prefix . 'dzn_student_account_claim_generations', array( 'expires_at' => '2000-01-01 00:00:00' ), array( 'id' => (int) $expired['generation_id'] ) );
$expect( $changed === 1, 'could not age the synthetic token' );
wp_set_current_user( $expiredUser );
$refuse( static fn() => (new StudentAccountClaimValidationService())->validateForAcceptance( (string) $expired['fixture_secret'], $expiredUser ), 'expired token passed validation', 'account_claim_expired' );
$refuse( static fn() => (new StudentAcceptanceAuthorityService())->establishPrincipalFromAccountClaim( (string) $expired['fixture_secret'], $expiredUser ), 'expired token established a principal' );
$expiredLinks = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dzn_student_principal_links WHERE student_id=%d", $expiredStudent ) );
$expect( $expiredLinks === 0 && $mailCalls === 1, 'expired/fail-closed paths created authority or bypassed the transport guard' );
unset( $expired['fixture_secret'] );

echo "Student account claim acceptance: PASS\n";
