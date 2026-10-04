<?php
/**
 * Student Portal read-model contract.
 *
 * Source-level contract (no WordPress runtime required) proving that the Student
 * Portal stays a presentation surface: it resolves the learner only from the
 * authenticated session through the Platform read seam, the Platform re-proves
 * ownership of every returned object, and the Theme renders only canonical state
 * instead of fabricating lesson, attendance, entitlement or notification truth.
 *
 * Run with a plain PHP CLI:
 * `php platform/tests/phase-student-portal-read-model-contract.php`.
 */

$root  = dirname( __DIR__, 2 );
$theme = $root . '/theme';

$fail = static function ( string $message ): void {
	throw new RuntimeException( $message );
};

$read = static function ( string $path ) use ( $fail ): string {
	if ( ! is_readable( $path ) ) {
		$fail( 'missing file: ' . $path );
	}
	return (string) file_get_contents( $path );
};

$parts  = glob( $theme . '/template-parts/portal/*.php' ) ?: array();
$pages  = glob( $theme . '/page-templates/student-portal-*.php' ) ?: array();
$portal_surfaces = array_merge(
	$parts,
	$pages,
	array(
		$theme . '/inc/portal.php',
		$theme . '/inc/platform-bridge.php',
		$theme . '/inc/routes.php',
	)
);
$portal_inc = $read( $theme . '/inc/portal.php' );
$bridge     = $read( $theme . '/inc/platform-bridge.php' );
$routes     = $read( $theme . '/inc/routes.php' );
$shell      = $read( $theme . '/template-parts/portal/shell.php' );
$upcoming   = $read( $theme . '/template-parts/portal/upcoming-lesson.php' );
$term       = $read( $theme . '/template-parts/portal/term-timeline.php' );
$notify     = $read( $theme . '/template-parts/portal/notifications.php' );
$profile    = $read( $theme . '/template-parts/portal/profile.php' );
$script     = $read( $theme . '/assets/js/portal.js' );

$service  = $read( $root . '/platform/src/Portals/PortalAuthenticatedReadService.php' );
$resolver = $read( $root . '/platform/src/Portals/PortalPrincipalResolver.php' );
$policy   = $read( $root . '/platform/src/Portals/PortalOwnerPorts.php' );
$owners   = $read( $root . '/platform/src/Core/Application/PortalOwnerReadPorts.php' );
$absence_controller = $read( $root . '/platform/src/Portals/StudentAbsenceController.php' );
$attendance_intake  = $read( $root . '/platform/src/Core/Application/CanonicalAttendanceIntakeService.php' );
$platform_bootstrap = $read( $root . '/platform/delnavazan-platform.php' );

/*
 * 1. The Portal presentation surface never reads domain state itself.
 *
 * A Theme template that queried a domain table could not be reviewed against the
 * canonical owner gate, so the read must stay behind the Platform seam.
 */
foreach ( $portal_surfaces as $path ) {
	$source = $read( $path );
	foreach ( array( '$wpdb', 'get_current_user_id(', 'wp_set_current_user', 'student_principal_links', 'teacher_principal_links', 'dzn_lessons', 'dzn_enrolments', 'dzn_students', 'dzn_teachers', 'portal_public_capabilities', 'SELECT ' ) as $needle ) {
		if ( str_contains( $source, $needle ) ) {
			$fail( sprintf( 'portal presentation must not read domain state itself: %s in %s', $needle, $path ) );
		}
	}
}

/* 2. The learner is resolved from the authenticated session, never from request input. */
foreach ( array( '$_GET', '$_POST', '$_REQUEST', '$_COOKIE' ) as $needle ) {
	if ( str_contains( $service, $needle ) ) {
		$fail( 'the authenticated portal read service must not accept request input: ' . $needle );
	}
	if ( str_contains( $resolver, $needle ) ) {
		$fail( 'the portal principal resolver must not accept request input: ' . $needle );
	}
}
if ( ! str_contains( $service, 'public function student(): array' ) ) {
	$fail( 'the student read must resolve the session principal instead of taking a student identifier' );
}
if ( ! str_contains( $service, "(new PortalPrincipalResolver())->resolve('student')" ) ) {
	$fail( 'the student read must go through the canonical principal resolver' );
}
if ( ! str_contains( $resolver, 'get_current_user_id()' ) ) {
	$fail( 'the principal resolver must resolve the current WordPress user' );
}
if ( ! str_contains( $resolver, 'count($candidates)!==1' ) ) {
	$fail( 'the principal resolver must fail closed unless exactly one principal resolves' );
}
foreach ( array( "'portal_principal_unresolved'", "'portal_principal_ambiguous'" ) as $needle ) {
	if ( ! str_contains( $resolver, $needle ) ) {
		$fail( 'the principal resolver must distinguish unresolved from ambiguous: ' . $needle );
	}
}
foreach ( $portal_surfaces as $path ) {
	$source = $read( $path );
	foreach ( array( 'student_id', 'student-id', 'student-uid' ) as $needle ) {
		if ( str_contains( $source, $needle ) ) {
			$fail( sprintf( 'no request-selectable student identifier may exist in the portal surface: %s in %s', $needle, $path ) );
		}
	}
}

