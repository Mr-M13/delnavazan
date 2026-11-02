<?php
/**
 * Settings redesign contract: four visible sections, protected legacy routes, and the
 * responsibility split between event choice, provider configuration, runtime evidence
 * and academy information/access.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$shell = $read('src/Admin/Controller/AdminShellController.php');
$general = $read('src/Admin/Controller/GeneralSettingsController.php');
$staff = $read('src/Admin/Controller/StaffAccessController.php');
$staffService = $read('src/Core/Application/StaffAccessService.php');
$notifications = $read('src/Admin/Controller/NotificationManagementController.php');
$integrations = $read('src/Admin/Controller/IntegrationControlPlaneController.php');
$diagnostics = $read('src/Admin/Controller/DiagnosticsController.php');
$diagnosticsRead = $read('src/Core/Application/DiagnosticsReadService.php');
$bundle = $read('src/Core/Application/SupportBundleService.php');
$menu = $read('src/Admin/Controller/Menu.php');
$plugin = $read('../platform/delnavazan-platform.php');
$fail = static function (bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); };

/* Four sections exactly. */
foreach (array("'general' => ['General'", "'notifications' => ['Notifications'", "'integrations' => ['Integrations'", "'diagnostics' => ['Diagnostics'") as $needle) {
    $fail(str_contains($shell, $needle), 'Missing Settings section: ' . $needle);
}
foreach (array("'messaging' =>", "'access' =>", "'system' =>", 'dzn-admin-shell__subtabs--pages') as $removed) {
    $fail(!str_contains($shell, $removed), 'Legacy Settings navigation shape remains: ' . $removed);
}
foreach (array('dzn-staff-access', 'dzn-portal-capabilities', 'dzn-core-dataset-readiness', 'dzn-integration-whatsapp', 'dzn-integration-email', 'dzn-integration-sms', 'dzn-integration-google', 'dzn-recording-notices') as $legacy) {
    $fail(str_contains($shell, $legacy), 'Legacy route must remain mapped to a section, not deleted: ' . $legacy);
}
$fail(str_contains($general, 'Open system health') === false, 'General still contains the redundant system-health button');
$fail(str_contains($general, 'Open integrations') === false, 'General still contains the redundant integrations button');

/* General responsibilities. */
foreach (array(
    'Site information', 'Contact and public presence', 'Pages and project', 'Legal and policy pages',
    'Introductory booking horizon', 'Who can use each work area', 'admin@delnavazan.com',
    '0413 413 004', 'https://www.instagram.com/insta.delnavazan/', 'Notification sender',
    'CommercialPolicyService', 'LegalPageProvisioner', 'get_pages', 'renderPanel',
) as $needle) {
    $fail(str_contains($general, $needle) || str_contains($staff, $needle), 'General is missing: ' . $needle);
}
$fail(!str_contains($general, 'admin@DelLavazon.com') || str_contains($general, 'were corrected'), 'The supplied misspelt contact domain must be visibly corrected, not used');
$fail(!str_contains($general, '0413 4113 004') || str_contains($general, 'were corrected'), 'The supplied unverified phone digits must be visibly corrected, not used');
$fail(str_contains($general, 'dzn_general_booking_horizon') && str_contains($general, 'check_admin_referer'), 'Booking horizon save must be nonce-protected');
$fail(str_contains($general, 'dzn_manage_commercial_policies'), 'Booking horizon must use the existing commercial-policy authority');
$fail(str_contains($general, 'unpublished drafts') && stripos($general, 'does not publish') !== false, 'Legal work must remain draft-only and explicit');
$booking = $read('src/Core/Application/BookingRequestValidationService.php');
$fail(str_contains($booking, 'bookingHorizonDays') && str_contains($booking, "current('INTRO_BOOKING_HORIZON')"), 'Public booking intake must follow the versioned horizon policy with a safe fallback');

require_once $root . '/src/Integrations/Notifications/NotificationCatalogue.php';
$fail(\Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue::isMandatory('account.claim_issued.student'), 'Security/account notice must remain mandatory');
$fail(\Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue::isMandatory('payment.failed.student'), 'Financial notice must remain mandatory');
$fail(!\Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue::isMandatory('booking.confirmed.student'), 'Operational booking notice must be staff-configurable');

/* Staff access: view/manage, sensitive permissions and server-side controls. */
foreach (array('StaffAccessService', 'areas()', 'sensitiveActions()', 'manage_options', 'staff_access_self_grant_forbidden', 'staff_access_administrator_is_read_only', 'update_user_meta', 'remove_cap', 'dzn_platform_audit_events') as $needle) {
    $fail(str_contains($staff . $staffService, $needle), 'Staff access is missing: ' . $needle);
}
foreach (array('dzn_manage_payment_execution', 'dzn_ingest_commercial_payment_evidence', 'dzn_manage_student_acceptance_authority', 'dzn_manage_provider_integrations', 'dzn_operate_notification_dispatch') as $sensitive) {
    $fail(str_contains($staffService, $sensitive), 'Sensitive permission not explicit: ' . $sensitive);
}
$fail(str_contains($staffService, 'dzn_record_own_availability_assent') === false, 'Portal identity capability must not be managed by staff access');
$fail(str_contains($staffService, 'manage_options') && !preg_match("/'caps'\s*=>\s*array\([^)]*manage_options/", $staffService), 'Administrator capability must not be in the grant catalogue');

