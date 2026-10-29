<?php
/**
 * Package 4 contract: student home and account hierarchy.
 *
 * Guards the task-first mobile order, the account order that surfaces payments
 * and notifications before history, machine-readable history instants, and the
 * removal of the always-rendered future-orders placeholder.
 *
 * Run: php platform/tests/student-home-account-contract.php
 */

$root   = dirname( __DIR__, 2 );
$theme  = $root . '/theme';
$shell  = (string) file_get_contents( $theme . '/template-parts/portal/shell.php' );
$history = (string) file_get_contents( $theme . '/template-parts/portal/history.php' );
$bridge = (string) file_get_contents( $theme . '/inc/platform-bridge.php' );
$css    = (string) file_get_contents( $theme . '/assets/css/portal.css' );

$failures = array();
$check = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		$failures[] = $name;
	}
};

$position = static function ( string $haystack, string $needle ): int {
	$at = strpos( $haystack, $needle );
	return false === $at ? PHP_INT_MAX : $at;
};

/* 1. Home is task-first: next class, term, requests/invitations, announcement, history. */
$home = substr( $shell, (string) strpos( $shell, "<?php else : ?>") );
$order = array( 'reschedule', 'term-timeline', 'schedule', 'booking-requests', 'post-intro', 'announcement' );
$previous = -1;
$ordered = true;
foreach ( $order as $component ) {
	$at = $position( $home, "'" . $component . "'" );
	if ( $at === PHP_INT_MAX || $at < $previous ) {
		$ordered = false;
	}
	$previous = $at;
}
$check( 'the merged classes tab puts pending reschedule first, then timeline and schedule', $ordered );
$postIntro = (string) file_get_contents( $theme . '/template-parts/portal/post-intro.php' );
$check( 'the post-intro activation-only card is filtered while actionable continuation remains', ! empty( $postIntro ) && str_contains( $postIntro, "! empty( \$item['actionable'] )" ) );

$check( 'student home greets the student by first name', str_contains( $shell, "'درود بر شما' . ( '' !== \$first_name" ) );
$check( 'student email is visibly read-only', str_contains( (string) file_get_contents( $theme . '/template-parts/portal/profile.php' ), 'is-readonly' ) );
$check( 'term replacement allowance is projected from the enrolment', str_contains( $bridge, 'replacement_allowance' ) && str_contains( $bridge, 'replacement_items' ) );
$check( 'replacement lessons are counted and listed from the canonical Term', str_contains( (string) file_get_contents( $root . '/platform/src/Core/Application/CanonicalStudentEnrolmentPortalReadPort.php' ), "lesson_type='replacement'" ) );
$check( 'replacement classes are labelled in the schedule', str_contains( (string) file_get_contents( $theme . '/template-parts/portal/schedule.php' ), 'جلسهٔ جایگزین' ) );
$check( 'rescheduled classes are labelled as a new time in the schedule', str_contains( (string) file_get_contents( $theme . '/template-parts/portal/schedule.php' ), 'زمان جدید' ) && str_contains( $bridge, 'schedule_revision_count' ) );
$check( 'replacement classes appear in the term timeline with confirmation state', str_contains( (string) file_get_contents( $theme . '/template-parts/portal/term-timeline.php' ), "replacement_item['confirmed']" ) );

/* 2. Account puts profile, payments and notifications before history. */
$account_at = strpos( $shell, "elseif ( 'account' === \$screen ) : ?>" );
$account = false === $account_at ? '' : substr( $shell, $account_at );
$account = substr( $account, 0, (int) strpos( $account, '<?php else : ?>' ) );
$account_previous = -1;
$account_ordered = true;
foreach ( array( 'profile', 'payments', 'notifications', 'account-status' ) as $component ) {
	$at = $position( $account, "'" . $component . "'" );
	if ( $at === PHP_INT_MAX || $at < $account_previous ) {
		$account_ordered = false;
	}
	$account_previous = $at;
}
$check( 'account puts account status at the bottom after profile and notifications', $account_ordered );
$check( 'the always-rendered future-orders placeholder is gone', ! str_contains( $shell, "'future-orders'" ) );

/* 3. History dates are machine-readable instants. */
$check( 'history items carry the canonical UTC start', str_contains( $bridge, "'starts_at_utc' => (string) ( \$lesson['starts_at_utc'] ?? '' )" ) );
$check( 'history renders a time element with datetime', str_contains( $history, '<time datetime=' ) );
$check( 'history instants are ISO 8601', str_contains( $bridge, 'starts_at_iso' ) && str_contains( $history, 'starts_at_iso' ) );
$check( 'history omits the time element when no instant exists', str_contains( $history, "! empty( \$item['starts_at_utc'] )" ) );

/* 4. Mobile task hierarchy is real CSS, not only markup order. */
$check( 'the upcoming card becomes a mobile surface', (bool) preg_match( '/@media \(max-width: 47\.99rem\)[\s\S]*?\.dzn-upcoming\s*\{[^}]*border:/', $css ) );
$check( 'the mobile primary action is 48px', (bool) preg_match( '/\.dzn-upcoming__actions \.dzn-button\s*\{[^}]*min-block-size:\s*48px/s', $css ) );
$profile = (string) file_get_contents( $theme . '/template-parts/portal/profile.php' );
$statusCard = (string) file_get_contents( $theme . '/template-parts/portal/account-status.php' );
$check( 'student may edit phone, WhatsApp, timezone and calendar but not email', str_contains( $profile, 'name="phone"' ) && str_contains( $profile, 'name="whatsapp_phone"' ) && str_contains( $profile, 'name="timezone"' ) && str_contains( $profile, 'name="calendar_preference"' ) && str_contains( $profile, 'id="dzn-profile-email"' ) && str_contains( $profile, 'readonly' ) );
$check( 'account status offers continuation and a support-backed discontinuation request', str_contains( $statusCard, 'ادامهٔ کلاس‌ها' ) && str_contains( $statusCard, 'درخواست توقف ثبت‌نام' ) );
$check( 'account status shows replacement usage and pending replacement time', str_contains( $statusCard, 'replacement_used' ) && str_contains( $statusCard, 'در انتظار تعیین زمان' ) );
$check( 'the student join boundary is registered for canonical term lessons', str_contains( (string) file_get_contents( $root . '/platform/delnavazan-platform.php' ), 'StudentClassJoinController::register' ) );
$joinService = (string) file_get_contents( $root . '/platform/src/Integrations/StudentClassJoinService.php' );
$check( 'student join re-proves ownership, exact schedule, window and vaulted provider reference', str_contains( $joinService, "resolve('student')" ) && str_contains( $joinService, 'PortalAccessPolicy::assertObject' ) && str_contains( $joinService, 'activeCalendarMapping' ) && str_contains( $joinService, "open('calendar_event'" ) );
$check( 'student join controller is CSRF protected', str_contains( (string) file_get_contents( $root . '/platform/src/Integrations/StudentClassJoinController.php' ), "check_admin_referer('dzn_student_join_class')" ) );
$check( 'mobile actions stack full width', (bool) preg_match( '/\.dzn-upcoming__actions\s*\{[^}]*flex-direction:\s*column/s', $css ) );
$check( 'portal content uses the wider desktop measure', str_contains( $css, 'max-inline-size: 73.75rem' ) );

if ( $failures ) {
	throw new RuntimeException( 'Student home/account contract failed: ' . implode( '; ', $failures ) );
}
echo "Student home/account contract passed\n";