/* 3. Every returned object is re-proved through the owning policy. */
if ( 3 > substr_count( $service, 'PortalAccessPolicy::assertObject(' ) ) {
	$fail( 'every lesson, enrolment and assignment must be re-proved through PortalAccessPolicy' );
}
if ( ! str_contains( $policy, 'function assertObject(' ) || ! str_contains( $policy, 'portal_object_not_owned' ) ) {
	$fail( 'the portal access policy must refuse an object the principal does not own' );
}
foreach ( array( 'portal_object_not_owned', "status='active'", 'revoked_at IS NULL', 'active_slot=1' ) as $needle ) {
	if ( ! str_contains( $owners, $needle ) ) {
		$fail( 'the owner read ports must re-prove the principal link: ' . $needle );
	}
}

/* 4. The Theme adapter consumes the canonical read seam and invents no canonical status. */
if ( ! str_contains( $bridge, 'PortalAuthenticatedReadService' ) ) {
	$fail( 'the Student Portal adapter must consume the Platform portal read service' );
}
if ( ! str_contains( $bridge, '$service->student()' ) ) {
	$fail( 'the Student Portal adapter must read the canonical student model' );
}
foreach ( array( "'schedule_state' => 'confirmed'", "'entitlement_state' => 'standard'", 'absence_notified' ) as $needle ) {
	if ( str_contains( $bridge, $needle ) ) {
		$fail( 'the Student Portal adapter must not fabricate canonical state: ' . $needle );
	}
}
if ( ! str_contains( $bridge, "'entitlement_available' => false" ) ) {
	$fail( 'an entitlement the read model does not expose must be declared unavailable' );
}

/* 5. Honest pending states: an unconnected section never asserts absence of data. */
if ( ! str_contains( $bridge, "\$model['notifications_available'] = false;" ) ) {
	$fail( 'the unconnected notification section must be declared unavailable' );
}
if ( ! str_contains( $notify, "\$args['available']" ) || ! str_contains( $shell, "'available'" ) ) {
	$fail( 'the notification component must know whether a canonical read exists' );
}
if ( ! str_contains( $bridge, "'lesson_allocation_known' => false" ) ) {
	$fail( 'the term progress scale must be declared unknown until a canonical allocation read exists' );
}
if ( ! str_contains( $term, '$scale_note' ) ) {
	$fail( 'the term progress bar must state the scale it is actually measuring' );
}
if ( ! str_contains( $term, "'' !== \$normal_replacement" ) || ! str_contains( $term, "'' !== \$academy_owed" ) ) {
	$fail( 'an unrecorded replacement/remedial fact must fall back to explicit copy, not a blank row' );
}
if ( ! str_contains( $bridge, "'timezone_preference_available' => false" ) ) {
	$fail( 'a timezone the read model does not expose must be declared unavailable' );
}
if ( ! str_contains( $profile, "\$profile['timezone_preference_available']" ) ) {
	$fail( 'the profile must say when the display timezone is not the learner preference' );
}

/* 6. An unavailable control must explain itself instead of appearing broken. */
foreach ( array( 'aria-describedby="dzn-join-pending"', 'aria-describedby="dzn-absence-pending"', 'id="dzn-join-pending"', 'id="dzn-absence-pending"' ) as $needle ) {
	if ( ! str_contains( $upcoming, $needle ) ) {
		$fail( 'every disabled upcoming-lesson control must carry its own explanation: ' . $needle );
	}
}
$calendar_at = strpos( $upcoming, '<dialog id="dzn-calendar-dialog"' );
if ( false === $calendar_at ) {
	$fail( 'the calendar dialog must exist so the calendar control is not a dead control' );
}
$calendar_guard = strrpos( substr( $upcoming, 0, (int) $calendar_at ), 'if ( $has_lesson ) : ?>' );
if ( false === $calendar_guard ) {
	$fail( 'the calendar dialog must be guarded only by the presence of a lesson, not by absence availability' );
}
if ( ! str_contains( $upcoming, "if ( \$has_lesson && ! empty( \$lesson['absence_available'] ) ) : ?>\n\t<dialog id=\"dzn-absence-dialog\"" ) ) {
	$fail( 'the absence dialog must stay bound to the canonical absence capability' );
}