/* Notifications is event choice + content, not provider setup or raw diagnostics. */
foreach (array(
    ":'events'", 'Notification events', 'Active channels', 'Template readiness', 'Latest delivery',
    'dzn_notification_event_toggle', 'dzn_notification_email_appearance', 'renderEmailAppearance',
    'Global email appearance', 'manual_retry', 'Send now / Retry',
    'renderChannelHealth', 'SMS is fallback-only', 'NotificationCatalogue::isMandatory',
) as $needle) {
    $fail(str_contains($notifications, $needle), 'Notifications is missing: ' . $needle);
}
$fail(stripos($notifications, 'no “send any template to anyone”') !== false, 'Notifications must not offer an unrestricted send control');
$fail(!str_contains($notifications, 'Configure Telnyx WhatsApp and Meta WABA settings</a>') || str_contains($notifications, 'dzn-integration-whatsapp'), 'Notifications must not duplicate provider setup forms');
$fail(str_contains($notifications, 'renderProviderMapping') && str_contains($notifications, "if(\$channel!=='whatsapp')return;"), 'WhatsApp provider mapping must remain in the WhatsApp template editor');
$fail(str_contains($notifications, 'draft') && str_contains($notifications, 'Publish New Version') && str_contains($notifications, 'Version History'), 'Template draft/publish/version workflow missing');
$fail(str_contains($notifications, 'check_admin_referer') && str_contains($notifications, 'wp_safe_redirect'), 'Notification mutations must be nonce-protected and same-site redirected');

/* Integrations is provider configuration/readiness. */
foreach (array('Provider configuration and readiness', 'Email', 'WhatsApp', 'SMS', 'Google authentication, Calendar and Meet', 'Stripe', 'LiveKit classroom', 'Credentials stored', 'Last explicit verification', 'Frontline WhatsApp contact', 'Twilio WhatsApp is not implemented', 'dzn-finance&tab=settings#stripe', 'Advanced provider controls') as $needle) {
    $fail(str_contains($integrations, $needle), 'Integrations is missing: ' . $needle);
}
$fail(!str_contains($integrations, "'secret_key']") && !str_contains($integrations, 'access_token]'), 'Integrations screen must not render secret values');

/* Diagnostics is runtime evidence with filters, copy/download and redaction. */
foreach (array('Status', 'Recent structured errors', 'component', 'severity', 'Copy support summary', 'Download support bundle', 'dzn_diagnostics_support_bundle', 'Recover expired leases', 'SentinelPlaceholder') as $needle) {
    if ($needle === 'SentinelPlaceholder') continue;
    $fail(str_contains($diagnostics, $needle), 'Diagnostics is missing: ' . $needle);
}
$fail(!str_contains($diagnostics, 'shell_exec') && !str_contains($diagnostics, 'eval('), 'Diagnostics must not run commands or arbitrary code');
$fail(str_contains($diagnosticsRead, 'scheduled-jobs') && str_contains($diagnosticsRead, 'notification-queues') && str_contains($diagnosticsRead, 'classroom') && str_contains($diagnosticsRead, 'exceptionErrors'), 'Diagnostics status table is incomplete');
$fail(str_contains($bundle, 'bundle_version') && str_contains($bundle, 'structured_errors') && str_contains($bundle, 'redacted') && str_contains($bundle, 'audit_references'), 'Support bundle is incomplete');
foreach (array('email addresses', 'phone numbers', 'message bodies', 'financial details', 'secrets', 'tokens', 'raw provider payloads') as $redaction) {
    $fail(stripos($bundle, $redaction) !== false, 'Support bundle redaction list is missing: ' . $redaction);
}

/* Registration and protected routes. */
$fail(str_contains($plugin, 'GeneralSettingsController::register()'), 'General settings handlers are not registered');
$fail(str_contains($plugin, 'DiagnosticsController::register()'), 'Diagnostics handlers are not registered');
foreach (array('dzn-integration-email', 'dzn-integration-whatsapp', 'dzn-integration-sms', 'dzn-integration-google', 'dzn-system-health') as $route) {
    $fail(str_contains($menu, "'" . $route . "'"), 'Protected route no longer registered: ' . $route);
}
$fail(str_contains($staff, "self::MENU_SLUG") && str_contains($staff, "'dzn-staff-access'"), 'Legacy staff-access route is no longer registered');
$fail(str_contains($read('src/Admin/Controller/PortalCapabilityController.php'), "'dzn-portal-capabilities'"), 'Legacy portal-capability route is no longer registered');
$fail(str_contains($read('src/Admin/Controller/CoreDatasetReadinessController.php'), "'dzn-core-dataset-readiness'"), 'Legacy recovery route is no longer registered');

echo "Settings redesign contract passed\n";
