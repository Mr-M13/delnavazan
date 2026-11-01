<?php
/** Source contract for the percentages-only shared tuition-allocation component. */
$root = dirname(__DIR__);
$theme = dirname($root) . '/theme';
$component = (string) file_get_contents($theme . '/template-parts/components/tuition-allocation.php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(!str_contains($component, 'مبلغ'), 'The shared component must not expose a monetary (مبلغ) column or term amount.');
$assert(!str_contains($component, 'format_money') && !str_contains($component, 'PricingMoney'), 'The shared component must not format money itself.');
$assert(!str_contains($component, 'amount_minor') && !str_contains($component, 'paid_minor'), 'The shared component must ignore term amounts in the model.');
$assert(!str_contains($component, 'term_reference') && !str_contains($component, 'term_id') && !str_contains($component, 'term-'), 'The shared component must not render a term selector or term cards.');
$assert(!str_contains($component, 'name="term"') && !str_contains($component, '<select'), 'No term selector may remain in the shared component.');
$assert(str_contains($component, '<details') && str_contains($component, '<summary>'), 'The percentage table must be compact and expandable.');
$assert(str_contains($component, 'TuitionAllocationEstimator::categories()'), 'Both portals must read the identical central category list.');
$assert(str_contains($component, '100.0%'), 'The percentages must explicitly total 100%.');
$estimator = (string) file_get_contents($root . '/src/Core/Application/Finance/TuitionAllocationEstimator.php');
$assert(str_contains($estimator, 'مالیات کالا و خدمات (GST') && str_contains($estimator, 'پرداختی به اساتید'), 'The shared Persian categories must come from the central estimator.');
$assert(str_contains($component, 'برآوردی') && str_contains($component, 'نه گزارش هزینهٔ حسابرسی‌شده'), 'The component must carry a clear estimate disclaimer.');

echo "tuition-allocation-transparency-contract: OK\n";
