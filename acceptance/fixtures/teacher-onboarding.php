<?php
// Isolated, synthetic end-to-end authority test. It deliberately never calls wp_mail.
defined( 'ABSPATH' ) || exit( 1 );

use Delnavazan\Platform\Core\Application\PrincipalInvitationService;
use Delnavazan\Platform\Core\Application\ArchiveService;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityService;
use Delnavazan\Platform\Core\Application\TeacherInvitationDeliveryWorker;
use Delnavazan\Platform\Core\Application\TeacherOnboardingService;
use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;
use Delnavazan\Platform\Portals\PortalAuthenticatedReadService;

if ( wp_get_environment_type() !== 'local' ) throw new RuntimeException( 'Teacher onboarding acceptance is local-only.' );
global $wpdb;

$expect = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) throw new RuntimeException( 'Teacher onboarding acceptance failed: ' . $message );
};
$refuse = static function ( callable $operation, string $message ) use ( $expect ): void {
	try { $operation(); } catch ( InvalidArgumentException | RuntimeException ) { return; }
	$expect( false, $message );
};
$state = static function ( int $teacherId ): object {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT state,readiness_state,profile_state,availability_state FROM {$wpdb->prefix}dzn_teacher_onboarding_states WHERE teacher_id=%d", $teacherId ) );
	if ( ! $row ) throw new RuntimeException( 'Missing teacher onboarding state.' );
	return $row;
};

wp_set_current_user( 1 );
$expect( current_user_can( 'dzn_issue_teacher_invitations' ), 'fixture administrator lacks invitation authority' );
$invitations = new PrincipalInvitationService();
$repository = new PrincipalInvitationRepository();

// Creation records delivery intent only in local mode. The direct preparation below is a
// controlled substitute for a received secret and is closed as failed before any claim.
$issued = $invitations->issueForEmail( 'onboarding-created@example.invalid', 600, 'acceptance-created-invitation' );
$expect( $issued['status'] === 'queued_for_delivery' && ! empty( $issued['teacher_created'] ), 'new invitation was not queued' );
$expect( TeacherInvitationDeliveryWorker::dispatchNow( (int) $issued['generation_id'] ) === 'queued', 'local dispatch was not fail-closed' );
$prepared = $invitations->prepareDelivery( (int) $issued['generation_id'] );
$repository->finishPreparedDelivery( (int) $issued['generation_id'], 'failed', 'acceptance_local_no_send' );

$claimCommand = 'acceptance-created-claim';
$claimedSecret = (string) $prepared['secret'];
$claim = $invitations->beginProvisioning( $claimedSecret, $claimCommand );
$expect( $claim['recipient'] === 'onboarding-created@example.invalid', 'claim recipient was not frozen to invitation' );
$userId = wp_create_user( 'dzn-acceptance-onboarding-created', 'dzn-acceptance-local-only', 'onboarding-created@example.invalid' );
$expect( ! is_wp_error( $userId ) && (int) $userId > 0, 'synthetic teacher account could not be provisioned' );
$userId = (int) $userId;
$invitations->recordProvisioningResult( $claimCommand, $userId, true );
$invitations->finalizeClaim( $claimCommand, $userId );
$expect( (int) $wpdb->get_var( $wpdb->prepare( "SELECT wordpress_user_id FROM {$wpdb->prefix}dzn_teachers WHERE id=%d", (int) $issued['teacher_id'] ) ) === $userId, 'canonical claim was not projected onto the Teacher profile' );
$user = get_userdata( $userId );
if ( $user ) $user->add_role( 'subscriber' );
if ( $user && get_role( 'dzn_teacher' ) ) $user->add_role( 'dzn_teacher' );
unset( $prepared['secret'] );

$expect( $state( (int) $issued['teacher_id'] )->state === 'linked_pending', 'claim did not create linked-pending onboarding' );
wp_set_current_user( $userId );
$onboarding = new TeacherOnboardingService();
$portal = new PortalAuthenticatedReadService();
$expect( $onboarding->requiresOnboarding(), 'incomplete linked teacher was treated as ready' );
$refuse( static fn() => $portal->teacher(), 'incomplete teacher portal was not denied' );

