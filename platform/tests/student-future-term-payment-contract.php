<?php
/** Source contract for future-Term advance payment presentation. */
$root = dirname(__DIR__);
$bridge = (string) file_get_contents($root . '/../theme/inc/platform-bridge.php');
$template = (string) file_get_contents($root . '/../theme/template-parts/portal/payments.php');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$start = strpos($bridge, 'function dzn_theme_platform_student_payments_with_future_term');
$end = strpos($bridge, '/** Read-only illustrative tuition allocations', $start);
$helper = $start !== false && $end !== false ? substr($bridge, $start, $end - $start) : '';

$assert($helper !== '', 'The future-Term presentation must be a dedicated read projection.');
$assert(str_contains($helper, '$term_sequence <= $current_term_sequence'), 'Only obligations beyond the current Term may be presented as advance payment.');
$assert(str_contains($helper, 'in_array( $payment_state, array( \'paid\', \'paid_prerequisite_pending\' ), true )'), 'Paid future obligations must not be presented as payable.');
$assert(str_contains($helper, "['future_term_action_url'] = '#dzn-payments-title'"), 'The action must target the existing secure checkout surface, not a new payment route.');
$assert(!preg_match('/INSERT|UPDATE|DELETE|wp_insert|wc_|stripe/i', $helper), 'The future-Term projection must remain read-only.');
$assert(str_contains($template, '$future_term_available'), 'The payments template must render the future-Term availability state.');
$assert(str_contains($template, 'پرداخت پیشاپیش ترم آینده'), 'The future-Term button must be present in Persian.');
$assert(str_contains($template, 'فهرست امن پرداخت'), 'The enabled state must point to the existing secure checkout list.');
$assert(str_contains($template, 'disabled'), 'The unavailable state must be explicitly disabled.');

echo "student-future-term-payment-contract: OK\n";
