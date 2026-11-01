<?php
/** Pure unit coverage for the brand-aligned, email-safe teacher statement. */
$root = dirname(__DIR__);
require_once $root . '/src/Core/Support/BrandIdentity.php';
require_once $root . '/src/Core/Support/CommunicationDesignTokens.php';
require_once $root . '/src/Integrations/Notifications/NotificationCatalogue.php';
require_once $root . '/src/Integrations/Notifications/DelnavazanEmailRenderer.php';
require_once $root . '/src/Core/Application/Finance/Pricing/PricingMoney.php';
require_once $root . '/src/Core/Application/Finance/Statements/TeacherStatementEmailRenderer.php';

use Delnavazan\Platform\Core\Application\Finance\Statements\TeacherStatementEmailRenderer;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$renderer = new TeacherStatementEmailRenderer();
$statement = array(
    'statement_id' => 42, 'statement_version' => 1, 'state' => 'issued',
    'period_start_utc' => '2026-10-01 00:00:00', 'period_end_utc' => '2026-11-01 00:00:00',
    'period_label' => 'اکتبر ۲۰۲۶', 'currency' => 'TOM',
    'payable_amount_minor' => 4500000, 'payable_line_count' => 1, 'total_line_count' => 2,
    'issued_at' => '2026-11-01 09:00:00',
);
$lines = array(
    array('occurrence_starts_at_utc' => '2026-10-05 15:00:00', 'course' => 'کلاس تار', 'duration_minutes' => 60, 'base_amount_minor' => 4000000, 'bonus_amount_minor' => 500000, 'line_amount_minor' => 4500000, 'disposition' => 'payable'),
    array('occurrence_starts_at_utc' => '2026-10-12 15:00:00', 'course' => 'کلاس تار', 'duration_minutes' => 60, 'base_amount_minor' => 0, 'bonus_amount_minor' => 0, 'line_amount_minor' => 0, 'disposition' => 'non_payable'),
);
$teacher = array('name' => 'استاد نمونه', 'reference' => 'DZN-TCH-0001', 'locale' => 'fa');
$fa = $renderer->render($statement, $lines, $teacher, array('academy_name' => 'دلنوازان'));
$assert(str_contains($fa['subject'], 'صورت‌حساب'), 'The Persian subject must be a teacher statement');
$assert(str_contains($fa['html'], 'dir="rtl"'), 'The Persian statement must render RTL');
$assert(str_contains($fa['html'], '#104A4D') && str_contains($fa['html'], '#A33E48') && str_contains($fa['html'], '#FBF8F2'), 'The email must use the Delnavazan palette');
$assert(str_contains($fa['html'], 'استاد نمونه') && str_contains($fa['html'], 'DZN-TCH-0001'), 'The teacher identity must be present');
$assert(str_contains($fa['html'], '۶۰ دقیقه'), 'The lesson duration must be present');
$assert(str_contains($fa['html'], '۴,۵۰۰,۰۰۰ تومان'), 'The recorded total must be formatted in Toman');
$assert(str_contains($fa['html'], 'کلاس تار'), 'The course must be present');
$assert(str_contains($fa['html'], 'غیرقابل پرداخت'), 'The non-payable row must be explained');
$assert(!str_contains($fa['html'], '<script') && !str_contains($fa['html'], '@import') && !str_contains($fa['html'], '{{'), 'The email must be fixed, script-free and template-free');
$assert(!str_contains($fa['html'], 'fonts.googleapis') && !str_contains($fa['html'], 'url('), 'The email must not depend on an external font or remote CSS');
$assert(str_contains($fa['text'], 'صورت‌حساب') && str_contains($fa['text'], '۴,۵۰۰,۰۰۰ تومان'), 'The plain-text alternative must carry the same facts');

$en = $renderer->render($statement, $lines, array('name' => 'Sample Teacher', 'reference' => 'DZN-TCH-0002', 'locale' => 'en'), array('locale' => 'en'));
$assert(str_contains($en['html'], 'dir="ltr"') && str_contains($en['subject'], 'Delnavazan teacher statement'), 'English rendering must be LTR');
$assert(str_contains($en['html'], 'Sample Teacher') && str_contains($en['html'], 'Total payable'), 'English email must use English labels');

echo "teacher-statement-email-unit: OK\n";
