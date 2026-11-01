<?php
/**
 * Student Classes / replacement summary contract.
 *
 * Proves that the compact Persian summary is projected from canonical Term and
 * Enrolment fields, that an exhausted replacement balance is shown as zero, that
 * the notification inbox belongs to the Classes tab, that an exhausted absence
 * report still tells the Student the Teacher is informed, and that past-class
 * history uses recorded delivery/attendance facts instead of clock time.
 *
 * Run: php platform/tests/student-classes-replacement-summary-contract.php
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
function add_filter( ...$args ): void {}
function apply_filters( $tag, $value ) { return $value; }
function wp_timezone_string(): string { return 'UTC'; }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'UTC' ); }
function wp_date( string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null ): string {
	return ( new DateTimeImmutable( '@' . ( $timestamp ?? time() ) ) )->setTimezone( $timezone ?? wp_timezone() )->format( $format );
}

require_once dirname( __DIR__, 2 ) . '/theme/inc/platform-bridge.php';

$root  = dirname( __DIR__, 2 );
$theme = $root . '/theme';
$failures = array();
$check = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) { $failures[] = $name; }
};

$enrolment = array(
	'enrolment_uid' => 'ENR-1',
	'term_reference' => 'TERM-1',
	'instrument_name' => 'سه‌تار',
	'lifecycle_state' => 'current',
	'replacement_allowance' => 2,
	'replacement_used' => 2,
	'replacement_pending_allocated' => 0,
	'replacement_available' => 0,
	'replacement_remaining' => 0,
	'replacement_lessons' => array(),
);
$lessons = array();
for ( $i = 1; $i <= 12; $i++ ) {
	$lessons[] = array(
		'lesson_uid' => 'L' . $i,
		'enrolment_uid' => 'ENR-1',
		'term_reference' => 'TERM-1',
		'lesson_kind' => 'standard',
		'lifecycle_state' => 1 === $i ? 'cancelled' : 'authorised',
		'delivery_state_summary' => $i <= 3 ? 'completed' : ( 1 === $i ? 'cancelled' : 'not_recorded' ),
	);
}
$lessons[] = array(
	'lesson_uid' => 'R1',
	'enrolment_uid' => 'ENR-1',
	'term_reference' => 'TERM-1',
	'lesson_kind' => 'replacement',
	'lifecycle_state' => 'authorised',
	'delivery_state_summary' => 'not_recorded',
);

$term = dzn_theme_platform_student_term_progress( $enrolment, null, $lessons );
$check( 'scheduled total stays 12 even with a cancelled class', (int) $term['total'] === 12 );
$check( 'replacement lessons are not counted as scheduled Term sessions', (int) $term['total'] !== 13 );
$check( 'completed sessions come from delivery facts', (int) $term['completed'] === 3 );
$check( 'cancelled sessions are counted separately', (int) $term['cancelled'] === 1 );
$check( 'remaining sessions exclude completed and cancelled', (int) $term['remaining'] === 8 );
$check( 'compact completed label is Persian and record-based', $term['completed_label'] === '۳ از ۱۲ جلسه' );
$check( 'compact remaining label is Persian and record-based', $term['remaining_label'] === '۸ جلسه باقی‌مانده' );
$check( 'exhausted replacement balance displays zero against its allowance', $term['replacement_label'] === 'جبرانی: ۰ از ۲ باقی‌مانده' );

$zero = dzn_theme_platform_student_term_progress(
	array_merge( $enrolment, array( 'replacement_allowance' => 0, 'replacement_used' => 0, 'replacement_available' => 0, 'replacement_remaining' => 0 ) ),
	null,
	$lessons
);
$check( 'a zero allowance still renders an explicit zero balance', $zero['replacement_label'] === 'جبرانی: ۰ از ۰ باقی‌مانده' );

$termTemplate = (string) file_get_contents( $theme . '/template-parts/portal/term-timeline.php' );
$scheduleTemplate = (string) file_get_contents( $theme . '/template-parts/portal/schedule.php' );
$shell = (string) file_get_contents( $theme . '/template-parts/portal/shell.php' );
$accountStatus = (string) file_get_contents( $theme . '/template-parts/portal/account-status.php' );
$bridge = (string) file_get_contents( $theme . '/inc/platform-bridge.php' );

$check( 'the compact summary uses model-provided labels, not a static allowance', str_contains( $termTemplate, "term['replacement_label']" ) && ! str_contains( $termTemplate, 'replacement_allowance = 2' ) );
$check( 'the compact summary has the required Persian progress heading', str_contains( $termTemplate, 'پیشرفت دوره' ) );
$check( 'the compact summary renders completed, remaining and replacement facts', str_contains( $termTemplate, "term['completed_label']" ) && str_contains( $termTemplate, "term['remaining_label']" ) && str_contains( $termTemplate, "term['replacement_label']" ) );
$check( 'the old large replacement status card is gone', ! str_contains( $termTemplate, 'normal_replacement' ) );
$check( 'the standalone account continuation button is gone', ! str_contains( $accountStatus, 'ادامهٔ کلاس‌ها' ) );
$check( 'the discontinuation request remains', str_contains( $accountStatus, 'درخواست توقف ثبت‌نام' ) );
$check( 'account status exposes cancellation separately from replacement', str_contains( $accountStatus, 'cancelled_label' ) && str_contains( $accountStatus, 'جبرانی' ) );
$check( 'account status shows instalment and rescheduling facts', str_contains( $accountStatus, 'instalment_label' ) && str_contains( $accountStatus, 'reschedule_label' ) );

$classesBranchAt = strpos( $shell, "<?php else : ?>" );
$classesBranch = false === $classesBranchAt ? '' : substr( $shell, $classesBranchAt );
$accountAt = strpos( $shell, "elseif ( 'account' === \$screen ) : ?>" );
$accountBranch = false === $accountAt ? '' : substr( $shell, $accountAt, (int) strpos( substr( $shell, $accountAt ), '<?php else : ?>' ) );
$check( 'notifications render in the Classes tab beneath the timeline', str_contains( $classesBranch, "'notifications'" ) && strpos( $classesBranch, "'notifications'" ) > strpos( $classesBranch, "'schedule'" ) );
$check( 'notifications no longer render in Settings', '' !== $accountBranch && ! str_contains( $accountBranch, "'notifications'" ) );
$check( 'the appbar bell points to the Classes tab', str_contains( $shell, "\$notification_classes_url = home_url( '/student-portal/' ) . '#dzn-portal-notifications'" ) && ! str_contains( $shell, 'notification_account_url' ) );

$check( 'the exhausted absence status says the Teacher will be informed', str_contains( $scheduleTemplate, 'به مدرس اطلاع داده می‌شود' ) && str_contains( $scheduleTemplate, 'جلسهٔ جبرانی دیگری صادر نمی‌شود' ) );
$check( 'the backend still owns the exhausted absence outcome', str_contains( $bridge, 'replacement_shortfall' ) && str_contains( $bridge, 'absence_excess' ) );
$check( 'past classes render a recorded outcome, not only the clock state', str_contains( $scheduleTemplate, "item['outcome_label']" ) && str_contains( $bridge, "'completed', 'delivered', 'presumed_completed', 'cancelled'" ) );
$check( 'history selects rows by recorded delivery/attendance facts', str_contains( $bridge, '$history_lessons' ) && str_contains( $bridge, "'settled', 'adjudicated', 'closed_no_change'" ) );

if ( $failures ) {
	throw new RuntimeException( 'Student classes/replacement summary contract failed: ' . implode( '; ', $failures ) );
}
echo "Student classes/replacement summary contract passed\n";