$onboarding->updateOwnProfile( array(
	'display_name' => 'Acceptance Onboarding Teacher', 'email' => 'onboarding-created@example.invalid',
	'country_code' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane',
	'locale' => 'en_AU', 'calendar_preference' => 'gregorian',
) );
$refuse( static fn() => $portal->teacher(), 'profile-only teacher portal was not denied' );
$availability = new TeacherAvailabilityService();
$availability->setOwnProfile( array( 'timezone' => 'Australia/Brisbane' ) );
$ruleId = $availability->setOwnRecurringRule( array(
	'weekday' => 1, 'local_start_time' => '09:00:00', 'local_end_time' => '11:00:00',
	'state' => 'requestable', 'timezone' => 'Australia/Brisbane',
) );
$onboarding->refreshOwnProgress();
// Runtime readiness requires a usable Google Calendar connection. Seed a disposable synthetic
// connection/credential; no provider request is made and no real secret is stored.
$now = current_time( 'mysql', true );
$providerScope = \Delnavazan\Platform\Core\Application\ProviderIntegrationRule::GOOGLE_CALENDAR_SCOPE;
$connectionUid = strtoupper( bin2hex( random_bytes( 13 ) ) );
$wpdb->insert( $wpdb->prefix . 'dzn_integration_connections', array(
	'uid' => $connectionUid, 'provider_code' => 'google_calendar', 'teacher_id' => (int) $issued['teacher_id'],
	'lifecycle_sequence' => 1, 'connection_state' => 'connected', 'connection_version' => 1, 'active_slot' => 1,
	'scope_snapshot' => $providerScope, 'consent_version' => 'acceptance_local', 'identity_digest' => hash( 'sha256', 'acceptance-google-' . $issued['teacher_id'] ),
	'identity_state' => 'verified', 'authorized_at' => $now, 'connected_at' => $now, 'created_at' => $now,
	'created_by' => $userId, 'updated_at' => $now, 'updated_by' => $userId,
) );
$connectionId = (int) $wpdb->insert_id;
$expect( $connectionId > 0, 'synthetic Google connection was not created' );
$wpdb->insert( $wpdb->prefix . 'dzn_integration_credentials', array(
	'uid' => strtoupper( bin2hex( random_bytes( 13 ) ) ), 'connection_id' => $connectionId, 'credential_sequence' => 1,
	'state' => 'active', 'key_version' => 'acceptance', 'cipher_version' => 'acceptance', 'nonce' => 'acceptance',
	'ciphertext' => 'acceptance-local-not-a-real-credential', 'valid_until_utc' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
	'created_at' => $now, 'created_by' => $userId,
) );
$onboarding->refreshOwnProgress();
$complete = $state( (int) $issued['teacher_id'] );
$expect( $complete->state === 'in_progress' && $complete->profile_state === 'complete' && $complete->availability_state === 'complete', 'complete facts were not ready for submission' );
$onboarding->submitOwn();
$expect( $state( (int) $issued['teacher_id'] )->state === 'pending_review', 'teacher submission did not enter pending review' );
$refuse( static fn() => $portal->teacher(), 'pending-review teacher portal was not denied' );
wp_set_current_user( 1 );
$onboarding->review( (int) $issued['teacher_id'], 'return', 'availability_confirmation_required' );
$expect( $state( (int) $issued['teacher_id'] )->state === 'returned', 'administrator return did not reopen onboarding' );
wp_set_current_user( $userId );
$onboarding->submitOwn();
wp_set_current_user( 1 );
$onboarding->review( (int) $issued['teacher_id'], 'approve' );
$ready = $state( (int) $issued['teacher_id'] );
$expect( $ready->state === 'active' && $ready->readiness_state === 'ready', 'administrator approval did not activate teacher' );
wp_set_current_user( $userId );
$expect( ! $onboarding->requiresOnboarding(), 'active ready teacher was routed back to onboarding' );
$expect( is_array( $portal->teacher() ), 'ready teacher portal did not open' );

