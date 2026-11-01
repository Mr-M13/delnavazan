<?php
/**
 * Unified communications brand, typography and contact contract.
 *
 * This is a runtime render guard, not a string-only audit: it renders the real email, statement and
 * receipt renderers, reads the actual theme token block, and fails if the web, email and document
 * surfaces drift apart or expose a non-canonical contact identity.
 */
$root = dirname(__DIR__);
$repo = dirname($root);
$fail = static function (string $name, bool $ok): void {
    if (!$ok) throw new RuntimeException('Communications brand contract failed: ' . $name);
};

require_once $root . '/src/Core/Support/BrandIdentity.php';
require_once $root . '/src/Core/Support/CommunicationDesignTokens.php';
require_once $root . '/src/Integrations/Notifications/NotificationCatalogue.php';
require_once $root . '/src/Integrations/Notifications/DelnavazanEmailRenderer.php';
require_once $root . '/src/Core/Application/Finance/Pricing/PricingMoney.php';
require_once $root . '/src/Core/Application/Finance/Statements/TeacherStatementEmailRenderer.php';
require_once $root . '/src/Core/Application/Finance/Statements/StatementTemplateRule.php';
require_once $root . '/src/Core/Application/Finance/Receipts/StudentReceiptRenderer.php';

use Delnavazan\Platform\Core\Application\Finance\Receipts\StudentReceiptRenderer;
use Delnavazan\Platform\Core\Application\Finance\Statements\StatementTemplateRule;
use Delnavazan\Platform\Core\Application\Finance\Statements\TeacherStatementEmailRenderer;
use Delnavazan\Platform\Core\Support\BrandIdentity;
use Delnavazan\Platform\Core\Support\CommunicationDesignTokens as Tokens;
use Delnavazan\Platform\Integrations\Notifications\DelnavazanEmailRenderer;
use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;

/* 1. Canonical public identity. */
$fail('Persian brand name', BrandIdentity::PERSIAN_NAME === 'دلنوازان');
$fail('English brand name', BrandIdentity::ENGLISH_NAME === 'Delnavazan');
$fail('canonical phone', BrandIdentity::PHONE_E164 === '+61413413004' && BrandIdentity::PHONE_HREF === 'tel:+61413413004');
$fail('display phone', BrandIdentity::PHONE_DISPLAY === '+61 413 413 004');
$fail('canonical WhatsApp', BrandIdentity::WHATSAPP_E164 === '+61413413004' && BrandIdentity::WHATSAPP_URL === 'https://wa.me/61413413004');
$fail('canonical support email', BrandIdentity::SUPPORT_EMAIL === 'admin@delnavazan.com' && BrandIdentity::SUPPORT_MAILTO === 'mailto:admin@delnavazan.com');
$fail('canonical site', BrandIdentity::SITE_URL === 'https://delnavazan.com');
$fail('canonical greeting', BrandIdentity::GREETING === 'درود');
$fail('canonical tagline', BrandIdentity::TAGLINE === 'موسیقی ایرانی؛ برای ایرانیان دور از وطن');
$links = BrandIdentity::contactLinks();
$fail('four public contact links', array_keys($links) === array('phone', 'whatsapp', 'email', 'site'));
$fail('tel link', $links['phone']['href'] === 'tel:+61413413004');
$fail('WhatsApp link', $links['whatsapp']['href'] === 'https://wa.me/61413413004');
$fail('mail link', $links['email']['href'] === 'mailto:admin@delnavazan.com');

