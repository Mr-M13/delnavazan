<?php
/** Phase 2A.2-D source contract guard. Runtime and concurrency checks are separate WP-CLI scripts. */
$root = dirname( __DIR__ );
$service = file_get_contents( $root . '/src/Core/Application/ProposalService.php' );
$idempotency = file_get_contents( $root . '/src/Core/Application/ProposalIdempotency.php' );
$repository = file_get_contents( $root . '/src/Core/Infrastructure/Repository/ProposalRepository.php' );
$assent = file_get_contents( $root . '/src/Core/Application/TeacherAvailabilityAssentService.php' );
$migration = file_get_contents( $root . '/src/Core/Infrastructure/Migration/Migrator.php' );
$controller = file_get_contents( $root . '/src/Admin/Controller/CoordinationCaseController.php' );
$privacy = file_get_contents( $root . '/src/Core/Application/BookingRequestPrivacyService.php' );
$plugin = file_get_contents( $root . '/delnavazan-platform.php' );
$docs = file_get_contents( $root . '/docs/PHASE-2A-2D-PROPOSAL-FOUNDATION.md' );

if ( ! preg_match( "/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/", $plugin, $schema ) || (int) $schema[1] < 10 ) throw new RuntimeException( 'Missing compatible Proposal schema marker' );
foreach ( array( '010_proposal_foundation', 'proposal_families', 'proposal_options', 'proposal_versions', 'ENGINE=InnoDB', 'verify_proposal_schema', 'dzn_issue_booking_request_proposals' ) as $needle ) if ( strpos( $migration . $plugin, $needle ) === false ) throw new RuntimeException( 'Missing Proposal schema/capability: ' . $needle );
foreach ( array(
    "UNIQUE KEY booking_request_id(booking_request_id)",
    "UNIQUE KEY coordination_case_id(coordination_case_id)",
    "UNIQUE KEY family_candidate(proposal_family_id,candidate_id)",
    "UNIQUE KEY family_teacher(proposal_family_id,teacher_id)",
    "UNIQUE KEY option_version(proposal_option_id,version_number)",
    "UNIQUE KEY supersedes_version(supersedes_proposal_version_id)",
    "UNIQUE KEY command_key_digest(command_key_digest)",
    "UNIQUE KEY option_fingerprint(proposal_option_id,version_fingerprint)",
    "UNIQUE KEY current_version_id(current_version_id)",
    "KEY exact_version(proposal_family_id,proposal_option_id,version_number)"
) as $needle ) if ( strpos( $migration, $needle ) === false ) throw new RuntimeException( 'Missing Proposal concurrency invariant: ' . $needle );
foreach ( array( 'issueInitial', 'issueReplacement', 'consumeCurrentForFutureProposalIssuance', 'versionForCommand', 'familyForCaseForUpdate', 'optionForFamilyCandidateForUpdate', 'currentVersionForOption', 'advanceOptionCurrent', 'Proposal Version changed concurrently', 'Equivalent Proposal Version already exists', 'Proposal material facts are unchanged', 'IdempotencyConflictException' ) as $needle ) if ( strpos( $service . $repository, $needle ) === false ) throw new RuntimeException( 'Missing Proposal service invariant: ' . $needle );

$lockedIssuance = strpos( $service, 'private function issueLocked' );
$familyLock = strpos( $service, 'ensureFamily(', $lockedIssuance );
$optionLock = strpos( $service, 'ensureOption(', $lockedIssuance );
$versionLock = strpos( $service, 'versionForCommand', $lockedIssuance );
if ( $lockedIssuance === false || $familyLock === false || $optionLock === false || $versionLock === false || $lockedIssuance > $familyLock || $familyLock > $optionLock || $optionLock > $versionLock ) throw new RuntimeException( 'Proposal lock order no longer follows Assent -> Family -> Option -> Version' );
if ( substr_count( $service, 'consumeCurrentForFutureProposalIssuance' ) !== 2 ) throw new RuntimeException( 'Every issuance path must use authoritative Assent consumption' );
if ( strpos( $service, '$this->assents->current(' ) !== false ) throw new RuntimeException( 'Proposal issuance bypasses Assent authority through current()' );
if ( preg_match( '/(?:begin|commit|rollback)\s*\(/', $service . $repository ) ) throw new RuntimeException( 'Proposal code creates a nested transaction' );

foreach ( array( 'updated_at', 'updated_by', 'archived_at', 'state varchar', 'status varchar' ) as $mutable ) {
    $create = substr( $migration, strpos( $migration, 'CREATE TABLE {$p}proposal_versions' ) );
    $create = substr( $create, 0, strpos( $create, 'ENGINE=InnoDB' ) );
    if ( stripos( $create, $mutable ) !== false ) throw new RuntimeException( 'Proposal Version has mutable lifecycle field: ' . $mutable );
}
foreach ( array( 'full_name', 'email', 'mobile', 'whatsapp', 'address', 'city', 'communication_language', 'notes', 'message', 'contact_snapshot' ) as $pii ) if ( preg_match( '/[\x27"]' . preg_quote( $pii, '/' ) . '[\x27"]\s*=>/i', $service ) ) throw new RuntimeException( 'Proposal Version freezes contact PII: ' . $pii );
foreach ( array( 'source_assent_uid', 'source_assent_version', 'prospective_subject_ref', 'arrangement_fingerprint', 'assent_authority_actor_id', 'assent_attribution_basis', 'assent_evidence_channel', 'assent_evidence_reference', 'issued_at', 'issued_by' ) as $evidence ) if ( strpos( $service . $migration, $evidence ) === false ) throw new RuntimeException( 'Missing independently intelligible historical evidence: ' . $evidence );
foreach ( array( 'createStudent', 'createLesson', 'createEnrolment', 'acceptProposal', 'teacher_assignments', 'platform_payments', 'amelia_', 'wp_amelia', 'register_rest_route', 'platform_outbox' ) as $forbidden ) if ( stripos( $service . $repository . $controller, $forbidden ) !== false ) throw new RuntimeException( 'Proposal Foundation exceeded authority: ' . $forbidden );
foreach ( array( 'issue_proposal_initial', 'issue_proposal_replacement', 'dzn_proposal_issue', 'requireProposalCapability', 'check_admin_referer', 'wp_safe_redirect' ) as $needle ) if ( strpos( $controller, $needle ) === false ) throw new RuntimeException( 'Missing protected Proposal coordination surface: ' . $needle );
foreach ( array( 'raw client keys never reach persistence', 'Family', 'Option', 'immutable', 'no acceptance', 'privacy', 'Assent', 'lock order', 'runtime' ) as $needle ) if ( stripos( $docs . $idempotency, $needle ) === false ) throw new RuntimeException( 'Missing Proposal documentation: ' . $needle );
if ( strpos( $privacy, 'invalidateForPrivacyErasure' ) === false || strpos( $service, 'consumeCurrentForFutureProposalIssuance' ) === false ) throw new RuntimeException( 'Privacy erasure does not close later issuance at the Assent authority gate' );
echo "Phase 2A.2-D source contract passed\n";
