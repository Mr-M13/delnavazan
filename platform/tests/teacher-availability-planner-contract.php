<?php
$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
	$source = file_get_contents($root . '/' . $path);
	if (false === $source) throw new RuntimeException('Unable to read ' . $path);
	return $source;
};
$assert = static function (bool $condition, string $message): void {
	if (!$condition) throw new RuntimeException($message);
};

$account = $read('../theme/template-parts/teacher-portal/account.php');
$planner = $read('../theme/template-parts/teacher-portal/availability-planner.php');
$script = $read('../theme/assets/js/availability-planner.js');
$style = $read('../theme/assets/css/availability-planner.css');
$service = $read('src/Core/Application/TeacherAvailabilityService.php');
$controller = $read('src/Portals/TeacherAvailabilitySelfServiceController.php');
$bridge = $read('../theme/inc/platform-bridge.php');

$assert(str_contains($account, "'availability-planner'"), 'The account screen must render the interactive availability planner.');
$assert(!str_contains($account, 'زمان‌های در دسترس برای تدریس'), 'The obsolete availability section heading must be removed.');
$assert(!str_contains($account, 'ظرفیت هفتگی'), 'The obsolete weekly-capacity section must be removed.');
$assert(!str_contains($account, 'روزهای تعطیل و تاریخ‌های استثنایی'), 'The day-off section must be removed from the account screen for now.');
$assert(!str_contains($account, 'دایره فقط یک نمای ساده'), 'The display-only circle copy must not return.');

$assert(str_contains($planner, 'data-dzn-ap-dial'), 'The planner must render the interactive dial mount point.');
$assert(str_contains($planner, 'data-dzn-ap-ranges'), 'The planner must render the range editor.');
$assert(str_contains($planner, 'dzn_teacher_availability_schedule'), 'The planner must post to the bulk schedule route.');
$assert(str_contains($planner, 'dzn_teacher_availability_instrument_empty'), 'The planner must retain the explicit empty action.');
$assert(str_contains($planner, 'data-dzn-planner-data'), 'The planner must ship its instrument, golden and gap payload.');

$assert(str_contains($script, 'DAY_START = 360'), 'The planner dial must start the day at 06:00 at the top.');
$assert(str_contains($script, 'hour % 6 === 0'), 'The planner dial must distinguish six-hour majors from intermediate hours.');
$assert(str_contains($script, 'pointerdown'), 'The planner dial must accept direct pointer selection.');
$assert(str_contains($script, "step = '900'"), 'The native time inputs must keep 15-minute precision.');
$assert(str_contains($script, 'is-golden') && str_contains($script, 'is-gap'), 'The planner must render Golden Hour and coverage-gap overlays.');
$assert(str_contains($script, 'is-booked'), 'The planner must render the existing-classes inner ring.');
$assert(str_contains($script, 'drawBlocks'), 'The planner must render time segments as discrete blocks like the admin coverage clock.');
$assert(str_contains($script, 'is-unallocated'), 'The full availability ring must segment unallocated time too.');
$assert(str_contains($script, 'ranges.push'), 'The planner must serialize multiple ranges per instrument.');

$assert(str_contains($style, '.dzn-ap__band.is-selected'), 'The planner stylesheet must style selected ranges.');
$assert(str_contains($style, '.dzn-ap__band.is-golden'), 'The planner stylesheet must style Golden Hour bands.');
$assert(str_contains($style, '.dzn-ap__band.is-booked'), 'The planner stylesheet must style the existing-classes inner ring.');
$assert(str_contains($style, '.dzn-ap__band.is-unallocated'), 'The planner stylesheet must style the unallocated ring segments.');
$assert(str_contains($style, '@media (max-width: 40rem)') && str_contains($style, '.dzn-ap__hour.is-minor'), 'Mobile captions must reduce to readable major labels.');
$assert(str_contains($style, '.dzn-ap__band.is-gap'), 'The planner stylesheet must style coverage-gap bands.');

$assert(str_contains($service, 'replaceOwnWeeklySchedule'), 'The canonical service must expose the atomic weekly-schedule replacement.');
$assert(str_contains($service, 'retireOwnInstrumentRules'), 'The schedule replacement must retire only the selected instrument.');
$assert(str_contains($service, 'saveInstrumentState'), 'The schedule replacement must persist the explicit/empty instrument state.');
$assert(str_contains($controller, 'dzn_teacher_availability_schedule'), 'The self-service controller must register the bulk schedule route.');
$assert(str_contains($bridge, 'dzn_theme_platform_teacher_availability_planner'), 'The portal model must build the planner payload.');
$assert(str_contains($bridge, 'dzn_theme_platform_teacher_coverage_clock'), 'The planner must reuse the canonical teacher coverage clock data.');
$assert(str_contains($bridge, 'dzn_theme_platform_teacher_booked_bands') && str_contains($bridge, "'booked_bands'"), 'The planner must expose the Teacher-local existing-class bands.');
$assert(str_contains($bridge, 'dzn_theme_platform_teacher_golden_windows'), 'Golden Hour bands must come from the configured effective-dated policy.');

echo "teacher-availability-planner-contract: OK\n";
