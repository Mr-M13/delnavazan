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
define( 'DZN_PLATFORM_SCHEMA_VERSION', '56' );
// Package-stamped runtime-validation identity. This is deliberately explicit
// because production packages do not include Git metadata.
define( 'DZN_PLATFORM_BUILD_ID', 'phase2a2x-telnyx-sms-whatsapp-20261030.1' );
// Temporary Phase 1F beta diagnostic. Define as false before loading the
// plugin to disable it; remove after the nonce failure is understood.
defined( 'DZN_PLATFORM_PHASE_1F_NONCE_DIAGNOSTICS' ) || define( 'DZN_PLATFORM_PHASE_1F_NONCE_DIAGNOSTICS', false );
define( 'DZN_PLATFORM_FILE', __FILE__ );
define( 'DZN_PLATFORM_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register( static function ( $class ) {
	$prefix = 'Delnavazan\\Platform\\';
	if ( ! str_starts_with( $class, $prefix ) ) { return; }
	$file = DZN_PLATFORM_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( is_readable( $file ) ) { require $file; return; }
	// Portal read-model types share one historical source file.
	if ( str_starts_with( $class, 'Delnavazan\\Platform\\Portals\\Portal' ) && is_readable( DZN_PLATFORM_DIR . 'src/Portals/PortalReadModels.php' ) ) { require_once DZN_PLATFORM_DIR . 'src/Portals/PortalReadModels.php'; }
} );

