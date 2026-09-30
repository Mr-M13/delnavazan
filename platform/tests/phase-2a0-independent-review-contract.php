<?php
/*
 * Source contract for the independent-review authority/concurrency corrections.
 * It is deliberately not a substitute for isolated WordPress/MySQL behaviour.
 */
$root = dirname( __DIR__ );
$service = file_get_contents( $root . '/src/Core/Application/PrincipalInvitationService.php' );
$repository = file_get_contents( $root . '/src/Core/Infrastructure/Repository/PrincipalInvitationRepository.php' );
$migrator = file_get_contents( $root . '/src/Core/Infrastructure/Migration/Migrator.php' );
$teacher = file_get_contents( $root . '/src/Core/Infrastructure/Repository/TeacherRepository.php' );
$archive = file_get_contents( $root . '/src/Core/Application/ArchiveService.php' );

foreach ( [
	'$principalId=(int)$attempt->wordpress_user_id',
	"'Claim principal conflicts with durable attempt'",
	"'wordpress_user_id'=>\$principalId",
	"['id'=>\$attempt->id,'wordpress_user_id'=>\$principalId]",
	'transitionProvisioningAttempt',
	"\$attempt->state!=='provisioning_pending'",
	"\$attempt->wordpress_user_id!==null",
	"AND state='provisioning_pending' AND wordpress_user_id IS NULL",
	"'Claim command key conflicts with durable context'",
	'private function expired(string $expiresAt,string $now):bool{return $expiresAt<=$now;}',
	'$this->expired((string)$generation->expires_at,$now)',
] as $fragment ) if ( strpos( $service . $repository, $fragment ) === false ) throw new RuntimeException( 'Missing independent-review claim contract: ' . $fragment );

foreach ( [
	'INNER JOIN {$this->prefix}teachers t ON t.id=l.teacher_id',
	"t.status='active' AND t.archived_at IS NULL",
	"o.readiness_state='ready'",
] as $fragment ) if ( strpos( $repository, $fragment ) === false ) throw new RuntimeException( 'Missing effective Teacher authority contract: ' . $fragment );
foreach ( [ 'hasActivePrincipalAuthority', 'hasApplicableTeacherAssignments', '$repository->hasOperationalEnrolments($id)', '$repository->hasApplicableTeacherAssignments($id)', '$repository->hasActivePrincipalAuthority($id)' ] as $fragment ) if ( strpos( $teacher . $archive, $fragment ) === false ) throw new RuntimeException( 'Missing archive/offboarding coordination contract: ' . $fragment );

foreach ( [
		'self::verify_current_schema();',
		'self::ensure_capabilities(); return;',
	'private static function verify_current_schema(): void',
	"'001_initial_core_schema', '002_principal_invitation_foundation', '003_invitation_recipient_snapshot'",
	"array('teacher_invitation_generations','invitation_generation',true)",
	"array('teacher_invitation_generations','delivery_key',true)",
	"array('account_claim_attempts','generation_owner',true)",
] as $fragment ) if ( strpos( $migrator, $fragment ) === false ) throw new RuntimeException( 'Missing strict current-schema verification contract: ' . $fragment );

echo "Phase 2A.0 independent-review source contract passed\n";
