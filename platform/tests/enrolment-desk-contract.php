<?php
/**
 * Source contract for the Enrolment Desk, slice 1.
 *
 * The Desk is deliberately read-only: it projects the existing booking/term records and links
 * to the one authority that changes each step. This contract pins that shape so a later slice
 * cannot quietly grow a second implementation of a command, a write, or a new status store.
 */
$root = dirname(__DIR__);
$repo = dirname($root);
$read = static function (string $path) use ($root, $repo): string {
    $file = $root . '/' . ltrim($path, '/');
    if (!is_file($file)) $file = $repo . '/' . ltrim($path, '/');
    if (!is_file($file)) throw new RuntimeException('Missing source file: ' . $path);
    return (string) file_get_contents($file);
};
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$contains = static function (string $haystack, array $needles, string $message) use ($assert): void {
    foreach ($needles as $needle) $assert(str_contains($haystack, $needle), $message . ': ' . $needle);
};
$notContains = static function (string $haystack, array $needles, string $message) use ($assert): void {
    foreach ($needles as $needle) $assert(!str_contains($haystack, $needle), $message . ': ' . $needle);
};

$controller = $read('src/Admin/Controller/EnrolmentDeskController.php');
$view = $read('src/Admin/View/EnrolmentDeskView.php');
$service = $read('src/Core/Application/EnrolmentDeskReadService.php');
$menu = $read('src/Admin/Controller/Menu.php');
$migrator = $read('src/Core/Infrastructure/Migration/Migrator.php');
$access = $read('src/Core/Application/StaffAccessService.php');

// 1. One surface, one capability, read-only.
$contains($controller, array(
    "public const PAGE = 'dzn-enrolment-desk';",
    "public const CAP  = 'dzn_use_enrolment_desk';",
    'current_user_can( self::CAP )',
    'wp_die(',
), 'The Desk must be a gated admin screen with its own capability');
$contains($controller, array(
    "public const ACTION = 'dzn_enrolment_desk';",
    "add_action( 'admin_post_' . self::ACTION",
    'check_admin_referer( self::ACTION )',
), 'The Desk must register exactly one nonce-checked command endpoint');
$notContains($service, array('$wpdb->insert', '$wpdb->update', '$wpdb->delete', '$wpdb->query'), 'The Desk read model must never write');
$contains($service, array(
    "'anchor' => '#dzn-record-outcome'",
    "'anchor' => '#dzn-execute-agreement'",
), 'The next action must deep-link to the exact existing control');
$contains($read('src/Admin/View/EnrolmentWorkspaceView.php'), array(
    'id="dzn-record-outcome"',
    'id="dzn-intro-complete"',
    'id="dzn-execute-agreement"',
), 'The existing panels must carry the ids the Desk links to');
$contains($service, array(
    "'anchor' => '&dzn_open=intro#dzn-create-intro'",
), 'Step 2 must open the existing Create intro dialog in one click');
$contains($read('src/Admin/View/EnrolmentWorkspaceView.php'), array(
    "=== 'intro'",
    '$openFromUrl',
), 'The workspace must open the Create intro dialog from a URL parameter');
$contains($view, array(
    "value=\"send_payment_link\"",
    "value=\"record_offline_payment\"",
    'admin-post.php',
), 'The Desk must post only its two supported commands');

// 2. Plain-language status, derived, with the payment gate stated.
$contains($service, array(
    "'label' => 'New request'",
    "'label' => 'Waiting for payment'",
    "'label' => 'Active term'",
    "'label' => 'Outcome due'",
    'self::status( (string) ( $case[\'stage_key\']',
), 'Status must be a derived plain-language vocabulary');
$contains($service, array(
    'No class exists before the payment is verified.',
), 'The Desk must state the payment gate on the Payment step');
$contains($view, array(
    'One person, one status, one next action.',
), 'The Desk list must state its own contract');

// 3. Five steps, and every action points at the existing screen.
$contains($service, array(
    "'title'  => 'Contact & identity'",
    "'title'  => 'Teacher & schedule'",
    "'title'  => 'Price & plan'",
    "'title'  => 'Payment'",
    "'title'  => 'Term'",
), 'The detail must be five steps');
$contains($service, array(
    "admin_url( 'admin.php?page=dzn-enrolment-workspace&request_id='",
), 'Each next action must link to the existing case screen, not reimplement it');

// 4. Exceptions are single cards, not blocker counts.
$contains($service, array(
    '$this->alerts( $case )',
    'A queued message is not delivery.',
), 'Exceptions must be single actionable cards');

// 5. Capability installation and delegation.
$contains($menu, array(
    "['Enrolment desk', EnrolmentDeskController::CAP, EnrolmentDeskController::PAGE",
), 'The menu must expose the Desk under its capability');
$contains($migrator, array(
    "\$role->add_cap('dzn_use_enrolment_desk')",
    "\$deskRole->remove_cap('dzn_use_enrolment_desk')",
), 'The capability must be granted to administrators and refused to teacher/student roles');
$contains($access, array(
    "'dzn_use_enrolment_desk'",
), 'The delegated enrolment bundle must be able to carry the Desk capability');

// 6. Slice 2 commands: existing authorities, server-resolved identifiers, no direct writes.
$contains($controller, array(
    "public const ACTION = 'dzn_enrolment_desk';",
    "add_action( 'admin_post_' . self::ACTION",
    'check_admin_referer( self::ACTION )',
    "self::requireCap( 'dzn_manage_post_intro_automation' )",
    "self::requireCap( 'dzn_manage_payment_execution' )",
    '->continueAsAdministrator(',
    '->markStandardPaid(',
    '$read->paymentFacts( $requestId )',
), 'The Desk commands must be one nonce-checked endpoint using the existing authorities');
$notContains($controller, array(
    "\$_POST['offer_uid']",
    "\$_POST['preparation_uid']",
    "\$_POST['obligation_sequence']",
), 'Identifiers must never come from the request body');
$notContains($service, array('$wpdb->insert', '$wpdb->update', '$wpdb->delete'), 'The Desk read model must never write');
$contains($service, array(
    'function confirmedIntros(',
    "c.state IN ('confirmed','awaiting_payment')",
    'new BookingJourneyReadService()',
), 'The Desk list must include confirmed and awaiting-payment introductions, with the journey status as the authority');
$contains($service, array(
    'function paymentFacts(',
    'commercial_offer_obligations',
    'commercial_obligation_settlements',
), 'Step 4 must read the real preparation, offer and obligation records');
$contains($view, array(
    "value=\"send_payment_link\"",
    "value=\"record_offline_payment\"",
), 'The Desk must post only its two supported commands');

// 7. Owner decision: coordination evidence is a timeline on the person page.
$contains($view, array(
    "(array) ( \$case['timeline'] ?? array() )",
    'Evidence',
), 'Coordination evidence must render as a timeline, not a case screen or drawer');

echo "Enrolment desk contract passed\n";