/*
 * 7. Presentation owns no write/provider authority. The existing absence form is
 * a command request to one Platform controller, which rechecks the session,
 * nonce, exact occurrence and current Student ownership before recording a claim.
 */
$student_region_start = strpos( $bridge, 'function dzn_theme_platform_student_model(' );
$student_region_end   = strpos( $bridge, "add_filter( 'dzn_theme_student_portal_view_model'" );
if ( false === $student_region_start || false === $student_region_end || $student_region_end <= $student_region_start ) {
	$fail( 'the Student Portal adapter region could not be located in the bridge' );
}
$student_adapter = substr( $bridge, (int) $student_region_start, (int) $student_region_end - (int) $student_region_start );
$absence_action  = "admin_url( 'admin-post.php?action=dzn_student_report_absence' )";
$absence_nonce   = "wp_create_nonce( 'dzn_student_report_absence' )";
if ( 1 !== substr_count( $student_adapter, $absence_action ) || 1 !== substr_count( $student_adapter, $absence_nonce ) ) {
	$fail( 'the read model may expose only the existing Student absence action and its scoped nonce' );
}
$form_start = strpos( $upcoming, '<form method="post" action=' );
$form_end   = false === $form_start ? false : strpos( $upcoming, '</form>', (int) $form_start );
if ( false === $form_start || false === $form_end || 1 !== substr_count( $upcoming, '<form' ) || 1 !== substr_count( $upcoming, '</form>' ) ) {
	$fail( 'the upcoming lesson may contain only the existing single absence command form' );
}
$absence_form = substr( $upcoming, (int) $form_start, (int) $form_end + strlen( '</form>' ) - (int) $form_start );
foreach ( array(
	"\$lesson['absence_action_url']",
	"esc_url( (string) ( \$lesson['absence_action_url'] ?? '' ) )",
	"\$lesson['absence_nonce']",
	"esc_attr( (string) ( \$lesson['absence_nonce'] ?? '' ) )",
	'name="lesson_uid"',
	'name="schedule_version_uid"',
) as $needle ) {
	if ( ! str_contains( $absence_form, $needle ) ) {
		$fail( 'the absence form must submit only the scoped server action and exact occurrence references: ' . $needle );
	}
}
if ( 3 !== substr_count( $absence_form, '<input' ) || ! str_contains( $absence_form, 'type="submit"' ) ) {
	$fail( 'the absence form may submit only its nonce and the two exact occurrence references' );
}
foreach ( array( '$wpdb', 'SELECT ', '->insert(', '->update(', '->delete(', 'wp_insert_post', 'wp_update_post', 'wp_remote_', 'curl_', 'stripe', 'Stripe', 'dzn_platform_portal_actions', 'fetch(', 'XMLHttpRequest', 'sendBeacon', 'localStorage' ) as $needle ) {
	if ( str_contains( $absence_form, $needle ) ) {
		$fail( 'the absence form must remain a command request without direct write/provider behavior: ' . $needle );
	}
}
if ( ! str_contains( $absence_controller, "add_action('admin_post_dzn_student_report_absence'" )
	|| ! str_contains( $absence_controller, 'if(!is_user_logged_in())auth_redirect();' )
	|| ! str_contains( $absence_controller, "check_admin_referer('dzn_student_report_absence')" )
	|| ! str_contains( $absence_controller, 'CanonicalAttendanceIntakeService' )
	|| 1 !== substr_count( $absence_controller, '->submitClaim(' )
	|| str_contains( $absence_controller, 'wp_remote_' )
	|| str_contains( $absence_controller, 'curl_' )
	|| str_contains( $absence_controller, 'Stripe' )
	|| ! str_contains( $absence_controller, 'WHERE uid=%s AND archived_at IS NULL LIMIT 1' )
	|| ! str_contains( $absence_controller, 'WHERE uid=%s AND lesson_id=%d AND applicable_slot=1 LIMIT 1' )
	|| ! str_contains( $platform_bootstrap, 'StudentAbsenceController::register()' )
) {
	$fail( 'the existing absence command must be registered only for authenticated POSTs and delegated to Platform intake' );
}
foreach ( array(
	'$this->authoriseOwnership($kind,$case,$late);',
	"if(!\$this->hasStudentPrincipal((int)\$case->student_id,\$user))throw new \\RuntimeException('Unauthorized');",
	"wordpress_user_id=%d AND status='active' AND revoked_at IS NULL",
	"if(!\$latest||(int)\$latest->id!==\$scheduleVersionId)throw new \\InvalidArgumentException('schedule_version_conflict');",
) as $needle ) {
	if ( ! str_contains( $attendance_intake, $needle ) ) {
		$fail( 'the absence action must re-prove current Student ownership and exact schedule authority: ' . $needle );
	}
}

