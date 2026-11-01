<?php
/** Pure unit proof of the future-Term presentation helper. */
$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/../theme/inc/platform-bridge.php');
$start = strpos($source, 'function dzn_theme_platform_student_payments_with_future_term');
$end = strpos($source, '/** Read-only illustrative tuition allocations', $start);
if ($start === false || $end === false) throw new RuntimeException('future-term helper missing');
$function = substr($source, $start, $end - $start);
if (!function_exists('dzn_theme_platform_persian_digits')) {
    function dzn_theme_platform_persian_digits($value): string {
        return strtr((string) $value, array('0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹'));
    }
}
eval($function);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$base = array(
    'state'=>'ok','checkout_enabled'=>true,
    'obligations'=>array(
        array('term_sequence'=>1,'obligation_sequence'=>1,'payment_state'=>'paid','action'=>null),
        array('term_sequence'=>2,'obligation_sequence'=>1,'payment_state'=>'paid','action'=>null),
        array('term_sequence'=>3,'obligation_sequence'=>1,'payment_state'=>'required','action'=>'pay'),
    ),
);
$out = dzn_theme_platform_student_payments_with_future_term($base, 2);
$assert($out['future_term_available'] === true, 'A future-term unpaid obligation must enable advance payment.');
$assert($out['future_term_obligations'][0]['term_sequence'] === 3, 'Only the obligation beyond the current term may be selected.');
$assert($out['future_term_action_url'] === '#dzn-payments-title', 'The action must target the existing checkout surface.');
$assert(str_contains($out['future_term_label'], '۳'), 'The Persian label must name the future term.');
$paid = $base;
$paid['obligations'][2]['payment_state'] = 'paid';
$out = dzn_theme_platform_student_payments_with_future_term($paid, 2);
$assert($out['future_term_available'] === false && $out['future_term_blocker'] === 'no_future_term_obligation', 'A paid future obligation must not be offered again.');
$disabled = $base;
$disabled['checkout_enabled'] = false;
$out = dzn_theme_platform_student_payments_with_future_term($disabled, 2);
$assert($out['future_term_available'] === false && $out['future_term_blocker'] === 'checkout_unavailable', 'Disabled checkout must never present a payment action.');
$unknown = dzn_theme_platform_student_payments_with_future_term($base, 0);
$assert($unknown['future_term_available'] === false && $unknown['future_term_blocker'] === 'current_term_unknown', 'An unknown current term must not enable advance payment.');
echo "student-future-term-payment-unit: OK\n";