/* 2. The website token block and the renderer tokens are one palette. */
$themeCss = (string) file_get_contents($repo . '/theme/assets/css/theme.css');
$fail('theme token block exists', str_contains($themeCss, '@layer tokens'));
foreach (Tokens::COLORS as $role => $hex) {
    $variable = '--dzn-color-' . str_replace('_', '-', $role);
    $pattern = '/' . preg_quote($variable, '/') . ':\s*(#[0-9a-fA-F]{6})\s*;/';
    $fail('theme defines ' . $variable, preg_match($pattern, $themeCss, $match) === 1);
    $fail('theme value matches renderer token for ' . $role, strtoupper($match[1]) === strtoupper($hex));
}
foreach (Tokens::RGB as $role => $channels) {
    $variable = '--dzn-rgb-' . str_replace('_', '-', $role);
    $fail('theme defines ' . $variable, preg_match('/' . preg_quote($variable, '/') . ':\s*([0-9 ]+);/', $themeCss, $match) === 1);
    $fail('RGB channels match for ' . $role, preg_replace('/\s+/', ' ', trim($match[1])) === $channels);
}
$fail('theme body font matches tokens', preg_match('/--dzn-font-body:\s*([^;]+);/s', $themeCss, $font) === 1
    && preg_replace('/\s+/', ' ', trim($font[1])) === Tokens::FONT_BODY);
$fail('supporting text is at least 14px', preg_match('/--dzn-text-small:\s*\.875rem;/', $themeCss) === 1);
$fail('button label token is 15px', preg_match('/--dzn-text-button:\s*\.9375rem;/', $themeCss) === 1);
$fail('focus token exists', str_contains($themeCss, '--dzn-color-focus:'));
$fail('one gold value is used', !str_contains($themeCss, '185 142 55') && str_contains($themeCss, '--dzn-rgb-gold: 212 171 79;'));

$themeJson = json_decode((string) file_get_contents($repo . '/theme/theme.json'), true);
$fail('theme.json is valid', is_array($themeJson));
$sizes = array();
$palette = array();
foreach ((array) ($themeJson['settings']['typography']['fontSizes'] ?? array()) as $size) $sizes[(string) $size['slug']] = (string) $size['size'];
foreach ((array) ($themeJson['settings']['color']['palette'] ?? array()) as $colour) $palette[(string) $colour['slug']] = strtoupper((string) $colour['color']);
$fail('editor small size matches the 14px screen minimum', ($sizes['small'] ?? '') === '0.875rem');
foreach (array('background', 'surface', 'surface-elevated', 'text-primary', 'text-secondary', 'text-muted', 'action-primary', 'border', 'accent', 'highlight', 'success', 'warning', 'error', 'information', 'focus', 'on-action') as $slug) {
    $fail('editor palette exposes ' . $slug, isset($palette[$slug]));
}

/* 3. The public footer uses the canonical identity and accessible links. */
$footer = (string) file_get_contents($repo . '/theme/footer.php');
$fail('footer reads the canonical brand helper', str_contains($footer, 'dzn_theme_brand_identity()'));
$fail('footer exposes a tel link', str_contains($footer, "esc_url( \$footer_brand['phone_href'] )"));
$fail('footer exposes the canonical mail link', str_contains($footer, "esc_url( \$footer_brand['support_mailto'] )"));
$fail('footer exposes the canonical WhatsApp link', str_contains($footer, "esc_url( \$footer_brand['whatsapp_url'] )"));
$fail('footer labels include the phone number', str_contains($footer, "phone_display"));
$fail('footer icons are decorative', substr_count($footer, 'alt=""') >= 3);
$fail('footer has no stale support address', !str_contains($footer, 'delnavazan@mail.com') && !str_contains($footer, 'support@delnavazan.com'));

/* 4. No active surface keeps a competing contact identity or greeting. */
$scan = array($root . '/src', $root . '/content', $repo . '/theme');
$stale = array();
$greetings = array();
$walk = static function (string $dir) use (&$walk, &$stale, &$greetings): void {
    foreach (scandir($dir) ?: array() as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        if (is_dir($path)) { $walk($path); continue; }
        if (!preg_match('/\.(php|html|css)$/', $entry)) continue;
        $text = (string) file_get_contents($path);
        foreach (array('support@delnavazan.com', 'delnavazan@mail.com') as $needle) {
            if (str_contains($text, $needle)) $stale[] = $path . ':' . $needle;
        }
        if (preg_match('/(?<![\p{L}])سلام(?=[\s،])/u', $text) === 1) $greetings[] = $path;
    }
};
foreach ($scan as $dir) $walk($dir);
$fail('no active file keeps a competing public email: ' . implode(', ', $stale), $stale === array());
$fail('no active customer copy keeps the old greeting: ' . implode(', ', $greetings), $greetings === array());

