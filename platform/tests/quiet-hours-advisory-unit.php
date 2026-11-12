<?php
/** Pure Quiet Hours advisory projection: boundaries, overnight, DST, candidates and policy. */
use Delnavazan\Platform\Core\Application\AvailabilityLocalTime;
use Delnavazan\Platform\Core\Application\BookingAvailabilityPolicy;
use Delnavazan\Platform\Core\Application\QuietHoursAdvisoryService;

$root = dirname(__DIR__);
$GLOBALS['dzn_quiet_option'] = null;
if (!function_exists('get_option')) {
    function get_option(string $key, mixed $default = array()): mixed {
        return $GLOBALS['dzn_quiet_option'] ?? $default;
    }
}
require_once $root . '/src/Core/Application/AvailabilityLocalTime.php';
require_once $root . '/src/Core/Application/BookingAvailabilityPolicy.php';
require_once $root . '/src/Core/Application/QuietHoursAdvisoryService.php';

$fail = static function (string $message): void { throw new RuntimeException($message); };
$policy = static function (array $override = array()): array {
    return array_replace(array(
        'enabled' => true,
        'student_advisory' => true,
        'start' => '01:00:00',
        'end' => '06:00:00',
        'timezone_basis' => 'teacher_local',
        'academy_timezone' => 'Asia/Tehran',
    ), $override);
};
$service = new QuietHoursAdvisoryService();

// 18:00 UTC is 04:00 next day in Brisbane and 19:00 in Berlin.
$brisbane = $service->forCandidates('2026-11-12 18:00:00', array('Australia/Brisbane'), 'Europe/Berlin', $policy());
if ($brisbane['enabled'] !== true || $brisbane['scope'] !== 'all' || $brisbane['affected_candidate_count'] !== 1) $fail('A single affected candidate must be an all-candidate advisory.');
$candidate = $brisbane['candidates'][0] ?? array();
if (($candidate['teacher_local_start'] ?? '') !== '04:00' || ($candidate['teacher_local_date'] ?? '') !== '2026-11-13') $fail('Teacher-local start time/date must be exact.');
if (($candidate['student_quiet_start'] ?? '') !== '16:00' || ($candidate['student_quiet_end'] ?? '') !== '21:00') $fail('Quiet Hours must be converted into the student timezone dynamically.');
if (($candidate['student_quiet_start_at_utc'] ?? '') !== '2026-11-12 15:00:00' || ($candidate['student_quiet_end_at_utc'] ?? '') !== '2026-11-12 20:00:00') $fail('Converted quiet-hour instants are incorrect.');

$outside = $service->forCandidates('2026-11-12 08:00:00', array('Australia/Brisbane'), 'Europe/Berlin', $policy());
if ($outside['scope'] !== 'none' || $outside['affected_candidate_count'] !== 0) $fail('A time outside Quiet Hours must produce no advisory.');

// Overnight boundaries, half-open at both ends.
$overnightPolicy = $policy(array('start' => '22:00:00', 'end' => '06:00:00'));
foreach (array(
    array('2026-11-12 21:59:00', false),
    array('2026-11-12 22:00:00', true),
    array('2026-11-13 05:59:00', true),
    array('2026-11-13 06:00:00', false),
) as [$instant, $expected]) {
    $result = $service->forCandidates($instant, array('UTC'), 'UTC', $overnightPolicy);
    $actual = ($result['candidates'][0]['applies'] ?? false) === true;
    if ($actual !== $expected) $fail('Overnight quiet-hour boundary failed for ' . $instant);
}
$overnightStudent = $service->forCandidates('2026-11-12 22:00:00', array('UTC'), 'UTC', $overnightPolicy);
if (empty($overnightStudent['candidates'][0]['student_crosses_midnight'])) $fail('A quiet window crossing midnight must be marked as crossing in the student timezone.');
if (($overnightStudent['candidates'][0]['student_quiet_start_date'] ?? '') === ($overnightStudent['candidates'][0]['student_quiet_end_date'] ?? '')) $fail('Cross-midnight quiet ranges must expose both local dates.');

// DST spring-forward: 01:00 EST to 04:00 EDT is a shorter civil window in UTC.
$dstPolicy = $policy(array('start' => '01:00:00', 'end' => '04:00:00'));
$dst = $service->forCandidates('2026-03-08 06:30:00', array('America/New_York'), 'Europe/London', $dstPolicy);
$dstCandidate = $dst['candidates'][0] ?? array();
if (($dstCandidate['applies'] ?? false) !== true) $fail('A lesson at 01:30 local must be inside 01:00–04:00.');
if (($dstCandidate['student_quiet_start_at_utc'] ?? '') !== '2026-03-08 06:00:00' || ($dstCandidate['student_quiet_end_at_utc'] ?? '') !== '2026-03-08 08:00:00') $fail('DST-adjusted quiet-hour instants are incorrect.');

// A boundary inside the spring-forward gap cannot be converted without guessing.
$gapPolicy = $policy(array('start' => '02:30:00', 'end' => '06:00:00'));
$gap = $service->forCandidates('2026-03-08 07:30:00', array('America/New_York'), 'Europe/London', $gapPolicy);
if (($gap['candidates'][0]['applies'] ?? false) !== true || ($gap['candidates'][0]['student_range_available'] ?? true) !== false) $fail('A DST-nonexistent boundary must keep the advisory but suppress the invented range.');

