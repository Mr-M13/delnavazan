<?php
// Isolated, synthetic end-to-end authority test. It deliberately never calls wp_mail.
defined( 'ABSPATH' ) || exit( 1 );

use Delnavazan\Platform\Core\Application\PrincipalInvitationService;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityService;
use Delnavazan\Platform\Core\Application\TeacherInvitationDeliveryWorker;
use Delnavazan\Platform\Core\Application\TeacherOnboardingService;
use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;
use Delnavazan\Platform\Portals\PortalAuthenticatedReadService;

if ( wp_get_environment_type() !== 'local' ) throw new RuntimeException( 'Teacher onboarding acceptance is local-only.' );

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
$user = get_userdata( $userId );
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
$ready = $state( (int) $issued['teacher_id'] );
$expect( $ready->state === 'active' && $ready->readiness_state === 'ready', 'complete profile and availability did not activate teacher' );
$expect( ! $onboarding->requiresOnboarding(), 'active ready teacher was routed back to onboarding' );
$expect( is_array( $portal->teacher() ), 'ready teacher portal did not open' );

// Removing the final usable rule must revoke both projection readiness and portal access.
$availability->retireActiveOwnRecurringRule( $ruleId );
$notReady = $state( (int) $issued['teacher_id'] );
$expect( $notReady->state === 'in_progress' && $notReady->readiness_state === 'not_ready', 'last-rule removal left teacher ready' );
$expect( $onboarding->requiresOnboarding(), 'last-rule removal did not restore onboarding gate' );
$refuse( static fn() => $portal->teacher(), 'portal remained open after final usable availability was removed' );

// A claimed secret is single-use, and an authenticated claim must bind to its frozen recipient.
wp_set_current_user( 1 );
$refuse( static fn() => $invitations->beginProvisioning( str_repeat( '0', 64 ), 'acceptance-invalid-claim' ), 'unknown invitation secret was accepted' );
$refuse( fn() => $invitations->beginProvisioning( $claimedSecret, 'acceptance-replay-claim' ), 'claimed invitation was replayed' );
unset( $claimedSecret );
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