// Induction is separate from activation: progress never changes the canonical lifecycle.
$induction = new \Delnavazan\Platform\Core\Application\Induction\TeacherInductionService();
$inductionHub = $induction->hubForUser( $userId );
$expect( count( $inductionHub['modules'] ) === 7 && $inductionHub['completed_count'] === 0, 'new induction progress was not empty' );
$inductionFirst = $induction->completeForUser( 'portal', $userId );
$expect( $inductionFirst['state'] === 'completed' && $inductionFirst['already_completed'] === false, 'induction completion was not recorded' );
$inductionAgain = $induction->completeForUser( 'portal', $userId );
$expect( $inductionAgain['already_completed'] === true && $inductionAgain['completed_at'] === $inductionFirst['completed_at'], 'repeated induction completion was not idempotent' );
$expect( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dzn_teacher_induction_progress WHERE teacher_id=%d AND module_id='portal'", (int) $issued['teacher_id'] ) ) === 1, 'induction completion created duplicate rows' );
$expect( $state( (int) $issued['teacher_id'] )->state === 'active' && $state( (int) $issued['teacher_id'] )->readiness_state === 'ready', 'induction progress changed canonical activation' );
wp_set_current_user( 1 );
$refuse( static fn() => $induction->hubForUser( 1 ), 'unlinked account read induction progress' );
wp_set_current_user( $userId );

// Removing the final usable rule must revoke both projection readiness and portal access.
$availability->retireActiveOwnRecurringRule( $ruleId );
$notReady = $state( (int) $issued['teacher_id'] );
$expect( $notReady->state === 'in_progress' && $notReady->readiness_state === 'not_ready', 'last-rule removal left teacher ready' );
$expect( $onboarding->requiresOnboarding(), 'last-rule removal did not restore onboarding gate' );
$refuse( static fn() => $portal->teacher(), 'portal remained open after final usable availability was removed' );

// A later complete resubmission can be rejected without granting portal access.
$replacementRuleId = $availability->setOwnRecurringRule( array(
	'weekday' => 2, 'local_start_time' => '09:00:00', 'local_end_time' => '11:00:00',
	'state' => 'requestable', 'timezone' => 'Australia/Brisbane',
) );
$onboarding->refreshOwnProgress();
$onboarding->submitOwn();
wp_set_current_user( 1 );
$onboarding->review( (int) $issued['teacher_id'], 'reject', 'application_not_approved' );
$expect( $state( (int) $issued['teacher_id'] )->state === 'rejected', 'administrator rejection was not retained' );
wp_set_current_user( $userId );
$refuse( static fn() => $portal->teacher(), 'rejected teacher portal was not denied' );

// A claimed secret is single-use, and an authenticated claim must bind to its frozen recipient.
wp_set_current_user( 1 );
$refuse( static fn() => $invitations->beginProvisioning( str_repeat( '0', 64 ), 'acceptance-invalid-claim' ), 'unknown invitation secret was accepted' );
$refuse( fn() => $invitations->beginProvisioning( $claimedSecret, 'acceptance-replay-claim' ), 'claimed invitation was replayed' );
unset( $claimedSecret );

// Soft archive must revoke only Teacher authority, retain lifecycle history, and
// make restoration explicitly require a replacement invitation.
global $wpdb;
$teacherId = (int) $issued['teacher_id'];
$eventsBeforeArchive = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dzn_teacher_onboarding_events WHERE teacher_id=%d", $teacherId ) );
( new ArchiveService() )->archive( 'teacher', $teacherId );
$archived = $wpdb->get_row( $wpdb->prepare( "SELECT status,archived_at FROM {$wpdb->prefix}dzn_teachers WHERE id=%d", $teacherId ) );
$link = $wpdb->get_row( $wpdb->prepare( "SELECT status,wordpress_user_id FROM {$wpdb->prefix}dzn_teacher_principal_links WHERE teacher_id=%d", $teacherId ) );
$invitationStatus = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}dzn_teacher_invitations WHERE teacher_id=%d", $teacherId ) );
$archivedUser = get_userdata( $userId );
$expect( $archived && $archived->status === 'archived' && $archived->archived_at !== null, 'Teacher was not soft archived' );
$expect( $link && $link->status === 'revoked' && (int) $link->wordpress_user_id === $userId, 'Teacher principal history was not retained as revoked' );
$expect( $invitationStatus === 'revoked', 'archive did not revoke the invitation aggregate' );
$expect( $archivedUser && ! in_array( 'dzn_teacher', (array) $archivedUser->roles, true ) && in_array( 'subscriber', (array) $archivedUser->roles, true ), 'archive did not preserve non-Teacher WordPress roles' );
$expect( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dzn_teacher_onboarding_events WHERE teacher_id=%d", $teacherId ) ) > $eventsBeforeArchive, 'archive did not retain and append lifecycle history' );

( new ArchiveService() )->restore( 'teacher', $teacherId );
$restored = $wpdb->get_row( $wpdb->prepare( "SELECT status,archived_at FROM {$wpdb->prefix}dzn_teachers WHERE id=%d", $teacherId ) );
$expect( $restored && $restored->status === 'active' && $restored->archived_at === null, 'Teacher was not restored for re-invitation' );
$expect( (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}dzn_teacher_principal_links WHERE teacher_id=%d", $teacherId ) ) === 'revoked', 'restore silently restored Teacher access' );
$replacement = $invitations->issueForEmail( 'onboarding-created@example.invalid', 600, 'acceptance-restored-invitation' );
$replacementPrepared = $invitations->prepareDelivery( (int) $replacement['generation_id'] );
$repository->finishPreparedDelivery( (int) $replacement['generation_id'], 'failed', 'acceptance_local_no_send' );
wp_set_current_user( $userId );
$invitations->beginExistingClaim( (string) $replacementPrepared['secret'], $userId, 'acceptance-restored-claim' );
$invitations->finalizeClaim( 'acceptance-restored-claim', $userId );
$restoredUser = get_userdata( $userId ); if ( $restoredUser && get_role( 'dzn_teacher' ) ) $restoredUser->add_role( 'dzn_teacher' );
$expect( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dzn_teacher_principal_links WHERE teacher_id=%d", $teacherId ) ) === 1, 're-invitation duplicated principal history' );
$expect( (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}dzn_teacher_principal_links WHERE teacher_id=%d", $teacherId ) ) === 'active', 'matching revoked principal was not safely reactivated' );
unset( $replacementPrepared['secret'] );

wp_set_current_user( 1 );
$targetId = wp_create_user( 'dzn-acceptance-onboarding-target', 'dzn-acceptance-local-only', 'onboarding-target@example.invalid' );
$wrongId = wp_create_user( 'dzn-acceptance-onboarding-wrong', 'dzn-acceptance-local-only', 'onboarding-wrong@example.invalid' );
$expect( ! is_wp_error( $targetId ) && ! is_wp_error( $wrongId ), 'synthetic existing accounts could not be created' );
$bound = $invitations->issueForEmail( 'onboarding-target@example.invalid', 600, 'acceptance-existing-invitation' );
$boundPrepared = $invitations->prepareDelivery( (int) $bound['generation_id'] );
$repository->finishPreparedDelivery( (int) $bound['generation_id'], 'failed', 'acceptance_local_no_send' );
wp_set_current_user( (int) $wrongId );
$refuse( fn() => $invitations->beginExistingClaim( (string) $boundPrepared['secret'], (int) $wrongId, 'acceptance-wrong-recipient' ), 'existing claim accepted a different account email' );
unset( $boundPrepared['secret'] );

echo "Teacher onboarding acceptance: PASS\n";
