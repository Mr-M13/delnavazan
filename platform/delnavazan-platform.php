<?php
/**
 * Plugin Name: Delnavazan Platform
 * Description: Canonical Core foundation for Delnavazan. No Amelia or provider integration.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 */
defined( 'ABSPATH' ) || exit;
define( 'DZN_PLATFORM_VERSION', '0.1.0' );
define( 'DZN_PLATFORM_SCHEMA_VERSION', '35' );
// Package-stamped runtime-validation identity. This is deliberately explicit
// because production packages do not include Git metadata.
define( 'DZN_PLATFORM_BUILD_ID', 'teacher-onboarding-v1-20261001.1' );
// Temporary Phase 1F beta diagnostic. Define as false before loading the
// plugin to disable it; remove after the nonce failure is understood.
defined( 'DZN_PLATFORM_PHASE_1F_NONCE_DIAGNOSTICS' ) || define( 'DZN_PLATFORM_PHASE_1F_NONCE_DIAGNOSTICS', true );
define( 'DZN_PLATFORM_FILE', __FILE__ );
define( 'DZN_PLATFORM_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register( static function ( $class ) {
	$prefix = 'Delnavazan\\Platform\\';
	if ( ! str_starts_with( $class, $prefix ) ) { return; }
	$file = DZN_PLATFORM_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( is_readable( $file ) ) { require $file; }
} );

Delnavazan\Platform\Admin\Diagnostic\NonceLifecycleDiagnostic::register();
register_activation_hook( __FILE__, array( 'Delnavazan\\Platform\\Core\\Infrastructure\\Migration\\Migrator', 'on_activation' ) );
add_action( 'plugins_loaded', static function () {
	// Full schema verification is intentionally expensive and belongs to the
	// activation/upgrade boundary, not the request hot path. maybe_upgrade()
	// remains the strict fail-closed verifier when explicitly invoked.
	if ( (string) get_option( 'dzn_platform_schema_version' ) !== (string) DZN_PLATFORM_SCHEMA_VERSION ) {
		Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();
	}
	Delnavazan\Platform\Admin\Controller\Menu::register();
	Delnavazan\Platform\Admin\Controller\PaymentExecutionController::register();
	Delnavazan\Platform\Admin\Controller\CommercialCatalogueController::register();
	Delnavazan\Platform\Admin\Controller\AcademyOperationsController::register();
	Delnavazan\Platform\Admin\Controller\FinancePolicyController::register();
	Delnavazan\Platform\Admin\Controller\FinanceRateController::register();
	Delnavazan\Platform\Admin\Controller\FinancePayabilityController::register();
	Delnavazan\Platform\Admin\Controller\FinanceStatementController::register();
	Delnavazan\Platform\Admin\Controller\FinanceReconciliationController::register();
	Delnavazan\Platform\Admin\Controller\CoreDatasetReadinessController::register();
	Delnavazan\Platform\Admin\Controller\PortalCapabilityController::register();
	Delnavazan\Platform\Portals\TeacherOnboardingController::register();
	Delnavazan\Platform\Portals\TeacherInvitationController::register();
	Delnavazan\Platform\Portals\TeacherDeliveryClaimController::register();
	Delnavazan\Platform\Portals\StudentAbsenceController::register();
	Delnavazan\Platform\Integrations\GoogleOAuthController::register();
	Delnavazan\Platform\Integrations\TeacherClassJoinController::register();
	Delnavazan\Platform\Integrations\GoogleCalendarProjectionWorker::register();
	Delnavazan\Platform\Portals\PortalOwnerPorts::configureCapability(new Delnavazan\Platform\Core\Application\CanonicalLessonPortalCapabilityOwner());
	require_once DZN_PLATFORM_DIR . 'src/Core/Application/PortalOwnerReadPorts.php';
	Delnavazan\Platform\Portals\PortalOwnerPorts::configureReadPorts(new Delnavazan\Platform\Core\Application\CanonicalTeacherAssignmentPortalReadPort(),new Delnavazan\Platform\Core\Application\CanonicalLessonSchedulePortalReadPortImpl(),new Delnavazan\Platform\Core\Application\CanonicalLessonDeliveryPortalReadPortImpl(),new Delnavazan\Platform\Core\Application\CanonicalAttendancePortalReadPortImpl());
} );
add_action( 'rest_api_init', array( 'Delnavazan\\Platform\\Public\\BookingRequestRestController', 'register' ) );
add_action( 'rest_api_init', array( 'Delnavazan\\Platform\\Public\\BookingOptionsRestController', 'register' ) );
add_action( 'rest_api_init', array( 'Delnavazan\\Platform\\Public\\BookingAvailabilityPreviewRestController', 'register' ) );
add_action( 'rest_api_init', array( 'Delnavazan\\Platform\\Public\\BookingDayAvailabilityRestController', 'register' ) );
add_action( 'rest_api_init', array( 'Delnavazan\\Platform\\Integrations\\Payment\\Stripe\\StripeWebhookController', 'register' ) );
add_action( 'rest_api_init', array( 'Delnavazan\\Platform\\Portals\\PortalPublicActionController', 'register' ) );