$student_adapter_without_absence_action = str_replace( array( $absence_action, $absence_nonce ), '', $student_adapter );
$upcoming_without_absence_form = substr_replace( $upcoming, '', (int) $form_start, strlen( $absence_form ) );
$write_scans = array(
	$theme . '/inc/portal.php'                              => $portal_inc,
	$theme . '/inc/platform-bridge.php (student adapter)'   => $student_adapter_without_absence_action,
	$theme . '/template-parts/portal/upcoming-lesson.php'   => $upcoming_without_absence_form,
);
foreach ( $parts as $path ) {
	if ( $path === $theme . '/template-parts/portal/upcoming-lesson.php' ) { continue; }
	$write_scans[ $path ] = $read( $path );
}
foreach ( $pages as $path ) {
	$write_scans[ $path ] = $read( $path );
}
foreach ( $write_scans as $path => $source ) {
	foreach ( array( 'admin-post.php', 'wp_nonce_field', '<form', 'method="post"', 'wp_remote_', 'curl_', 'stripe', 'Stripe', 'dzn_platform_portal_actions' ) as $needle ) {
		if ( str_contains( $source, $needle ) ) {
			$fail( sprintf( 'the portal presentation must not acquire write or provider authority: %s in %s', $needle, $path ) );
		}
	}
}
foreach ( array( '->insert(', '->update(', '->delete(', 'wp_insert_post', 'wp_update_post' ) as $needle ) {
	if ( str_contains( $service, $needle ) ) {
		$fail( 'the authenticated portal read service must not write: ' . $needle );
	}
}
foreach ( array( 'fetch(', 'XMLHttpRequest', 'sendBeacon', 'localStorage' ) as $needle ) {
	if ( str_contains( $script, $needle ) ) {
		$fail( 'the portal presentation script must not persist or transmit anything: ' . $needle );
	}
}

/* 8. The portal route requires an authenticated session before it renders. */
$route_at  = strpos( $routes, 'function dzn_theme_render_portal_route(' );
$login_at  = false === $route_at ? false : strpos( $routes, 'is_user_logged_in()', (int) $route_at );
$render_at = false === $route_at ? false : strpos( $routes, 'dzn_theme_render_student_portal(', (int) $route_at );
if ( false === $route_at || false === $login_at || false === $render_at || $login_at > $render_at ) {
	$fail( 'the student portal route must require an authenticated session before rendering' );
}

/* 9. Synthetic fixtures stay in the gated preview surface only. */
if ( ! str_contains( $portal_inc, "'production' !== wp_get_environment_type()" ) || ! str_contains( $portal_inc, "current_user_can( 'edit_theme_options' )" ) ) {
	$fail( 'synthetic portal fixtures must stay restricted to non-production Theme administrators' );
}
foreach ( array( $theme . '/inc/platform-bridge.php', $theme . '/inc/routes.php', $theme . '/page-templates/student-portal-home.php', $theme . '/page-templates/student-portal-account.php' ) as $path ) {
	$source = $read( $path );
	if ( str_contains( $source, 'dzn_theme_student_portal_demo_model' ) ) {
		$fail( 'production portal surfaces must not render synthetic fixtures: ' . $path );
	}
}
$preview = $read( $theme . '/page-templates/student-portal-preview.php' );
if ( ! str_contains( $preview, 'dzn_theme_student_portal_demo_model' ) || ! str_contains( $portal_inc, "'is_demo'" ) ) {
	$fail( 'the preview surface must still label its synthetic fixtures' );
}

echo "Student Portal read-model contract passed\n";
