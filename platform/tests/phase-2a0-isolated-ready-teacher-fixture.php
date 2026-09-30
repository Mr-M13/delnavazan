<?php
/**
 * Disposable WP-CLI-only fixture for the real Phase 2A.0 completion path.
 * It is never loaded by the plugin and is excluded from deployment packages.
 */
defined( 'ABSPATH' ) || exit( 1 );

use Delnavazan\Platform\Core\Application\PrincipalInvitationService;
use Delnavazan\Platform\Core\Application\TeacherService;
use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;

function dzn_phase_2a0_ready_teacher_guard(): int {
    $run = (string) getenv( 'DZN_PHASE_2A0_RUNTIME_RUN_ID' );
    $environment = wp_get_environment_type();
    if ( getenv( 'DZN_PHASE_2A0_RUNTIME_TEST' ) !== 'isolated' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( $environment, array( 'local', 'development' ), true ) || ! preg_match( '/^[A-Za-z0-9_-]{12,64}$/', $run ) || get_option( 'dzn_phase_2a0_isolated_runtime_marker' ) !== $run || ! current_user_can( 'dzn_manage_onboarding' ) || ! current_user_can( 'dzn_issue_teacher_invitations' ) || ! current_user_can( 'dzn_manage_teachers' ) ) {
        throw new RuntimeException( 'Ready-teacher fixture refused.' );
    }
    return get_current_user_id();
}

/** @return array{teacher_id:int,wordpress_user_id:int,admin_user_id:int} */
function dzn_phase_2a0_create_ready_teacher_fixture(): array {
    $admin = dzn_phase_2a0_ready_teacher_guard();
    if ( $admin < 1 ) throw new RuntimeException( 'Ready-teacher fixture refused.' );
    $suffix = strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
    $recipient = 'dzn-ready-' . substr( $suffix, 0, 24 ) . '@example.invalid';
    if ( ! str_ends_with( $recipient, '@example.invalid' ) ) throw new RuntimeException( 'Ready-teacher fixture refused.' );
    $teacher = 0; $user = 0; $finalized = false; $secret = null;
    $service = new PrincipalInvitationService();
    try {
        $teacher = ( new TeacherService() )->create( array( 'display_name' => 'DZN READY RUNTIME ' . substr( $suffix, 0, 8 ), 'email' => $recipient, 'status' => 'active' ) );
        $issued = $service->issue( $teacher, $recipient, 900, 'dzn-ready-issue-' . $suffix );
        $payload = $service->prepareDelivery( (int) $issued['generation_id'] );
        $secret = (string) ( $payload['secret'] ?? '' );
        if ( strlen( $secret ) !== 64 || ( $payload['recipient'] ?? null ) !== $recipient ) throw new RuntimeException( 'Ready-teacher fixture failed.' );
        if ( ! function_exists( 'wp_create_user' ) ) require_once ABSPATH . 'wp-admin/includes/user.php';
        $user = wp_create_user( 'dznready' . substr( $suffix, 0, 12 ), wp_generate_password( 40, true, true ), $recipient );
        if ( is_wp_error( $user ) || ! $user ) throw new RuntimeException( 'Ready-teacher fixture failed.' );
        $user = (int) $user;
        wp_set_current_user( $user );
        $command = 'dzn-ready-claim-' . $suffix;
        $service->beginExistingClaim( $secret, $user, $command );
        $service->finalizeClaim( $command, $user );
        $finalized = true;
        wp_set_current_user( $admin );
        if ( ! ( new PrincipalInvitationRepository() )->hasActiveTeacherAuthority( $user, $teacher ) ) throw new RuntimeException( 'Ready-teacher fixture failed.' );
        unset( $payload, $secret, $recipient );
        return array( 'teacher_id' => $teacher, 'wordpress_user_id' => $user, 'admin_user_id' => $admin );
    } catch ( Throwable $e ) {
        wp_set_current_user( $admin );
        if ( $teacher > 0 ) {
            try { $finalized ? $service->offboard( $teacher, 'isolated_fixture_failed' ) : $service->revoke( $teacher, 'isolated_fixture_failed', 'dzn-ready-cleanup-' . $suffix ); } catch ( Throwable ) {}
        }
        if ( $user > 0 && function_exists( 'wp_delete_user' ) ) wp_delete_user( $user );
        unset( $secret, $recipient );
        throw $e;
    }
}

/** Uses the normal offboarding transition; disposable-container teardown removes residual synthetic rows. */
function dzn_phase_2a0_cleanup_ready_teacher_fixture( array $fixture ): void {
    $admin = dzn_phase_2a0_ready_teacher_guard();
    if ( (int) ( $fixture['admin_user_id'] ?? 0 ) !== $admin ) throw new RuntimeException( 'Ready-teacher fixture cleanup refused.' );
    ( new PrincipalInvitationService() )->offboard( (int) $fixture['teacher_id'], 'isolated_fixture_cleanup' );
    if ( ! function_exists( 'wp_delete_user' ) ) require_once ABSPATH . 'wp-admin/includes/user.php';
    if ( ! wp_delete_user( (int) $fixture['wordpress_user_id'] ) ) throw new RuntimeException( 'Ready-teacher fixture cleanup failed.' );
}

if ( ! defined( 'DZN_PHASE_2A0_READY_TEACHER_FIXTURE_LIBRARY' ) ) {
    try {
        $fixture = dzn_phase_2a0_create_ready_teacher_fixture();
        dzn_phase_2a0_cleanup_ready_teacher_fixture( $fixture );
        echo "Ready-teacher fixture passed; no invitation secret printed.\n";
    } catch ( Throwable ) {
        fwrite( STDERR, "Ready-teacher fixture refused or failed.\n" );
        exit( 1 );
    }
}
