<?php
/**
 * Settings consolidation contract.
 *
 * Locks the six-section navigation, removal of duplicated General/Recording/Account surfaces,
 * the canonical Policy + Consent Management interface, granular delegated access and the
 * centralised diagnostic registry.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$fail = static function (bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); };

$shell = $read('src/Admin/Controller/AdminShellController.php');
$menu = $read('src/Admin/Controller/Menu.php');
$general = $read('src/Admin/Controller/GeneralSettingsController.php');
$policies = $read('src/Admin/Controller/PoliciesController.php');
$access = $read('src/Admin/Controller/DelegatedAccessController.php');
$roleService = $read('src/Core/Application/AccessRoleService.php');
$registry = $read('src/Core/Application/PermissionRegistry.php');
$diagnostics = $read('src/Admin/Controller/DiagnosticsController.php');
$diagnosticRegistry = $read('src/Core/Application/Diagnostics/DiagnosticRegistry.php');
$integrations = $read('src/Admin/Controller/IntegrationControlPlaneController.php');
$plugin = $read('../platform/delnavazan-platform.php');

/* Settings has exactly six visible sections. */
foreach (array(
    "'general' => ['General'",
    "'access' => ['Administration & Delegated Access'",
    "'policy' => ['Policy'",
    "'notifications' => ['Notifications'",
    "'integrations' => ['Integrations'",
    "'diagnostics' => ['Diagnostics'",
) as $needle) $fail(str_contains($shell, $needle), 'Missing Settings section: ' . $needle);
foreach (array("'recordings' => ['Recordings'", "'policies' => ['Policies'", "'messaging' =>", "'system' =>") as $removed) {
    $fail(!str_contains($shell, $removed), 'Obsolete Settings navigation remains: ' . $removed);
}

/* General owns identity and contact only. */
foreach (array('Site information', 'Contact and public presence', 'admin@delnavazan.com', '0413 413 004', 'https://www.instagram.com/insta.delnavazan/', 'Notification sender', 'DZN_PLATFORM_BUILD_ID') as $needle) {
    $fail(str_contains($general, $needle), 'General is missing: ' . $needle);
}
foreach (array('renderPages', 'get_pages', 'renderLegalPages', 'LegalPageProvisioner', 'renderRecordingCapture', 'ACTION_RECORDING_CAPTURE', 'dzn-account-removal', 'Courses &amp; instruments', 'StaffAccessController::renderPanel', 'RecordingFeatureFlags', 'RecordingControlPlane') as $removed) {
    $fail(!str_contains($general, $removed), 'General still owns obsolete or duplicated functionality: ' . $removed);
}
$fail(!str_contains($general, 'admin@DelLavazon.com') || str_contains($general, 'were corrected'), 'The misspelt contact domain must be visibly corrected, not used');
$fail(!str_contains($general, '0413 4113 004') || str_contains($general, 'were corrected'), 'The unverified phone digits must be visibly corrected, not used');

/* Policy is card-based and has one consent management surface plus legal pages. */
foreach (array('policyCards', 'dzn-policy-card-grid', 'dzn-policy-card--', "'operational'=>", "'financial'=>", "'attendance'=>", "'cancellation'=>", "'consent'=>", 'Consent Management', 'legal-policy', 'LegalPageProvisioner', 'legalPages', 'consentManagement') as $needle) {
    $fail(str_contains($policies, $needle), 'Policy is missing: ' . $needle);
}
foreach (array('Operational policies and rules', 'dzn-policy-search', 'dzn-policy-section-') as $removed) {
    $fail(!str_contains($policies, $removed), 'Policy still contains the obsolete jump navigation: ' . $removed);
}
foreach (array('BookingRequestValidationService', 'INTRO_BOOKING_HORIZON', 'horizonConfiguration', 'dzn_manage_commercial_policies', 'check_admin_referer') as $needle) {
    $fail(str_contains($policies, $needle), 'Policy versioned authority missing: ' . $needle);
}
$fail(str_contains($policies, "'reason_code'=>'settings_policy_update'"), 'Policy saves must supply the controlled reason code the commercial authority requires');
$fail(!(str_contains($policies, 'recording_decisions') && str_contains($policies, 'INSERT INTO')), 'Policy must never create or edit a consent decision');

