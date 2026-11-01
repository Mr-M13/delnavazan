<?php
/** Pure unit coverage for safe statement-template rendering. */
$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/Finance/Pricing/PricingMoney.php';
require_once $root . '/src/Core/Application/Finance/Statements/StatementTemplateRule.php';
require_once $root . '/src/Core/Application/Finance/Statements/StatementTemplateService.php';

use Delnavazan\Platform\Core\Application\Finance\Statements\StatementTemplateRule;
use Delnavazan\Platform\Core\Application\Finance\Statements\StatementTemplateService;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$throws = static function (callable $fn, string $message) use ($assert): void {
    try { $fn(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException($message);
};

$dirty = '<h1>{{academy_name}}</h1><script>alert(1)</script><iframe src="https://evil.test"></iframe><img src="javascript:alert(1)" onerror="alert(2)" srcdoc="<script>alert(3)</script>"><form action="https://evil.test"><input></form><a href="https://delnavazan.com" onclick="alert(4)" formaction="https://evil.test">ok</a>';
$clean = StatementTemplateRule::sanitizeHtml($dirty);
$assert(!str_contains($clean, '<script'), 'Scripts must be removed');
$assert(!str_contains($clean, '<iframe'), 'Frames must be removed');
$assert(!str_contains($clean, 'onerror') && !str_contains($clean, 'onclick'), 'Event handlers must be removed');
$assert(!str_contains($clean, 'srcdoc') && !str_contains($clean, 'formaction') && !str_contains($clean, '<form'), 'Script-bearing attributes and forms must be removed');
$assert(!str_contains($clean, 'javascript:'), 'Javascript URLs must be removed');
$assert(str_contains($clean, 'https://delnavazan.com'), 'Safe https links must survive');

$css = '@import url(https://evil.test/x.css); body{color:red;background:url(https://evil.test/x)} .x{position:fixed;content:"fake"}';
$safeCss = StatementTemplateRule::sanitizeCss($css);
$assert(!str_contains($safeCss, '@import') && !str_contains($safeCss, 'url('), 'Remote CSS loads must be removed');
$assert(!str_contains($safeCss, 'position:fixed') && !str_contains($safeCss, 'content:'), 'Overlay and injected-content CSS must be removed');
$assert(str_contains($safeCss, 'color:red'), 'Safe typography CSS must survive');
$throws(static fn() => StatementTemplateRule::safeUrl('http://example.com/logo.png'), 'Unsafe logo schemes must be refused');
$assert(StatementTemplateRule::safeUrl('https://delnavazan.com/logo.png') === 'https://delnavazan.com/logo.png', 'HTTPS logo must be accepted');

$template = StatementTemplateRule::sanitizeTemplate(array(
    'header_html' => '<header><h1>{{academy_name}}</h1><script>alert(1)</script></header>',
    'body_html' => '<table>{{#lessons}}<tr><td>{{lesson_id}}</td><td>{{amount}}</td></tr>{{/lessons}}</table><p>{{payable_amount}}</p>',
    'footer_html' => '<footer>{{teacher_name}}</footer>',
    'css' => 'body{font-family:Tahoma}',
    'locale' => 'fa',
));
$statement = array(
    'statement_id' => 7, 'statement_version' => 2, 'state' => 'issued',
    'period_start_utc' => '2026-10-01', 'period_end_utc' => '2026-10-31',
    'currency' => 'TOM', 'payable_amount_minor' => 4500000,
    'total_line_count' => 2, 'payable_line_count' => 1,
);
$lines = array(
    array('lesson_id' => 101, 'occurrence_starts_at_utc' => '2026-10-05 15:00:00', 'disposition' => 'payable', 'basis_code' => 'delivered_occurrence', 'line_amount_minor' => 4500000),
    array('lesson_id' => 102, 'occurrence_starts_at_utc' => '2026-10-12 15:00:00', 'disposition' => 'non_payable', 'basis_code' => 'academy_obligation', 'line_amount_minor' => 0),
);
$html = (new StatementTemplateService())->render($statement, $lines, $template, 'Delnavazan <Academy>');
$assert(str_contains($html, 'dir="rtl"') && str_contains($html, 'lang="fa"'), 'Persian template must render RTL');
$assert(!str_contains($html, '<script'), 'Rendered output must remain script-free');
$assert(str_contains($html, 'Delnavazan &lt;Academy&gt;'), 'Substituted values must be HTML-escaped');
$assert(str_contains($html, '101') && str_contains($html, '102'), 'Lesson loop must render every line');
$assert(str_contains($html, '۴,۵۰۰,۰۰۰ تومان'), 'Formatted payable amount must come from the recorded total');
$assert(str_contains($html, '2026-10-01') && str_contains($html, '2026-10-31'), 'Recorded period must be displayed');

$malformed = StatementTemplateRule::sanitizeTemplate(array('body_html' => '<p>{{unknown_placeholder}}</p><p>{{lessons}}</p>', 'locale' => 'en'));
$malformedHtml = (new StatementTemplateService())->render($statement, array(), $malformed, 'Delnavazan');
$assert(!str_contains($malformedHtml, '{{'), 'Unknown or malformed placeholders must be removed rather than executed');
$english = StatementTemplateRule::sanitizeTemplate(array('locale' => 'en'));
$html = (new StatementTemplateService())->render($statement, $lines, $english, 'Delnavazan');
$assert(str_contains($html, 'dir="ltr"') && str_contains($html, 'lang="en"'), 'English template must render LTR');

// Template data cannot change the financial facts supplied by the statement.
$assert(($statement['payable_amount_minor'] + 0) === 4500000, 'Renderer must not mutate the recorded total');
$sample = (new StatementTemplateService())->renderSample($template);
$assert(str_contains($sample, '۴,۵۰۰,۰۰۰ تومان'), 'Sample preview must use illustrative recorded-style totals and render safely');

// The delivered default is the branded Delnavazan statement and shows the pay detail per lesson.
$default = StatementTemplateRule::defaultTemplate();
$assert(str_contains($default['css'], '#104A4D') && str_contains($default['css'], '#A33E48') && str_contains($default['css'], '#FBF8F2'), 'Default statement CSS must use the Delnavazan palette');
$assert(str_contains($default['css'], 'Vazirmatn'), 'Default statement CSS must use the brand font with a fallback');
$assert(isset(StatementTemplateRule::LESSON_PLACEHOLDERS['duration_minutes'], StatementTemplateRule::LESSON_PLACEHOLDERS['base_amount'], StatementTemplateRule::LESSON_PLACEHOLDERS['bonus_amount']), 'Statement placeholders must expose duration, base pay and bonus');
$defaultHtml = (new StatementTemplateService())->renderSample($default);
$assert(str_contains($defaultHtml, 'ریز حقوق جلسات') && str_contains($defaultHtml, '۶۰ دقیقه'), 'Default statement must show the lesson pay detail');
$assert(str_contains($defaultHtml, '۴,۰۰۰,۰۰۰ تومان') && str_contains($defaultHtml, '۵۰۰,۰۰۰ تومان'), 'Default statement must show base pay and golden-hour bonus separately');
$assert(!str_contains($defaultHtml, '{{'), 'Default statement rendering must leave no unresolved placeholder');

echo "finance-statement-template-unit: OK\n";
