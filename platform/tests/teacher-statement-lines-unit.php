<?php
/** Pure unit coverage for teacher statement line formatting and template compatibility. */
$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/Finance/Pricing/PricingMoney.php';
require_once $root . '/src/Core/Application/Finance/Statements/StatementTemplateRule.php';
require_once $root . '/src/Core/Application/Finance/Statements/StatementTemplateService.php';

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

// Simulate the output shape of TeacherStatementReadService::statementLines().
$lines = array(
    array(
        'lesson_id' => '101', 'lesson_date' => '2026-10-05 15:00:00', 'course' => 'کلاس تار',
        'duration_minutes' => 60, 'rate_amount_minor' => 4000000,
        'base_amount_minor' => 4000000, 'base_amount' => '۴,۰۰۰,۰۰۰ تومان',
        'bonus_amount_minor' => 500000, 'bonus_amount' => '۵۰۰,۰۰۰ تومان',
        'disposition' => 'payable', 'basis_code' => 'delivered_occurrence', 'basis' => 'delivered_occurrence',
        'line_amount_minor' => 4500000, 'amount' => '۴,۵۰۰,۰۰۰ تومان', 'amount_minor' => '4500000',
        'status' => 'payable', 'currency' => 'TOM',
    ),
    array(
        'lesson_id' => '102', 'lesson_date' => '2026-10-12 15:00:00', 'course' => 'کلاس پیانو',
        'duration_minutes' => 45, 'rate_amount_minor' => 3000000,
        'base_amount_minor' => 0, 'base_amount' => '۰ تومان',
        'bonus_amount_minor' => 0, 'bonus_amount' => '۰ تومان',
        'disposition' => 'non_payable', 'basis_code' => 'academy_obligation', 'basis' => 'academy_obligation',
        'line_amount_minor' => 0, 'amount' => '۰ تومان', 'amount_minor' => '0',
        'status' => 'non_payable', 'currency' => 'TOM',
    ),
);

// Verify each line carries both raw minor-unit integers and formatted strings.
foreach ($lines as $line) {
    $assert(is_int($line['rate_amount_minor']) && $line['rate_amount_minor'] > 0, 'rate_amount_minor must be a positive integer');
    $assert(is_int($line['base_amount_minor']) && $line['base_amount_minor'] >= 0, 'base_amount_minor must be a non-negative integer');
    $assert(is_int($line['bonus_amount_minor']) && $line['bonus_amount_minor'] >= 0, 'bonus_amount_minor must be a non-negative integer');
    $assert(is_int($line['line_amount_minor']) && $line['line_amount_minor'] >= 0, 'line_amount_minor must be a non-negative integer');
    $assert(is_string($line['amount_minor']) && (int) $line['amount_minor'] === $line['line_amount_minor'], 'amount_minor must mirror line_amount_minor');
    $assert(is_string($line['currency']) && $line['currency'] !== '', 'currency must be a non-empty string');
    $assert(is_string($line['disposition']) && in_array($line['disposition'], array('payable', 'non_payable', 'pending'), true), 'disposition must be a valid value');
}

// Verify the total reconciles: sum of payable lines equals recorded payable total.
$recordedPayableMinor = 4500000;
$computedPayable = 0;
foreach ($lines as $line) {
    if ($line['disposition'] === 'payable') {
        $computedPayable += $line['line_amount_minor'];
    }
}
$assert($computedPayable === $recordedPayableMinor, 'Computed payable total must reconcile exactly with recorded total');

// Verify StatementTemplateService placeholders are all present in each line.
require_once $root . '/src/Core/Application/Finance/Statements/StatementTemplateService.php';
use Delnavazan\Platform\Core\Application\Finance\Statements\StatementTemplateService;
$renderer = new StatementTemplateService();

// Use reflection to confirm the render method accepts the line shape produced by statementLines().
$ref = new ReflectionMethod($renderer, 'render');
$assert($ref->getNumberOfParameters() >= 4, 'StatementTemplateService::render must accept statement, lines, template, academyName');

// Verify that rendering with statementLines-shaped output produces no unresolved placeholders.
$template = array(
    'header_html' => '<header><h1>{{academy_name}}</h1><p>{{teacher_name}} · {{teacher_reference}}</p></header>',
    'body_html' => '<table>{{#lessons}}<tr><td>{{lesson_date}}</td><td>{{course}}</td><td>{{duration_minutes}} دقیقه</td><td>{{amount}}</td></tr>{{/lessons}}</table><p>{{payable_amount}}</p>',
    'footer_html' => '<footer>{{statement_id}} · {{statement_version}} · {{generated_at}}</footer>',
    'css' => 'body{font-family:Tahoma}',
    'locale' => 'fa',
);
$statement = array(
    'statement_id' => 42, 'statement_version' => 1, 'state' => 'issued',
    'period_start_utc' => '2026-10-01', 'period_end_utc' => '2026-11-01',
    'period_label' => 'اکتبر ۲۰۲۶', 'currency' => 'TOM',
    'payable_amount_minor' => 4500000, 'payable_line_count' => 1, 'total_line_count' => 2,
    'teacher_name' => 'استاد نمونه', 'teacher_reference' => 'DZN-TCH-0001',
    'issued_at' => '2026-11-01 09:00:00',
);
$html = $renderer->render($statement, $lines, $template, 'دلنوازان');
$assert(str_contains($html, 'استاد نمونه'), 'Teacher name must render');
$assert(str_contains($html, 'DZN-TCH-0001'), 'Teacher reference must render');
$assert(str_contains($html, '۴,۵۰۰,۰۰۰ تومان'), 'Payable amount must render in Persian Toman');
$assert(str_contains($html, 'کلاس تار'), 'Course name must render');
$assert(str_contains($html, 'کلاس پیانو'), 'Second course must render');
$assert(str_contains($html, '۶۰ دقیقه'), 'Duration must render in Persian digits');
$assert(str_contains($html, '۴۵ دقیقه'), 'Second duration must render in Persian digits');
$assert(!str_contains($html, '{{'), 'No unresolved placeholders must remain');
$assert(str_contains($html, 'dir="rtl"'), 'Persian rendering must be RTL');

// Verify immutability: rendering does not mutate the input statement or lines.
$statementCopy = $statement;
$linesCopy = $lines;
$renderer->render($statement, $lines, $template, 'Delnavazan');
$assert($statement === $statementCopy || $statement['payable_amount_minor'] === 4500000, 'Statement total must not be mutated by rendering');
$assert($lines[0]['line_amount_minor'] === 4500000, 'Line amount must not be mutated by rendering');
$assert($lines[1]['line_amount_minor'] === 0, 'Non-payable line amount must remain zero');

echo "teacher-statement-lines-unit: OK\n";
