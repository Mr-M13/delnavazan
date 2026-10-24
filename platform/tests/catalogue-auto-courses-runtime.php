<?php
/**
 * Disposable WordPress/MariaDB proof for automatic Instrument course naming and reconciliation.
 * Run with: DZN_CATALOGUE_RUNTIME_TEST=authority wp eval-file ... --user=1
 */
if (getenv('DZN_CATALOGUE_RUNTIME_TEST') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Catalogue runtime refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\CatalogueReconciliationService;
use Delnavazan\Platform\Core\Application\CatalogueService;
use Delnavazan\Platform\Core\Application\InstrumentIntroCourseDefaultService;
use Delnavazan\Platform\Core\Application\PublicBookingOptionsReadService;
use Delnavazan\Platform\Core\Infrastructure\Repository\CourseRepository;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$actor = 1;
wp_set_current_user($actor);
function dzn_cat_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

$name = 'سه‌تار';
$input = array(
    'name' => $name,
    'standard_duration_minutes' => 30,
    'gap_between_lessons_minutes' => 15,
    'introductory_lesson_billing' => 'free',
);
$count = static function (string $table) use ($wpdb, $p): int {
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table}");
};
$before = array();
foreach (array('instruments', 'courses', 'commercial_products', 'commercial_prices', 'terms', 'enrolments', 'lessons') as $table) $before[$table] = $count($table);

$service = new CatalogueService();
$first = $service->setupInstrument($input);
$instrumentId = (int) $first['instrument_id'];
$introId = (int) $first['introductory_course_id'];
$standardId = (int) $first['standard_course_id'];
$productId = (int) $first['product_id'];
dzn_cat_assert($instrumentId > 0 && $introId > 0 && $standardId > 0 && $productId > 0, 'automatic catalogue identities required');

$courses = new CourseRepository();
$intro = $courses->find($introId);
$standard = $courses->find($standardId);
dzn_cat_assert($intro && $standard, 'both auto-created Courses required');
dzn_cat_assert((string) $intro->name_fa === 'کلاس معارفه سه‌تار', 'introductory Persian name must follow کلاس معارفه [نام ساز]');
dzn_cat_assert((string) $standard->name_fa === 'کلاس سه‌تار', 'standard Persian name must follow کلاس [نام ساز]');
foreach (array($intro, $standard) as $course) {
    dzn_cat_assert((int) $course->instrument_id === $instrumentId, 'course must belong to its instrument');
    dzn_cat_assert((string) $course->status === 'active' && $course->archived_at === null, 'auto-created course must be active');
    dzn_cat_assert((int) $course->default_duration_minutes === 30 && (int) $course->default_buffer_minutes === 15, 'default timing must be deterministic');
}
dzn_cat_assert(count($courses->activeForInstrumentAndType($instrumentId, 'introductory')) === 1, 'exactly one introductory Course required');
dzn_cat_assert(count($courses->activeForInstrumentAndType($instrumentId, 'standard')) === 1, 'exactly one standard Course required');

$default = (new InstrumentIntroCourseDefaultService())->resolve($instrumentId);
dzn_cat_assert($default && (int) $default->course_id === $introId, 'public booking default must point at the introductory Course');
$options = (new PublicBookingOptionsReadService())->introductoryInstruments();
$option = null;
foreach ($options as $candidate) if ((int) $candidate['id'] === $instrumentId) { $option = $candidate; break; }
dzn_cat_assert($option !== null, 'new instrument must be publicly bookable through the same catalogue authority');
dzn_cat_assert((int) $option['course_id'] === $introId && (string) $option['course_name_fa'] === 'کلاس معارفه سه‌تار', 'public booking must use the active default introductory Course');
dzn_cat_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_products WHERE id=%d AND course_id=%d AND status='active' AND archived_at IS NULL", $productId, $standardId)) === 1, 'standard Course must retain its commercial product');
dzn_cat_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_prices WHERE product_id=%d", $productId)) === 0, 'instrument setup must not invent regional prices');

$replay = $service->setupInstrument($input);
dzn_cat_assert($replay === $first, 'repeated setup must converge on the same identities and product');
dzn_cat_assert(count($courses->activeForInstrumentAndType($instrumentId, 'introductory')) === 1 && count($courses->activeForInstrumentAndType($instrumentId, 'standard')) === 1, 'repeated setup must not duplicate courses');

$planBefore = array('instruments' => $count('instruments'), 'courses' => $count('courses'), 'defaults' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}instrument_intro_course_defaults"));
$plan = (new CatalogueReconciliationService())->plan();
$planAfter = array('instruments' => $count('instruments'), 'courses' => $count('courses'), 'defaults' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}instrument_intro_course_defaults"));
dzn_cat_assert($planBefore === $planAfter, 'reconciliation plan must be read-only');
foreach ((array) $plan['findings']['duplicate_active_courses'] as $finding) dzn_cat_assert((int) $finding['instrument_id'] !== $instrumentId, 'new catalogue must not be reported as duplicate');
foreach ((array) $plan['findings']['invalid_intro_defaults'] as $finding) dzn_cat_assert((int) $finding['instrument_id'] !== $instrumentId, 'new catalogue default must be valid');
foreach ((array) $plan['findings']['missing_intro_defaults'] as $finding) dzn_cat_assert((int) $finding['instrument_id'] !== $instrumentId, 'new catalogue must not be reported as missing a default');

foreach (array('terms', 'enrolments', 'lessons') as $table) {
    dzn_cat_assert($count($table) === $before[$table], 'automatic catalogue setup must not create ' . $table);
}
dzn_cat_assert($count('commercial_prices') === $before['commercial_prices'], 'automatic catalogue setup must not create regional prices');

echo "Catalogue auto-naming, idempotency and reconciliation runtime passed\n";
