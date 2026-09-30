<?php
/*
 * Source/package contract only. It is not a WordPress/MySQL behavioural test.
 * Execute in an isolated PHP environment when PHP is available.
 */
$root = dirname( __DIR__ );
$migration = file_get_contents( $root . '/src/Core/Infrastructure/Migration/Migrator.php' );
$service = file_get_contents( $root . '/src/Core/Application/PrincipalInvitationService.php' );
$repository = file_get_contents( $root . '/src/Core/Infrastructure/Repository/PrincipalInvitationRepository.php' );
$admin = file_get_contents( $root . '/src/Admin/Controller/OnboardingController.php' );
$runtime = file_get_contents( $root . '/tests/phase-2a0-isolated-delivery-assertion.php' );
$negative = file_get_contents( $root . '/tests/phase-2a0-isolated-negative-claim-assertion.php' );
$readyFixture = file_get_contents( $root . '/tests/phase-2a0-isolated-ready-teacher-fixture.php' );
$runbook = file_get_contents( $root . '/docs/PHASE-2A-0-RUNTIME-VALIDATION.md' );
$required = [
    '002_principal_invitation_foundation', '003_invitation_recipient_snapshot',
    'teacher_onboarding_states', 'teacher_principal_links', 'student_principal_links',
    'teacher_invitations', 'teacher_invitation_generations',
    'student_account_invitations', 'student_account_invitation_generations',
    'account_claim_attempts', 'platform_audit_events', 'platform_outbox',
    'UNIQUE KEY teacher_id(teacher_id)', 'UNIQUE KEY wordpress_user_id(wordpress_user_id)',
    'recipient_snapshot varchar(320) NULL', 'recipient_digest char(64) NULL',
    'secret_digest char(64) NULL', 'delivery_prepared_at', 'delivery_attempt_count',
    'UNIQUE KEY secret_digest(secret_digest)', 'UNIQUE KEY command_key(command_key)',
    'UNIQUE KEY idempotency_key(idempotency_key)', 'ENGINE=InnoDB',
];
foreach ( $required as $fragment ) if ( strpos( $migration, $fragment ) === false ) throw new RuntimeException( 'Missing 2A.0 schema contract: ' . $fragment );
foreach ( [
    'install_invitation_recipient_snapshot',
    "SHOW COLUMNS FROM {\$table} LIKE %s",
    'ALTER TABLE {$table} ADD COLUMN {$definition}',
    "'recipient_snapshot' => 'recipient_snapshot varchar(320) NULL'",
    "'recipient_digest' => 'recipient_digest char(64) NULL'",
    'if ( $result === false )',
] as $fragment ) if ( strpos( $migration, $fragment ) === false ) throw new RuntimeException( 'Missing 002-to-003 upgrade contract: ' . $fragment );
if ( strpos( $migration, '002_principal_invitation_foundation' ) >= strpos( $migration, '003_invitation_recipient_snapshot' ) ) throw new RuntimeException( 'Migration 003 must remain ordered after the schema-2 foundation' );
foreach ( ['queued_for_delivery', 'prepareDelivery(int $generationId)', 'bin2hex(random_bytes(32))', 'hash_hmac(', 'recovery_required', 'Authenticated principal ownership and recipient binding required', 'Invitation has been superseded', 'Teacher principal link already exists', 'teacher_principal.claim_finalized', 'anonymizeTerminalInvitationRecipient', 'Nonterminal invitation recipient cannot be anonymized'] as $fragment ) if ( strpos( $service, $fragment ) === false ) throw new RuntimeException( 'Missing invitation/claim contract: ' . $fragment );
$issue = substr( $service, strpos( $service, 'public function issue' ), strpos( $service, 'public function prepareDelivery' ) - strpos( $service, 'public function issue' ) );
if ( strpos( $issue, 'random_bytes' ) !== false || strpos( $issue, "'secret'=>" ) !== false ) throw new RuntimeException( 'Issuance must not generate or return an invitation secret' );
foreach ( ['supersedeUnclaimedGenerations', 'revokeUnclaimedGenerations', 'cancelPendingDeliveryOutboxes', 'activateQueuedGeneration', 'markDeliveryPrepared', 'deliveryOutboxForGenerationForUpdate'] as $fragment ) if ( strpos( $repository, $fragment ) === false ) throw new RuntimeException( 'Missing delivery recovery contract: ' . $fragment );
foreach ( ['recipient_snapshot', 'recipient_digest', 'generationForSecretForUpdate', 'anonymizeTerminalRecipientSnapshots', 'anonymizeInvitationRecipientDigest', 'hasNonterminalGeneration', 'secret_digest', 'idempotency_key', 'hasActiveTeacherAuthority', 'FOR UPDATE'] as $fragment ) if ( strpos( $repository, $fragment ) === false ) throw new RuntimeException( 'Missing repository integrity contract: ' . $fragment );
if ( strpos( $service, 'prepareDelivery(int $generationId,string $recipient)' ) !== false ) throw new RuntimeException( 'Delivery preparation must not accept caller-supplied recipient authority' );
if ( strpos( $service, 'beginExistingClaim(string $secret,string $recipient' ) !== false || strpos( $service, 'beginProvisioning(string $secret,string $recipient' ) !== false ) throw new RuntimeException( 'Claim flows must bind to the frozen generation recipient' );
foreach ( ['check_admin_referer', 'dzn_manage_onboarding', 'never rendered, retained, logged, or placed in URLs'] as $fragment ) if ( strpos( $admin, $fragment ) === false ) throw new RuntimeException( 'Missing admin security contract: ' . $fragment );
if ( strpos( $admin, "['secret']" ) !== false ) throw new RuntimeException( 'Admin must not render invitation secrets' );
$phase2a0Schema = substr( $migration, strpos( $migration, 'private static function install_principal_invitation_foundation' ), strpos( $migration, 'private static function install_teaching_eligibility_foundation' ) - strpos( $migration, 'private static function install_principal_invitation_foundation' ) );
if ( strpos( $phase2a0Schema, 'booking_requests' ) !== false || strpos( $phase2a0Schema, 'availability_windows' ) !== false ) throw new RuntimeException( '2A.0 must not introduce booking/routing/availability tables' );
foreach ( ['DZN_PHASE_2A0_RUNTIME_TEST', "wp_get_environment_type() === 'production'", 'dzn_phase_2a0_isolated_runtime_marker', 'prepareDelivery', 'dzn_phase_2a0_assert_no_raw_secret_persistence', 'no invitation secret printed'] as $fragment ) if ( strpos( $runtime, $fragment ) === false ) throw new RuntimeException( 'Missing isolated runtime safety contract: ' . $fragment );
foreach ( ['DZN_PHASE_2A0_NEGATIVE_CLAIM_MODE', 'WP_CLI', 'wp_get_environment_type() === \'production\'', 'dzn_phase_2a0_negative_no_raw_secret', 'dzn_phase_2a0_negative_rejected', 'unset( $secret', 'Negative-claim assertion passed', 'expired', 'revoked', 'superseded', 'double_claim'] as $fragment ) if ( strpos( $negative, $fragment ) === false ) throw new RuntimeException( 'Missing negative-claim runtime safety contract: ' . $fragment );
if ( strpos( $negative, 'add_action(' ) !== false || strpos( $negative, 'register_rest_route' ) !== false ) throw new RuntimeException( 'Negative-claim helper must not expose an HTTP route' );
foreach ( ['DZN_PHASE_2A0_RUNTIME_TEST', 'DZN_PHASE_2A0_RUNTIME_RUN_ID', 'dzn_phase_2a0_isolated_runtime_marker', "array( 'local', 'development' )", '@example.invalid', 'TeacherService', 'issue(', 'prepareDelivery(', 'beginExistingClaim(', 'finalizeClaim(', 'hasActiveTeacherAuthority', 'offboard(', 'wp_delete_user', 'no invitation secret printed'] as $fragment ) if ( strpos( $readyFixture, $fragment ) === false ) throw new RuntimeException( 'Missing ready-teacher fixture contract: ' . $fragment );
foreach ( ['teacher_onboarding_states', 'teacher_principal_links'] as $forbidden ) if ( preg_match( '/\\$wpdb->(?:insert|update)\\([^\\n]*' . preg_quote( $forbidden, '/' ) . '/', $readyFixture ) ) throw new RuntimeException( 'Ready-teacher fixture must not write readiness directly' );
foreach ( ['002 → 003', 'No beta data or accounts', 'raw invitation secret', 'existing authenticated WordPress account', 'recovery_required', 'terminal recipient anonymization', 'rollback', 'Cleanup'] as $fragment ) if ( strpos( $runbook, $fragment ) === false ) throw new RuntimeException( 'Missing runtime validation runbook coverage: ' . $fragment );
echo "Phase 2A.0 source contract passed\n";
