<?php
/** Static contract guard only. Runtime concurrency requires isolated WordPress/MySQL. */
$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/Core/Application/BookingRequestMatchAssessmentService.php');
$repository = file_get_contents($root . '/src/Core/Infrastructure/Repository/BookingRequestMatchAssessmentRepository.php');
$exceptions = file_get_contents($root . '/src/Core/Application/ExceptionService.php');
$exceptionRepository = file_get_contents($root . '/src/Core/Infrastructure/Repository/OperationalExceptionRepository.php');
$privacy = file_get_contents($root . '/src/Core/Application/BookingRequestPrivacyService.php');
$migration = file_get_contents($root . '/src/Core/Infrastructure/Migration/Migrator.php');
$admin = file_get_contents($root . '/src/Admin/Controller/BookingRequestController.php');
$capabilityRuntime = file_get_contents($root . '/tests/phase-2a2a-capability-lifecycle.php');
$assessmentRuntime = file_get_contents($root . '/tests/phase-2a2a-isolated-runtime.php');
$positiveRuntime = file_get_contents($root . '/tests/phase-2a2a-positive-isolated-runtime.php');
$positiveWorker = file_get_contents($root . '/tests/phase-2a2a-positive-assessment-worker.php');

foreach (['BookingRequestMatchAssessmentService','CRITERIA_VERSION = \'v1\'','requestForUpdate','requestedTimes','instrumentForUpdate','defaultForUpdate','courseForUpdate','eligibleTeachers','TeacherAvailabilityService','occupied_ends_at_utc','preferred', 'requestable', 'limited', 'booking_request_match_attention', 'coverage_found', 'no_current_coverage', 'instrument_unavailable', 'selected_course_unavailable', 'default_intro_course_unavailable'] as $needle) if (strpos($service . $repository, $needle) === false) throw new RuntimeException('Missing Phase 2A.2-A match contract: ' . $needle);
foreach (['submitted', 'unresolved', 'student_id !== null', 'privacy_erased_at', 'selected_intro_course_id', 'course_type', 'introductory', 'default_intro_course_unavailable'] as $needle) if (strpos($service, $needle) === false) throw new RuntimeException('Missing Phase 2A.2-A request/course safety contract: ' . $needle);
foreach (['START TRANSACTION', 'FOR UPDATE', 'deleteBookingRequestMatchAttention', 'removeTrustedBookingRequestMatchAttention', 'resolveTrustedBookingRequestMatchAttention', 'booking_request'] as $needle) if (strpos($service . $repository . $exceptions . $exceptionRepository . $privacy, $needle) === false) throw new RuntimeException('Missing Phase 2A.2-A concurrency/privacy contract: ' . $needle);
foreach (['dzn_prepare_booking_request_matches', 'CAPABILITY_VERSION', 'CAPABILITY_OPTION', 'ensure_capabilities', 'has_cap(\'dzn_prepare_booking_request_matches\')', 'check_admin_referer', 'dzn_prepare_booking_request_matches_', 'Current advisory assessment', 'assess_matches', 'Assessment could not be completed.', 'catch (\\Throwable)'] as $needle) if (strpos($migration . $admin, $needle) === false) throw new RuntimeException('Missing Phase 2A.2-A protected admin contract: ' . $needle);
if (!str_contains($migration, 'capability installation failed')) throw new RuntimeException('Missing deterministic capability installation failure handling');
foreach (['Absent capability marker was not installed', 'Current capability marker was not a harmless no-op', 'Missing current capability was not restored'] as $needle) if (strpos($capabilityRuntime, $needle) === false) throw new RuntimeException('Missing capability lifecycle runtime guard: ' . $needle);
foreach (['coverage_found', 'no_current_coverage', 'default_intro_course_unavailable', 'selected_course_unavailable', 'operational_exceptions', 'platform_audit_events'] as $needle) if (strpos($assessmentRuntime, $needle) === false) throw new RuntimeException('Missing assessment runtime guard: ' . $needle);
foreach (['dzn_phase_2a0_create_ready_teacher_fixture', 'BookingRequestSubmissionService', 'occupied_ends_at_utc', 'limited', 'no_current_coverage', 'coverage_found', 'dzn_phase_2a0_cleanup_ready_teacher_fixture'] as $needle) if (strpos($positiveRuntime, $needle) === false) throw new RuntimeException('Missing positive assessment runtime guard: ' . $needle);
foreach (['DZN_PHASE_2A2A_RUNTIME_TEST', 'phase2a2a-assessment.release', 'BookingRequestMatchAssessmentService', 'coverage_found'] as $needle) if (strpos($positiveWorker, $needle) === false) throw new RuntimeException('Missing positive assessment concurrency guard: ' . $needle);
if (preg_match('/teacher_onboarding_states.*(?:insert|update)|(?:insert|update).*teacher_onboarding_states/', $positiveRuntime)) throw new RuntimeException('Positive assessment must use the real readiness transition');
foreach (['teacher_offers', 'booking_reservations', 'createStudent', 'createLesson', 'platform_payments', 'amelia_', 'wp_amelia'] as $forbidden) if (stripos($service . $repository . $admin, $forbidden) !== false) throw new RuntimeException('Phase 2A.2-A exceeded authority: ' . $forbidden);
echo "Phase 2A.2-A source contract passed\n";