/* 5. Transactional email renders the canonical shell. */
$fa = DelnavazanEmailRenderer::render('عنوان <تست>', "درود نگار،\n\nمتن پیام <script>alert(1)</script>", array(
    'locale' => 'fa-IR',
    'cta' => array('label' => 'مشاهده و پاسخ', 'url' => 'https://delnavazan.com/student-portal/'),
));
$fail('Persian email is RTL', str_contains($fa['html'], 'dir="rtl"') && str_contains($fa['html'], 'lang="fa"'));
$fail('email is 600px', str_contains($fa['html'], 'width="600"') && str_contains($fa['html'], 'max-width:600px'));
$fail('email uses Vazirmatn with a fallback stack', str_contains($fa['html'], 'font-family:Vazirmatn, Tahoma, &#039;Segoe UI&#039;, Arial, sans-serif')
    || str_contains($fa['html'], "font-family:Vazirmatn, Tahoma, 'Segoe UI', Arial, sans-serif"));
$fail('email uses the canonical palette', str_contains($fa['html'], Tokens::color('background')) && str_contains($fa['html'], Tokens::color('accent')) && !str_contains($fa['html'], '#0E8C8C') && !str_contains($fa['html'], '#B23A48'));
$fail('email carries the canonical tagline', str_contains($fa['html'], BrandIdentity::TAGLINE));
$fail('email carries the canonical support email', str_contains($fa['html'], BrandIdentity::SUPPORT_EMAIL) && str_contains($fa['html'], BrandIdentity::SUPPORT_MAILTO));
$fail('email carries phone and WhatsApp links', str_contains($fa['html'], 'tel:+61413413004') && str_contains($fa['html'], 'https://wa.me/61413413004'));
$fail('email carries the site link', str_contains($fa['html'], BrandIdentity::SITE_URL));
$fail('email escapes the subject and body', !str_contains($fa['html'], '<تست>') && !str_contains($fa['html'], '<script'));
$fail('email text does not contain markup', !str_contains($fa['text'], '<script'));
$unsafe = DelnavazanEmailRenderer::render('t', 'body', array('cta' => array('label' => 'x', 'url' => 'javascript:alert(1)')));
$fail('unsafe CTA is dropped', !str_contains($unsafe['html'], 'javascript:'));
$en = DelnavazanEmailRenderer::render('Subject', 'Body', array('locale' => 'en'));
$fail('English email is LTR and uses the English tagline', str_contains($en['html'], 'dir="ltr"') && str_contains($en['html'], BrandIdentity::TAGLINE_EN));

