<?php
/**
 * Missed-lesson replacement lifecycle contract.
 *
 * The repair must extend the existing canonical authority:
 *  - completed/past occurrences use a linked replacement Lesson, never a schedule rewrite;
 *  - the original Lesson, attendance evidence and completion records stay immutable;
 *  - the Student's accepted proposal is the only authorisation;
 *  - Teacher/Student portals expose one action and the administrator sees the full history.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

$plugin = $read('delnavazan-platform.php');
$assert(str_contains($plugin, "DZN_PLATFORM_SCHEMA_VERSION', '54'"), 'The repair must not claim a new schema version');
$assert(str_contains($plugin, 'LessonRescheduleController::register') && str_contains($plugin, 'LessonRescheduleWorker::register'), 'The existing portal/worker wiring must remain active');

$service = $read('src/Core/Application/LessonRescheduleService.php');
$authority = $read('src/Core/Application/CanonicalLessonAuthorityService.php');
$validator = $read('src/Core/Application/CanonicalLessonAuthorityValidator.php');
$schedule = $read('src/Core/Application/CanonicalLessonScheduleService.php');
$repository = $read('src/Core/Infrastructure/Repository/LessonRescheduleRepository.php');
$authorityRepository = $read('src/Core/Infrastructure/Repository/CanonicalLessonAuthorityRepository.php');
$actor = $read('src/Core/Application/LessonReplacementSystemActor.php');
$absenceRead = $read('src/Core/Application/TeacherAbsenceAttentionReadService.php');
$rejectedRead = $read('src/Core/Application/TeacherRescheduleAttentionReadService.php');
$notification = $read('src/Integrations/Notifications/LessonRescheduleNotificationService.php');
$studentRead = $read('src/Portals/PortalAuthenticatedReadService.php');
$admin = $read('src/Admin/Controller/AcademyOperationsController.php');
$capacity = $read('src/Core/Application/CommercialCapacityAuthority.php');
$bridge = $read('../theme/inc/platform-bridge.php');
$teacherForm = $read('../theme/template-parts/teacher-portal/reschedule-form.php');
$studentTemplate = $read('../theme/template-parts/portal/reschedule.php');

$assert(str_contains($service, 'classifyRequest'), 'The backend must determine reschedule versus replacement');
$assert(str_contains($service, "if(\$state==='completed')return 'replacement';"), 'Completed occurrences must route to replacement');
$assert(str_contains($service, "if(\$start<=\$now&&\$now<\$end)throw new \\InvalidArgumentException('reschedule_lesson_in_progress')"), 'In-progress occurrences must be refused');
$assert(str_contains($service, 'assertReplacementEligible'), 'Replacement entitlement must be revalidated');
$assert(str_contains($service, "student_advance_absence"), 'A prior absence notice remains replacement evidence');
$assert(str_contains($service, "student_reports_missed_class"), 'A missed-class report is replacement evidence');
$assert(str_contains($service, "student_disputes_class"), 'A disputed report must remain a separate exception');
$assert(str_contains($service, 'replacement_entitlement_exhausted'), 'Exhausted allowance must be an explicit refusal');
$assert(str_contains($service, 'replacement_academy_owed'), 'Academy-owed non-delivery must not silently consume Student entitlement');
$assert(str_contains($service, 'replacement_not_owed_delivered'), 'A delivered occurrence must not be replaced');
$assert(str_contains($service, 'createReplacementWithinTransaction'), 'The workflow must call the canonical caller-owned issuance seam');
$assert(str_contains($service, 'replacementCommit'), 'The replacement issuance seam must be injectable and atomic');
$assert(str_contains($service, 'teacher_replacement_proposed'), 'Proposal kind must be durable workflow evidence');
$assert(str_contains($service, 'student_replacement_accepted'), 'Acceptance audit must distinguish replacement from reschedule');
$assert(str_contains($service, 'escalateAfterRejection'), 'Three rejections must become a durable escalation');
$assert(str_contains($service, 'reschedule_rejections_exhausted'), 'Escalation must carry a controlled reason code');
$assert(!str_contains($service, "if((string)\$context->lifecycle_state!=='authorised')throw new \\InvalidArgumentException('reschedule_lesson_not_eligible');"), 'The unconditional authorised-only gate must be removed');

$assert(str_contains($authority, 'createReplacementWithinTransaction'), 'Canonical Lesson authority must expose a caller-owned transaction seam');
$assert(str_contains($authority, '!$ownTransaction'), 'Caller-owned issuance must not start or commit its own transaction');
$assert(str_contains($authority, "!==\$termId||!\$this->eligible(\$origin)"), 'Replacement issuance must rely on the canonical eligibility gate, not a cancelled-only literal');
$assert(str_contains($validator, 'acceptedReplacementAuthorisation'), 'Replacement eligibility must verify the accepted Student proposal');
$assert(str_contains($validator, 'cancelled'), 'Cancelled origins remain supported');
$assert(str_contains($schedule, 'scheduleWithinTransaction'), 'Replacement scheduling must join the caller-owned transaction');
$assert(str_contains($actor, "dzn_manage_canonical_lessons") && str_contains($actor, "dzn_manage_canonical_lesson_schedules"), 'The replacement actor must be least-privilege and explicit');
$assert(str_contains($actor, "manage_options"), 'The replacement actor must not be a general administrator');
$assert(str_contains($authorityRepository, 'lesson_reschedule_events') && str_contains($authorityRepository, 'teacher_replacement_proposed'), 'Provenance must be the immutable accepted proposal event');

$assert(str_contains($repository, 'function replacementFacts'), 'The store must expose attendance and entitlement facts');
$assert(str_contains($repository, 'function proposalKind'), 'The store must derive proposal kind from immutable events');
$assert(str_contains($repository, 'function proposalRejectionCount'), 'The store must expose durable rejection count');

$assert(str_contains($absenceRead, 'v.lesson_id=l.id AND v.applicable_slot=1'), 'Absence attention must read the current applicable schedule version');
$assert(str_contains($absenceRead, 'NOT EXISTS'), 'Absence attention must not duplicate an already-acted case');
$assert(str_contains($absenceRead, "'request_kind'"), 'Absence attention must tell the Teacher which lifecycle applies');
$assert(str_contains($rejectedRead, "r.state IN ('rejected','failed')"), 'Rejected and failed proposals must both be recoverable');
$assert(str_contains($rejectedRead, 'proposal_reason'), 'Rejected attention must preserve the original proposal kind');
$assert(str_contains($rejectedRead, 'function ownPending'), 'The Teacher must be able to see pending Student decisions');
$assert(str_contains($bridge, 'reschedule_pending'), 'The Teacher portal must display pending proposals without duplicating the action');

$assert(str_contains($notification, 'proposalKind') && str_contains($notification, 'جلسهٔ جبرانی'), 'Notifications must distinguish replacement from reschedule');
$assert(str_contains($studentRead, 'original_starts_at_utc') && str_contains($studentRead, 'teacher_replacement_proposed'), 'Student proposals must include the original occurrence and kind');
$assert(str_contains($admin, 'dzn-academy-reschedule-history') && str_contains($admin, 'expired'), 'Admin must see confirmed, rejected, failed and expired proposals');
$assert(str_contains($capacity, "lesson_type??'')==='replacement'") && substr_count($capacity, "==='replacement'") >= 3, 'Replacement issuance must not bind, satisfy or reopen a standard protected capacity interval');
$assert(str_contains($capacity, 'claimsWholeTerm&&!$isReplacement'), 'A replacement allowance must not be mistaken for a missing funded session');

$assert(str_contains($bridge, "'تغییر زمان / جلسهٔ جبرانی'"), 'The Teacher portal must present one clear action');
$assert(str_contains($bridge, "'request_kind'"), 'The Teacher portal must render the backend-determined lifecycle');
$assert(str_contains($teacherForm, 'ثبت جلسهٔ جبرانی'), 'The shared form must explain replacement mode');
$assert(str_contains($studentTemplate, 'زمان اصلی') && str_contains($studentTemplate, 'زمان پیشنهادی'), 'The Student portal must show original and proposed times');

echo "Missed-lesson replacement contract passed\n";
