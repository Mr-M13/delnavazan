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

echo "Enrolment desk contract passed\n";
