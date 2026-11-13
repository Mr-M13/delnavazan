<?php
/**
 * Disposable WordPress/MariaDB proof for instrument-scoped availability.
 *
 * This exercises the real self-service clear operation, persisted rules and
 * the administrator coverage projection. It must only run in the disposable
 * local runtime via the matching runner.
 */
if (getenv('DZN_TEACHER_AVAILABILITY_INSTRUMENT_RUNTIME') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Teacher instrument availability runtime refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\{CatalogueService, InstrumentCoverageClockReadService, TeacherAcceptingStateService, TeacherAvailabilityService, TeacherService, TeachingEligibilityService};

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$suffix = substr(hash('sha256', wp_generate_uuid4()), 0, 10);
$admin = 1;
wp_set_current_user($admin);

$catalogue = new CatalogueService();
$first = $catalogue->setupInstrument(array('name' => 'Runtime Scope A ' . $suffix, 'standard_duration_minutes' => 30, 'gap_between_lessons_minutes' => 0, 'introductory_lesson_billing' => 'free'));
$second = $catalogue->setupInstrument(array('name' => 'Runtime Scope B ' . $suffix, 'standard_duration_minutes' => 30, 'gap_between_lessons_minutes' => 0, 'introductory_lesson_billing' => 'free'));
$instrumentA = (int) $first['instrument_id'];
$instrumentB = (int) $second['instrument_id'];
$courseA = (int) $first['introductory_course_id'];
$courseB = (int) $second['introductory_course_id'];
$third = $catalogue->setupInstrument(array('name' => 'Runtime Scope C ' . $suffix, 'standard_duration_minutes' => 30, 'gap_between_lessons_minutes' => 0, 'introductory_lesson_billing' => 'free'));
$instrumentC = (int) $third['instrument_id'];
$courseC = (int) $third['introductory_course_id'];

$teacher = (new TeacherService())->create(array('display_name' => 'Runtime instrument scope ' . $suffix, 'email' => 'runtime-scope-' . $suffix . '@example.invalid', 'timezone' => 'UTC'));
$now = gmdate('Y-m-d H:i:s');
$assert($wpdb->insert($p . 'teacher_onboarding_states', array('teacher_id' => $teacher, 'state' => 'active', 'readiness_state' => 'ready', 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => $admin, 'updated_by' => $admin)) === 1, 'Unable to persist ready Teacher state.');
(new TeachingEligibilityService())->setEligibility(array('teacher_id' => $teacher, 'course_id' => $courseA, 'status' => 'active', 'reason_code' => 'runtime_fixture'));
(new TeachingEligibilityService())->setEligibility(array('teacher_id' => $teacher, 'course_id' => $courseB, 'status' => 'active', 'reason_code' => 'runtime_fixture'));
(new TeachingEligibilityService())->setEligibility(array('teacher_id' => $teacher, 'course_id' => $courseC, 'status' => 'active', 'reason_code' => 'runtime_fixture'));
(new TeacherAcceptingStateService())->set(array('teacher_id' => $teacher, 'state' => 'accepting', 'reason_code' => 'runtime_fixture'));

$availability = new TeacherAvailabilityService();
$availability->setProfile(array('teacher_id' => $teacher, 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'runtime_fixture'));
// Each instrument gets a distinct Monday window. These are real rows written
// through the service, not fixture-only source strings or mocked availability.
$availability->setRecurringRule(array('teacher_id' => $teacher, 'instrument_id' => $instrumentA, 'weekday' => 1, 'local_start_time' => '09:00:00', 'local_end_time' => '12:00:00', 'state' => 'preferred', 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'runtime_fixture'));
$availability->setRecurringRule(array('teacher_id' => $teacher, 'instrument_id' => $instrumentB, 'weekday' => 1, 'local_start_time' => '13:00:00', 'local_end_time' => '16:00:00', 'state' => 'preferred', 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'runtime_fixture'));

$teacherUser = wp_insert_user(array('user_login' => 'dzn-runtime-scope-' . $suffix, 'user_pass' => wp_generate_password(32, true, true), 'user_email' => 'runtime-scope-user-' . $suffix . '@example.invalid', 'role' => 'dzn_teacher'));
$assert(!is_wp_error($teacherUser), 'Unable to create the linked runtime Teacher user.');
$assert($wpdb->insert($p . 'teacher_principal_links', array('teacher_id' => $teacher, 'wordpress_user_id' => (int) $teacherUser, 'status' => 'active', 'linked_at' => $now, 'linked_by' => $admin)) === 1, 'Unable to link the runtime Teacher user.');

wp_set_current_user((int) $teacherUser);
(new TeacherAvailabilityService())->configureOwnInstrument($instrumentA);
wp_set_current_user($admin);

$activeA = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_availability_rules WHERE teacher_id=%d AND instrument_id=%d AND status='active'", $teacher, $instrumentA));
$inactiveA = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_availability_rules WHERE teacher_id=%d AND instrument_id=%d AND status='inactive' AND reason_code='teacher_self_service_instrument_cleared'", $teacher, $instrumentA));
$activeB = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_availability_rules WHERE teacher_id=%d AND instrument_id=%d AND status='active'", $teacher, $instrumentB));
$configuredA = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_availability_instrument_states WHERE teacher_id=%d AND instrument_id=%d AND status='configured'", $teacher, $instrumentA));
$assert($activeA === 0 && $inactiveA > 0 && $configuredA === 1, 'Configured-empty must retire the instrument’s persisted active rules and record its state.');
$assert($activeB > 0, 'Clearing one instrument must not retire another instrument’s rules.');

$monday = (new DateTimeImmutable('next monday', new DateTimeZone('UTC')))->format('Y-m-d');
$assert($availability->coverage($teacher, $monday . ' 09:00:00', $monday . ' 09:30:00', $instrumentA) === null, 'A configured-empty instrument must have no effective coverage.');
$assert($availability->coverage($teacher, $monday . ' 13:00:00', $monday . ' 13:30:00', $instrumentB) === 'preferred', 'The other instrument must retain its own effective coverage.');

$find = static function (array $overview, int $instrument): ?array {
    foreach ((array) ($overview['instruments'] ?? array()) as $row) if ((int) ($row['id'] ?? 0) === $instrument) return $row;
    return null;
};
$segment = static function (array $row, int $minute): ?array {
    foreach ((array) ($row['clocks'][1]['segments'] ?? array()) as $candidate) if ((int) ($candidate['minute'] ?? -1) === $minute) return $candidate;
    return null;
};
$clock = new InstrumentCoverageClockReadService();
$all = $clock->admin(array('date' => $monday, 'timezone' => 'UTC'));
$rowA = $find($all, $instrumentA); $rowB = $find($all, $instrumentB);
$aAtNine = $rowA ? $segment($rowA, 9 * 60) : null; $bAtThirteen = $rowB ? $segment($rowB, 13 * 60) : null;
$assert($aAtNine !== null && (int) $aAtNine['teacher_count'] === 0, 'The all-instruments overview must show the cleared instrument without coverage.');
$assert($bAtThirteen !== null && (int) $bAtThirteen['teacher_count'] === 1, 'The all-instruments overview must read the other card in its own instrument scope.');

$selected = (new InstrumentCoverageClockReadService())->admin(array('date' => $monday, 'timezone' => 'UTC', 'instrument_id' => $instrumentA));
$selectedB = $find($selected, $instrumentB); $selectedBAtThirteen = $selectedB ? $segment($selectedB, 13 * 60) : null;
$assert($selectedBAtThirteen !== null && (int) $selectedBAtThirteen['teacher_count'] === 1, 'Selecting one instrument must not reuse its facts for another instrument card.');

// The interactive planner saves a whole instrument week atomically and must
// preserve legacy inheritance rules for instruments that are not configured.
wp_set_current_user($admin);
$availability->setRecurringRule(array('teacher_id' => $teacher, 'weekday' => 1, 'local_start_time' => '06:00:00', 'local_end_time' => '07:00:00', 'state' => 'preferred', 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'runtime_legacy'));
$assert($availability->coverage($teacher, $monday . ' 06:15:00', $monday . ' 06:45:00', $instrumentC) === 'preferred', 'An unconfigured instrument must inherit the legacy unscoped schedule.');

wp_set_current_user((int) $teacherUser);
$availability->replaceOwnWeeklySchedule($instrumentB, array(
    array('weekday' => 2, 'start' => '08:00', 'end' => '10:00'),
    array('weekday' => 2, 'start' => '15:00', 'end' => '22:00'),
));
$tuesday = (new DateTimeImmutable('next tuesday', new DateTimeZone('UTC')))->format('Y-m-d');
$activeB = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_availability_rules WHERE teacher_id=%d AND instrument_id=%d AND status='active'", $teacher, $instrumentB));
$configuredB = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_availability_instrument_states WHERE teacher_id=%d AND instrument_id=%d AND status='configured'", $teacher, $instrumentB));
$assert($activeB === 2 && $configuredB === 1, 'The planner save must persist both ranges and the explicit instrument state.');
$assert($availability->coverage($teacher, $tuesday . ' 08:30:00', $tuesday . ' 09:00:00', $instrumentB) === 'preferred', 'The first planner range must be effective.');
$assert($availability->coverage($teacher, $tuesday . ' 15:30:00', $tuesday . ' 16:00:00', $instrumentB) === 'preferred', 'The second planner range must be effective.');
$assert($availability->coverage($teacher, $tuesday . ' 11:00:00', $tuesday . ' 11:30:00', $instrumentB) === null, 'A gap between planner ranges must remain unavailable.');
$availability->replaceOwnWeeklySchedule($instrumentC, array());
$assert($availability->coverage($teacher, $monday . ' 06:15:00', $monday . ' 06:45:00', $instrumentC) === null, 'An explicit instrument schedule must stop legacy inheritance for that instrument.');
$availability->replaceOwnWeeklySchedule($instrumentC, array(array('weekday' => 3, 'start' => '10:00', 'end' => '12:00')));
$wednesday = (new DateTimeImmutable('next wednesday', new DateTimeZone('UTC')))->format('Y-m-d');
$assert($availability->coverage($teacher, $wednesday . ' 10:30:00', $wednesday . ' 11:00:00', $instrumentC) === 'preferred', 'An instrument must return to an explicit schedule after its empty state is cleared.');

$availability->replaceOwnWeeklySchedule($instrumentB, array());
$activeB = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_availability_rules WHERE teacher_id=%d AND instrument_id=%d AND status='active'", $teacher, $instrumentB));
$assert($activeB === 0, 'Saving an empty planner week must retire the instrument’s active rules.');
$assert($availability->coverage($teacher, $tuesday . ' 08:30:00', $tuesday . ' 09:00:00', $instrumentB) === null, 'An explicitly empty instrument must have no effective availability.');

$availability->replaceOwnWeeklySchedule($instrumentB, array(array('weekday' => 2, 'start' => '09:00', 'end' => '11:00')));
$activeB = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_availability_rules WHERE teacher_id=%d AND instrument_id=%d AND status='active'", $teacher, $instrumentB));
$assert($activeB === 1, 'Adding hours after an empty state must clear the empty state and persist the new range.');
$assert($availability->coverage($teacher, $tuesday . ' 09:30:00', $tuesday . ' 10:00:00', $instrumentB) === 'preferred', 'Availability must return after the explicit empty state is cleared.');

$legacy = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_availability_rules WHERE teacher_id=%d AND instrument_id IS NULL AND status='active'", $teacher));
$assert($legacy === 1, 'Planner saves must never retire or rewrite the legacy unscoped rule.');

echo "Teacher instrument availability runtime passed\n";
