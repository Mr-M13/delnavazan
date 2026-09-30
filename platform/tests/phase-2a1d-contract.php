<?php
/** Static contract guard only. WordPress/MySQL behavioural coverage is separate. */
$root=dirname(__DIR__);
$migration=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$submission=file_get_contents($root.'/src/Core/Application/BookingRequestSubmissionService.php');
$idempotency=file_get_contents($root.'/src/Core/Application/BookingRequestIdempotency.php');
$validation=file_get_contents($root.'/src/Core/Application/BookingRequestValidationService.php');
$intake=file_get_contents($root.'/src/Core/Infrastructure/Repository/BookingRequestIntakeRepository.php');
$privacy=file_get_contents($root.'/src/Core/Application/BookingRequestPrivacyService.php');
$rest=file_get_contents($root.'/src/Public/BookingRequestRestController.php');
$rate=file_get_contents($root.'/src/Public/BookingRequestRateLimiter.php');
$admin=file_get_contents($root.'/src/Admin/Controller/BookingRequestController.php');
$restRuntime=file_get_contents($root.'/tests/phase-2a1d-rest-runtime.php');
$migrationRuntime=file_get_contents($root.'/tests/phase-2a1d-migration-runtime.php');
$isolatedRuntime=file_get_contents($root.'/tests/phase-2a1d-isolated-runtime.php');
foreach(['007_booking_request_intake_privacy','booking_request_submission_keys','booking_request_duplicate_flags','booking_request_privacy_tombstones','UNIQUE KEY key_digest','UNIQUE KEY duplicate_signal','verify_booking_request_intake_privacy_schema','dzn_erase_booking_request_pii','dzn_review_booking_request_duplicates'] as $needle)if(strpos($migration,$needle)===false)throw new RuntimeException('Missing Phase 2A.1-D schema/capability: '.$needle);
foreach(['idempotency-key','IdempotencyConflictException','reserveSubmissionKey','completeSubmissionKey','WINDOW_SECONDS = 86400','payloadDigest','canonicalize','array_is_list','restartExpiredSubmissionKey','canonicalRequestedTime'] as $needle)if(strpos($submission.$idempotency.$rest.$intake.$validation,$needle)===false)throw new RuntimeException('Missing idempotency contract: '.$needle);
if(strpos($idempotency,'ksort')===false||strpos($idempotency,'array_is_list')===false)throw new RuntimeException('Idempotency canonicalization must retain list order but normalize object keys');
foreach(['BookingRequestRateLimitException','newSubmissionGate','! hash_equals','state === \'completed\'','! $operation->created_new','new BookingRequestRateLimiter','array(\'success\'=>true,\'request_reference\'=>$result[\'request_reference\'])'] as $needle)if(strpos($submission.$rest,$needle)===false)throw new RuntimeException('Missing idempotency-first rate-limit/response contract: '.$needle);
if(strpos($rest,'$result[\'replayed\']')!==false)throw new RuntimeException('Public response must not expose the internal replay indicator');
foreach(['Public response contract failed','Replay created an additional request','Rate limit overrode replay','Rate limit overrode conflict','New intake was not rate limited','pre_add_option__transient_'] as $needle)if(strpos($restRuntime,$needle)===false)throw new RuntimeException('Missing REST idempotency/rate-limit runtime guard: '.$needle);
foreach(['Migrator::maybe_upgrade();','DROP INDEX key_digest','Damaged intake privacy schema was accepted','ADD UNIQUE KEY key_digest(key_digest)'] as $needle)if(strpos($migrationRuntime,$needle)===false)throw new RuntimeException('Missing schema-7 migration/index runtime guard: '.$needle);
foreach(['email_digest','mobile_digest','whatsapp_digest','matchingContactRequests','createDuplicateFlag','candidate_booking_request_id','Duplicate review persistence failed','contactPhone','strtolower'] as $needle)if(strpos($submission.$intake.$migration.$validation,$needle)===false)throw new RuntimeException('Missing exact duplicate-review contract: '.$needle);
foreach(['privacy_erased_at','privacy_erased_by','privacy_erasure_reason','privacy_erased',"state='revoked'",'booking_request_privacy_tombstones','eraseRequestPii','privacyResolutionConsistent','DELETE FROM','candidate_booking_request_id','FOR UPDATE','signal_digest'] as $needle)if(strpos($migration.$intake.$privacy,$needle)===false)throw new RuntimeException('Missing privacy erasure contract: '.$needle);
foreach(['Privacy erasure retained duplicate relationship or signal material','Privacy erasure retained replay authority','Privacy tombstone contains unexpected facts','Origin-side privacy erasure retained duplicate relationship or signal material'] as $needle)if(strpos($isolatedRuntime,$needle)===false)throw new RuntimeException('Missing privacy erasure runtime guard: '.$needle);
foreach(['hash_hmac','REMOTE_ADDR','set_transient','catch (\\Throwable) { return true; }','LIMIT = 5'] as $needle)if(strpos($rate,$needle)===false)throw new RuntimeException('Missing fail-open rate-limit contract: '.$needle);
foreach(['current_user_can','check_admin_referer','dzn_review_booking_request_duplicates','dzn_erase_booking_request_pii','wp_safe_redirect'] as $needle)if(strpos($admin,$needle)===false)throw new RuntimeException('Missing protected admin mutation contract: '.$needle);
foreach(['amelia_','teacher_offers','platform_payments','booking_reservations','createStudent','createLesson'] as $forbidden)if(stripos($submission.$intake.$privacy.$rest.$rate,$forbidden)!==false)throw new RuntimeException('Phase 2A.1-D exceeded authority: '.$forbidden);
echo "Phase 2A.1-D source contract passed\n";
