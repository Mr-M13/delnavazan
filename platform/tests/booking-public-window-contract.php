<?php
$root = dirname( __DIR__ );
$source = file_get_contents( $root . '/src/Core/Application/BookingRequestValidationService.php' );
if ( false === $source ) throw new RuntimeException( 'Unable to read booking validation service' );
if ( strpos( $source, 'MAX_REQUESTED_TIMES = 3' ) === false ) throw new RuntimeException( 'Submission must match the public three-preference contract' );
if ( strpos( $source, 'BOOKING_HORIZON_DAYS = 90' ) === false ) throw new RuntimeException( 'Submission must declare the public 90-day horizon' );
if ( strpos( $source, "new \\DateTimeZone( (string) \$item['timezone'] )" ) === false ) throw new RuntimeException( 'Booking horizon must use the requested local timezone' );
if ( strpos( $source, "modify( '+1 day' )" ) === false ) throw new RuntimeException( 'Booking horizon must begin tomorrow' );
if ( strpos( $source, 'Requested time outside booking horizon' ) === false ) throw new RuntimeException( 'Submission must reject dates outside the public booking horizon' );
if ( strpos( $source, 'horizonConfiguration' ) === false || strpos( $source, 'recorded_value_type' ) === false ) throw new RuntimeException( 'Administration must read the same effective horizon resolution as public intake' );
$policies = file_get_contents( $root . '/src/Admin/Controller/PoliciesController.php' );
if ( false === $policies ) throw new RuntimeException( 'Unable to read policies controller' );
if ( strpos( $policies, 'horizonConfiguration' ) === false || strpos( $policies, 'built-in' ) === false ) throw new RuntimeException( 'Policies must report the effective horizon, including the built-in fallback' );

// Phase 1A: one minimum-notice authority across every public surface and the
// allocator, plus atomic capacity-lost retirement and typed retryable contention.
$leadTime = file_get_contents( $root . '/src/Core/Application/BookingLeadTimePolicy.php' );
if ( false === $leadTime ) throw new RuntimeException( 'Minimum-notice authority must exist' );
foreach ( array(
    'MINIMUM_LEAD_SECONDS = self::RESPONSE_WINDOW_SECONDS + self::PROJECTION_BUFFER_SECONDS',
    'return $startUtc > self::minimumStartUtc',
    "'reason_code' => 'lead_time_too_short'",
    "'minimum_start_local'",
    'allStartsInsideMinimumNotice',
) as $needle ) if ( strpos( $leadTime, $needle ) === false ) throw new RuntimeException( 'Minimum-notice authority contract missing: ' . $needle );