/* 6. Teacher statement email and student receipt share the same identity. */
$statement = array(
    'statement_id' => 42, 'statement_version' => 1, 'state' => 'issued',
    'period_start_utc' => '2026-10-01 00:00:00', 'period_end_utc' => '2026-11-01 00:00:00',
    'period_label' => 'اکتبر ۲۰۲۶', 'currency' => 'TOM',
    'payable_amount_minor' => 4500000, 'payable_line_count' => 1, 'total_line_count' => 1,
    'issued_at' => '2026-11-01 09:00:00',
);
$lines = array(array('occurrence_starts_at_utc' => '2026-10-05 15:00:00', 'course' => 'کلاس تار با ندا محمدی', 'duration_minutes' => 60, 'base_amount_minor' => 4000000, 'bonus_amount_minor' => 500000, 'line_amount_minor' => 4500000, 'disposition' => 'payable'));
$statementEmail = (new TeacherStatementEmailRenderer())->render($statement, $lines, array('name' => 'نداً محمدی', 'reference' => 'DZN-TCH-0001', 'locale' => 'fa'), array('timezone' => 'Asia/Tehran', 'calendar' => 'gregorian'));
$fail('statement email uses the shared shell', str_contains($statementEmail['html'], 'width="640"') && str_contains($statementEmail['html'], BrandIdentity::TAGLINE));
$fail('statement email keeps the exact recorded amount', str_contains($statementEmail['html'], '۴,۵۰۰,۰۰۰ تومان') && str_contains($statementEmail['text'], '۴,۵۰۰,۰۰۰ تومان'));
$fail('statement email carries the canonical contact', str_contains($statementEmail['html'], BrandIdentity::SUPPORT_EMAIL) && str_contains($statementEmail['html'], 'tel:+61413413004'));
$fail('statement email renders the issued instant in the recipient timezone', str_contains($statementEmail['html'], '۱۲:۳۰') && str_contains($statementEmail['html'], 'Asia/Tehran') && !str_contains($statementEmail['html'], '09:00:00'));
$fail('statement email renders line dates in the recipient timezone', str_contains($statementEmail['html'], '۲۰۲۶-۱۰-۰۵'));
$fail('statement email wraps a long Persian identity', str_contains($statementEmail['html'], 'نداً محمدی') && str_contains($statementEmail['html'], 'کلاس تار با ندا محمدی'));

$receipt = (new StudentReceiptRenderer())->render(array(
    'receipt_uid' => 'RCPT-0001', 'paid_at' => '2026-11-01', 'student_name' => 'نگار رضایی',
    'course' => 'کلاس تار با ندا محمدی', 'instalment' => 1, 'method' => 'manual',
    'offer_reference' => 'OFFER-9', 'currency' => 'AUD', 'amount_minor' => 12000,
    'refunded_minor' => 0, 'remaining_minor' => 12000,
));
$fail('receipt uses the canonical font', str_contains($receipt, Tokens::FONT_BODY));
$fail('receipt keeps the transaction currency', str_contains($receipt, 'A$۱۲۰.۰۰'));
$fail('receipt carries the canonical contact', str_contains($receipt, BrandIdentity::SUPPORT_EMAIL) && str_contains($receipt, 'tel:+61413413004') && str_contains($receipt, 'https://wa.me/61413413004'));
$fail('receipt has no stale palette', !str_contains($receipt, '#0E8C8C') && !str_contains($receipt, '#B23A48'));
$fail('statement document default footer carries the canonical contact', str_contains(StatementTemplateRule::DEFAULT_FOOTER, BrandIdentity::SUPPORT_EMAIL));
$fail('statement document default CSS uses the canonical font', str_contains(StatementTemplateRule::DEFAULT_CSS, Tokens::FONT_BODY));
$fail('statement document default CSS has no competing teal', !str_contains(StatementTemplateRule::DEFAULT_CSS, '#0E8C8C'));

/* 7. Notification copy uses the canonical greeting and never announces a proposal as confirmed. */
foreach (NotificationCatalogue::events() as $key => $event) {
    $copy = NotificationCatalogue::defaultCopy((string) $key, 'email', 'fa-IR');
    if (!is_array($copy)) continue;
    $body = (string) ($copy['body'] ?? '');
    $fail('notification copy greets with درود: ' . $key, str_starts_with($body, 'درود'));
    $fail('notification copy contains no old greeting: ' . $key, preg_match('/(?<![\p{L}])سلام(?=[\s،])/u', $body) !== 1);
}
$proposal = NotificationCatalogue::defaultCopy('reschedule.replacement.proposed.student', 'email', 'fa-IR');
$fail('a proposed replacement is not called confirmed', is_array($proposal)
    && str_contains((string) $proposal['body'], 'تا پاسخ شما')
    && !str_contains((string) $proposal['body'], 'قطعی شد'));

echo "communications-brand-contract: OK\n";
