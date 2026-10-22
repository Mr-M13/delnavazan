<?php
/**
 * Source contract for the Term, Class and introductory-outcome workflow repair.
 *
 * The legacy writers remain intentionally legacy-only. This guard proves that the operator UI
 * exposes their canonical replacements first, that standard and replacement classes are issued
 * through the canonical Lesson authority, that unscheduled canonical classes cannot break the
 * portals, and that a real introductory outcome still reaches the post-intro workflow.
 */
$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_readable($full)) throw new RuntimeException('missing file: ' . $path);
    return (string) file_get_contents($full);
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$contains = static function (string $haystack, array $needles, string $message) use ($assert): void {
    foreach ($needles as $needle) $assert(str_contains($haystack, $needle), $message . ': ' . $needle);
};

$screen = $read('platform/src/Admin/Controller/ScreenController.php');
$coreRead = $read('platform/src/Core/Application/CoreReadService.php');
$issueRead = $read('platform/src/Core/Application/CanonicalLessonIssueReadService.php');
$portalRead = $read('platform/src/Portals/PortalAuthenticatedReadService.php');
$portalBridge = $read('theme/inc/platform-bridge.php');
$termService = $read('platform/src/Core/Application/TermService.php');
$lessonService = $read('platform/src/Core/Application/LessonService.php');
$postIntro = $read('platform/src/Core/Application/PostIntroPreparationService.php');
$postIntroActivation = $read('platform/src/Core/Application/PostIntroActivationService.php');

// 1. Canonical Term creation is the primary operator path; legacy remains compatibility-only.
$contains($screen, array(
    'private static function termCreateSection(): void',
    'if ($canonical) self::canonicalTermCreateForm();',
    'Legacy Term compatibility',
    'legacyEnrolmentOptionsForTerm()',
    'self::entityCreateForm(\'term\')',
), 'the Terms screen must lead with the canonical creator and keep legacy creation explicit');
$assert(strpos($screen, 'self::canonicalTermCreateForm();') < strpos($screen, 'self::entityCreateForm(\'term\');'), 'canonical Term creation must render before the legacy compatibility form');
$assert(str_contains($termService, 'Usable legacy Enrolment required; canonical Enrolment grants no Term authority'), 'the legacy Term writer must keep refusing canonical Enrolments');

// 2. Canonical standard and replacement classes are issued from the Classes screen.
$contains($screen, array(
    'private static function classCreateSection(): void',
    'private static function canonicalLessonCreateForm(): void',
    'private static function canonicalLessonCreate(array $post): int',
    "'create_canonical_lesson' => self::canonicalLessonCreate(\$post)",
    'new CanonicalLessonAuthorityService()',
    '->createStandard(',
    '->createReplacement(',
    'current canonical Term and its current Teacher Assignment',
    'class type is never a status',
), 'the Classes screen must expose the canonical standard/replacement issuance path');
$contains($issueRead, array(
    "t.record_model='canonical_enrolment_term_v1'",
    "t.lifecycle_state='current'",
    "a.applicable_slot=1 AND a.state='assigned'",
    'standard_remaining',
    'replacement_remaining',
    "ev.reason_code='canonical_lesson_cancelled_replacement_eligible'",
    'NOT EXISTS (SELECT 1 FROM',
), 'canonical class choices must be current, assigned, allocated and replacement-eligible');
$contains($coreRead, array(
    'public function canonicalLessonIssueOptions(): array',
    "current_user_can('dzn_manage_canonical_lessons')",
    'new CanonicalLessonIssueReadService())->options()',
), 'canonical class choices must stay behind their own capability and read seam');
$assert(str_contains($lessonService, 'Standard/replacement Lesson needs Enrolment and Term'), 'the legacy Lesson writer must keep its explicit relationship checks');
$assert(str_contains($lessonService, 'Canonical Enrolment grants no Lesson or Teacher Assignment authority'), 'the legacy Lesson writer must keep refusing canonical Enrolments');
$assert(str_contains($lessonService, 'completeScheduledIntroductory') && str_contains($lessonService, 'booking_linked_intro_requires_post_intro_outcome'), 'a standalone introductory Lesson must complete through Lesson authority while a booking-linked one must use the post-intro workflow');

// 3. The introductory outcome is an authorised, visible action and routes to canonical work.
$contains($screen, array(
    'private static function recordIntroOutcome(array $post): int',
    "private static function introductoryOutcomeForm(object \$lesson): void",
    "'record_intro_outcome' => self::recordIntroOutcome(\$post)",
    "new PostIntroPreparationService())->recordAdministrativeOutcome(",
    "new LessonService())->completeScheduledIntroductory(",
    "'intro_not_finished'",
    "'confirmed_occurrence_required'",
    'The introductory delivery outcome is recorded as completed.',
), 'the introductory outcome action must be visible, time-bounded and update its canonical workflow');
$assert(str_contains($postIntro, 'self::OUTCOME_DELIVERED') && str_contains($postIntro, 'completeIntroductory'), 'only a delivered introductory outcome may continue and complete the introductory Lesson');
$assert(str_contains($postIntroActivation, 'stableEvidenceAt') && !str_contains($postIntroActivation, '$preparation->created_at') && !str_contains($postIntroActivation, '$preparation->prepared_at'), 'activation evidence must never predate the Enrolment conversion event');

// 4. Unscheduled canonical Lessons must not break portal reads; scheduled classes stay authoritative.
$contains($portalRead, array(
    'INNER JOIN {$schedules} v ON v.lesson_id=l.id AND v.applicable_slot=1',
    'ORDER BY v.starts_at_utc ASC,l.id ASC',
    'authorised but unscheduled Lesson has no date/time to present',
), 'portal lesson reads must select scheduled canonical classes rather than failing on an unscheduled one');
$contains($portalBridge, array(
    "\$display_timezone = (string) ( \$next['schedule_timezone'] ?? '' ) ?: ( wp_timezone_string() ?: 'UTC' );",
    "dzn_theme_platform_zoned_time( \$lesson['starts_at_utc'] ?? '', 'j F Y', \$timezone )",
), 'the Student Portal must present the canonical schedule timezone');

echo "PASS Terms, Classes, introductory outcome and portal visibility workflow repair\n";