if ( strpos( $source, 'BookingLeadTimePolicy::refusal' ) === false || strpos( $source, 'BookingLeadTimeException' ) === false ) throw new RuntimeException( 'Public intake must enforce the shared minimum notice' );
$day = file_get_contents( $root . '/src/Core/Application/BookingDayAvailabilityService.php' );
if ( false === $day ) throw new RuntimeException( 'Unable to read day availability service' );
foreach ( array( 'BookingLeadTimePolicy::isStartAllowed', "'lead_time_omitted'", "'minimum_start_utc'", "'minimum_start_local'", "'lead_time_hours'" ) as $needle ) if ( strpos( $day, $needle ) === false ) throw new RuntimeException( 'Day grid must share the minimum-notice policy: ' . $needle );
$preview = file_get_contents( $root . '/src/Core/Application/BookingAvailabilityPreviewService.php' );
if ( false === $preview ) throw new RuntimeException( 'Unable to read preview service' );
foreach ( array( 'BookingLeadTimePolicy::refusal', "'reason_code'", "'minimum_start_local'", "'lead_time_hours'" ) as $needle ) if ( strpos( $preview, $needle ) === false ) throw new RuntimeException( 'Preview must explain the shared minimum notice: ' . $needle );
$allocation = file_get_contents( $root . '/src/Core/Application/BookingAllocationService.php' );
if ( false === $allocation ) throw new RuntimeException( 'Unable to read allocation service' );
foreach ( array( 'BookingLeadTimePolicy::isStartAllowed', 'BookingLeadTimePolicy::allStartsInsideMinimumNotice', 'LEAD_TIME_REFERRAL_REASON', 'BookingOfferRetirementService', 'BookingContention::capture' ) as $needle ) if ( strpos( $allocation, $needle ) === false ) throw new RuntimeException( 'Allocation must consume shared notice, retirement and contention: ' . $needle );
$allocationRepo = file_get_contents( $root . '/src/Core/Infrastructure/Repository/BookingAllocationRepository.php' );
if ( false === $allocationRepo ) throw new RuntimeException( 'Unable to read allocation repository' );
foreach ( array( "o.state='pending' AND o.active_slot=1", "r.privacy_erased_at IS NULL", "FOR UPDATE", "'capacity_lost'", "state IN ('queued','retryable','sending')", 'capacity_retirement_read_failed' ) as $needle ) if ( strpos( $allocationRepo, $needle ) === false ) throw new RuntimeException( 'Capacity-lost retirement contract missing: ' . $needle );
$response = file_get_contents( $root . '/src/Core/Application/BookingTeacherResponseService.php' );
if ( false === $response ) throw new RuntimeException( 'Unable to read Teacher response service' );
foreach ( array( 'BookingContention::capture', 'teacherOccupancyConflicts', 'BookingContentionException::TEACHER_BUSY' ) as $needle ) if ( strpos( $response, $needle ) === false ) throw new RuntimeException( 'Teacher acceptance must map contention and re-read pending occupancy: ' . $needle );
$lifecycle = file_get_contents( $root . '/src/Core/Application/IntroLessonLifecycleService.php' );
if ( false === $lifecycle ) throw new RuntimeException( 'Unable to read intro lifecycle service' );
foreach ( array( 'BookingOfferRetirementService', 'BookingTeacherOccupancyReadService', 'ensureAndLockTeacherRoot', 'BookingContention::capture' ) as $needle ) if ( strpos( $lifecycle, $needle ) === false ) throw new RuntimeException( 'Intro reschedule must share capacity, retirement and contention rules: ' . $needle );
if ( strpos( $lifecycle, "if (\$expectedVersion < 1) throw new \\InvalidArgumentException('A current booking confirmation version is required')" ) === false ) throw new RuntimeException( 'A reschedule must require the exact confirmation version' );
$commitAt = strrpos( $lifecycle, "\$wpdb->query('COMMIT')" );
$projectionAt = strpos( $lifecycle, '$this->projector()->retryReschedule(' );
if ( false === $commitAt || false === $projectionAt || $projectionAt < $commitAt ) throw new RuntimeException( 'A reschedule must commit canonical state before provider projection' );
foreach ( array( 'booking_request.intro_reschedule_provider_sync', 'recordProviderSyncOutcome', 'reschedule_provider_pending:revision_required', 'dzn_intro_reschedule_locked', 'coveredByPublishedAvailability($teacherId, $startsAtUtc, $occupiedEndsAtUtc' ) as $needle ) if ( strpos( $lifecycle, $needle ) === false ) throw new RuntimeException( 'Reschedule provider evidence or buffer coverage contract missing: ' . $needle );
$preAuditAt = strpos( $lifecycle, "'intro-reschedule:' . \$idempotencyKey" );
if ( false === $preAuditAt ) throw new RuntimeException( 'Reschedule pre-projection audit event missing' );
$preAudit = substr( $lifecycle, max( 0, $preAuditAt - 900 ), 900 );
if ( strpos( $preAudit, "'provider_sync'" ) !== false ) throw new RuntimeException( 'The pre-projection audit must not claim a provider outcome' );
$responseRepo = file_get_contents( $root . '/src/Core/Infrastructure/Repository/BookingTeacherResponseRepository.php' );
if ( false === $responseRepo ) throw new RuntimeException( 'Unable to read Teacher response repository' );
foreach ( array( 'rescheduleRetryConfirmations', 'markRescheduleProviderPending', 'clearRescheduleProviderPending', "AND version=%d AND state='confirmed'", 'reschedule_provider_pending:' ) as $needle ) if ( strpos( $responseRepo, $needle ) === false ) throw new RuntimeException( 'Version-fenced reschedule provider retry contract missing: ' . $needle );
$projection = file_get_contents( $root . '/src/Integrations/BookingCalendarProjectionService.php' );
if ( false === $projection || strpos( $projection, 'retryReschedule' ) === false || strpos( $projection, 'acquireLock($confirmationId)' ) === false ) throw new RuntimeException( 'The projection service must serialize and retry a committed reschedule' );
$projectionWorker = file_get_contents( $root . '/src/Integrations/BookingCalendarProjectionWorker.php' );
if ( false === $projectionWorker || strpos( $projectionWorker, 'rescheduleRetryConfirmations' ) === false ) throw new RuntimeException( 'The projection worker must drain durable reschedule retries' );
$concurrencyVerify = file_get_contents( $root . '/tests/booking-slot-conflict-concurrency-verify.php' );
if ( false === $concurrencyVerify ) throw new RuntimeException( 'Unable to read booking concurrency verifier' );
foreach ( array( 'Booking confirmation changed concurrently', 'reschedule_provider_pending:', 'provider_sync', 'provider_sync":"updated"', 'retryable":false', 'provider-requests.ndjson', 'The older provider result must report supersession' ) as $needle ) if ( strpos( $concurrencyVerify, $needle ) === false ) throw new RuntimeException( 'Concurrent reschedule/provider proof missing: ' . $needle );
$concurrencyWorker = file_get_contents( $root . '/tests/booking-slot-conflict-concurrency-worker.php' );
if ( false === $concurrencyWorker || strpos( $concurrencyWorker, 'pre_http_request' ) === false || strpos( $concurrencyWorker, 'abcdef12345' ) === false ) throw new RuntimeException( 'Provider-success runtime proof missing from the reschedule worker' );
$concurrencySetup = file_get_contents( $root . '/tests/booking-slot-conflict-concurrency-setup.php' );
if ( false === $concurrencySetup ) throw new RuntimeException( 'Unable to read booking concurrency fixture' );
foreach ( array( "'10:44:59'", "'10:45:00'", "'16:30'", "'17:00'", "'20:00'", "'21:00'", 'provider_projection_secrets', 'IntegrationSecretService' ) as $needle ) if ( strpos( $concurrencySetup, $needle ) === false ) throw new RuntimeException( 'Buffer-boundary and provider fixtures missing: ' . $needle );
$themeJs = file_get_contents( $root . '/../theme/assets/js/booking.js' );
if ( false === $themeJs ) throw new RuntimeException( 'Unable to read booking theme script' );
foreach ( array( 'lead_time_omitted', 'lead_time_hours', 'lead_time_too_short', 'minimum_start_local' ) as $needle ) if ( strpos( $themeJs, $needle ) === false ) throw new RuntimeException( 'Student booking UI must explain minimum notice: ' . $needle );
$rest = file_get_contents( $root . '/src/Public/BookingRequestRestController.php' );
if ( false === $rest ) throw new RuntimeException( 'Unable to read booking request controller' );
foreach ( array( 'BookingLeadTimeException', 'BookingContentionException', "'retryable' => true" ) as $needle ) if ( strpos( $rest, $needle ) === false ) throw new RuntimeException( 'Public intake must return typed lead-time and retryable contention responses: ' . $needle );
echo "Booking public window contract passed\n";
