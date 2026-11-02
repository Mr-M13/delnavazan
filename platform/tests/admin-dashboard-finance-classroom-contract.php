<?php
/**
 * Source contract for the Overview Finance Snapshot and administrator classroom controls.
 *
 * These are presentation and boundary guarantees: the dashboard reuses canonical finance and
 * classroom authorities, keeps manual bookkeeping separate from Stripe, and never weakens the
 * participant joining window.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($expect): void {
    foreach ($needles as $needle) $expect(str_contains($source, $needle), $message . ': ' . $needle);
};

$dashboard = $read('src/Admin/Controller/AcademyOperationsController.php');
$dashboardRead = $read('src/Core/Application/AcademyDashboardReadService.php');
$financeRead = $read('src/Core/Application/Finance/Read/FinanceAdminReadService.php');
$policy = $read('src/Integrations/LiveKit/LiveKitClassroomGrantPolicy.php');
$adminService = $read('src/Integrations/LiveKit/LiveKitAdministratorClassroomService.php');
$adminController = $read('src/Integrations/LiveKit/LiveKitAdministratorClassroomController.php');
$launch = $read('src/Integrations/LiveKit/LiveKitLaunchController.php');
$ticket = $read('src/Integrations/LiveKit/LiveKitLaunchTicket.php');
$fallback = $read('src/Integrations/LiveKit/LiveKitMeetFallbackController.php');
$css = $read('src/Admin/Assets/admin.css');
$js = $read('src/Admin/Assets/admin.js');

// Finance: two sources, explicitly separated.
$contains($dashboard, array(
    'Manual bookkeeping',
    'Iran Balance',
    'Australia Balance',
    'Monthly payment activity',
    'Students Paid',
    'Student Receipts',
    'Teacher Payments',
    'OperationalCashRule::format',
    'Join Class',
    'Copy student link',
    'Copy teacher link',
    'Open Google Meet',
    'Copy Meet link',
    'overviewCategories',
    "'Today'",
    "'Classes'",
    "'Learners'",
    "'Activity'",
    "'Finance'",
    "'Journeys'",
    "'Time changes'",
), 'The Overview must expose both finance sources, seven overview categories and the full classroom action set.');
$contains($dashboardRead, array(
    'OperationalCashReadService',
    'monthlyActivity',
    'teacherPaymentsThisMonth',
    'cashbook_available',
    'manual_accounts',
    'monthly_available',
), 'The dashboard read service must project the manual ledger and monthly Stripe activity independently.');
$contains($financeRead, array(
    'public function monthlyActivity',
    'public function teacherPaymentsThisMonth',
    "provider_key='stripe'",
    "evidence_kind='success'",
    "event_kind='payment_succeeded'",
    "event_type='payment_recorded'",
    'paid_students',
), 'Monthly finance must use canonical Stripe evidence and recorded statement settlements.');
$monthlyMethod = substr($financeRead, strpos($financeRead, 'public function monthlyActivity'));
$monthlyMethod = substr($monthlyMethod, 0, strpos($monthlyMethod, 'public function payments'));
$expect(strpos($monthlyMethod, 'checkout_sessions') === false, 'Monthly finance must not count checkout attempts.');
$expect(strpos($monthlyMethod, 'finance_cash_') === false, 'Monthly Stripe finance must not read the manual cashbook.');
$expect(!str_contains($dashboard, 'net_financial_position'), 'The dashboard must not merge manual and Stripe sources into one net figure.');

// Classroom: administrator actions are separate from shareable participant links.
$contains($dashboard, array(
    'dzn_admin_livekit_class',
    'dzn_admin_classroom_meet',
    'data-dzn-copy',
    'data-dzn-meet-copy',
    'LiveKit: ',
    'Meet: ',
), 'Today’s classes must expose administrator LiveKit, participant-copy and Meet controls.');
$contains($adminService, array(
    'public const CAPABILITY = \'dzn_manage_lessons\'',
    'admin_classroom.livekit_access',
    'admin_classroom.meet_link_accessed',
    'participantEntryUrl',
    'forLesson',
    'forBooking',
), 'The administrator classroom service must authorise, audit and expose participant portal links.');
$contains($adminController, array(
    'dzn_admin_classroom_meet',
    '/admin/classroom/meet-link',
    "wp_verify_nonce(\$nonce, 'wp_rest')",
    'Referrer-Policy: no-referrer',
), 'Meet open and copy must be authenticated, nonce-protected and referrer-safe.');
$contains($launch, array(
    'dzn_admin_livekit_class',
    'launchAdministrator',
    'administrator',
), 'Administrator LiveKit launch must use its own action and ticket role.');
$contains($ticket, array("array('teacher', 'student', 'administrator')"), 'The launch ticket must support the administrator role without changing participant roles.');
$contains($fallback, array('LiveKitAdministratorClassroomService'), 'The classroom Meet fallback must support the administrator ticket.');

// Participant restrictions remain exactly as before.
$contains($policy, array(
    'public const EARLY_SECONDS = 3600',
    'public const LATE_SECONDS = 7200',
    "if (\$now < \$start - self::EARLY_SECONDS || \$now > \$end + self::LATE_SECONDS)",
    'authorizeAdministrator',
), 'Participant window enforcement must remain unchanged beside the administrator grant.');
$adminMethod = substr($policy, strpos($policy, 'public function authorizeAdministrator'));
$expect(!str_contains($adminMethod, 'EARLY_SECONDS') && !str_contains($adminMethod, 'LATE_SECONDS'), 'Administrator access must not silently reuse the participant window.');

// No privileged material is rendered by the dashboard.
$expect(!str_contains($dashboard, 'api_secret') && !str_contains($dashboard, 'apiSecret'), 'The dashboard must not expose provider credentials.');
$expect(!str_contains($dashboard, 'livekit_token') && !str_contains($dashboard, 'AccessToken'), 'The dashboard must not render a permanent LiveKit token.');
$contains($css, array('.dzn-finance-sources', '.dzn-finance-source--manual', '.dzn-finance-source--payments', '.dzn-class-actions'), 'The Overview needs a distinct two-source finance layout and compact classroom actions.');
$contains($js, array('data-dzn-copy', 'data-dzn-meet-copy', 'X-WP-Nonce', 'navigator.clipboard'), 'Copy controls must be real and nonce-protected.');

echo "Admin dashboard finance and classroom contract passed\n";