Delnavazan\Platform\Admin\Diagnostic\NonceLifecycleDiagnostic::register();
register_activation_hook( __FILE__, array( 'Delnavazan\\Platform\\Core\\Infrastructure\\Migration\\Migrator', 'on_activation' ) );
add_action( 'plugins_loaded', static function () {
	// Phase 2A.2-T provider seam wiring. The registry is process-local and must be populated before
	// any REST webhook delivery is dispatched; without it every Stripe event fails closed with
	// `unsupported_payment_provider` before a receipt can be recorded. Registration is idempotent
	// (one adapter per provider key) and never authorises live execution: live charging remains
	// closed by PaymentExecutionRule::LIVE_EXECUTION_PROVIDERS, which is empty in this build.
	if ( class_exists( 'Delnavazan\\Platform\\Core\\Application\\PaymentExecution\\PaymentProviderRegistry' ) ) {
		$dzn_payment_registry = 'Delnavazan\\Platform\\Core\\Application\\PaymentExecution\\PaymentProviderRegistry';
		if ( ! $dzn_payment_registry::hasTranslator( 'stripe' )
			&& class_exists( 'Delnavazan\\Platform\\Integrations\\Payment\\Stripe\\StripeEventTranslator' ) ) {
			$dzn_payment_registry::registerTranslator( new Delnavazan\Platform\Integrations\Payment\Stripe\StripeEventTranslator() );
		}
		if ( ! $dzn_payment_registry::hasExecutionPort( 'stripe' )
			&& class_exists( 'Delnavazan\\Platform\\Integrations\\Payment\\Stripe\\StripePaymentAdapter' ) ) {
			$dzn_payment_registry::registerExecutionPort( new Delnavazan\Platform\Integrations\Payment\Stripe\StripePaymentAdapter() );
		}
	}
	// Full schema verification is intentionally expensive and belongs to the
	// activation/upgrade boundary, not the request hot path. maybe_upgrade()
	// remains the strict fail-closed verifier when explicitly invoked.
	if ( (string) get_option( 'dzn_platform_schema_version' ) !== (string) DZN_PLATFORM_SCHEMA_VERSION ) {
		Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();
	}
	Delnavazan\Platform\Admin\Controller\Menu::register();
	Delnavazan\Platform\Admin\Controller\CommunicationsTemplateController::register();
	Delnavazan\Platform\Admin\Controller\NotificationManagementController::register();
	Delnavazan\Platform\Portals\PortalNotificationController::register();
	Delnavazan\Platform\Core\Application\PortalNotificationRecorder::register();
	// The admin-editable copy store is the canonical copy source once a template version is published.
	// Registering definitions never installs a transport binding, so delivery stays behind its own gate.
	add_filter( 'dzn_notification_copy_definitions', static function ( $definitions ) {
		$definitions = is_array( $definitions ) ? $definitions : array();
		try {
			$stored = ( new Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary() )->copyDefinitions();
		} catch ( \Throwable $error ) {
			$stored = array();
		}
		return array_merge( $definitions, $stored );
	} );
	// Telnyx SMS and WhatsApp are offered to the existing router only when their sender, API key and
	// webhook verification key are configured. The binding itself performs no network request.
	add_filter( 'dzn_notification_transport_bindings', array( 'Delnavazan\\Platform\\Integrations\\Notifications\\NotificationProviderBindings', 'filter' ) );
	Delnavazan\Platform\Admin\Controller\OperationalCashbookController::register();
	Delnavazan\Platform\Admin\Controller\IntegrationControlPlaneController::register();
	Delnavazan\Platform\Integrations\WordPressMailSettings::register();
	// Delnavazan's operational overview lives in the capability-filtered Academy
	// console. Do not duplicate a stale snapshot on WordPress's generic dashboard.
	Delnavazan\Platform\Admin\Controller\AdminShellController::register();
	Delnavazan\Platform\Admin\RecordAction\RecordActionController::register();
	Delnavazan\Platform\Admin\Controller\StaffAccessController::register();
	Delnavazan\Platform\Admin\Controller\PaymentExecutionController::register();
	Delnavazan\Platform\Admin\Controller\FinanceAdminController::register();
	Delnavazan\Platform\Admin\Controller\CommercialCatalogueController::register();
	Delnavazan\Platform\Admin\Controller\AcademyCoverageController::register();
	Delnavazan\Platform\Admin\Controller\FinancePolicyController::register();
	Delnavazan\Platform\Admin\Controller\FinanceRateController::register();
	Delnavazan\Platform\Admin\Controller\FinancePayabilityController::register();
	Delnavazan\Platform\Admin\Controller\FinanceStatementController::register();
	Delnavazan\Platform\Admin\Controller\FinanceReconciliationController::register();
	Delnavazan\Platform\Admin\Controller\CoreDatasetReadinessController::register();
	Delnavazan\Platform\Admin\Controller\PortalCapabilityController::register();
	Delnavazan\Platform\Admin\Controller\RescheduleReviewController::register();
	Delnavazan\Platform\Portals\StudentProfileController::register();
	Delnavazan\Platform\Portals\RecordingDecisionController::register();
	Delnavazan\Platform\Portals\TeacherOnboardingController::register();
	Delnavazan\Platform\Portals\TeacherAvailabilitySelfServiceController::register();
	Delnavazan\Platform\Portals\TeacherInvitationController::register();
	Delnavazan\Platform\Portals\StudentAccountClaimController::register();
	Delnavazan\Platform\Core\Application\TeacherInvitationDeliveryWorker::register();
	Delnavazan\Platform\Integrations\Notifications\NotificationWorkerBootstrap::register();
	Delnavazan\Platform\Portals\TeacherDeliveryClaimController::register();
	Delnavazan\Platform\Portals\StudentAbsenceController::register();
	Delnavazan\Platform\Portals\PortalCalendarExportController::register();
	Delnavazan\Platform\Integrations\GoogleOAuthController::register();
	Delnavazan\Platform\Portals\TeacherBookingResponseController::register();
	Delnavazan\Platform\Portals\LessonRescheduleController::register();
	Delnavazan\Platform\Integrations\LessonRescheduleWorker::register();
	Delnavazan\Platform\Integrations\BookingCalendarProjectionWorker::register();
	Delnavazan\Platform\Integrations\BookingStudentNotificationWorker::register();
	Delnavazan\Platform\Integrations\TeacherClassJoinController::register();
	Delnavazan\Platform\Integrations\StudentClassJoinController::register();
	Delnavazan\Platform\Integrations\BookingClassJoinController::register();
	Delnavazan\Platform\Integrations\LiveKit\LiveKitLaunchController::register();
	Delnavazan\Platform\Integrations\LiveKit\LiveKitMeetFallbackController::register();
	Delnavazan\Platform\Integrations\LiveKit\LiveKitRoomRevocationListener::register();
	Delnavazan\Platform\Integrations\GoogleCalendarProjectionWorker::register();
	Delnavazan\Platform\Integrations\LessonScheduleCalendarResync::register();
	Delnavazan\Platform\Integrations\PostIntroSettlementHook::register();
	Delnavazan\Platform\Integrations\PostIntroActivationWorker::register();
	Delnavazan\Platform\Integrations\LessonCompletionAutomationWorker::register();
	// A superseded schedule version is a historical occurrence: close any unresolved Phase-P case
	// bound to it while the schedule transaction is still open, so operator review never presents a
	// stale absence claim as current attendance truth.
	add_action( 'dzn_phase_2a2n_after_version_supersede', static function ( string $operation, int $lessonId ): void {
		if ( $lessonId < 1 || ! in_array( $operation, array( 'schedule_revise', 'schedule_release' ), true ) ) { return; }
		try {
			( new Delnavazan\Platform\Core\Application\CanonicalAttendanceIntakeService() )->supersedeForScheduleChange( $lessonId );
		} catch ( \Throwable $error ) {
			// The schedule change is canonical and must commit. A stale case remains historical and
			// is excluded from the current review queue by the schedule-version join.
		}
	}, 10, 2 );
	Delnavazan\Platform\Portals\PostIntroContinuationController::register();
	Delnavazan\Platform\Portals\PostIntroTeacherOutcomeController::register();
	Delnavazan\Platform\Admin\Controller\PostIntroAdminController::register();
	if ( defined( 'WP_CLI' ) && WP_CLI ) { Delnavazan\Platform\Admin\Cli\PostIntroRecoveryCommand::register(); }
	Delnavazan\Platform\Portals\PortalOwnerPorts::configureCapability(new Delnavazan\Platform\Core\Application\CanonicalLessonPortalCapabilityOwner());
	require_once DZN_PLATFORM_DIR . 'src/Core/Application/PortalOwnerReadPorts.php';
	Delnavazan\Platform\Portals\PortalOwnerPorts::configureReadPorts(new Delnavazan\Platform\Core\Application\CanonicalTeacherAssignmentPortalReadPort(),new Delnavazan\Platform\Core\Application\CanonicalLessonSchedulePortalReadPortImpl(),new Delnavazan\Platform\Core\Application\CanonicalLessonDeliveryPortalReadPortImpl(),new Delnavazan\Platform\Core\Application\CanonicalAttendancePortalReadPortImpl());
} );
add_action( 'rest_api_init', array( 'Delnavazan\\Platform\\Public\\BookingRequestRestController', 'register' ) );
add_action( 'rest_api_init', array( 'Delnavazan\\Platform\\Public\\BookingOptionsRestController', 'register' ) );
add_action( 'rest_api_init', array( 'Delnavazan\\Platform\\Public\\BookingAvailabilityPreviewRestController', 'register' ) );
add_action( 'rest_api_init', array( 'Delnavazan\\Platform\\Public\\BookingDayAvailabilityRestController', 'register' ) );
add_action( 'rest_api_init', array( 'Delnavazan\\Platform\\Integrations\\Payment\\Stripe\\StripeWebhookController', 'register' ) );
add_action( 'rest_api_init', array( 'Delnavazan\\Platform\\Integrations\\Notifications\\TelnyxWebhookController', 'register' ) );
add_action( 'rest_api_init', array( 'Delnavazan\\Platform\\Portals\\PortalPublicActionController', 'register' ) );
add_action( 'rest_api_init', array( 'Delnavazan\\Platform\\Integrations\\LiveKit\\LiveKitTokenRestController', 'register' ) );
add_action( 'rest_api_init', array( 'Delnavazan\\Platform\\Integrations\\LiveKit\\LiveKitWebhookController', 'register' ) );

add_action( 'dzn_teacher_activated', static function ( int $teacherId ): void {
	try {
		( new Delnavazan\Platform\Core\Application\Finance\TeacherRateInheritanceService() )->applyForTeacher( $teacherId );
	} catch ( \Throwable $error ) {
		do_action( 'dzn_teacher_rate_inheritance_failed', $teacherId, get_class( $error ) );
	}
}, 10, 1 );
add_action( 'rest_api_init', array( 'Delnavazan\\Platform\\Portals\\StudentCheckoutController', 'register' ) );
add_action( 'rest_api_init', array( 'Delnavazan\\Platform\\Public\\CommercialPromotionRestController', 'register' ) );