/* Granular access: module → area → action, roles, overrides, effective preview. */
foreach (array("'classes' => self::module", "'attendance' => self::area", "'reschedule' => self::action", "'confirm' => self::action", "'retry_meet' => self::action", 'managedCapabilities') as $needle) {
    $fail(str_contains($registry, $needle), 'Granular permission registry is missing: ' . $needle);
}
foreach (array('defaultRoles', 'saveRole', 'duplicateRole', 'setRoleActive', 'saveAssignment', 'effective', 'deny', 'allow', 'primaryAdministratorId', 'staff_access_self_grant_forbidden', 'delegated_access_primary_administrator_protected', 'syncCapabilities') as $needle) {
    $fail(str_contains($roleService . $access, $needle), 'Delegated access is missing: ' . $needle);
}
foreach (array('Search permissions', 'Select all in this group', 'data-sensitive', 'Unsaved changes', 'Role comparison', 'Effective permissions preview', 'confirm(', 'permissions[]', 'roles[]', 'allow[]', 'deny[]') as $needle) {
    $fail(str_contains($access, $needle), 'Access interface is missing: ' . $needle);
}
$fail(str_contains($access, 'wp_ajax_') === false, 'Access interface must not add an unauthenticated AJAX path');

/* Diagnostics is registry-driven, lazy and redacted. */
foreach (array('DiagnosticRegistry', 'summary(', 'category(', 'dzn-diagnostics-accordion', 'data-dzn-diagnostic-category', 'admin_post_', 'supportSummary', 'Refresh checks', 'Failed', 'Unknown', 'not_configured', 'Notification delivery register', 'notification-register') as $needle) {
    $fail(str_contains($diagnostics, $needle), 'Diagnostics is missing: ' . $needle);
}
foreach (array('Platform & System Health', 'Booking & Coordination', 'Classes & Attendance', 'Google Workspace & Meet', 'Payments & Finance', 'Notifications & Delivery', 'Teacher & Student Portals', 'Consent & Policies', 'Background Jobs & Queues', 'Database & Migrations', 'Security & Authorisation', 'External Integrations') as $category) {
    $fail(str_contains($diagnosticRegistry, $category), 'Diagnostic category missing: ' . $category);
}
foreach (array('checkBookingDuplicates', 'checkAttendanceEvidence', 'checkGoogleOAuth', 'checkWebhookReceipts', 'checkNotificationFailures', 'checkConsentJobs', 'checkOutbox', 'checkMigrations', 'checkPermissionCatalogue', 'checkRoleIntegrity', 'diagnostic_query_failed', 'state\' => \'unknown\'') as $needle) {
    $fail(str_contains($diagnosticRegistry, $needle), 'Diagnostic check or failure isolation missing: ' . $needle);
}
$fail(!str_contains($diagnostics, 'shell_exec') && !str_contains($diagnostics, 'eval('), 'Diagnostics must not execute commands');
$fail(!str_contains($diagnosticRegistry, 'SELECT * FROM') && !str_contains($diagnosticRegistry, 'message_body'), 'Diagnostic checks must remain aggregate and redacted');

/* Recording capture UI retired, shared Google Meet/consent infrastructure preserved. */
foreach (array('dzn-recordings', 'Recording capture') as $removed) {
    $fail(!str_contains($menu . $general, $removed), 'Retired recording surface remains: ' . $removed);
}
foreach (array('GoogleWorkspaceOAuthController::register()', 'GoogleWorkspaceWorker::register()', 'MeetRecordingPlaybackController::register()', 'RecordingInvalidationListener::register()', 'ConsentLifecycleWorker::register()') as $preserved) {
    $fail(str_contains($plugin, $preserved), 'Shared Google Meet/recording/consent infrastructure was removed: ' . $preserved);
}
$fail(str_contains($plugin, 'RecordingOperationsController::register()') === false, 'Retired recording operations route must not be registered');
$fail(file_exists($root . '/src/Admin/Controller/AccountRemovalController.php') === false, 'Account-removal controller still exists');
$fail(str_contains($plugin, 'AccountRemovalController::register()') === false, 'Account-removal route still registered');

/* Integrations: one Google Workspace & Meet interface. */
foreach (array('renderGoogleCard', 'Google Workspace &amp; Meet', 'Manage Google Workspace', 'OAuth', 'Calendar', 'Meet', 'recording') as $needle) {
    $fail(str_contains($integrations, $needle), 'Consolidated Google integration is missing: ' . $needle);
}
$fail(!str_contains($integrations, 'renderClassroomCard()'), 'A second Google Meet card remains');
$fail(str_contains($menu, "'Google Workspace & Meet'"), 'Google Workspace & Meet route label missing');

/* Registration. */
foreach (array('DelegatedAccessController::register()', 'GeneralSettingsController::register()', 'DiagnosticsController::register()', 'PoliciesController::register()') as $registered) {
    $fail(str_contains($plugin, $registered), 'Required controller is not registered: ' . $registered);
}
$fail(str_contains($plugin, 'AccountRemovalController') === false, 'Removed account controller still referenced');
$fail(str_contains($menu, "['Policy', 'dzn_manage_consent_policies'") || str_contains($menu, "'dzn-policies'"), 'Policy route no longer registered');

echo "Settings redesign contract passed\n";