// DST fall-back: an ambiguous 01:00 boundary is not guessed.
$foldPolicy = $policy(array('start' => '01:00:00', 'end' => '03:00:00'));
$fold = $service->forCandidates('2026-11-01 05:30:00', array('America/New_York'), 'Europe/London', $foldPolicy);
if (($fold['candidates'][0]['applies'] ?? false) !== true) $fail('A lesson at the first 01:30 occurrence must remain inside 01:00–03:00.');
if (($fold['candidates'][0]['student_range_available'] ?? true) !== false) $fail('An ambiguous fall-back boundary must not produce an invented student range.');

// Some candidates affected, not all.
$some = $service->forCandidates('2026-11-12 18:00:00', array('Australia/Brisbane', 'Europe/Berlin'), 'Europe/Berlin', $policy());
if ($some['scope'] !== 'some' || $some['candidate_count'] !== 2 || $some['affected_candidate_count'] !== 1) $fail('Mixed candidate timezones must produce a some-candidate advisory.');
if (($some['candidates'][0]['timezone'] ?? '') === ($some['candidates'][1]['timezone'] ?? '')) $fail('Candidate timezones must remain distinct.');

// All candidates affected.
$all = $service->forCandidates('2026-11-12 18:00:00', array('Australia/Brisbane', 'Asia/Tokyo'), 'Europe/Berlin', $policy());
if ($all['scope'] !== 'all' || $all['candidate_count'] !== 2 || $all['affected_candidate_count'] !== 2) $fail('All affected candidate timezones must produce an all-candidate advisory.');

// Disabled advisory.
$disabled = $service->forCandidates('2026-11-12 18:00:00', array('Australia/Brisbane'), 'Europe/Berlin', $policy(array('enabled' => false)));
if ($disabled['enabled'] !== false || $disabled['scope'] !== 'none') $fail('Disabled Quiet Hours must produce no advisory.');
$studentDisabled = $service->forCandidates('2026-11-12 18:00:00', array('Australia/Brisbane'), 'Europe/Berlin', $policy(array('student_advisory' => false)));
if ($studentDisabled['enabled'] !== false || $studentDisabled['scope'] !== 'none') $fail('Disabled student advisory must produce no warning.');

// Academy basis uses the academy timezone for the quiet window while still showing teacher-local start.
$academy = $service->forCandidates('2026-11-12 02:00:00', array('Australia/Brisbane'), 'Europe/Berlin', $policy(array('timezone_basis' => 'academy', 'academy_timezone' => 'UTC')));
if (($academy['candidates'][0]['applies'] ?? false) !== true) $fail('Academy-basis Quiet Hours must evaluate in the academy timezone.');
if (($academy['candidates'][0]['teacher_local_start'] ?? '') !== '12:00') $fail('Academy-basis advisory must still show the teacher-local class start.');

// Policy validation and historical quiet_start/quiet_end reuse.
$validated = BookingAvailabilityPolicy::validate(array(
    'academy_timezone' => 'Asia/Tehran',
    'candidate_interval_minutes' => 45,
    'version' => 2,
    'quiet_hours_enabled' => '1',
    'quiet_start' => '22:00',
    'quiet_end' => '06:00',
    'quiet_timezone_basis' => 'teacher_local',
    'quiet_student_advisory' => '0',
));
if ($validated['quiet_hours_enabled'] !== true || $validated['quiet_start'] !== '22:00:00' || $validated['quiet_end'] !== '06:00:00') $fail('Quiet Hours must validate crossing midnight.');
if ($validated['quiet_student_advisory'] !== false || $validated['quiet_timezone_basis'] !== 'teacher_local') $fail('Quiet Hours advisory flags must validate.');
try { BookingAvailabilityPolicy::validate(array_merge($validated, array('quiet_start' => '06:00:00', 'quiet_end' => '06:00:00'))); $fail('An equal start/end must be rejected.'); } catch (InvalidArgumentException) {}

$GLOBALS['dzn_quiet_option'] = array(
    'academy_timezone' => 'Asia/Tehran',
    'candidate_interval_minutes' => 45,
    'version' => 4,
    'quiet_start' => '22:30:00',
    'quiet_end' => '05:15:00',
    'student_quiet_start' => '01:00:00',
    'student_quiet_end' => '06:00:00',
);
$historical = BookingAvailabilityPolicy::current();
if ($historical['quiet_start'] !== '22:30:00' || $historical['quiet_end'] !== '05:15:00') $fail('Historical quiet_start/quiet_end values must be reused.');
if ($historical['quiet_hours_enabled'] !== true || $historical['quiet_student_advisory'] !== true) $fail('Restored advisory defaults must be enabled.');
if (array_key_exists('student_quiet_start', $historical) || array_key_exists('student_quiet_end', $historical)) $fail('Obsolete student-local quiet keys must not be exposed.');

echo "quiet-hours-advisory-unit: OK\n";
