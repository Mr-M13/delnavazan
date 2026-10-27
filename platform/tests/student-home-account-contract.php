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
$order = array( 'upcoming-lesson', 'term-timeline', 'booking-requests', 'post-intro', 'announcement', 'history' );
$previous = -1;
$ordered = true;
foreach ( $order as $component ) {
	$at = $position( $home, "'" . $component . "'" );
	if ( $at === PHP_INT_MAX || $at < $previous ) {
		$ordered = false;
	}
	$previous = $at;
}
$check( 'home renders next class before term, requests, announcement and history', $ordered );

/* 2. Account puts profile, payments and notifications before history. */
$account = substr( $shell, (string) strpos( $shell, "'account' === \$screen" ) );
$account = substr( $account, 0, (int) strpos( $account, '<?php else : ?>' ) );
$account_previous = -1;
$account_ordered = true;
foreach ( array( 'profile', 'payments', 'notifications', 'history' ) as $component ) {
	$at = $position( $account, "'" . $component . "'" );
	if ( $at === PHP_INT_MAX || $at < $account_previous ) {
		$account_ordered = false;
	}
	$account_previous = $at;
}
$check( 'account renders profile, payments and notifications before history', $account_ordered );
$check( 'the always-rendered future-orders placeholder is gone', ! str_contains( $shell, "'future-orders'" ) );

/* 3. History dates are machine-readable instants. */
$check( 'history items carry the canonical UTC start', str_contains( $bridge, "'starts_at_utc' => (string) ( \$lesson['starts_at_utc'] ?? '' )" ) );
$check( 'history renders a time element with datetime', str_contains( $history, '<time datetime=' ) );
$check( 'history omits the time element when no instant exists', str_contains( $history, "! empty( \$item['starts_at_utc'] )" ) );

/* 4. Mobile task hierarchy is real CSS, not only markup order. */
$check( 'the upcoming card becomes a mobile surface', (bool) preg_match( '/@media \(max-width: 47\.99rem\)[\s\S]*?\.dzn-upcoming\s*\{[^}]*border:/', $css ) );
$check( 'the mobile primary action is 48px', (bool) preg_match( '/\.dzn-upcoming__actions \.dzn-button\s*\{[^}]*min-block-size:\s*48px/s', $css ) );
$check( 'mobile actions stack full width', (bool) preg_match( '/\.dzn-upcoming__actions\s*\{[^}]*flex-direction:\s*column/s', $css ) );
$check( 'portal content uses the wider desktop measure', str_contains( $css, 'max-inline-size: 73.75rem' ) );

if ( $failures ) {
	throw new RuntimeException( 'Student home/account contract failed: ' . implode( '; ', $failures ) );
}
echo "Student home/account contract passed\n";
