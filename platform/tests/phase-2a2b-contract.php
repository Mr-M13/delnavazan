<?php
/** Static contract guard only. Runtime concurrency requires isolated WordPress/MySQL. */
$root = dirname( __DIR__ );
$service = file_get_contents( $root . '/src/Core/Application/CoordinationCaseService.php' );
$repository = file_get_contents( $root . '/src/Core/Infrastructure/Repository/CoordinationCaseRepository.php' );
$migration = file_get_contents( $root . '/src/Core/Infrastructure/Migration/Migrator.php' );
$privacy = file_get_contents( $root . '/src/Core/Application/BookingRequestPrivacyService.php' );
$controller = file_get_contents( $root . '/src/Admin/Controller/CoordinationCaseController.php' );
$menu = file_get_contents( $root . '/src/Admin/Controller/Menu.php' );
$docs = file_get_contents( $root . '/docs/PHASE-2A-2-COORDINATION-CANDIDATES.md' );
$runtime = file_get_contents( $root . '/tests/phase-2a2b-isolated-runtime.php' );
$worker = file_get_contents( $root . '/tests/phase-2a2b-concurrency-worker.php' );

foreach ( array( '008_coordination_candidate_foundation', 'coordination_cases', 'coordination_case_candidates', 'UNIQUE KEY booking_request_id', 'UNIQUE KEY case_teacher', 'ENGINE=InnoDB', 'verify_coordination_candidate_schema', 'dzn_manage_booking_request_coordination' ) as $needle ) if ( strpos( $migration, $needle ) === false ) throw new RuntimeException( 'Missing Phase 2A.2-B schema/capability: ' . $needle );
foreach ( array( 'open', 'candidate_search', 'manual_search', 'waiting_for_availability', 'withdrawn', 'declined', 'unable_to_arrange', 'abandoned', 'advisory_match', 'student_or_guardian_preference', 'teacher_referral', 'operational_referral', 'approved_exception', 'unreviewed', 'under_discussion', 'not_available', 'not_suitable', 'withdrawn_from_consideration', 'superseded', 'closed', 'expectedVersion', 'FOR UPDATE', 'changed concurrently', 'Booking Request is not available for coordination' ) as $needle ) if ( strpos( $service . $repository, $needle ) === false ) throw new RuntimeException( 'Missing Coordination Case contract: ' . $needle );
foreach ( array( 'terminateForPrivacyErasure', 'privacy_erased', 'coordination_case.terminated_privacy_erasure', 'coordination_candidate.closed_privacy_erasure', 'platform_audit_events', 'reference_code' ) as $needle ) if ( strpos( $repository . $privacy, $needle ) === false ) throw new RuntimeException( 'Missing privacy/audit contract: ' . $needle );
foreach ( array( 'current_user_can', 'check_admin_referer', 'dzn_coordination_open', 'dzn_coordination_candidate_', 'dzn_coordination_case_state_', 'dzn_coordination_candidate_state_', 'wp_safe_redirect' ) as $needle ) if ( strpos( $controller . $menu, $needle ) === false ) throw new RuntimeException( 'Missing protected internal admin contract: ' . $needle );
foreach ( array( 'Teacher Availability Assent', 'not identity', 'availability_requested', 'available_in_principle', 'assignment', 'proposal', 'acceptance', 'conversion' ) as $needle ) if ( strpos( $docs, $needle ) === false ) throw new RuntimeException( 'Missing authority-boundary documentation: ' . $needle );
$legacyController = substr( $controller, 0, strpos( $controller, 'private static function provisionalAcceptanceControls' ) );
foreach ( array( 'teacher_offers', 'booking_reservations', 'createStudent', 'createLesson', 'platform_payments', 'amelia_', 'wp_amelia', 'proposal_open', 'accepted_pending' ) as $forbidden ) if ( stripos( $service . $repository . $legacyController, $forbidden ) !== false ) throw new RuntimeException( 'Phase 2A.2-B exceeded authority: ' . $forbidden );
foreach ( array( 'DZN_PHASE_2A2B_RUNTIME_TEST', 'dzn-coordination-runtime@example.invalid', 'Canonical Coordination Case failed', 'Stale candidate update was accepted', 'Privacy erasure did not terminate coordination safely', 'Coordination REST surface exists' ) as $needle ) if ( strpos( $runtime, $needle ) === false ) throw new RuntimeException( 'Missing isolated runtime guard: ' . $needle );
foreach ( array( 'phase2a2b.release', 'transitionCandidate', 'candidate_transition=won', 'candidate_transition=stale_rejected' ) as $needle ) if ( strpos( $worker, $needle ) === false ) throw new RuntimeException( 'Missing concurrency runtime guard: ' . $needle );
echo "Phase 2A.2-B source contract passed\n";
